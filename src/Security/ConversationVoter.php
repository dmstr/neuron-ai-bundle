<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:34:45 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Security;

use Dmstr\NeuronAiBundle\Entity\Conversation;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decides the three owner attributes of {@see Permissions}
 * (`neuronai:conversation:read_own`, `turn_own` and `delete_own`): granted
 * when the conversation's `ownerSubject` equals the user identifier of the
 * token, denied otherwise. The permissions of other holders (`read_any`,
 * `delete_any`, `read_eval`) are mapped to roles in `za7_core.permissions`
 * and decided by the core voter; {@see ConversationSecurity} links both.
 *
 * Fails closed and never throws: no user, an empty identifier or a `null`
 * subject deny. The Hydra operation filter evaluates item security
 * expressions with a `null` object, so the null case must be a plain
 * denial. It calls no authorization checker.
 */
final class ConversationVoter extends Voter
{
    private const array OWN_ATTRIBUTES = [Permissions::READ_OWN, Permissions::TURN_OWN, Permissions::DELETE_OWN];

    public function supportsAttribute(string $attribute): bool
    {
        return \in_array($attribute, self::OWN_ATTRIBUTES, true);
    }

    public function supportsType(string $subjectType): bool
    {
        return 'null' === $subjectType || is_a($subjectType, Conversation::class, true);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $this->supportsAttribute($attribute) && (null === $subject || $subject instanceof Conversation);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (null === $token->getUser() || !$subject instanceof Conversation) {
            return false;
        }

        $identifier = $token->getUserIdentifier();

        return '' !== $identifier && $subject->getOwnerSubject() === $identifier;
    }
}
