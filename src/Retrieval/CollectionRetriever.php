<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:17:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Retrieval;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingConnectionException;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingsProviderLocator;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronPgvectorStore\PgVectorStore;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\SearchRequest;
use Symfony\Component\Uid\Uuid;

/**
 * Similarity search across collections that may use different embedding
 * connections and models.
 *
 * The query is embedded once per distinct (embedding connection, model)
 * pair (with the model's query prefix, through the connection) and searched
 * in the store scoped to that model:
 *
 * - with collections given: one store query per collection, filtered by its
 *   metadata "collection_id", using the collection's current connection and
 *   model. A collection without embedding connection (stored before
 *   provider connections) or on an inactive connection fails the search
 *   with an {@see EmbeddingConnectionException} (422);
 * - without collections: per model in use, one store query without a
 *   collection filter when all collections of that model share one active
 *   connection; when collections of the same model use different
 *   connections (or some have none), one query per collection of an active
 *   connection, filtered by "collection_id", because the store rows are
 *   labelled by the model only and a query vector of one server must not
 *   rank the vectors of another. Collections without or with an inactive
 *   connection are skipped here, so one stale collection does not break
 *   the search across all of them. Chunks whose model no collection uses
 *   (e.g. left over from a finished reindex) are invisible.
 *
 * The results are merged by score descending and cut to $topK. Scores are
 * `1 − cosine distance` within one model; scores of different models are not
 * comparable, so a merged list across models is only a rough ranking.
 * Every returned document carries "collection_id" (from ingest) and "model"
 * (from the store) in its metadata.
 *
 * The collection and the additional filters are the store scope
 * ({@see PgVectorStore::withFilters()}, JSONB containment); their keys have
 * to be declared filterable in the store's document schema
 * ({@see \Dmstr\NeuronAiBundle\Embeddings\EmbeddingDocumentSchema}),
 * otherwise the search fails with a
 * {@see \NeuronAI\RAG\Schema\DocumentSchemaException}.
 *
 * Hybrid search (`$hybrid` true with collections given): per
 * collection a vector list and a keyword list
 * ({@see PgVectorStore::keywordSearch()} in the collection's
 * {@see Collection::getKeywordLanguage()}), both scoped to the collection's
 * current model and filters, each with {@see self::candidates()} entries.
 * The keyword side needs no embedding, so there is still one query
 * embedding per distinct model. All lists of all collections are fused by
 * weighted reciprocal rank fusion ({@see RankFusion}, k = 60): keyword
 * lists weigh `$hybridWeight`, vector lists `1 − $hybridWeight`. The
 * fused list is cut to $topK, and the `score` of a returned document is its
 * RRF score (a small positive number, at most about 1/61 per list), not a
 * cosine similarity; ContextChunk::$score, the `sources` of a turn and of
 * `agent_chat` carry that value. Without hybrid the scores stay cosine.
 *
 * Identical text is returned once ({@see distinct()}): chunks are stored
 * per document, so a passage that several documents share (a page served
 * under two URLs, a repeated boilerplate paragraph) is stored and found
 * several times; the copies would fill the top K with one passage. Both
 * modes fetch {@see self::candidates()} hits per query, so the result
 * still holds up to $topK different passages; the best-ranked copy wins.
 */
final class CollectionRetriever
{
    public function __construct(
        private readonly EmbeddingsProviderLocator $locator,
        private readonly EmbeddingModelRegistry $registry,
        private readonly PgVectorStore $store,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** Minimum number of candidates per retriever and collection in hybrid search. */
    public const int HYBRID_MIN_CANDIDATES = 20;

    /**
     * @param list<Collection>      $collections  empty = all collections (see the class description); hybrid needs collections, without them the vector search runs
     * @param array<string, scalar> $filters      additional metadata containment filters (e.g. topic)
     * @param bool                  $hybrid       true: keyword and vector lists per collection, fused by weighted RRF; false: vector search only (cosine scores)
     * @param float                 $hybridWeight keyword share of the fusion, 0..1 (0 = vector ranks only, 1 = keyword ranks only); ignored without hybrid
     *
     * @return list<Document> merged by score descending (hybrid: fused RRF score, ties by id), cut to $topK; metadata carries collection_id and model
     *
     * @throws \InvalidArgumentException  for a hybrid weight outside 0..1
     * @throws EmbeddingConnectionException for a given collection without embedding connection or on an unusable connection
     */
    public function search(string $query, array $collections, int $topK, array $filters = [], bool $hybrid = false, float $hybridWeight = 0.5): array
    {
        if ($hybrid && [] !== $collections) {
            return $this->hybridSearch($query, $collections, $topK, $filters, $hybridWeight);
        }

        /** @var array<string, float[]> $embeddings connection id and model => query vector */
        $embeddings = [];
        $documents = [];

        foreach ($this->scopes($collections, $filters) as [$connection, $model, $scopeFilters]) {
            $embedding = $embeddings[self::pairKey($connection, $model)] ??= $this->locator->for($connection, $model)->embedText($query);

            $hits = $this->store
                ->forModel($model, $this->registry->get($model)->dimension)
                ->withFilters($scopeFilters)
                ->withTopK(self::candidates($topK))
                ->search(new SearchRequest($embedding));

            foreach ($hits as $hit) {
                $documents[] = $hit;
            }
        }

        usort($documents, static fn (Document $a, Document $b): int => ($b->getScore() ?? 0.0) <=> ($a->getScore() ?? 0.0));

        return \array_slice(self::distinct($documents), 0, max(0, $topK));
    }

    /**
     * Candidates per query (vector search) and per retriever and collection
     * (hybrid search):
     * max(2 × $topK, {@see HYBRID_MIN_CANDIDATES}).
     */
    public static function candidates(int $topK): int
    {
        return max(2 * $topK, self::HYBRID_MIN_CANDIDATES);
    }

    /**
     * @param non-empty-list<Collection> $collections
     * @param array<string, scalar>      $filters
     *
     * @return list<Document> fused by weighted RRF, cut to $topK
     */
    private function hybridSearch(string $query, array $collections, int $topK, array $filters, float $hybridWeight): array
    {
        if ($hybridWeight < 0.0 || $hybridWeight > 1.0) {
            throw new \InvalidArgumentException(\sprintf('The hybrid weight must be between 0 and 1, got %s.', $hybridWeight));
        }

        $candidates = self::candidates($topK);
        /** @var array<string, float[]> $embeddings connection id and model => query vector */
        $embeddings = [];
        $lists = [];

        foreach ($collections as $collection) {
            [$connection, $model, $scopeFilters] = $this->collectionScope($collection, $filters);
            $embedding = $embeddings[self::pairKey($connection, $model)] ??= $this->locator->for($connection, $model)->embedText($query);

            $store = $this->store
                ->forModel($model, $this->registry->get($model)->dimension)
                ->withFilters($scopeFilters)
                ->withTopK($candidates);

            $lists[] = [$store->search(new SearchRequest($embedding)), 1.0 - $hybridWeight];
            $lists[] = [$store->keywordSearch($query, $collection->getKeywordLanguage()), $hybridWeight];
        }

        return \array_slice(self::distinct(RankFusion::fuse($lists)), 0, max(0, $topK));
    }

    /**
     * Keeps the first document of each text: the sha256 of the content with
     * runs of whitespace collapsed, so a copy that differs only in line
     * breaks counts as the same text.
     *
     * @param list<Document> $documents ranked best first
     *
     * @return list<Document>
     */
    public static function distinct(array $documents): array
    {
        $seen = [];
        $distinct = [];
        foreach ($documents as $document) {
            $hash = hash('sha256', trim((string) preg_replace('/\s+/u', ' ', $document->getContent())));
            if (!isset($seen[$hash])) {
                $seen[$hash] = true;
                $distinct[] = $document;
            }
        }

        return $distinct;
    }

    /**
     * @param list<Collection>      $collections
     * @param array<string, scalar> $filters
     *
     * @return list<array{ApiConfiguration, string, array<string, scalar>}> connection, model and store filters per query
     *
     * @throws EmbeddingConnectionException for a given collection without embedding connection
     */
    private function scopes(array $collections, array $filters): array
    {
        if ($collections === []) {
            return $this->allCollectionScopes($filters);
        }

        return array_map(fn (Collection $collection): array => $this->collectionScope($collection, $filters), $collections);
    }

    /**
     * The scope of one given collection: its current connection and model,
     * filtered by its id.
     *
     * @param array<string, scalar> $filters
     *
     * @return array{ApiConfiguration, string, array<string, scalar>}
     *
     * @throws EmbeddingConnectionException for a collection without embedding connection
     * @throws \InvalidArgumentException    for a collection without model
     */
    private function collectionScope(Collection $collection, array $filters): array
    {
        $connection = $collection->getEmbeddingProviderConnection() ?? throw EmbeddingConnectionException::missing($collection);
        $model = $collection->getEmbeddingModel();
        if (null === $model || '' === $model) {
            throw new \InvalidArgumentException(\sprintf('Collection "%s" has no embedding model.', $collection->getName()));
        }

        return [$connection, $model, $filters + ['collection_id' => (string) $collection->getId()]];
    }

    /**
     * Scopes of a search without collections (see the class description).
     * Models that are no longer registered are skipped.
     *
     * @param array<string, scalar> $filters
     *
     * @return list<array{ApiConfiguration, string, array<string, scalar>}>
     */
    private function allCollectionScopes(array $filters): array
    {
        $rows = $this->entityManager
            ->createQuery(\sprintf(
                'SELECT c.id AS id, c.embeddingModel AS model, IDENTITY(c.embeddingProviderConnection) AS connection FROM %s c ORDER BY c.embeddingModel, c.id',
                Collection::class,
            ))
            ->getScalarResult();

        /** @var array<string, list<array{id: string, connection: ?string}>> $byModel */
        $byModel = [];
        foreach ($rows as $row) {
            $model = (string) $row['model'];
            if (!$this->registry->has($model)) {
                continue;
            }
            $byModel[$model][] = [
                'id' => (string) $row['id'],
                'connection' => null !== $row['connection'] && '' !== $row['connection'] ? (string) $row['connection'] : null,
            ];
        }
        ksort($byModel);

        $scopes = [];
        foreach ($byModel as $model => $modelCollections) {
            $connectionIds = array_values(array_unique(array_map(static fn (array $c): ?string => $c['connection'], $modelCollections), \SORT_REGULAR));

            if (1 === \count($connectionIds) && null !== $connectionIds[0]) {
                // One connection serves every collection of the model: one
                // unfiltered query.
                $connection = $this->usableConnection($connectionIds[0]);
                if (null !== $connection) {
                    $scopes[] = [$connection, (string) $model, $filters];
                }
                continue;
            }

            // Several connections (or collections without one) share the
            // model: one filtered query per collection of a usable connection.
            foreach ($modelCollections as $modelCollection) {
                $connection = null !== $modelCollection['connection'] ? $this->usableConnection($modelCollection['connection']) : null;
                if (null !== $connection) {
                    $scopes[] = [$connection, (string) $model, $filters + ['collection_id' => $modelCollection['id']]];
                }
            }
        }

        return $scopes;
    }

    /**
     * The connection with id $id when it can serve embeddings now (active
     * `ollama`); null otherwise.
     */
    private function usableConnection(string $id): ?ApiConfiguration
    {
        $connection = Uuid::isValid($id) ? $this->entityManager->find(ApiConfiguration::class, Uuid::fromString($id)) : null;
        if (!$connection instanceof ApiConfiguration) {
            return null;
        }

        try {
            EmbeddingsProviderLocator::assertUsable($connection);
        } catch (EmbeddingConnectionException) {
            return null;
        }

        return $connection;
    }

    private static function pairKey(ApiConfiguration $connection, string $model): string
    {
        return $connection->getId()->toRfc4122()."\0".$model;
    }
}

// - revised 2026-10-14
