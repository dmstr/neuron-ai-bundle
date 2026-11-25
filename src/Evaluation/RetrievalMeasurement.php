<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:21:35 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

use Dmstr\NeuronAiBundle\Conversation\ContextChunk;

/**
 * The retrieval outcome of one evaluation question: a pure value
 * object, no services, no database.
 *
 * {@see self::classify()} applies the relevance rule to the uncapped pool of
 * the repeated search ({@see \Dmstr\NeuronAiBundle\Conversation\ContextRetriever::pool()}):
 *
 * - a `negative` question → {@see RetrievalStatus::NEGATIVE};
 * - otherwise no markers (null or empty) → {@see RetrievalStatus::UNMEASURED};
 * - otherwise {@see RetrievalStatus::MEASURED}: the first 1-based pool index
 *   whose chunk content contains any marker is `firstRelevantRank` and
 *   `recallHit` is true; without such a chunk `recallHit` is false and
 *   `firstRelevantRank` null.
 *
 * Only the chunk content counts, never file name, source URL or heading path
 * (breadcrumb). The comparison is `mb_stripos()`, case-insensitive per
 * character: `Ä` matches `ä`, but `ß` and `SS` do not match each other
 * (known limit, no full case folding).
 *
 * `recallHit` and `firstRelevantRank` are set only for `measured`;
 * {@see self::failed()} stands for a search that threw.
 */
final class RetrievalMeasurement
{
    private function __construct(
        /** One of {@see RetrievalStatus::ALL}. */
        public readonly string $status,
        public readonly ?bool $recallHit,
        public readonly ?int $firstRelevantRank,
    ) {
    }

    /**
     * @param list<string>|null  $markers the question's markers
     * @param list<ContextChunk> $pool    the uncapped search result, in ranking order
     */
    public static function classify(bool $negative, ?array $markers, array $pool): self
    {
        if ($negative) {
            return new self(RetrievalStatus::NEGATIVE, null, null);
        }

        $markers = array_values(array_filter(
            $markers ?? [],
            static fn (mixed $marker): bool => \is_string($marker) && '' !== $marker,
        ));
        if ([] === $markers) {
            return new self(RetrievalStatus::UNMEASURED, null, null);
        }

        foreach (array_values($pool) as $index => $chunk) {
            foreach ($markers as $marker) {
                if (false !== mb_stripos($chunk->content, $marker)) {
                    return new self(RetrievalStatus::MEASURED, true, $index + 1);
                }
            }
        }

        return new self(RetrievalStatus::MEASURED, false, null);
    }

    /**
     * The repeated search threw; no hit or rank.
     */
    public static function failed(): self
    {
        return new self(RetrievalStatus::FAILED, null, null);
    }
}
