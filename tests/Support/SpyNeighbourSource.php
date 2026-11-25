<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:30:10 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use Dmstr\NeuronAiBundle\Retrieval\Neighbour;
use Dmstr\NeuronAiBundle\Retrieval\NeighbourSourceInterface;

/**
 * Neighbour source that answers with fixed neighbours and records every
 * call in {@see $calls}, to prove that refused `rag_get_context` calls
 * never ask it.
 */
final class SpyNeighbourSource implements NeighbourSourceInterface
{
    /** @var list<string> chunk ids asked for, in order */
    public array $calls = [];

    public function __construct(
        private readonly ?Neighbour $before = null,
        private readonly ?Neighbour $after = null,
    ) {
    }

    public function neighbours(string $chunkId): array
    {
        $this->calls[] = $chunkId;

        return ['before' => $this->before, 'after' => $this->after];
    }
}
