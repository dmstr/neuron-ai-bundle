<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Replay;

/**
 * Status of a {@see \Dmstr\NeuronAiBundle\Entity\ConversationReplay}
 * (`neuron_conversation_replay.status`, a string column).
 *
 * - pending: stored and queued, the job has not started;
 * - running: the job replays the source's user messages;
 * - completed: every frozen source user message was replayed;
 * - partial: the replay conversation closed before every message was
 *   replayed (the target version's user-turn limit, `closeReason`
 *   `user_turn_limit`, or any other close);
 * - cancelled: the cancel flag stopped the replay before a turn;
 * - failed: a turn could not run (a stale reference of the target version,
 *   a source deleted during the replay, a database error), see
 *   `errorMessage`.
 */
final class ConversationReplayStatus
{
    public const string PENDING = 'pending';
    public const string RUNNING = 'running';
    public const string COMPLETED = 'completed';
    public const string PARTIAL = 'partial';
    public const string CANCELLED = 'cancelled';
    public const string FAILED = 'failed';

    public const array ALL = [self::PENDING, self::RUNNING, self::COMPLETED, self::PARTIAL, self::CANCELLED, self::FAILED];

    /** Statuses of a replay that has not ended. */
    public const array ACTIVE = [self::PENDING, self::RUNNING];

    public static function isActive(string $status): bool
    {
        return \in_array($status, self::ACTIVE, true);
    }
}
