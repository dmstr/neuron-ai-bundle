<?php

// file generated with AI assistance: Claude Code - 2026-10-03 10:41:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\NeuronAiBundle\Agent\StaleReferenceException;
use Dmstr\NeuronAiBundle\Chat\AgentChat;
use Dmstr\NeuronAiBundle\Chat\AgentChatResult;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Security\Permissions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * `POST /agents/{id}/chat` (`agent_chat`): one stateless turn on the
 * current published version of the agent, or, with `target` `candidate`,
 * on a preview of its current candidate.
 *
 * The operation loads the agent (`read: true`) and has already checked
 * `neuronai:agent:chat`. Order of the checks, all before any provider is contacted:
 *  1. a body key that the version fixes ({@see self::VERSION_FIXED_KEYS},
 *     among them `connection` and `model`, both given by the version's
 *     model catalogue entry, and `artifactTools`: this action never offers
 *     artifact tools, it has no conversation and no working folder)
 *     answers 422 naming the key; a
 *     `provider` key is rejected by the input schema (no such property); checked on the raw decoded body, so the
 *     message names the key instead of an `additionalProperties` error;
 *  2. the body is validated against `Entity/Agent/chat.input.json`
 *     ({@see InputBodyValidator}: malformed JSON 400, schema violation 422);
 *  3. `target` `candidate` needs the permission {@see Permissions::AGENT_CHAT_CANDIDATE} on top of the
 *     operation's `neuronai:agent:chat` (403 naming the permission, checked through the
 *     authorization checker here, so the published target keeps the
 *     operation's permission): a preview tests an unpublished configuration,
 *     stores a version row and runs the live model check, which is
 *     agent-admin work like the agent writes;
 *  4. a disabled agent answers 422 `Agent "<slug>" is disabled.` for both
 *     targets; then the version of the turn:
 *     - `target` `published` (default): the current version; an agent
 *       without one answers 422 `Agent "<slug>" has no published version.`;
 *     - `target` `candidate`: the preview of the agent's current candidate
 *       from {@see AgentPreviewFactory::previewFor()}, created by the user
 *       identifier of the token (as the publisher in
 *       {@see AgentPublishProcessor}; no token or user denies, fail
 *       closed): the candidate is validated like a publish (422 at
 *       `candidate/<field>`, nothing is stored), an unchanged candidate
 *       reuses its preview, a changed one gets a new preview (409 on a
 *       concurrent creation). The agent's current version is not needed
 *       and not touched;
 *  5. {@see VersionTurnMapper::map()} resolves the version with the
 *     caller's `variables`, `language` and `maxToolCalls` (422 for invalid
 *     variables or an unsupported language, {@see StaleReferenceException}
 *     for a reference of the version that no longer resolves, the model
 *     entry and its connection at `configuration/model`);
 *  6. the version's pre-answer rules are evaluated on the `content` of the
 *     last message with role `user` (none: no evaluation) in the effective
 *     language: a rule that answers or refuses returns its rendered text as
 *     `answer` with `rule` set, `sources` `[]`, `usage` null and no
 *     retrieval or provider call.
 * Otherwise the turn runs on {@see ChatService::chatConfigured()} with the
 * version's answer gates on (the only caller that enables them) and `rule`
 * is null: a turn the prompt-injection guard refuses returns the
 * version's `injectionRefusal` text as `answer` with `gate` set
 * (`{gate, kind, template}`, never the reason), `sources` `[]`, no frame
 * and `usage` with the guard's tokens under `usage.gates`; nothing is
 * stored. With `followUps` on, a complete text answer carries the
 * suggestions as `followUps` (`[]` when the gate failed or found
 * nothing); a rule-decided or refused call has `followUps` null. Returns an {@see AgentChatResult} (200)
 * whose `agentVersion` is the IRI of the version that answered (the
 * preview for `target` `candidate`) and `agentVersionKind` its kind.
 * Steps 3 to 6 and the turn run in {@see AgentChat}, shared with the
 * OpenAI-compatible `POST /v1/chat/completions`.
 *
 * @implements ProcessorInterface<Agent, AgentChatResult>
 */
final class AgentChatProcessor implements ProcessorInterface
{
    /** `target` of the body: the current published version (default). */
    public const string TARGET_PUBLISHED = AgentChat::TARGET_PUBLISHED;

    /** `target` of the body: a preview of the current candidate. */
    public const string TARGET_CANDIDATE = AgentChat::TARGET_CANDIDATE;

    /** Body keys the agent version fixes; rejected on this action. */
    public const array VERSION_FIXED_KEYS = [
        'instructions',
        'connection',
        'model',
        'collections',
        'mcpConnections',
        'retrievalDepth',
        'responseSchema',
        'terminalTools',
        'maxUserTurns',
        'rules',
        'templates',
        'hybrid',
        'hybridWeight',
        'contextMaxChunks',
        'contextMaxChars',
        'contextExpansionMaxChars',
        'contextInstructions',
        'injectionGuard',
        'answerabilityCheck',
        'answerabilityVotes',
        'followUps',
        'artifactTools',
    ];

    public function __construct(
        private readonly InputBodyValidator $inputBodyValidator,
        private readonly AgentChat $agentChat,
    ) {
    }

    /**
     * @throws ValidationException   for an invalid candidate with `target` `candidate` (422 at `candidate/<field>`)
     * @throws ConflictHttpException when the candidate's preview was created concurrently (409)
     * @throws AccessDeniedHttpException with `target` `candidate` without {@see Permissions::AGENT_CHAT_CANDIDATE} (403)
     * @throws AccessDeniedException     with `target` `candidate` and no authenticated user
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AgentChatResult
    {
        if (!$data instanceof Agent) {
            throw new NotFoundHttpException('Not Found');
        }

        $request = $context['request'] ?? null;
        $body = $request instanceof Request ? $request->getContent() : '';

        // Malformed JSON skips the pre-check; the validator answers it with 400.
        $raw = json_decode('' === trim($body) ? '{}' : $body, true);
        if (\is_array($raw)) {
            foreach (self::VERSION_FIXED_KEYS as $key) {
                if (\array_key_exists($key, $raw)) {
                    throw new UnprocessableEntityHttpException(\sprintf('"%s" is fixed by the agent version and cannot be set on this action.', $key));
                }
            }
        }

        return $this->agentChat->chat($data, $this->inputBodyValidator->validate(Agent::OPERATION_CHAT, $body));
    }
}

// - revised 2026-10-13
// - revised 2026-10-16
// - revised 2026-11-19
// - revised 2026-11-20
