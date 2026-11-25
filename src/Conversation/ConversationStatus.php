<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:21:48 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

/**
 * Status of a conversation (`neuron_conversation.status`). A running turn is
 * not a status: it is derived from the conversation's advisory lock.
 *
 * - active: accepts turns;
 * - archived: read-only, a turn is rejected;
 * - closed: read-only after a limit was reached or the retention passed
 *   (anonymous conversations; `closedAt` and `closeReason` say when and
 *   why), a turn is rejected; deleted after the deletion delay.
 */
final class ConversationStatus
{
    public const string ACTIVE = 'active';
    public const string ARCHIVED = 'archived';
    public const string CLOSED = 'closed';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::ACTIVE, self::ARCHIVED, self::CLOSED];
    }
}
