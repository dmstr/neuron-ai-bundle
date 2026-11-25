<?php

// file generated with AI assistance: Claude Code - 2026-10-03 06:59:14 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

/**
 * The user-turn limit of a conversation as it applies to one run: set on
 * the {@see TurnRequest} only when the turn being run is the last allowed
 * one, so the runner answers it under a forced close from its first
 * inference ({@see UserTurnLimitMiddleware}). A conversation without a
 * limit, or with turns left after this one, gets no object.
 *
 * The caller (the turn services) decides; this object only carries the
 * numbers into the records and the stream's `turn_limit` event.
 */
final readonly class UserTurnLimit
{
    /**
     * @param int $max  the conversation's maximum number of user turns (>= 1)
     * @param int $turn the 1-based number of the turn being run (the conversation's turn count before it plus one)
     */
    public function __construct(
        public int $max,
        public int $turn,
    ) {
        if ($max < 1) {
            throw new \InvalidArgumentException(sprintf('The user-turn limit must be at least 1, %d given.', $max));
        }
        if ($turn < 1) {
            throw new \InvalidArgumentException(sprintf('The turn number must be at least 1, %d given.', $turn));
        }
    }

    /**
     * The limit of the next turn (`$turnCount + 1`) when that turn is the
     * last allowed one (`$turnCount + 1 >= $maxUserTurns`), else null; null
     * $maxUserTurns means unbounded. A turn beyond the limit still yields
     * the object: rejecting such a turn is the caller's job, and should one
     * get through, it is at least forced.
     *
     * @param int|null $maxUserTurns the conversation's limit; null = unbounded
     * @param int      $turnCount    user turns the conversation has counted so far
     */
    public static function forNextTurn(?int $maxUserTurns, int $turnCount): ?self
    {
        if (null === $maxUserTurns || $turnCount + 1 < $maxUserTurns) {
            return null;
        }

        return new self($maxUserTurns, $turnCount + 1);
    }
}
