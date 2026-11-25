<?php

// file generated with AI assistance: Claude Code - 2026-10-14 13:05:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Embeddings;

use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;

/**
 * The neuron-ai document schema of the embeddings in the pgvector store
 * (neuron-ai 4.x vector store filters): the metadata keys that searches and
 * deletes filter on, each declared filterable.
 *
 * - `collection_id` (string): every embedding of a chunk carries the UUID of
 *   its collection; {@see \Dmstr\NeuronAiBundle\Retrieval\CollectionRetriever}
 *   scopes per collection, the reindex and the content purge delete per
 *   collection;
 * - `topic` (string, null when the chunk has none): the optional topic
 *   filter of the knowledge search.
 *
 * The store adds its column `model` itself
 * ({@see \Dmstr\NeuronPgvectorStore\PgVectorStore::MODEL_FIELD}). Neither
 * field is required, so a filter on them can use eq/in but not neq. Every
 * construction of the store (service definition, test doubles) passes this
 * schema; a filter on any other metadata key throws a
 * {@see \NeuronAI\RAG\Schema\DocumentSchemaException}.
 */
final class EmbeddingDocumentSchema
{
    public const COLLECTION_ID = 'collection_id';

    public const TOPIC = 'topic';

    public static function schema(): DocumentSchema
    {
        return DocumentSchema::of(
            DocumentField::string(self::COLLECTION_ID)->filterable(),
            DocumentField::string(self::TOPIC)->filterable(),
        );
    }
}
