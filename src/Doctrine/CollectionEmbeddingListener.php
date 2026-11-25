<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:17:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Doctrine;

use Dmstr\NeuronAiBundle\Embeddings\EmbeddingIndexManager;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelResolver;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Provider\ProviderModelReferenceException;

/**
 * Doctrine entity listener on {@see Collection}:
 *
 * - prePersist: resolves the string reference `embeddingModel`
 *   ({@see EmbeddingModelResolver::resolve()}) into the catalogue entry
 *   `embeddingProviderModel` (and with it the embedding connection) and
 *   the vector label (the registered name), unless the entry is already
 *   set; an entry set by code without a label gets the registered name of
 *   the entry. A collection without a dimension gets the model's
 *   registered dimension. There is no default model: the API validates it
 *   as required ({@see \Dmstr\NeuronAiBundle\Validator\ValidEmbeddingModel});
 *   a collection persisted without a catalogue entry (also one with a
 *   label and a connection only) fails here, before the database would
 *   refuse the NULL in `embedding_model_id`. A reference that
 *   does not resolve (validated before on the API path) throws the
 *   resolver's 422.
 * - postPersist: ensures the partial vector index of the collection's
 *   label in the store table, so the first search of a new model already
 *   has an index.
 */
final class CollectionEmbeddingListener
{
    public function __construct(
        private readonly EmbeddingModelRegistry $registry,
        private readonly EmbeddingIndexManager $indexManager,
        private readonly EmbeddingModelResolver $resolver,
    ) {
    }

    /**
     * @throws ProviderModelReferenceException for a model reference that does not resolve
     * @throws \InvalidArgumentException       for a collection without catalogue entry or with an unregistered label
     */
    public function prePersist(Collection $collection): void
    {
        $reference = $collection->embeddingModelInput();
        $entry = $collection->getEmbeddingProviderModel();
        if (null === $entry && null !== $reference && '' !== trim($reference)) {
            $choice = $this->resolver->resolve($reference);
            $collection->setEmbeddingProviderModel($choice->entry)
                ->setEmbeddingModel($choice->model->name)
                ->setEmbeddingModelReference(null);
        } elseif (null !== $entry && null === $collection->getEmbeddingModel()) {
            $collection->setEmbeddingModel($this->resolver->modelOf($entry)->name);
        }

        $model = $collection->getEmbeddingModel();
        if (null === $collection->getEmbeddingProviderModel() || null === $model || '' === $model) {
            throw new \InvalidArgumentException(\sprintf('Collection "%s" has no embedding model; there is no default model.', $collection->getName()));
        }

        if ($collection->getEmbeddingDimension() === null) {
            $collection->setEmbeddingDimension($this->registry->get($model)->dimension);
        }
    }

    public function postPersist(Collection $collection): void
    {
        $model = $collection->getEmbeddingModel();
        if ($model !== null) {
            $this->indexManager->ensure($model);
        }
    }
}
