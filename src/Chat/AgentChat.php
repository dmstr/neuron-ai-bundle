<?php

// file generated with AI assistance: Claude Code - 2026-11-20 13:40:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Chat;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\NeuronAiBundle\Agent\AgentPreviewFactory;
use Dmstr\NeuronAiBundle\Agent\StaleReferenceException;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Entity\Agent;
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
 * Steps 2 to 4 and the turn are the {@see AgentRunner}'s; this class keeps
 * what needs the request: the permission of step 1 and the preview of the
 * token's user.
 */
final class AgentChat
{
    /** `target` of the input: the current published version (default). */
    public const string TARGET_PUBLISHED = 'published';

    /** `target` of the input: a preview of the current candidate. */
    public const string TARGET_CANDIDATE = 'candidate';

    private readonly AgentRunner $runner;

    /**
     * Without $runner the runner is built from $mapper, $chatService,
     * $iriConverter and $freshness; with one, those four are not used.
     */
    public function __construct(
        VersionTurnMapper $mapper,
        ChatService $chatService,
        IriConverterInterface $iriConverter,
        private readonly AgentPreviewFactory $previewFactory,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        ?SourceFreshnessInterface $freshness = null,
        ?AgentRunner $runner = null,
    ) {
        $this->runner = $runner ?? new AgentRunner($mapper, $chatService, $iriConverter, $freshness);
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

        /** @var list<array{role: string, content: string}> $messages the schema requires the list */
        $messages = $input['messages'];
        /** @var array<string, mixed> $variables */
        $variables = $input['variables'] ?? [];
        $options = new AgentRunOptions(
            variables: $variables,
            language: isset($input['language']) ? (string) $input['language'] : null,
            maxToolCalls: isset($input['maxToolCalls']) ? (int) $input['maxToolCalls'] : null,
            rules: true,
            gates: true,
        );

        if (!$onCandidate) {
            return $this->runner->run($agent, $messages, $options);
        }

        $this->runner->assertEnabled($agent);

        return $this->runner->runVersion($this->previewFactory->previewFor($agent, $this->userIdentifier()), $messages, $options);
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
}

// - revised 2026-11-27 (run on AgentRunner)
