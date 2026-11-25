<?php

// file generated with AI assistance: Claude Code - 2026-10-08 20:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Embeddings;

use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;

/**
 * A resolved embedding model of a collection
 * ({@see EmbeddingModelResolver}): the catalogue entry, which names the
 * provider connection and the name the provider lists, and the registered
 * model that matches it, whose name is the vector label of the collection
 * (`$model->name`, e.g. `nomic-embed-text` for the entry
 * `nomic-embed-text:latest`) and which gives the dimension and the task
 * prefixes.
 */
final readonly class EmbeddingModelChoice
{
    public function __construct(
        public ProviderModel $entry,
        public EmbeddingModel $model,
    ) {
    }
}
