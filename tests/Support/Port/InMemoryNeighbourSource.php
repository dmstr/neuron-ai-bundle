<?php

// file generated with AI assistance: Claude Code - 2026-11-25 21:50:20 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support\Port;

use Dmstr\NeuronAiBundle\Retrieval\Neighbour;
use Dmstr\NeuronAiBundle\Retrieval\NeighbourSourceInterface;

/**
 * Neighbours of a hit for the bundle's context expansion
 * ({@see NeighbourSourceInterface}), from a static map: a chunk id without
 * an entry has no neighbours. Records the asked chunk ids. Cleared by
 * {@see TestPorts::reset()}.
 */
final class InMemoryNeighbourSource implements NeighbourSourceInterface
{
    /** @var array<string, array{before: ?Neighbour, after: ?Neighbour}> */
    private static array $neighbours = [];

    /** @var list<string> */
    private static array $asked = [];

    public static function set(string $chunkId, ?Neighbour $before, ?Neighbour $after): void
    {
        self::$neighbours[$chunkId] = ['before' => $before, 'after' => $after];
    }

    /**
     * @return list<string> the chunk ids asked since the last reset
     */
    public static function asked(): array
    {
        return self::$asked;
    }

    public static function reset(): void
    {
        self::$neighbours = [];
        self::$asked = [];
    }

    public function neighbours(string $chunkId): array
    {
        self::$asked[] = $chunkId;

        return self::$neighbours[$chunkId] ?? ['before' => null, 'after' => null];
    }
}
