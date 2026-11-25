<?php

// file generated with AI assistance: Claude Code - 2026-10-04 10:14:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use Dmstr\NeuronAiBundle\Retrieval\NeighbourSourceInterface;

/**
 * Widens retrieved hits by one neighbour before and after them in their
 * source document, within a character budget.
 *
 * Expansion only ever changes the content of a hit, never the list: count,
 * order, score and metadata stay as retrieved, so the sources a turn stores
 * still describe the hits (with `content` as sent to the model). A hit
 * becomes `before + "\n\n" + hit + "\n\n" + after`, with only the parts that
 * were added.
 *
 * Hits are walked in rank order and `before` is tried before `after`, so
 * the budget goes to the best hits first. A neighbour that is already in the
 * context (as a hit or as a neighbour added earlier) is skipped, so no text
 * is sent twice. A neighbour costs its length plus the two-character
 * separator; one that does not fit the remaining budget is skipped whole,
 * never cut mid-text, because half a paragraph misleads more than it helps,
 * and a later smaller neighbour may still fit.
 *
 * Without a {@see NeighbourSourceInterface} (the application has not aliased
 * one) expansion is a no-op.
 */
final class ContextExpander
{
    private const string SEPARATOR = "\n\n";

    public function __construct(private readonly ?NeighbourSourceInterface $neighbours = null)
    {
    }

    /**
     * @param list<ContextChunk> $chunks hits in rank order
     * @param int                $budget maximum number of characters added in total
     *
     * @return list<ContextChunk>
     */
    public function expand(array $chunks, int $budget): array
    {
        if ($budget <= 0 || null === $this->neighbours || [] === $chunks) {
            return $chunks;
        }

        // Chunks already in the context: every hit, then every added neighbour.
        $seen = [];
        foreach ($chunks as $chunk) {
            if (null !== $chunk->chunkId) {
                $seen[$chunk->chunkId] = true;
            }
        }

        $remaining = $budget;
        $separatorLength = mb_strlen(self::SEPARATOR);
        $expanded = [];
        foreach ($chunks as $chunk) {
            if (null === $chunk->chunkId || $remaining < $separatorLength) {
                $expanded[] = $chunk;
                continue;
            }

            $found = $this->neighbours->neighbours($chunk->chunkId);
            $parts = ['before' => null, 'after' => null];
            foreach (['before', 'after'] as $side) {
                $neighbour = $found[$side] ?? null;
                if (null === $neighbour || isset($seen[$neighbour->chunkId])) {
                    continue;
                }
                $cost = mb_strlen($neighbour->content) + $separatorLength;
                if ($cost > $remaining) {
                    continue;
                }
                $remaining -= $cost;
                $seen[$neighbour->chunkId] = true;
                $parts[$side] = $neighbour->content;
            }

            if (null === $parts['before'] && null === $parts['after']) {
                $expanded[] = $chunk;
                continue;
            }

            $content = $chunk->content;
            if (null !== $parts['before']) {
                $content = $parts['before'].self::SEPARATOR.$content;
            }
            if (null !== $parts['after']) {
                $content .= self::SEPARATOR.$parts['after'];
            }
            $expanded[] = $chunk->withContent($content);
        }

        return $expanded;
    }
}
