<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:54:19 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Anonymous;

use Dmstr\NeuronAiBundle\Conversation\ConversationStatus;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Psr\Clock\ClockInterface;

/**
 * Closes a conversation: status `closed`, `closedAt` and `closeReason` (one
 * of the `Conversation::CLOSE_REASON_*` constants). A closed conversation
 * stays readable and deletable but accepts no turn.
 *
 * Idempotent: a conversation that is already closed keeps its time and
 * reason. Nothing is persisted here; the caller flushes (the versioned
 * fields make the close an audited `update`).
 */
final class ConversationCloser
{
    public function __construct(
        private readonly ?ClockInterface $clock = null,
    ) {
    }

    /**
     * @return bool true when the conversation was closed by this call
     */
    public function close(Conversation $conversation, string $reason, ?\DateTimeImmutable $at = null): bool
    {
        if (ConversationStatus::CLOSED === $conversation->getStatus()) {
            return false;
        }

        $conversation
            ->setStatus(ConversationStatus::CLOSED)
            ->setClosedAt($at ?? $this->clock?->now() ?? new \DateTimeImmutable())
            ->setCloseReason($reason);

        return true;
    }
}
