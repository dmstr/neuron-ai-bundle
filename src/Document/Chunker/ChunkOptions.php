<?php

// file generated with AI assistance: Claude Code - 2026-10-01 17:14:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Document\Chunker;

/**
 * Sizes for {@see MarkdownChunker}, in characters (not bytes, not tokens).
 *
 * - `targetSize`: maximum body size of a chunk.
 * - `overlap`: characters of the previous chunk repeated at the start of
 *   the next one when an oversized section is split; 0 means no overlap.
 * - `minSize`: sections below this size are merged into a neighbour.
 */
final readonly class ChunkOptions
{
    public const DEFAULT_TARGET_SIZE = 1500;
    public const DEFAULT_OVERLAP = 200;
    public const DEFAULT_MIN_SIZE = 300;

    /** API keys accepted by fromArray(), mapped to the constructor arguments. */
    private const KEYS = [
        'chunkTargetSize' => 'targetSize',
        'chunkOverlap' => 'overlap',
        'chunkMinSize' => 'minSize',
    ];

    /**
     * @throws \InvalidArgumentException when the target or minimum size is
     *                                   not positive, the overlap is negative
     *                                   or not below the target, or the
     *                                   minimum exceeds the target
     */
    public function __construct(
        public int $targetSize = self::DEFAULT_TARGET_SIZE,
        public int $overlap = self::DEFAULT_OVERLAP,
        public int $minSize = self::DEFAULT_MIN_SIZE,
    ) {
        foreach (['target size' => $targetSize, 'minimum size' => $minSize] as $label => $value) {
            if ($value < 1) {
                throw new \InvalidArgumentException(sprintf('The chunk %s must be a positive integer, got %d.', $label, $value));
            }
        }
        if ($overlap < 0) {
            throw new \InvalidArgumentException(sprintf('The chunk overlap must be a non-negative integer, got %d.', $overlap));
        }
        if ($overlap >= $targetSize) {
            throw new \InvalidArgumentException(sprintf('The chunk overlap (%d) must be smaller than the chunk target size (%d).', $overlap, $targetSize));
        }
        if ($minSize > $targetSize) {
            throw new \InvalidArgumentException(sprintf('The chunk minimum size (%d) must not exceed the chunk target size (%d).', $minSize, $targetSize));
        }
    }

    /**
     * Options from an API payload with the keys `chunkTargetSize`,
     * `chunkOverlap` and `chunkMinSize`. Missing or null keys fall back to
     * `$defaults` (or the class defaults). Values may be integers or
     * strings of digits (multipart form fields); other keys are ignored.
     *
     * @param array<string, mixed> $options
     *
     * @throws \InvalidArgumentException for a non-integer value or an invalid combination
     */
    public static function fromArray(array $options, ?self $defaults = null): self
    {
        $defaults ??= new self();
        $values = [
            'targetSize' => $defaults->targetSize,
            'overlap' => $defaults->overlap,
            'minSize' => $defaults->minSize,
        ];

        foreach (self::KEYS as $key => $argument) {
            $value = $options[$key] ?? null;
            if ($value === null) {
                continue;
            }
            if (\is_string($value) && preg_match('/^\s*-?\d+\s*$/', $value) === 1) {
                $value = (int) $value;
            }
            if (!\is_int($value)) {
                throw new \InvalidArgumentException(sprintf('%s must be an integer.', $key));
            }
            $values[$argument] = $value;
        }

        return new self($values['targetSize'], $values['overlap'], $values['minSize']);
    }
}
