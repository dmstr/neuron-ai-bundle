<?php

// file generated with AI assistance: Claude Code - 2026-11-20 13:40:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Chat;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\NeuronAiBundle\Agent\AgentPreviewFactory;
use Dmstr\NeuronAiBundle\Agent\Rule\RuleOutcome;
use Dmstr\NeuronAiBundle\Agent\StaleReferenceException;
use Dmstr\NeuronAiBundle\Agent\TurnConfiguration;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Retrieval\SourceFreshnessInterface;
use Dmstr\NeuronAiBundle\Security\Permissions;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * One stateless turn on an agent, shared by `POST /agents/{id}/chat`
 * ({@see \Dmstr\NeuronAiBundle\State\AgentChatProcessor}) and the
 * OpenAI-compatible `POST /v1/chat/completions`
 * ({@see \Dmstr\NeuronAiBundle\Controller\OpenAiChatController}).
 *
 * The input is the validated body of `Entity/Agent/chat.input.json`.
 * Order of the checks, all before any provider is contacted:
 *  1. `target` `candidate` needs {@see Permissions::AGENT_CHAT_CANDIDATE}
 *     (403 naming the permission);
 *  2. a disabled agent answers 422 `Agent "<slug>" is disabled.`; then the
 *     version: the current one for `target` `published` (422 `Agent
 *     "<slug>" has no published version.` without one), the preview of the
 *     current candidate for `target` `candidate`
 *     ({@see AgentPreviewFactory::previewFor()}, created by the user of the
 *     token; no user denies);
 *  3. {@see VersionTurnMapper::map()} resolves the version with the
 *     caller's `variables`, `language` and `maxToolCalls`;
 *  4. the version's pre-answer rules on the last user message: a rule that
 *     answers or refuses returns its text without retrieval or provider call.
 * Otherwise the turn runs on {@see ChatService::chatConfigured()} with the
 * version's answer gates on; the sources carry `checkedAt` and `changedAt`.
 */
final class AgentChat
{
    /** `target` of the input: the current published version (default). */
    public const string TARGET_PUBLISHED = 'published';

    /** `target` of the input: a preview of the current candidate. */
    public const string TARGET_CANDIDATE = 'candidate';

    public function __construct(
        private readonly VersionTurnMapper $mapper,
        private readonly ChatService $chatService,
        private readonly IriConverterInterface $iriConverter,
        private readonly AgentPreviewFactory $previewFactory,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly ?SourceFreshnessInterface $freshness = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input the validated chat input
     *
     * @throws ValidationException              for an invalid candidate with `target` `candidate` (422 at `candidate/<field>`)
     * @throws ConflictHttpException            when the candidate's preview was created concurrently (409)
     * @throws AccessDeniedHttpException        with `target` `candidate` without {@see Permissions::AGENT_CHAT_CANDIDATE} (403)
     * @throws AccessDeniedException            with `target` `candidate` and no authenticated user
     * @throws UnprocessableEntityHttpException for a disabled or unpublished agent, invalid variables or language (422)
     * @throws StaleReferenceException          for a reference of the version that no longer resolves
     */
    public function chat(Agent $agent, array $input): AgentChatResult
    {
        $onCandidate = self::TARGET_CANDIDATE === ($input['target'] ?? self::TARGET_PUBLISHED);
        if ($onCandidate && !$this->authorizationChecker->isGranted(Permissions::AGENT_CHAT_CANDIDATE)) {
            throw new AccessDeniedHttpException(\sprintf('Chatting with the candidate of an agent (target "candidate") requires the permission %s.', Permissions::AGENT_CHAT_CANDIDATE));
        }

        if (!$agent->isEnabled()) {
            throw new UnprocessableEntityHttpException(\sprintf('Agent "%s" is disabled.', $agent->getSlug()));
        }
        $version = $onCandidate
            ? $this->previewFactory->previewFor($agent, $this->userIdentifier())
            : $this->publishedVersion($agent);

        /** @var list<array{role: string, content: string}> $messages the schema requires the list */
        $messages = $input['messages'];
        /** @var array<string, mixed> $variables */
        $variables = $input['variables'] ?? [];
        $language = isset($input['language']) ? (string) $input['language'] : null;
        $maxToolCalls = isset($input['maxToolCalls']) ? (int) $input['maxToolCalls'] : null;

        $configuration = $this->mapper->map($version, $variables, $language, $maxToolCalls);

        $question = self::lastUserContent($messages);
        $outcome = null !== $question ? $configuration->rules->evaluate($question, $configuration->language) : null;
        if (null !== $outcome) {
            return $this->ruled($configuration, $outcome);
        }

        $result = $this->chatService->chatConfigured($messages, $configuration, gates: true);

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
     * @throws UnprocessableEntityHttpException for an agent without a current version
     */
    private function publishedVersion(Agent $agent): AgentVersion
    {
        return $agent->getCurrentVersion()
            ?? throw new UnprocessableEntityHttpException(\sprintf('Agent "%s" has no published version.', $agent->getSlug()));
    }

    /**
     * The user identifier of the token, stored as the creator of a preview.
     *
     * @throws AccessDeniedException without an authenticated user
     */
    private function userIdentifier(): string
    {
        $token = $this->tokenStorage->getToken();
        $identifier = null !== $token?->getUser() ? $token->getUserIdentifier() : '';
        if ('' === $identifier) {
            throw new AccessDeniedException('Chatting with the candidate of an agent requires an authenticated user.');
        }

        return $identifier;
    }

    private function versionIri(TurnConfiguration $configuration): string
    {
        return $this->iriConverter->getIriFromResource($configuration->version) ?? '/api/agent_versions/'.$configuration->version->getId()->toRfc4122();
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
