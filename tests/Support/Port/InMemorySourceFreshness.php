<?php

// file generated with AI assistance: Claude Code - 2026-11-25 21:50:20 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support\Port;

use Dmstr\NeuronAiBundle\Retrieval\SourceFreshnessInterface;

/**
 * Freshness of the documents behind the sources of an answer
 * ({@see SourceFreshnessInterface}), from a static map: unknown ids are
 * missing from the result. Cleared by {@see TestPorts::reset()}.
 */
final class InMemorySourceFreshness implements SourceFreshnessInterface
{
    /** @var array<string, array{checkedAt: ?\DateTimeImmutable, changedAt: ?\DateTimeImmutable}> */
    private static array $freshness = [];

    public static function set(string $documentId, ?\DateTimeImmutable $checkedAt, ?\DateTimeImmutable $changedAt): void
    {
        self::$freshness[$documentId] = ['checkedAt' => $checkedAt, 'changedAt' => $changedAt];
    }

    public static function reset(): void
    {
        self::$freshness = [];
    }

    public function freshness(array $documentIds): array
    {
        return array_intersect_key(self::$freshness, array_flip($documentIds));
    }
}
