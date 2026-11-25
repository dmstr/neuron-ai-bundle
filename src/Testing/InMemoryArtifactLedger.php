<?php

// file generated with AI assistance: Claude Code - 2026-10-16 08:40:52 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Testing;

use Dmstr\NeuronAiBundle\Artifact\ArtifactLedgerEntry;
use Dmstr\NeuronAiBundle\Artifact\ArtifactLedgerInterface;
use Dmstr\NeuronAiBundle\Artifact\ArtifactPath;

/**
 * The artifact ledger in memory, for tests without a database. Behaves like
 * {@see \Dmstr\NeuronAiBundle\Artifact\DoctrineArtifactLedger}: one row per
 * owner and lower-cased path; a new row keeps its creating conversation.
 */
final class InMemoryArtifactLedger implements ArtifactLedgerInterface
{
    /**
     * @var array<string, array<string, array{path: string, sizeBytes: int, createdBy: string, updatedBy: string}>> owner => pathLower => row
     */
    private array $rows = [];

    public function find(string $ownerSubject, string $pathLower): ?ArtifactLedgerEntry
    {
        $row = $this->rows[$ownerSubject][$pathLower] ?? null;

        return null === $row ? null : new ArtifactLedgerEntry($row['path'], $row['sizeBytes']);
    }

    public function countInFolder(string $ownerSubject, string $folder): int
    {
        $prefix = ArtifactPath::lower(rtrim($folder, '/')).'/';

        return \count(array_filter(
            array_keys($this->rows[$ownerSubject] ?? []),
            static fn (string $pathLower): bool => str_starts_with($pathLower, $prefix),
        ));
    }

    public function totalSize(string $ownerSubject): int
    {
        return array_sum(array_column($this->rows[$ownerSubject] ?? [], 'sizeBytes'));
    }

    public function record(string $ownerSubject, string $path, int $sizeBytes, string $conversationId): void
    {
        $key = ArtifactPath::lower($path);
        $existing = $this->rows[$ownerSubject][$key] ?? null;

        $this->rows[$ownerSubject][$key] = [
            'path' => $path,
            'sizeBytes' => $sizeBytes,
            'createdBy' => $existing['createdBy'] ?? $conversationId,
            'updatedBy' => $conversationId,
        ];
    }

    /**
     * All rows, for assertions.
     *
     * @return array<string, array<string, array{path: string, sizeBytes: int, createdBy: string, updatedBy: string}>>
     */
    public function rows(): array
    {
        return $this->rows;
    }
}
