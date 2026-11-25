<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:12 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Stream;

/**
 * Coalesces text deltas of one type (`token`, `reasoning`) into fewer
 * events: one open buffer at a time; it is due when the window has passed
 * since the last flush or the buffer reached the character limit. The
 * owner decides when to flush (it checks for a cancellation first) and
 * flushes before every event of another type, at the end of a step and
 * before the stream ends.
 */
final class DeltaBatcher
{
    private ?string $type = null;

    private string $buffer = '';

    private float $lastFlush;

    /**
     * @param \Closure(): float|null $clock seconds as float (default microtime(true)); a seam for tests
     */
    public function __construct(
        private readonly int $windowMs = 100,
        private readonly int $maxChars = 200,
        private readonly ?\Closure $clock = null,
    ) {
        $this->lastFlush = $this->now();
    }

    /**
     * The type of the open buffer; null when nothing is buffered.
     */
    public function openType(): ?string
    {
        return '' === $this->buffer ? null : $this->type;
    }

    /**
     * Appends a delta; the caller flushes first when the type differs from
     * {@see openType()}.
     */
    public function add(string $type, string $text): void
    {
        if (null !== $this->openType() && $type !== $this->type) {
            throw new \LogicException('Flush the open buffer before adding a delta of another type.');
        }
        $this->type = $type;
        $this->buffer .= $text;
    }

    public function isDue(): bool
    {
        if ('' === $this->buffer) {
            return false;
        }

        return mb_strlen($this->buffer) >= $this->maxChars || ($this->now() - $this->lastFlush) * 1000 >= $this->windowMs;
    }

    /**
     * Empties the buffer and returns its event; null when nothing was
     * buffered.
     */
    public function flush(): ?StreamEvent
    {
        $this->lastFlush = $this->now();
        if ('' === $this->buffer || null === $this->type) {
            return null;
        }

        $event = new StreamEvent($this->type, ['text' => $this->buffer]);
        $this->buffer = '';

        return $event;
    }

    private function now(): float
    {
        return null !== $this->clock ? ($this->clock)() : microtime(true);
    }
}
