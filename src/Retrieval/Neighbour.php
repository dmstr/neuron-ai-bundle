<?php

// file generated with AI assistance: Claude Code - 2026-10-04 10:14:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Retrieval;

/**
 * A neighbouring part of a retrieved hit in its source document, as found by
 * a {@see NeighbourSourceInterface}. The id lets the expander recognise a
 * neighbour that is already in the context (as a hit or as the neighbour of
 * another hit), so it is never sent twice.
 */
final readonly class Neighbour
{
    public function __construct(
        /** Id of the chunk the neighbour text comes from. */
        public string $chunkId,
        /** Text of the neighbour as it would be added to the context. */
        public string $content,
    ) {
    }
}
