<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:35:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use Dmstr\NeuronAiBundle\Agent\AgentLimitDefaultsInterface;
use Dmstr\NeuronAiBundle\Agent\LimitResolver;
use Dmstr\NeuronAiBundle\Agent\Rule\RuleOutcome;
use Dmstr\NeuronAiBundle\Agent\StaleReferenceException;
use Dmstr\NeuronAiBundle\Agent\TurnConfiguration;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousAccessPolicy;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousAccessPolicyInterface;
use Dmstr\NeuronAiBundle\Anonymous\ConversationCloser;
use Dmstr\NeuronAiBundle\Anonymous\ConversationLimitException;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateRun;
use Dmstr\NeuronAiBundle\Conversation\Gate\TurnGates;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\Mcp\McpToolFactory;
use Dmstr\NeuronAiBundle\Provider\ProviderType;
use Dmstr\NeuronAiBundle\Stream\StreamEventStore;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * One turn of a persisted conversation.
 *
 * The gate, before the lock and before any retrieval or provider call:
 *
 * - a conversation that accepts no turns (archived or closed) → 422 with a
 *   {@see ConversationLimitException} code;
 * - a conversation whose `turnCount` reached its `maxUserTurns` (possible
 *   only after the counting fallback {@see TurnPersistence::markFinal()},
 *   which does not close) → closed and 422 `user_turn_limit`;
 * - agent conversations (pinned `agentVersion`): `responseSchema` or
 *   `terminalTools` → 422 naming the key (`[]` counts as given), both are
 *   fixed by the version;
 * - anonymous conversations additionally, with the current
 *   {@see AnonymousAccessPolicy}: `responseSchema` → 422 (it would become
 *   system-prompt text from a public endpoint); retention passed
 *   (`createdAt + retentionDays <= now`), turn limit or token budget
 *   reached → the conversation is closed (flushed and audited as an
 *   `update`) and the turn answers 422 `retention_expired`, `turn_limit` or
 *   `token_limit`. Their turns never offer MCP tools, whatever the
 *   conversation row or its agent version carries
 *   ({@see TurnPersistence::offersMcpTools()}).
 *
 * Then, under the conversation's advisory lock ({@see TurnLock}; held → 409):
 *
 * 1. orphan sweep ({@see TurnPersistence::prepare()}): records left
 *    `streaming` by a dead worker become `aborted`, the stored stream
 *    events of the conversation are deleted and its cancel flag cleared;
 * 2. resolution: the stored provider connection (missing, inactive or of
 *    another type → 422, {@see TurnPersistence::connectionOf()}; no live
 *    model check), the stored collections (unknown → 422) and MCP connections
 *    (rejected by {@see McpConnectionResolver} → 422) and their tools, and
 *    the terminal tool names against those tools ({@see TerminalToolNames},
 *    unknown → 422). An agent conversation instead maps its pinned version
 *    with the stored variables and language
 *    ({@see TurnPersistence::agentConfigurationFor()},
 *    {@see VersionTurnMapper}) and takes provider connection, model, instructions,
 *    response schema, collections, retrieval depth, hybrid search, context
 *    limits and context instruction, the version's tool
 *    subset, its terminal tools, the tool-call bound (the request may only
 *    lower it) and model options from that configuration; a reference of the
 *    version that no longer resolves → 422 with its path
 *    ({@see StaleReferenceException}). Publishing or rolling back the agent
 *    later, or disabling it, does not change a running conversation;
 *    the version's pre-answer rules are evaluated on the user message right
 *    here ({@see \Dmstr\NeuronAiBundle\Agent\Rule\RuleSet::evaluate()}): when a
 *    rule answers or refuses, the user record and the rule record
 *    ({@see RuleClose}) are stored and the turn is counted (+1 turn, +0
 *    tokens, closing at the limits as in 7.) without history, retrieval or
 *    any model call; a ruled last allowed turn closes the conversation
 *    with `user_turn_limit` but has no `limit` record;
 * 3. history: the complete earlier turns, rebuilt by {@see HistoryReconstructor};
 * 4. the user record is stored (and the title set on the first turn);
 * 5. on an agent conversation whose version has the prompt-injection
 *    guard on, the guard checks the user message ({@see GateRun},
 *    {@see TurnGates}); when it refuses, the refusal record (kind and close
 *    reason `injection_refusal`, the version's `injectionRefusal` text,
 *    the reason only in `providerPayload.gates`) is stored without
 *    retrieval or answer call and the turn is counted as in 7. with the
 *    guard's tokens (a refused last allowed turn closes with
 *    `user_turn_limit` but has no `limit` record); a guard that passes or
 *    fails open keeps its outcome and tokens for the final record. Then
 *    retrieval over the collections with the user message as query and
 *    the answerability hook ({@see GateRun::beforeAnswer()});
 * 6. the run ({@see TurnRunner}) with the limits of the turn
 *    ({@see TurnPersistence::userTurnLimitFor()},
 *    {@see TurnPersistence::maxToolCallsFor()} or the agent configuration);
 * 7. the assistant records are stored ({@see TurnRecorder}), `turnCount`
 *    grows by one and `tokensUsed` by the turn's input plus output tokens
 *    (cached and reasoning tokens are not counted); a conversation that now
 *    reaches its `maxUserTurns` is closed with `user_turn_limit`, otherwise
 *    an anonymous conversation that now reaches its turn limit or token
 *    budget is closed, in the same transaction. The turn itself still
 *    answers 201: the budget is soft by one turn. On an agent conversation
 *    whose version has a `frame` template, the final record gets the
 *    rendered frame ({@see applyFrame()}) before it is stored; its
 *    `content` stays unframed.
 *
 * The last allowed turn of a conversation with `maxUserTurns` (`turnCount`
 * + 1 >= `maxUserTurns`) runs forced from its first inference
 * ({@see UserTurnLimit}): a `limit` record (`user_turns`), then the answer
 * with close reason `user_turn_limit` (a terminal tool's answer keeps
 * `terminal_tool`); an error or aborted turn counts as a turn too, and the
 * counting closes the conversation either way.
 *
 * Every insert takes its sequence from `Conversation::$nextSequence`, read
 * under a pessimistic write lock and incremented in the same transaction.
 * A provider failure, a failed retrieval or a passed deadline is recorded as
 * an assistant record with status `error` (and still counts as a turn); the
 * user record keeps its sequence. The lock is released in every case. The
 * gate, the record writes and the counting are shared with
 * {@see StreamingTurnService} through {@see TurnPersistence}.
 */
final class ConversationTurnService
{
    public const int TITLE_LENGTH = 80;

    private readonly LoggerInterface $logger;

    private readonly TurnPersistence $persistence;

    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly TurnLock $lock,
        private readonly TurnRunner $runner,
        private readonly HistoryReconstructor $reconstructor,
        private readonly ContextRetriever $contextRetriever,
        private readonly McpConnectionResolver $connectionResolver,
        private readonly McpToolFactory $toolFactory,
        ConversationCloser $closer,
        ClockInterface $clock,
        ?AnonymousAccessPolicyInterface $anonymousPolicy = null,
        ?LoggerInterface $logger = null,
        ?LimitResolver $limitResolver = null,
        ?AgentLimitDefaultsInterface $limitDefaults = null,
        ?int $maxToolCallsDefault = null,
        ?VersionTurnMapper $versionTurnMapper = null,
        private readonly ?TurnGates $gates = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->persistence = new TurnPersistence($entityManager, $entityManager->getConnection(), new StreamEventStore($entityManager->getConnection()), $closer, $clock, $anonymousPolicy, $limitResolver, $limitDefaults, $maxToolCallsDefault, $versionTurnMapper);
    }

    /**
     * @param string                    $content        the user message (validated, non-empty)
     * @param array<string, mixed>|null $responseSchema JSON schema the answer must follow; refused on an agent conversation,
     *                                                  which uses the version's schema
     * @param int|null                  $maxToolCalls   tool calls the model may make before it is forced to answer
     *                                                  ({@see ToolCallBoundMiddleware}: a `limit` record, then the
     *                                                  answer with close reason `forced_bound`); null = the
     *                                                  configured default, unbounded by default; for a conversation
     *                                                  created with an agent the request may only lower the bound of
     *                                                  the agent version, settings or bundle default
     *                                                  ({@see VersionTurnMapper::map()})
     * @param list<string>|null         $terminalTools  names of offered tools whose call ends the turn with the tool's
     *                                                  result as the answer ({@see TerminalClose}); null or [] = none;
     *                                                  refused on an agent conversation (even []), which uses the
     *                                                  version's terminal tools
     *
     * @return Message the final assistant record (kind `text` or `schema`, status `complete` or `error`; kind `rule_answer` or `rule_refusal` for a turn a pre-answer rule decided, `injection_refusal` or `unanswerable_refusal` for a turn an answer gate refused)
     *
     * @throws ConversationLimitException       when the conversation accepts no further turn (422 with a code, also
     *                                          `user_turn_limit` once `maxUserTurns` is used up)
     * @throws ConflictHttpException            when another turn of the conversation runs
     * @throws UnprocessableEntityHttpException for a provider connection that is missing or inactive, for collections or connections that are gone or rejected, for a terminal tool name that is not an offered tool, for `responseSchema` or `terminalTools` on an agent conversation, for a stale reference of its version ({@see StaleReferenceException}), and for `responseSchema` on an anonymous conversation
     * @throws ServiceUnavailableHttpException  for an anonymous conversation when no access policy is configured
     * @throws \LogicException                  for an agent conversation when the service has no {@see VersionTurnMapper}
     */
    public function turn(Conversation $conversation, string $content, ?array $responseSchema = null, ?int $maxToolCalls = null, ?array $terminalTools = null): Message
    {
        $policy = $this->persistence->gate($conversation, $responseSchema, $terminalTools);

        $id = $conversation->getId();
        if (!$this->lock->acquire($id)) {
            throw new ConflictHttpException('Another turn of this conversation is running.');
        }

        try {
            // Refreshes and checks the gate again: a turn that finished
            // between the gate and the lock may have closed the conversation.
            $this->persistence->prepare($conversation);

            // An agent conversation runs on its pinned version (the gate
            // refused a caller's responseSchema and terminalTools), any
            // other on its own columns and the request.
            // Never MCP tools in an anonymous conversation, even when its
            // pinned version gained some: decided before the version is
            // mapped, so no MCP transport is built for them.
            $configuration = $this->persistence->agentConfigurationFor($conversation, $maxToolCalls, $this->logger);
            if (null !== $configuration) {
                $outcome = $configuration->rules->evaluate($content, $configuration->language);
                if (null !== $outcome) {
                    return $this->ruledTurn($conversation, $content, $configuration, $outcome, $policy);
                }
                $connection = $configuration->getConnection() ?? throw new \LogicException('The agent configuration has no provider connection.');
                $collections = $this->contextRetriever->loadCollections($configuration->collections);
                $tools = $configuration->tools;
                $terminalTools = $configuration->terminalTools;
                $responseSchema = $configuration->responseSchema;
            } else {
                $connection = TurnPersistence::connectionOf($conversation);
                $collections = $this->contextRetriever->loadCollections($conversation->getCollections());
                // Stored references, not named by the caller: unchecked
                // resolution; never MCP tools in an anonymous conversation.
                $tools = TurnPersistence::offersMcpTools($conversation, [] !== $conversation->getMcpConnections(), $this->logger)
                    ? $this->toolFactory->forConnections($this->connectionResolver->resolveMany($conversation->getMcpConnections()))
                    : [];
                $terminalTools = TerminalToolNames::resolve($terminalTools, $tools);
            }
            $gates = GateRun::of($this->gates, $configuration);
            $history = $this->reconstructor->reconstruct($this->persistence->records($conversation));

            $this->persistence->append($conversation, [TurnRecorder::userRecord($conversation, $content, $responseSchema)], $content);

            $request = new TurnRequest(
                provider: ProviderType::of($connection),
                model: $configuration?->model ?? $conversation->getModel() ?? throw new \LogicException('The conversation has no model.'),
                content: $content,
                history: $history,
                tools: $tools,
                instructions: TurnPersistence::instructionsOf($configuration),
                responseSchema: $responseSchema,
                maxToolCalls: null !== $configuration ? $configuration->maxToolCalls : $this->persistence->maxToolCallsFor($conversation, $maxToolCalls),
                terminalTools: $terminalTools,
                userTurnLimit: $this->persistence->userTurnLimitFor($conversation),
                modelOptions: $configuration?->modelOptions,
                texts: $configuration?->texts ?? new TurnTexts(),
                contextInstruction: $configuration?->contextInstruction ?? $this->contextRetriever->defaultInstruction(),
                connection: $connection,
                providerModel: null !== $configuration ? $configuration->providerModel : $conversation->getProviderModel(),
                threadId: $conversation->getId()->toRfc4122(),
            );
            $retrievalDepth = $configuration?->retrievalDepth ?? $conversation->getRetrievalDepth() ?? 0;

            try {
                // A refusing gate ends the turn with its refusal result.
                $result = $gates->beforeRetrieval($request);
                if (null === $result) {
                    // Hybrid search and context limits are settings of agent
                    // versions only; a conversation without an agent searches
                    // vector-only with the bundle cap and no expansion.
                    $context = $this->contextRetriever->retrieve($this->contextRetriever->query($content, $history), $collections, $retrievalDepth, $configuration?->hybrid ?? false, $configuration?->hybridWeight ?? 0.5, $configuration?->contextLimits);
                    $result = $gates->beforeAnswer($request, $context) ?? $this->runner->run($request->withContext($context));
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Conversation turn failed before the provider call: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
                $result = TurnResult::failedBeforeRun($request, $e);
            }
            $result = $gates->finish($result);

            $records = TurnRecorder::assistantRecords($conversation, $result);
            self::applyFrame($records[array_key_last($records)], $configuration?->frame);
            $this->persistence->append($conversation, $records, result: $result, policy: $policy);

            return $records[array_key_last($records)];
        } finally {
            $this->lock->release($id);
        }
    }

    /**
     * Stores the user record and the rule record of a turn the pre-answer
     * rule $outcome decided and counts the turn; no history, no retrieval,
     * no model call.
     */
    private function ruledTurn(Conversation $conversation, string $content, TurnConfiguration $configuration, RuleOutcome $outcome, ?AnonymousAccessPolicy $policy): Message
    {
        $this->persistence->append($conversation, [TurnRecorder::userRecord($conversation, $content, $configuration->responseSchema)], $content);

        $request = new TurnRequest(
            provider: $configuration->provider,
            model: $configuration->model,
            content: $content,
            responseSchema: $configuration->responseSchema,
            texts: $configuration->texts,
            connection: $configuration->getConnection(),
            providerModel: $configuration->providerModel,
        );
        $result = TurnResult::ruled($request, $outcome);
        $records = TurnRecorder::assistantRecords($conversation, $result);
        $this->persistence->append($conversation, $records, result: $result, policy: $policy);

        return $records[array_key_last($records)];
    }

    /**
     * Sets $frame (the rendered `frame` template of the agent version,
     * {@see TurnConfiguration::$frame}) on the final record $final of a turn
     * when it frames: kind `text`, status `complete` and a close reason other
     * than `terminal_tool`, so a normal, a forced (`forced_bound`,
     * `user_turn_limit`) and a fallback answer are framed, while schema,
     * terminal-tool, rule, error and aborted records never are. The record's
     * `content` (and its provider payload) stay unframed. Shared with
     * {@see StreamingTurn}, which also announces the frame in a `frame`
     * event.
     *
     * @param array{prefix: string, suffix: string}|null $frame null leaves the record unchanged
     *
     * @return bool whether the frame was set
     */
    public static function applyFrame(Message $final, ?array $frame): bool
    {
        if (null === $frame
            || MessageKind::TEXT !== $final->getKind()
            || MessageStatus::COMPLETE !== $final->getStatus()
            || TerminalClose::REASON === $final->getCloseReason()) {
            return false;
        }
        $final->setFrame($frame);

        return true;
    }

    /**
     * The first user message, whitespace collapsed and cut at a word
     * boundary to at most {@see TITLE_LENGTH} characters.
     */
    public static function titleFrom(string $content): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $content));
        if (mb_strlen($title) <= self::TITLE_LENGTH) {
            return $title;
        }

        $cut = mb_substr($title, 0, self::TITLE_LENGTH + 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim(false !== $space && $space > 0 ? mb_substr($cut, 0, $space) : mb_substr($title, 0, self::TITLE_LENGTH));
    }
}

// - revised 2026-10-09
// - revised 2026-10-09

// - revised 2026-10-14
// - revised 2026-11-09
