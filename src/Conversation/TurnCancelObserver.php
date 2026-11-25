<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:12 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use NeuronAI\Observability\ObserverInterface;

/**
 * Cancellation check of one streaming turn, the sibling of
 * {@see TurnDeadlineObserver}: at `inference-start`, `tool-calling` and
 * `tool-called` it asks the closure and throws
 * {@see TurnCancelledException} when it answers true, which ends the run.
 *
 * It is registered after the {@see TurnRecorder}, so a tool call that
 * completed is recorded before the cancellation at `tool-called` ends the
 * run. `tool-calling` is emitted outside the tool error handler and
 * `tool-called` in its `finally`, so neither throw becomes a tool result.
 * One instance per run.
 */
final class TurnCancelObserver implements ObserverInterface
{
    private const array CHECKED_EVENTS = ['inference-start', 'tool-calling', 'tool-called'];

    /**
     * @param \Closure(): bool $isCancelled
     */
    public function __construct(
        private readonly \Closure $isCancelled,
    ) {
    }

    public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
    {
        if (\in_array($event, self::CHECKED_EVENTS, true) && true === ($this->isCancelled)()) {
            throw new TurnCancelledException('The turn was cancelled.');
        }
    }
}
