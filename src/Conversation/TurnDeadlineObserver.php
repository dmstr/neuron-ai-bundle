<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:35:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use NeuronAI\Observability\ObserverInterface;

/**
 * Wall-clock deadline of one turn (`turn.deadline_seconds`).
 *
 * The agent emits `inference-start` before every provider call and
 * `tool-calling` before every tool call, both outside the tool error
 * handler; once the deadline has passed, the observer throws there and the
 * run ends. A call already in flight is bounded by the provider's HTTP
 * timeout (the same number of seconds), so the worst case is the deadline
 * plus one call. One instance per run.
 */
final class TurnDeadlineObserver implements ObserverInterface
{
    private const array CHECKED_EVENTS = ['inference-start', 'tool-calling'];

    private readonly float $deadline;

    /**
     * @param \Closure(): float|null $clock seconds as float (default microtime(true)); a seam for tests
     */
    public function __construct(
        private readonly int $seconds,
        private readonly ?\Closure $clock = null,
    ) {
        $this->deadline = $this->now() + $seconds;
    }

    public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
    {
        if (\in_array($event, self::CHECKED_EVENTS, true) && $this->now() > $this->deadline) {
            throw new TurnDeadlineExceededException(sprintf('The turn exceeded its deadline of %d seconds.', $this->seconds));
        }
    }

    private function now(): float
    {
        return null !== $this->clock ? ($this->clock)() : microtime(true);
    }
}
