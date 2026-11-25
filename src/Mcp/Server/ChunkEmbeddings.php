<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:30:10 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp\Server;

use Dmstr\NeuronPgvectorStore\PgVectorStore;
use NeuronAI\RAG\Document;

/**
 * The stored embeddings of one chunk (embedding metadata `chunk_id`) in the
 * vector store table, for the MCP tool `rag_get_context`.
 *
 * Reads the table directly ({@see KnowledgeStoreTable}): the bundle does not
 * know the application's chunk table, but every searchable chunk has its
 * embeddings here, with `collection_id` in their metadata. Rows of every
 * embedding model are returned (during a reindex a chunk has embeddings for
 * two models); the caller picks the model.
 *
 * Not final, so callers can be tested with a double.
 */
class ChunkEmbeddings
{
    public function __construct(
        private readonly KnowledgeStoreTable $store,
    ) {
    }

    /**
     * The embeddings of $chunkId with content, source and metadata; the
     * row's embedding model is in the metadata key `model` (as in search
     * results of the store), built by {@see PgVectorStore::hydrate()}
     * like a search result. No score (null), unordered.
     *
     * @param string $chunkId as stored in the embedding metadata (RFC 4122)
     *
     * @return list<Document>
     */
    public function embeddings(string $chunkId): array
    {
        $rows = $this->store->fetchAllContaining(
            'id, content, source_type, source_name, metadata, model',
            ['chunk_id' => $chunkId],
        );

        return array_values(array_map(PgVectorStore::hydrate(...), $rows));
    }

    /**
     * The collection ids in the metadata of the embeddings of $chunkId, as
     * stored; empty when the chunk has no embeddings.
     *
     * @return list<string>
     */
    public function collectionIds(string $chunkId): array
    {
        $rows = $this->store->fetchAllContaining(
            "DISTINCT metadata->>'collection_id' AS collection_id",
            ['chunk_id' => $chunkId],
        );

        $ids = [];
        foreach ($rows as $row) {
            if (\is_string($row['collection_id'] ?? null) && '' !== $row['collection_id']) {
                $ids[] = $row['collection_id'];
            }
        }

        return $ids;
    }
}

// - revised 2026-10-14
