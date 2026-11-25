<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:17:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Embeddings;

/**
 * One entry of the embedding model registry
 * (`dmstr_neuron_ai.embeddings.models`): the provider that serves the model,
 * the dimension of its vectors, its task prefixes and, optionally, the
 * context length in tokens the provider is asked to use.
 */
final readonly class EmbeddingModel
{
    public function __construct(
        public string $name,
        public string $provider,
        public int $dimension,
        public string $queryPrefix = '',
        public string $documentPrefix = '',
        public ?int $contextLength = null,
    ) {
    }
}
