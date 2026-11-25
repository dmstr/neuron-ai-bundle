<?php

// file generated with AI assistance: Claude Code - 2026-10-08 20:34:12 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Retrieval;

use Dmstr\NeuronAiBundle\Conversation\ContextChunk;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingDocumentSchema;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingsProviderLocator;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Retrieval\CollectionReferenceResolver;
use Dmstr\NeuronAiBundle\Retrieval\CollectionRetriever;
use Dmstr\NeuronAiBundle\Retrieval\KnowledgeSearch;
use Dmstr\NeuronAiBundle\Retrieval\UnknownCollectionReferenceException;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronPgvectorStore\PgVectorStore;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\SearchRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Kernel-free: {@see KnowledgeSearch} resolves the collection references,
 * searches through the real {@see CollectionRetriever} and assembles
 * {@see ContextChunk}s with collection names. A scripted store double (no
 * PostgreSQL) answers per model and records the store queries, the fake
 * provider factory records the query embeddings, and a stubbed entity
 * manager knows the collections of {@see $all}; name queries are counted.
 */
final class KnowledgeSearchTest extends TestCase
{
    private EmbeddingModelRegistry $registry;

    private FakeProviderFactory $factory;

    /** @var \ArrayObject<int, array{model: ?string, filters: array<string, scalar>, topK: int}> */
    private \ArrayObject $storeCalls;

    /** @var list<Collection> */
    private array $all = [];

    private int $nameQueries = 0;

    protected function setUp(): void
    {
        $this->registry = new EmbeddingModelRegistry([
            'nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768, 'query_prefix' => 'search_query: '],
        ]);
        $this->factory = new FakeProviderFactory($this->registry);
        $this->storeCalls = new \ArrayObject();
    }

    public function testSearchByNameReturnsChunksOfThatCollection(): void
    {
        $handbook = $this->collection('handbuch');
        $faq = $this->collection('faq');
        $documentId = (string) Uuid::v4();

        $chunks = $this->search([
            self::hit('h1', $handbook, 0.9, ['topic' => 'wartung', 'source_url' => 'https://example.org/h1', 'chunk_id' => 'raw-1', 'chunk_number' => '2', 'document_id' => $documentId, 'filename' => 'handbuch.pdf', 'breadcrumb' => 'Wartung > Spindel']),
            self::hit('f1', $faq, 0.8),
        ])->search('Wartung', ['handbuch'], 5, 'wartung');

        self::assertCount(1, $chunks);
        self::assertEquals(new ContextChunk(
            content: 'h1',
            score: 0.9,
            collectionId: $handbook->getId()->toRfc4122(),
            collectionName: 'handbuch',
            topic: 'wartung',
            sourceUrl: 'https://example.org/h1',
            chunkId: 'raw-1',
            chunkNumber: 2,
            documentId: $documentId,
            filename: 'handbuch.pdf',
            breadcrumb: 'Wartung > Spindel',
        ), $chunks[0]);

        // One store query, filtered by topic and collection; the name comes
        // from the resolved collection, without a name query.
        self::assertSame([
            ['model' => 'nomic-embed-text', 'filters' => ['topic' => 'wartung', 'collection_id' => (string) $handbook->getId()], 'topK' => CollectionRetriever::candidates(5)],
        ], $this->storeCalls->getArrayCopy());
        self::assertSame(['search_query: Wartung'], $this->factory->getEmbeddingsRecorder()->getRecordedTexts());
        self::assertSame(0, $this->nameQueries);
    }

    public function testSearchByUuidReference(): void
    {
        $this->collection('handbuch');
        $faq = $this->collection('faq');

        $chunks = $this->search([self::hit('f1', $faq, 0.8)])->search('Ersatzteile', [strtoupper((string) $faq->getId())]);

        self::assertSame(['faq'], array_map(static fn (ContextChunk $c): ?string => $c->collectionName, $chunks));
    }

    public function testSearchWithoutCollectionsSearchesAllAndLooksUpNamesOnce(): void
    {
        $handbook = $this->collection('handbuch');
        $faq = $this->collection('faq');
        $orphan = Uuid::v4()->toRfc4122();

        $orphanHit = (new Document('o1'))->setScore(0.95)->setMetadata(['collection_id' => $orphan]);

        $chunks = $this->search([
            $orphanHit,
            self::hit('h1', $handbook, 0.9),
            self::hit('f1', $faq, 0.7),
        ])->search('Wartung', [], 10, '');

        self::assertSame(['o1', 'h1', 'f1'], array_map(static fn (ContextChunk $c): string => $c->content, $chunks));
        self::assertSame([null, 'handbuch', 'faq'], array_map(static fn (ContextChunk $c): ?string => $c->collectionName, $chunks));
        self::assertSame($orphan, $chunks[0]->collectionId);
        // An empty topic is no filter; one store query for the one model and
        // connection shared by all collections.
        self::assertSame([['model' => 'nomic-embed-text', 'filters' => [], 'topK' => CollectionRetriever::candidates(10)]], $this->storeCalls->getArrayCopy());
        self::assertSame(1, $this->nameQueries);
    }

    public function testKCutsTheMergedHits(): void
    {
        $handbook = $this->collection('handbuch');

        $chunks = $this->search([
            self::hit('h1', $handbook, 0.9),
            self::hit('h2', $handbook, 0.8),
            self::hit('h3', $handbook, 0.7),
        ])->search('Wartung', ['handbuch'], 2);

        self::assertSame(['h1', 'h2'], array_map(static fn (ContextChunk $c): string => $c->content, $chunks));
    }

    public function testUnknownReferenceFailsBeforeAnySearch(): void
    {
        $this->collection('handbuch');
        $search = $this->search([]);

        try {
            $search->search('Wartung', ['handbuch', 'gibt-es-nicht']);
            self::fail('Expected UnknownCollectionReferenceException');
        } catch (UnknownCollectionReferenceException $exception) {
            self::assertSame('Collection "gibt-es-nicht" not found', $exception->getMessage());
            self::assertSame(422, $exception->getStatusCode());
        }

        self::assertSame([], $this->storeCalls->getArrayCopy());
        self::assertSame([], $this->factory->getEmbeddingsRecorder()->getRecorded());
    }

    private function collection(string $name): Collection
    {
        $collection = new Collection()
            ->setName($name)
            ->setEmbeddingModel('nomic-embed-text')
            ->setEmbeddingDimension(768)
            ->setEmbeddingProviderConnection(ProviderConnectionFixtures::ollama());
        $this->all[] = $collection;

        return $collection;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private static function hit(string $content, Collection $collection, float $score, array $metadata = []): Document
    {
        return (new Document($content))
            ->setScore($score)
            ->setMetadata(['collection_id' => (string) $collection->getId()] + $metadata);
    }

    /**
     * @param list<Document> $hits answered by every store query (filtered by collection_id when set)
     */
    private function search(array $hits): KnowledgeSearch
    {
        $connection = ProviderConnectionFixtures::ollama();

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturnCallback(function (array $criteria): ?Collection {
            foreach ($this->all as $collection) {
                if ($collection->getName() === $criteria['name']) {
                    return $collection;
                }
            }

            return null;
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('find')->willReturnCallback(function (string $class, mixed $id) use ($connection): ?object {
            if (Collection::class === $class) {
                foreach ($this->all as $collection) {
                    if ($id instanceof Uuid && $collection->getId()->equals($id)) {
                        return $collection;
                    }
                }

                return null;
            }

            // The retriever looks up the connection of the collections when
            // no collection is given.
            return $connection;
        });
        $entityManager->method('createQuery')->willReturnCallback(function (string $dql) use ($connection): Query {
            $query = $this->createStub(Query::class);
            $query->method('setParameter')->willReturnSelf();
            if (str_contains($dql, 'c.name')) {
                // Name lookup of CollectionReferenceResolver::namesByIds().
                ++$this->nameQueries;
                $query->method('getArrayResult')->willReturnCallback(fn (): array => array_map(
                    static fn (Collection $collection): array => ['id' => $collection->getId(), 'name' => $collection->getName()],
                    $this->all,
                ));
            } else {
                // Models and connections of all collections (CollectionRetriever).
                $query->method('getScalarResult')->willReturnCallback(fn (): array => array_map(
                    static fn (Collection $collection): array => [
                        'id' => $collection->getId(),
                        'model' => $collection->getEmbeddingModel(),
                        'connection' => $connection->getId()->toRfc4122(),
                    ],
                    $this->all,
                ));
            }

            return $query;
        });

        $retriever = new CollectionRetriever(
            new EmbeddingsProviderLocator($this->factory, $this->registry),
            $this->registry,
            new KnowledgeSearchStore($this->storeCalls, $hits),
            $entityManager,
        );

        return new KnowledgeSearch($retriever, new CollectionReferenceResolver($entityManager));
    }
}

/**
 * Store double: answers every vector search with the scripted hits whose
 * collection_id matches the scoped filter (all of them without one), cut
 * to the scoped top-k, and records the query. The in-memory SQLite
 * connection is never used.
 */
final class KnowledgeSearchStore extends PgVectorStore
{
    /**
     * @param \ArrayObject<int, array<string, mixed>> $calls shared by all clones
     * @param list<Document>                          $hits
     */
    public function __construct(
        private readonly \ArrayObject $calls,
        private readonly array $hits,
    ) {
        parent::__construct(new \PDO('sqlite::memory:'), 'embedding', schema: EmbeddingDocumentSchema::schema());
    }

    public function search(SearchRequest $request): iterable
    {
        $this->calls[] = ['model' => $this->model, 'filters' => $this->filters, 'topK' => $request->topK ?? $this->topK];

        $hits = [];
        foreach ($this->hits as $document) {
            if (isset($this->filters['collection_id']) && $document->getMetadata()['collection_id'] !== $this->filters['collection_id']) {
                continue;
            }
            $hits[] = clone $document;
        }

        return \array_slice($hits, 0, $this->topK);
    }
}

// - revised 2026-10-14
