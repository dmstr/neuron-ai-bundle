<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:17:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Embeddings;

use Dmstr\NeuronPgvectorStore\PgVectorStore;

/**
 * Creates the per-model partial vector indexes of the store table with the
 * dimension from the {@see EmbeddingModelRegistry}. Idempotent: an existing
 * index is left alone (`CREATE INDEX IF NOT EXISTS`).
 */
final class EmbeddingIndexManager
{
    public function __construct(
        private readonly PgVectorStore $store,
        private readonly EmbeddingModelRegistry $registry,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the model is not configured
     */
    public function ensure(string $model): void
    {
        $this->store->ensureModelIndex($model, $this->registry->get($model)->dimension);
    }

    /**
     * Ensures the index of each distinct model in $models.
     *
     * @param iterable<string> $models
     *
     * @return array{created: list<string>, existing: list<string>} model names
     *
     * @throws \InvalidArgumentException when a model is not configured
     */
    public function ensureAll(iterable $models): array
    {
        $result = ['created' => [], 'existing' => []];
        $seen = [];

        foreach ($models as $model) {
            if (isset($seen[$model])) {
                continue;
            }
            $seen[$model] = true;

            if ($this->store->modelIndexExists($model)) {
                $result['existing'][] = $model;
                continue;
            }
            $this->ensure($model);
            $result['created'][] = $model;
        }

        return $result;
    }

    public function indexName(string $model): string
    {
        return $this->store->modelIndexName($model);
    }
}
