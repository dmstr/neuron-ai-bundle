<?php

// file generated with AI assistance: Claude Code - 2026-10-04 10:14:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Retrieval;

/**
 * Port between the {@see \Dmstr\NeuronAiBundle\Conversation\ContextExpander}
 * and the application that owns the documents behind the chunks. The bundle
 * only knows the chunk id of a hit, not where documents live or in
 * which order their parts stand; the application implements this interface
 * and aliases it in its service configuration. Without an alias, context
 * expansion is a no-op.
 */
interface NeighbourSourceInterface
{
    /**
     * The part directly before and directly after the chunk
     * $chunkId in its source document.
     *
     * Both are null when the chunk is unknown, has no document order
     * or is not eligible for expansion; a single side is null at the start
     * or end of a document or when that neighbour no longer exists. Must not
     * throw for an id that is not a valid identifier.
     *
     * @return array{before: ?Neighbour, after: ?Neighbour}
     */
    public function neighbours(string $chunkId): array;
}
