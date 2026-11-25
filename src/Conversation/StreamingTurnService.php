<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:12 UTC

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
use Dmstr\NeuronAiBundle\Conversation\Gate\TurnGates;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\Mcp\McpToolFactory;
use Dmstr\NeuronAiBundle\Provider\ProviderType;
use Dmstr\NeuronAiBundle\Stream\StreamEventStore;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * One streamed turn of a persisted conversation, in two parts so every
 * client error is answered before the first byte of the stream:
 *
 * - {@see begin()} runs the preflight under the conversation's advisory
 *   lock and returns a {@see StreamingTurn} that holds the lock;
 * - {@see StreamingTurn::run()} retrieves, streams the run to a sink,
 *   stores the records and releases the lock.
 *
 * The records equal those of {@see ConversationTurnService} for the same
 * run; the final assistant record exists from the start as a `streaming`
 * placeholder, so its id can be announced in the first event. The gate
 * (archived, closed and anonymous conversations, `maxUserTurns` used up →
 * closed and 422 `user_turn_limit`, `responseSchema` or `terminalTools` on
 * an agent conversation → 422 naming the key), the refresh and second
 * check under the lock, the turn configuration of an agent conversation
 * (its pinned version with the stored variables and language,
 * {@see TurnPersistence::agentConfigurationFor()}), the provider connection
 * of a conversation without an agent (missing or inactive → 422,
 * {@see TurnPersistence::connectionOf()}), the limits of the run, the
 * absence of MCP tools in turns of anonymous conversations
 * ({@see TurnPersistence::offersMcpTools()}) and the counting of the turn
 * (`turnCount`, `tokensUsed`, closing a conversation that reaches its
 * `maxUserTurns` with `user_turn_limit`, else an anonymous one at its
 * limits) are the same as there ({@see TurnPersistence}); a cancelled or
 * failed streamed turn counts as a turn too.
 *
 * The last allowed turn of a conversation with `maxUserTurns` runs forced
 * from its first inference ({@see UserTurnLimit}): the stream sends
 * `turn_limit` once before the first model event, the records start with a
 * `limit` record (`user_turns`) and `done.closeReason` is
 * `user_turn_limit` (a terminal tool's answer keeps `terminal_tool`).
 *
 * The pre-answer rules of an agent conversation's version are evaluated on
 * the user message in {@see begin()}, right after the version is mapped
 * and before collections, history or any model work: a turn a rule answers
 * or refuses streams `sources` (empty), `rule` and `done` only
 * ({@see StreamingTurn}), stores the user record and the placeholder as the
 * rule record ({@see RuleClose}) and is counted like any turn (+1 turn, +0
 * tokens); a ruled last allowed turn closes the conversation with
 * `user_turn_limit` but sends no `turn_limit` event and stores no `limit`
 * record.
 *
 * The answer gates of an agent conversation's version ({@see TurnGates})
 * run in {@see StreamingTurn::run()}, not here, so
 * `sources` stays the first event and the response starts without waiting
 * for a gate call. A turn the prompt-injection guard refuses streams
 * `sources` (empty), `gate` and `done` (`closeReason`
 * `injection_refusal`), stores the placeholder as the refusal record and
 * is counted with the guard's tokens, closing at the limits as above.
 */
final class StreamingTurnService
{
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
        private readonly StreamEventStore $eventStore,
        private readonly Connection $connection,
        ConversationCloser $closer,
        ClockInterface $clock,
        ?AnonymousAccessPolicyInterface $anonymousPolicy = null,
        ?LoggerInterface $logger = null,
        private readonly int $cancelPollIntervalMs = 250,
        ?LimitResolver $limitResolver = null,
        ?AgentLimitDefaultsInterface $limitDefaults = null,
        ?int $maxToolCallsDefault = null,
        ?VersionTurnMapper $versionTurnMapper = null,
        private readonly ?TurnGates $gates = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->persistence = new TurnPersistence($entityManager, $connection, $eventStore, $closer, $clock, $anonymousPolicy, $limitResolver, $limitDefaults, $maxToolCallsDefault, $versionTurnMapper);
    }

    /**
     * The gate ({@see TurnPersistence::gate()}), then the preflight under
     * the lock, before any output: acquires the lock, refreshes the
     * conversation and checks again that it accepts turns, clears
     * `cancel_requested_at`, sweeps orphans and deletes the
     * conversation's stored events, resolves collections, connections and
     * tools and checks the terminal tool names against those tools
     * ({@see TerminalToolNames}), rebuilds the history, resolves the
     * limits of the run ({@see TurnPersistence::userTurnLimitFor()},
     * {@see TurnPersistence::maxToolCallsFor()}), and stores the user record and the
     * `streaming` placeholder (the next sequence is reserved for it). The
     * lock is released when anything here throws.
     *
     * An agent conversation instead maps its pinned version with the
     * stored variables and language ({@see VersionTurnMapper}) and takes
     * provider, model, instructions, response schema (also on the user
     * record and the placeholder, which become kind `schema`),
     * collections, retrieval depth, the version's tool subset, its terminal
     * tools, the tool-call bound (the request may only lower it) and
     * model options from that configuration, plus the turn texts (limit
     * reasons, forced-close fallback) and the rendered `frame`, which the
     * turn sets on its final record and announces in a `frame` event
     * ({@see StreamingTurn}); a reference of the version that no
     * longer resolves → 422 with its path ({@see StaleReferenceException}).
     * Publishing or rolling back the agent later, or disabling it, does not
     * change a running conversation.
     *
     * @param string                    $content        the user message (validated, non-empty)
     * @param array<string, mixed>|null $responseSchema JSON schema the answer must follow; refused on an agent conversation,
     *                                                  which uses the version's schema
     * @param int|null                  $maxToolCalls   tool calls the model may make before it is forced to answer
     *                                                  ({@see ToolCallBoundMiddleware}: a `limit` event and record,
     *                                                  then the answer with close reason `forced_bound`); null = the
     *                                                  configured default ({@see TurnRunner}), unbounded by default;
     *                                                  for a conversation created with an agent the request may only
     *                                                  lower the bound of the agent version, settings or bundle
     *                                                  default ({@see VersionTurnMapper::map()})
     * @param list<string>|null         $terminalTools  names of offered tools whose call ends the turn with the tool's
     *                                                  result as the answer and a `terminal_tool` event ({@see TerminalClose});
     *                                                  null or [] = none; refused on an agent conversation (even []),
     *                                                  which uses the version's terminal tools
     *
     * @throws ConversationLimitException       when the conversation accepts no further turn (422 with a code, also
     *                                          `user_turn_limit` once `maxUserTurns` is used up)
     * @throws ConflictHttpException            when another turn of the conversation runs (409)
     * @throws UnprocessableEntityHttpException for a provider connection that is missing or inactive, for collections or connections that are gone or rejected, for a terminal tool name that is not an offered tool, for `responseSchema` or `terminalTools` on an agent conversation, for a stale reference of its version ({@see StaleReferenceException}), and for `responseSchema` on an anonymous conversation (422)
     * @throws ServiceUnavailableHttpException  for an anonymous conversation when no access policy is configured (503)
     * @throws \LogicException                  for an agent conversation when the service has no {@see VersionTurnMapper}
     */
    public function begin(Conversation $conversation, string $content, ?array $responseSchema = null, ?int $maxToolCalls = null, ?array $terminalTools = null): StreamingTurn
    {
        $policy = $this->persistence->gate($conversation, $responseSchema, $terminalTools);

        $id = $conversation->getId();
        if (!$this->lock->acquire($id)) {
            throw new ConflictHttpException('Another turn of this conversation is running.');
        }

        try {
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
            $history = $this->reconstructor->reconstruct($this->persistence->records($conversation));

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

            $placeholder = TurnRecorder::placeholderRecord($conversation, $request->provider, $request->model, $responseSchema, $request->connection, $request->providerModel);
            $this->persistence->append($conversation, [TurnRecorder::userRecord($conversation, $content, $responseSchema), $placeholder], $content);

            return new StreamingTurn(
                conversation: $conversation,
                placeholder: $placeholder,
                request: $request,
                collections: $collections,
                retrievalDepth: $configuration?->retrievalDepth ?? $conversation->getRetrievalDepth() ?? 0,
                hybrid: $configuration?->hybrid ?? false,
                hybridWeight: $configuration?->hybridWeight ?? 0.5,
                contextLimits: $configuration?->contextLimits,
                persistence: $this->persistence,
                policy: $policy,
                lock: $this->lock,
                runner: $this->runner,
                contextRetriever: $this->contextRetriever,
                eventStore: $this->eventStore,
                connection: $this->connection,
                logger: $this->logger,
                cancelPollIntervalMs: $this->cancelPollIntervalMs,
                frame: $configuration?->frame,
                configuration: $configuration,
                turnGates: $this->gates,
            );
        } catch (\Throwable $e) {
            try {
                $this->lock->release($id);
            } catch (\Throwable $releaseFailure) {
                $this->logger->error('Could not release the turn lock: {message}', ['message' => $releaseFailure->getMessage(), 'exception' => $releaseFailure]);
            }

            throw $e;
        }
    }

    /**
     * The streamed turn the pre-answer rule $outcome decided: stores the
     * user record and the placeholder (which becomes the rule record) and
     * returns a turn that sends `sources`, `rule` and `done` only; no
     * history, no retrieval, no model call. Runs under the lock taken by
     * {@see begin()}.
     */
    private function ruledTurn(Conversation $conversation, string $content, TurnConfiguration $configuration, RuleOutcome $outcome, ?AnonymousAccessPolicy $policy): StreamingTurn
    {
        $request = new TurnRequest(
            provider: $configuration->provider,
            model: $configuration->model,
            content: $content,
            responseSchema: $configuration->responseSchema,
            texts: $configuration->texts,
            connection: $configuration->getConnection(),
            providerModel: $configuration->providerModel,
        );

        $placeholder = TurnRecorder::placeholderRecord($conversation, $request->provider, $request->model, $configuration->responseSchema, $request->connection, $request->providerModel);
        $this->persistence->append($conversation, [TurnRecorder::userRecord($conversation, $content, $configuration->responseSchema), $placeholder], $content);

        return new StreamingTurn(
            conversation: $conversation,
            placeholder: $placeholder,
            request: $request,
            collections: [],
            retrievalDepth: 0,
            persistence: $this->persistence,
            policy: $policy,
            lock: $this->lock,
            runner: $this->runner,
            contextRetriever: $this->contextRetriever,
            eventStore: $this->eventStore,
            connection: $this->connection,
            logger: $this->logger,
            cancelPollIntervalMs: $this->cancelPollIntervalMs,
            ruleOutcome: $outcome,
        );
    }
}

// - revised 2026-10-09
// - revised 2026-10-09

// - revised 2026-10-14
