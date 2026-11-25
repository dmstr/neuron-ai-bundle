<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:37:11 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

/**
 * Shared state of {@see RecordingVectorStore} and its scoped clones: the
 * rows (one entry per chunk) and the recorded calls.
 */
final class RecordingVectorStoreState
{
    /** @var list<array{model: string, sourceType: string, sourceName: string, metadata: array<string, string>}> */
    public array $rows = [];

    /** @var list<array{string, string, string}> (model, sourceType, sourceName) */
    public array $countCalls = [];

    /** @var list<array{string, array<string, scalar>}> (model, filters) */
    public array $deleteByModelCalls = [];

    /** @var list<array{string, int}> (model, dimension) */
    public array $ensuredIndexes = [];

    /**
     * @param array<string, string> $metadata
     */
    public function addRows(string $model, string $sourceType, string $sourceName, int $count, array $metadata = []): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->rows[] = ['model' => $model, 'sourceType' => $sourceType, 'sourceName' => $sourceName, 'metadata' => $metadata];
        }
    }

    public function deleteRows(string $model, string $sourceType, string $sourceName): void
    {
        $this->rows = array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => !($row['model'] === $model && $row['sourceType'] === $sourceType && $row['sourceName'] === $sourceName),
        ));
    }

    /**
     * @return list<array{model: string, sourceType: string, sourceName: string, metadata: array<string, string>}>
     */
    public function rowsOf(string $model, ?string $sourceType = null, ?string $sourceName = null): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => $row['model'] === $model
                && (null === $sourceType || $row['sourceType'] === $sourceType)
                && (null === $sourceName || $row['sourceName'] === $sourceName),
        ));
    }
}
