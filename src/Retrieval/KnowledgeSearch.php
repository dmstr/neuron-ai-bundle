<?php

// file generated with AI assistance: Claude Code - 2026-10-08 20:34:12 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Retrieval;

use Dmstr\NeuronAiBundle\Conversation\ContextChunk;
use NeuronAI\RAG\Document;

/**
 * Knowledge search as a service: resolves the collection references
 * ({@see CollectionReferenceResolver}, unknown reference → 422), searches
 * through {@see CollectionRetriever::search()} and returns the hits as
 * {@see ContextChunk}s with their collection names.
 *
 * Shared by the app's `POST /api/search` and the MCP tool `rag_search`, so
 * both resolve references and assemble hits the same way. Unlike
 * {@see \Dmstr\NeuronAiBundle\Conversation\ContextRetriever::pool()} there
 * is no context cap and no expansion: the caller gets the ranked hits
 * cut to $k.
 *
 * - Without collection references every collection is searched (per
 *   embedding model in use, see {@see CollectionRetriever}); with
 *   references only those.
 * - A topic restricts the hits by metadata containment (`topic`); null or
 *   an empty string means no topic filter.
 * - Collection names come from the resolved collections, and for hits of
 *   other collections (search without references) from one name lookup;
 *   a hit whose collection no longer exists has no name.
 * - `$k` is not clamped here; the caller validates it (the search resource
 *   allows 1..50, an MCP endpoint clamps to its `maxK`).
 *
 * Errors of the retriever pass through unchanged: an
 * {@see \Dmstr\NeuronAiBundle\Embeddings\EmbeddingConnectionException} (422)
 * for a collection without usable embedding connection, an
 * \InvalidArgumentException for a hybrid weight outside 0..1.
 */
final class KnowledgeSearch
{
    public function __construct(
        private readonly CollectionRetriever $retriever,
        private readonly CollectionReferenceResolver $collections,
    ) {
    }

    /**
     * @param list<string> $collections  collection UUIDs or names; empty = all collections
     * @param ?string      $topic        metadata filter `topic`; null or '' = no filter
     * @param bool         $hybrid       keyword plus vector search fused by weighted RRF (needs collections, see {@see CollectionRetriever::search()}); scores are RRF scores then
     * @param float        $hybridWeight keyword share of the fusion, 0..1; ignored without hybrid
     *
     * @return list<ContextChunk> ranked by score descending, at most $k
     *
     * @throws UnknownCollectionReferenceException for a reference that matches no collection
     */
    public function search(string $query, array $collections = [], int $k = 5, ?string $topic = null, bool $hybrid = false, float $hybridWeight = 0.5): array
    {
        $resolved = $this->collections->collections($collections);
        $filters = null !== $topic && '' !== $topic ? ['topic' => $topic] : [];

        $documents = $this->retriever->search($query, $resolved, $k, $filters, $hybrid, $hybridWeight);

        $names = [];
        foreach ($resolved as $collection) {
            $names[$collection->getId()->toRfc4122()] = $collection->getName();
        }
        $names += $this->collections->namesByIds(array_diff(self::collectionIds($documents), array_keys($names)));

        return array_map(
            static fn (Document $document): ContextChunk => ContextChunk::fromDocument($document, $names),
            $documents,
        );
    }

    /**
     * Collection ids of the documents' metadata, as stored (not normalized;
     * the name lookup ignores what is no UUID).
     *
     * @param list<Document> $documents
     *
     * @return list<string>
     */
    private static function collectionIds(array $documents): array
    {
        $ids = [];
        foreach ($documents as $document) {
            $id = $document->getMetadata()['collection_id'] ?? null;
            if (\is_string($id) && '' !== $id) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}

// - revised 2026-10-14
