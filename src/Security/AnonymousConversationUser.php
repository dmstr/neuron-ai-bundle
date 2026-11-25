<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:50:51 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Security;

use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The principal of a request authenticated with the capability token of an
 * anonymous conversation ({@see CapabilityTokenAuthenticator}).
 *
 * Identity trick: the user identifier is the conversation's
 * `ownerSubject` (`anon:<conversation uuid>`). {@see ConversationVoter}
 * grants `neuronai:conversation:read_own`, `neuronai:conversation:turn_own` and
 * `neuronai:conversation:delete_own` when `ownerSubject` equals the token's user
 * identifier, and the owner query extension filters on the same equality,
 * so the principal is bound to exactly one conversation without any change
 * to the ownership rules. The identifier is never the secret token: it is
 * written to the audit log (`dmstr_log_entries.username`) as the actor.
 *
 * The principal has no roles. Every other operation checks a mapped
 * permission, and none of them is mapped to a conversation token, so a
 * token holder gets 403 there and reaches nothing beyond the item
 * operations of its own conversation (decided by the owner attributes).
 */
final class AnonymousConversationUser implements UserInterface
{
    /**
     * @param non-empty-string $identifier the conversation's ownerSubject
     */
    public function __construct(
        private readonly string $identifier,
        private readonly Uuid $conversationId,
    ) {
    }

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return [];
    }

    public function getConversationId(): Uuid
    {
        return $this->conversationId;
    }

    /**
     * Nothing to erase: the user holds neither the token nor its hash.
     */
    #[\Deprecated(since: 'symfony/security-core 7.3')]
    public function eraseCredentials(): void
    {
    }
}
