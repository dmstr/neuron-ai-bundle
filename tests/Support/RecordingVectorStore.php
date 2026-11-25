<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:37:11 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use Dmstr\NeuronPgvectorStore\PgVectorStore;

/**
 * Store double for the kernel-free reindex tests: keeps the real scoping
 * (forModel() is an immutable clone) and holds row counts per
 * (model, source type, source name) in memory instead of PostgreSQL. The
 * in-memory SQLite connection is never used. All clones share one state
 * object, so the test sees what every scoped copy did.
 */
final class RecordingVectorStore extends PgVectorStore
{
    public function __construct(
        private readonly RecordingVectorStoreState $state = new RecordingVectorStoreState(),
    ) {
        parent::__construct(new \PDO('sqlite::memory:'), 'embedding');
    }

    public function state(): RecordingVectorStoreState
    {
        return $this->state;
    }

    public function countBy(string $sourceType, string $sourceName): int
    {
        if (null === $this->model) {
            throw new \LogicException('countBy() needs a scoped store.');
        }
        $this->state->countCalls[] = [$this->model, $sourceType, $sourceName];

        return \count($this->state->rowsOf($this->model, $sourceType, $sourceName));
    }

    public function deleteByModel(string $model, array $filters = []): void
    {
        $this->state->deleteByModelCalls[] = [$model, $filters];
        foreach ($this->state->rows as $key => $row) {
            if ($row['model'] === $model && array_intersect_assoc($filters, $row['metadata']) === $filters) {
                unset($this->state->rows[$key]);
            }
        }
    }

    public function ensureModelIndex(string $model, int $dimension): void
    {
        $this->state->ensuredIndexes[] = [$model, $dimension];
    }

    public function modelIndexExists(string $model): bool
    {
        return \in_array($model, array_column($this->state->ensuredIndexes, 0), true);
    }
}
