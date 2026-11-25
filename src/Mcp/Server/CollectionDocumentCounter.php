<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:16:39 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp\Server;

/**
 * Number of searchable documents per collection, counted in the vector
 * store table the bundle owns (the application's document tables are not
 * known here).
 *
 * A document is what a reader would call one: the chunks of an uploaded or
 * imported file (`document_id`) count once, the chunks of one source URL
 * (`source_url`) count once, every other chunk (`chunk_id`,
 * else the row's source name) counts once. Rows of all embedding models are
 * counted together, so a collection in the middle of a reindex is not
 * counted twice. A collection without rows has 0. Reads the table through
 * {@see KnowledgeStoreTable}.
 *
 * The bundle default of {@see CollectionDocumentCounterInterface}; it
 * knows no chunk count ({@see countChunks()} returns none). Applications
 * with document tables alias the interface to their own counter, which may
 * fall back to this one.
 *
 * Not final, so callers can be tested with a double.
 */
class CollectionDocumentCounter implements CollectionDocumentCounterInterface
{
    public function __construct(
        private readonly KnowledgeStoreTable $store,
    ) {
    }

    /**
     * @param list<string> $collectionIds collection ids as stored in the chunk metadata `collection_id` (RFC 4122)
     *
     * @return array<string, int> collection id => document count, one entry per given id
     */
    public function count(array $collectionIds): array
    {
        $select = <<<'SQL'
            count(DISTINCT COALESCE(
                NULLIF(metadata->>'document_id', ''),
                NULLIF(metadata->>'source_url', ''),
                NULLIF(metadata->>'chunk_id', ''),
                source_name
            ))
            SQL;

        // One query per collection: the containment filter uses the GIN
        // index on metadata, and a scope holds a handful of collections.
        $counts = [];
        foreach ($collectionIds as $id) {
            $counts[$id] = (int) $this->store->fetchOneContaining($select, ['collection_id' => $id]);
        }

        return $counts;
    }

    /**
     * No chunk count: the store rows of a chunk are one per embedding
     * piece and model, not one per chunk.
     */
    public function countChunks(array $collectionIds): array
    {
        return [];
    }
}
// - revised 2026-10-09 16:30:10 UTC
// - revised 2026-10-13 20:10:00 UTC
