<?php

// file generated with AI assistance: Claude Code - 2026-10-08 20:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Embeddings;

use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\Model\ModelCapability;
use Dmstr\NeuronAiBundle\Provider\ProviderModelReferenceException;
use Dmstr\NeuronAiBundle\Provider\ProviderModelResolver;

/**
 * Resolves the embedding model reference of a collection (IRI or full or
 * partial UUID of an entry of the model catalogue) to the entry and the
 * registered model that matches it ({@see EmbeddingModelChoice}).
 *
 *  - The entry must be usable for the capability `embedding`
 *    ({@see ProviderModelResolver}): available, on an active `ollama`
 *    connection, not known to lack the capability.
 *  - Its name must match a model of `dmstr_neuron_ai.embeddings.models`
 *    ({@see EmbeddingModelRegistry::forEntry()}: exactly, or with or
 *    without `:latest`). The registered name is the vector label the
 *    collection's rows and its partial vector index are keyed by; the
 *    catalogue name is the one the provider lists.
 *
 * There is no live availability check: a collection's embedding jobs fail
 * with the provider's message when the model is not pulled, and
 * a re-embed run of the application picks the documents up later.
 *
 * Every failure is a {@see ProviderModelReferenceException} (HTTP 422);
 * a name that is not registered has the reason
 * {@see ProviderModelReferenceException::UNREGISTERED}.
 */
final class EmbeddingModelResolver
{
    public function __construct(
        private readonly ProviderModelResolver $models,
        private readonly EmbeddingModelRegistry $registry,
    ) {
    }

    /**
     * @throws ProviderModelReferenceException
     */
    public function resolve(?string $reference): EmbeddingModelChoice
    {
        $entry = $this->models->resolve($reference, ModelCapability::EMBEDDING);

        return new EmbeddingModelChoice($entry, $this->modelOf($entry, $reference));
    }

    /**
     * {@see resolve()} for an entry the caller already holds (set by code),
     * without a lookup.
     *
     * @throws ProviderModelReferenceException
     */
    public function check(ProviderModel $entry): EmbeddingModelChoice
    {
        $this->models->check($entry, ModelCapability::EMBEDDING);

        return new EmbeddingModelChoice($entry, $this->modelOf($entry, $entry->iri()));
    }

    /**
     * The registered model of $entry, without the usability checks (a
     * stored collection's entry may have become unavailable meanwhile).
     *
     * @throws ProviderModelReferenceException with reason `unregistered`
     */
    public function modelOf(ProviderModel $entry, ?string $reference = null): EmbeddingModel
    {
        try {
            return $this->registry->forEntry($entry);
        } catch (\InvalidArgumentException $e) {
            throw new ProviderModelReferenceException($e->getMessage(), ProviderModelReferenceException::UNREGISTERED, $reference ?? $entry->iri());
        }
    }
}
