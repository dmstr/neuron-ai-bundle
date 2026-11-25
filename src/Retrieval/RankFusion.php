<?php

// file generated with AI assistance: Claude Code - 2026-10-04 09:02:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Retrieval;

use NeuronAI\RAG\Document;

/**
 * Weighted reciprocal rank fusion (RRF) of ranked document lists; pure, no
 * I/O.
 *
 * Every list carries a weight. A document scores the sum over the lists
 * that contain it of `weight / (k + rank)`, with its 1-based rank within
 * that list (a later duplicate of an id within the same list is ignored).
 * Documents are identified by {@see Document::$id}, so the same chunk found
 * by two retrievers is one document. Documents whose fused score is 0 (they
 * occur only in lists of weight 0) are dropped. The result is sorted by
 * fused score descending, ties by id ascending (string comparison, which
 * for canonical UUIDs equals PostgreSQL's order).
 *
 * The returned documents are clones of the first occurrence of each id,
 * metadata unchanged, with the fused score as `score`. The contributions of
 * a document are summed in ascending order, so two documents with the same
 * multiset of contributions get bit-identical scores and the id decides.
 */
final class RankFusion
{
    /** RRF constant of the original paper (Cormack et al. 2009). */
    public const int K = 60;

    /**
     * @param list<array{0: iterable<Document>, 1: float}> $weightedLists ranked lists (best first) with their weight (>= 0)
     * @param int                                          $k             RRF constant (>= 0)
     *
     * @return list<Document> fused, score descending, ties by id ascending; not cut
     *
     * @throws \InvalidArgumentException for a negative weight or a negative $k
     */
    public static function fuse(array $weightedLists, int $k = self::K): array
    {
        if ($k < 0) {
            throw new \InvalidArgumentException(\sprintf('The RRF constant must not be negative, got %d.', $k));
        }

        /** @var array<string, Document> $documents id => first occurrence */
        $documents = [];
        /** @var array<string, list<float>> $contributions id => terms */
        $contributions = [];

        foreach ($weightedLists as [$list, $weight]) {
            if ($weight < 0) {
                throw new \InvalidArgumentException(\sprintf('A fusion weight must not be negative, got %s.', $weight));
            }

            $rank = 0;
            $seen = [];
            foreach ($list as $document) {
                $id = (string) $document->getId();
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                ++$rank;

                $documents[$id] ??= $document;
                $contributions[$id][] = $weight / ($k + $rank);
            }
        }

        $scores = [];
        foreach ($contributions as $id => $terms) {
            sort($terms);
            $score = 0.0;
            foreach ($terms as $term) {
                $score += $term;
            }
            if ($score > 0.0) {
                $scores[(string) $id] = $score;
            }
        }

        uksort($scores, static fn (string|int $a, string|int $b): int => ($scores[$b] <=> $scores[$a]) ?: strcmp((string) $a, (string) $b));

        $fused = [];
        foreach ($scores as $id => $score) {
            $document = clone $documents[(string) $id];
            $document->setScore($score);
            $fused[] = $document;
        }

        return $fused;
    }
}
