<?php

// file generated with AI assistance: Claude Code - 2026-11-27 22:41:45 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Chat;

use ApiPlatform\Metadata\IriConverterInterface;
use Dmstr\NeuronAiBundle\Agent\Rule\RuleOutcome;
use Dmstr\NeuronAiBundle\Agent\StaleReferenceException;
use Dmstr\NeuronAiBundle\Agent\TurnConfiguration;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Retrieval\SourceFreshnessInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Runs one stateless turn on an agent version, without HTTP request,
 * security token or user: the rules, gates and result of
 * `POST /agents/{id}/chat`, usable from a queue worker or a command.
 * {@see AgentChat} adds the request-bound part (the `target` `candidate`
 * permission and the preview of the token's user) and runs on it, as does
 * the evaluation judge ({@see \Dmstr\NeuronAiBundle\Evaluation\Judge}).
 *
 * Entries, each one step further down:
 *  - {@see run()}: an agent; a disabled agent answers 422 `Agent "<slug>"
 *    is disabled.`, an agent without a current version 422 `Agent
 *    "<slug>" has no published version.`; then the current version;
 *  - {@see runVersion()}: a version, mapped by {@see VersionTurnMapper::map()}
 *    with the `variables`, `language` and `maxToolCalls` of the options;
 *  - {@see runConfigured()}: an already mapped {@see TurnConfiguration}
 *    (no database needed beyond what the configuration references).
 * With `rules` on, the version's pre-answer rules run on the last user
 * message: a rule that answers or refuses returns its text without
 * retrieval or provider call. Otherwise the turn runs on
 * {@see ChatService::chatConfigured()}, with the version's answer gates
 * when `gates` is on. The result is an {@see AgentChatResult} whose sources
 * carry `checkedAt` and `changedAt` of the application's
 * {@see SourceFreshnessInterface}.
 *
 * {@see execute()} and {@see executeConfigured()} run the same turn but
 * return the raw {@see AgentRunResult} (configuration, rule outcome or the
 * array of `chatConfigured()`), for callers that evaluate the answer
 * themselves; they touch neither the IRI converter nor the freshness port.
 *
 * Errors are the HTTP exceptions of the agent chat, also for headless
 * callers, which map them themselves: {@see UnprocessableEntityHttpException}
 * for a disabled or unpublished agent and for invalid variables or language,
 * {@see StaleReferenceException} for a reference of the version that no
 * longer resolves, and the provider failure unchanged.
 */
final readonly class AgentRunner
{
    public function __construct(
        private VersionTurnMapper $mapper,
        private ChatService $chatService,
        private ?IriConverterInterface $iriConverter = null,
        private ?SourceFreshnessInterface $freshness = null,
    ) {
    }

    /**
     * The turn on the current published version of $agent.
     *
     * @param list<array{role: string, content: string}> $messages user/assistant messages; the last one must be a user message
     *
     * @throws UnprocessableEntityHttpException for a disabled or unpublished agent, invalid variables or language (422)
     * @throws StaleReferenceException          for a reference of the version that no longer resolves
     */
    public function run(Agent $agent, array $messages, AgentRunOptions $options = new AgentRunOptions()): AgentChatResult
    {
        return $this->runVersion($this->publishedVersion($agent), $messages, $options);
    }

    /**
     * The turn on $version, whatever its kind (published or preview); the
     * agent's `enabled` flag is not checked here ({@see assertEnabled()}).
     *
     * @param list<array{role: string, content: string}> $messages
     *
     * @throws UnprocessableEntityHttpException for invalid variables or language (422)
     * @throws StaleReferenceException          for a reference of the version that no longer resolves
     */
    public function runVersion(AgentVersion $version, array $messages, AgentRunOptions $options = new AgentRunOptions()): AgentChatResult
    {
        return $this->chatResult($this->execute($version, $messages, $options));
    }

    /**
     * The turn on an already mapped configuration; `variables`, `language`
     * and `maxToolCalls` of $options are ignored.
     *
     * @param list<array{role: string, content: string}> $messages
     *
     * @throws UnprocessableEntityHttpException for an invalid message list or a collection that vanished since the mapping
     */
    public function runConfigured(TurnConfiguration $configuration, array $messages, AgentRunOptions $options = new AgentRunOptions()): AgentChatResult
    {
        return $this->chatResult($this->executeConfigured($configuration, $messages, $options));
    }

    /**
     * {@see runVersion()} without shaping the result.
     *
     * @param list<array{role: string, content: string}> $messages
     *
     * @throws UnprocessableEntityHttpException for invalid variables or language (422)
     * @throws StaleReferenceException          for a reference of the version that no longer resolves
     */
    public function execute(AgentVersion $version, array $messages, AgentRunOptions $options = new AgentRunOptions()): AgentRunResult
    {
        $configuration = $this->mapper->map($version, $options->variables, $options->language, $options->maxToolCalls);

        return $this->executeConfigured($configuration, $messages, $options);
    }

    /**
     * {@see runConfigured()} without shaping the result.
     *
     * @param list<array{role: string, content: string}> $messages
     *
     * @throws UnprocessableEntityHttpException for an invalid message list or a collection that vanished since the mapping
     */
    public function executeConfigured(TurnConfiguration $configuration, array $messages, AgentRunOptions $options = new AgentRunOptions()): AgentRunResult
    {
        // Every entry passes here right after the mapping: the place for
        // per-run changes of the mapped configuration (such as its tool
        // list), before the rules and the turn.

        if ($options->rules) {
            $question = self::lastUserContent($messages);
            $outcome = null !== $question ? $configuration->rules->evaluate($question, $configuration->language) : null;
            if (null !== $outcome) {
                return new AgentRunResult($configuration, rule: $outcome);
            }
        }

        return new AgentRunResult($configuration, chat: $this->chatService->chatConfigured($messages, $configuration, gates: $options->gates));
    }

    /**
     * The current version of an enabled agent.
     *
     * @throws UnprocessableEntityHttpException for a disabled agent or an agent without a current version (422)
     */
    public function publishedVersion(Agent $agent): AgentVersion
    {
        $this->assertEnabled($agent);

        return $agent->getCurrentVersion()
            ?? throw new UnprocessableEntityHttpException(\sprintf('Agent "%s" has no published version.', $agent->getSlug()));
    }

    /**
     * @throws UnprocessableEntityHttpException for a disabled agent (422)
     */
    public function assertEnabled(Agent $agent): void
    {
        if (!$agent->isEnabled()) {
            throw new UnprocessableEntityHttpException(\sprintf('Agent "%s" is disabled.', $agent->getSlug()));
        }
    }

    private function chatResult(AgentRunResult $run): AgentChatResult
    {
        if (null !== $run->rule) {
            return $this->ruled($run->configuration, $run->rule);
        }

        $configuration = $run->configuration;
        $result = $run->chat ?? throw new \LogicException('A run without a rule outcome has a chat result.');

        return new AgentChatResult(
            answer: $result['answer'],
            provider: $result['provider'],
            model: $result['model'],
            connection: $result['connection'],
            mcpConnections: $result['mcp_connections'],
            sources: $this->withFreshness($result['sources']),
            usage: $result['usage'],
            structured: $result['structured'],
            schemaErrors: $result['schema_errors'],
            forced: $result['forced'],
            limitReached: $result['limit_reached'],
            terminalTool: $result['terminal_tool'],
            agentVersion: $this->versionIri($configuration),
            agentVersionKind: $configuration->version->getKind()->value,
            language: $configuration->language,
            frame: $result['frame'],
            gate: $result['gate'],
            followUps: $result['follow_ups'],
        );
    }

    /**
     * The sources with `checkedAt` and `changedAt` (ISO 8601) of their
     * document from the application's {@see SourceFreshnessInterface}; null
     * without one, for a chunk without a document or an unknown document.
     *
     * @param list<array<string, mixed>> $sources
     *
     * @return list<array<string, mixed>>
     */
    private function withFreshness(array $sources): array
    {
        $ids = array_values(array_filter(array_map(static fn (array $source): ?string => isset($source['documentId']) ? (string) $source['documentId'] : null, $sources)));
        $freshness = null !== $this->freshness && [] !== $ids ? $this->freshness->freshness($ids) : [];

        return array_map(static function (array $source) use ($freshness): array {
            $dates = $freshness[$source['documentId'] ?? ''] ?? [];
            $source['checkedAt'] = isset($dates['checkedAt']) ? $dates['checkedAt']->format(\DATE_ATOM) : null;
            $source['changedAt'] = isset($dates['changedAt']) ? $dates['changedAt']->format(\DATE_ATOM) : null;

            return $source;
        }, $sources);
    }

    /**
     * The result of a call a pre-answer rule answered or refused: the
     * rendered text as the answer, nothing retrieved, no provider called.
     */
    private function ruled(TurnConfiguration $configuration, RuleOutcome $outcome): AgentChatResult
    {
        return new AgentChatResult(
            answer: $outcome->text,
            provider: $configuration->provider,
            model: $configuration->model,
            connection: $configuration->getConnection()?->getName() ?? '',
            mcpConnections: $configuration->mcpConnections,
            sources: [],
            usage: null,
            structured: null,
            schemaErrors: null,
            forced: false,
            limitReached: false,
            terminalTool: null,
            agentVersion: $this->versionIri($configuration),
            agentVersionKind: $configuration->version->getKind()->value,
            language: $configuration->language,
            rule: [
                'index' => $outcome->index,
                'name' => $outcome->name,
                'match' => $outcome->match,
                'outcome' => $outcome->outcome,
                'template' => $outcome->template,
            ],
        );
    }

    /**
     * The IRI of the version; `/api/agent_versions/<uuid>` without an IRI
     * converter or when it finds none.
     */
    private function versionIri(TurnConfiguration $configuration): string
    {
        return $this->iriConverter?->getIriFromResource($configuration->version) ?? '/api/agent_versions/'.$configuration->version->getId()->toRfc4122();
    }

    /**
     * The `content` of the last message with role `user`; null when there
     * is none.
     *
     * @param list<array{role: string, content: string}> $messages
     */
    private static function lastUserContent(array $messages): ?string
    {
        foreach (array_reverse($messages) as $message) {
            if ('user' === ($message['role'] ?? null) && \is_string($message['content'] ?? null)) {
                return $message['content'];
            }
        }

        return null;
    }
}
