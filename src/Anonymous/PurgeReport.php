<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:26 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Anonymous;

/**
 * Outcome of one run of {@see AnonymousConversationPurger}: conversations
 * closed after their retention, conversations deleted after their deletion
 * delay, and due conversations skipped because a turn held their advisory
 * lock (they are picked up by a later run). `ran` is false when the run did
 * nothing because the application supplies no access policy.
 */
final readonly class PurgeReport
{
    public function __construct(
        public int $closed = 0,
        public int $deleted = 0,
        public int $skipped = 0,
        public bool $ran = true,
    ) {
    }

    public static function notRun(): self
    {
        return new self(ran: false);
    }

    public function isEmpty(): bool
    {
        return 0 === $this->closed && 0 === $this->deleted && 0 === $this->skipped;
    }

    public function __toString(): string
    {
        return \sprintf('closed %d, deleted %d, skipped %d', $this->closed, $this->deleted, $this->skipped);
    }
}
