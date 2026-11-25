<?php

// file generated with AI assistance: Claude Code - 2026-10-16 09:00:40 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Artifact;

use Dmstr\NeuronAiBundle\Agent\AgentConfiguration;
use Dmstr\NeuronAiBundle\Anonymous\SystemActor;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Security\Permissions;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The gate of the artifact tools of one conversation turn: decides, when
 * the turn's tools are built, whether the turn gets the artifact tools and
 * whether it gets the write tools, and creates the conversation's working
 * folder.
 *
 * {@see resolve()} returns null (no artifact tools) for a version without
 * `artifactTools` (silently, the normal case) and, with a warning naming the
 * reason (the `REASON_*` constants), for
 *
 *  - an anonymous conversation (the creation does not refuse such a
 *    version: it answers sensibly without files);
 *  - a conversation with an `origin` (`eval`, `replay`): their turns run
 *    under the system actor for the run's owner, who never sent them;
 *  - a conversation without an owner;
 *  - no security token, a token of the system actor ({@see SystemActor}),
 *    or a token without a user identifier;
 *  - a token user who is not the conversation's owner (`neuronai:conversation:turn_own`
 *    is owner-only, so this only happens on paths that bypass the voter);
 *  - an owner subject that is no valid folder name
 *    ({@see ArtifactScope::isValidSubject()});
 *  - a working folder that cannot be prepared ({@see ArtifactScope::ensure()}:
 *    missing or read-only root, symlinked folder).
 *
 * `writable` is decided here once, from the token of the request, with the
 * permission `neuronai:artifact:write` (mapped to roles in the
 * application's `za7_core.permissions`); a streamed turn
 * runs its tools after the request has been answered, so the decision can
 * never wait for the call (the rule of {@see \Dmstr\NeuronAiBundle\Mcp\McpToolFactory}).
 * Without the permission the read tools stay and a warning says that the
 * write tools are dropped for this turn.
 *
 * Fails closed and never throws. Call it under the turn lock: it creates
 * `<root>/<ownerSubject>/<conversationId>`.
 */
final class ArtifactAccessResolver
{
    public const string REASON_ANONYMOUS = 'anonymous conversations cannot use artifact tools';
    public const string REASON_ORIGIN = 'evaluation and replay conversations cannot use artifact tools';
    public const string REASON_NO_OWNER = 'the conversation has no owner';
    public const string REASON_NO_TOKEN = 'there is no security token';
    public const string REASON_SYSTEM_ACTOR = 'the turn runs under the system actor';
    public const string REASON_NO_IDENTIFIER = 'the security token has no user identifier';
    public const string REASON_FOREIGN_USER = 'the user of the security token is not the owner of the conversation';
    public const string REASON_INVALID_SUBJECT = 'the owner subject is not usable as an artifact folder name';
    public const string REASON_SCOPE = 'the artifact folder could not be prepared';

    /** Warning for a dropped tool set; {reason} is one of the REASON_* texts. */
    public const string MESSAGE_SKIPPED = 'Artifact tools of conversation {conversation} were not offered: {reason}.';

    /** Warning when only the read tools are offered. */
    public const string MESSAGE_READ_ONLY = 'Artifact write tools (write_file, edit_file) of conversation {conversation} were not offered: the user lacks the permission neuronai:artifact:write.';

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly ArtifactScope $scope,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * The artifact access of the next turn of $conversation, whose pinned
     * version is $configuration; null when the turn offers no artifact tools.
     */
    public function resolve(Conversation $conversation, AgentConfiguration $configuration): ?ArtifactAccess
    {
        if (!$configuration->artifactTools) {
            return null;
        }

        if ($conversation->isAnonymous()) {
            return $this->skip($conversation, self::REASON_ANONYMOUS);
        }

        if (null !== $conversation->getOrigin()) {
            return $this->skip($conversation, self::REASON_ORIGIN, ['origin' => $conversation->getOrigin()]);
        }

        $owner = $conversation->getOwnerSubject();
        if (null === $owner || '' === $owner) {
            return $this->skip($conversation, self::REASON_NO_OWNER);
        }

        $token = $this->tokenStorage->getToken();
        if (null === $token) {
            return $this->skip($conversation, self::REASON_NO_TOKEN);
        }

        if (self::isSystemActor($token)) {
            return $this->skip($conversation, self::REASON_SYSTEM_ACTOR, ['actor' => $token->getUserIdentifier()]);
        }

        $identifier = null === $token->getUser() ? '' : $token->getUserIdentifier();
        if ('' === $identifier) {
            return $this->skip($conversation, self::REASON_NO_IDENTIFIER);
        }

        if ($identifier !== $owner) {
            return $this->skip($conversation, self::REASON_FOREIGN_USER);
        }

        if (!ArtifactScope::isValidSubject($owner)) {
            return $this->skip($conversation, self::REASON_INVALID_SUBJECT);
        }

        $conversationId = $conversation->getId()->toRfc4122();

        try {
            $scopeRoot = $this->scope->ensure($owner, $conversationId);
        } catch (ArtifactScopeException $e) {
            // The message may name server paths: the log is the only place for it.
            return $this->skip($conversation, self::REASON_SCOPE, ['error' => $e->getMessage()]);
        }

        $writable = $this->mayWrite($conversation);
        if (!$writable) {
            $this->logger->warning(self::MESSAGE_READ_ONLY, ['conversation' => $conversationId]);
        }

        return new ArtifactAccess($owner, $conversationId, $scopeRoot, $writable);
    }

    /**
     * Whether the token holds `neuronai:artifact:write`; a failing check denies.
     */
    private function mayWrite(Conversation $conversation): bool
    {
        try {
            return $this->authorizationChecker->isGranted(Permissions::ARTIFACT_WRITE, $conversation);
        } catch (\Throwable $e) {
            $this->logger->warning('The permission neuronai:artifact:write could not be checked for conversation {conversation}: {error}', [
                'conversation' => $conversation->getId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The token {@see SystemActor::run()} installs: it carries an identifier
     * such as `system:neuron:eval`, which is never an owner, but a turn under
     * it acts for nobody who sent it.
     */
    private static function isSystemActor(TokenInterface $token): bool
    {
        return $token instanceof PreAuthenticatedToken && SystemActor::FIREWALL === $token->getFirewallName();
    }

    /**
     * @param array<string, mixed> $context
     */
    private function skip(Conversation $conversation, string $reason, array $context = []): null
    {
        $this->logger->warning(self::MESSAGE_SKIPPED, [
            'conversation' => $conversation->getId()->toRfc4122(),
            'reason' => $reason,
        ] + $context);

        return null;
    }
}
