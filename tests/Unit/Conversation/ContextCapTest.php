<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:35:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use Dmstr\NeuronAiBundle\Conversation\ContextChunk;
use Dmstr\NeuronAiBundle\Conversation\ContextLimits;
use Dmstr\NeuronAiBundle\Conversation\ContextRetriever;
use Dmstr\NeuronAiBundle\Conversation\StructuredAnswerValidator;
use Dmstr\NeuronAiBundle\Conversation\TurnRequest;
use Dmstr\NeuronAiBundle\Conversation\TurnRunner;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingDocumentSchema;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingsProviderLocator;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Retrieval\CollectionRetriever;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronPgvectorStore\PgVectorStore;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\SearchRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Kernel-free checks of the context cap: the real
 * CollectionRetriever over a store double returns oversized results, the
 * ContextRetriever keeps at most `context_max_chunks` chunks with at most
 * `context_max_chars` characters, and the turn sends exactly those chunks
 * and reports exactly those as sources. {@see ContextRetriever::pool()}
 * is the same search without the cap, and retrieve() is exactly
 * cap(pool()) without an expansion source. With {@see ContextLimits} (agent
 * versions) the version's chunk count and character budget
 * replace the bundle cap.
 */
final class ContextCapTest extends TestCase
{
    private EmbeddingModelRegistry $registry;

    private FakeProviderFactory $factory;

    private Collection $handbook;

    /** @var list<Document> */
    private array $hits = [];

    protected function setUp(): void
    {
        $this->registry = new EmbeddingModelRegistry([
            'nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768, 'query_prefix' => 'search_query: '],
        ]);
        $this->factory = new FakeProviderFactory($this->registry);
        $this->handbook = new Collection()->setName('handbuch')->setEmbeddingModel('nomic-embed-text')->setEmbeddingDimension(768)->setEmbeddingProviderConnection(ProviderConnectionFixtures::ollama());
    }

    public function testOversizedRetrievalIsCappedByCharacters(): void
    {
        $this->hits = $this->chunks(20, 1000);

        $chunks = $this->contextRetriever()->retrieve('Wartung', [$this->handbook], 20);

        // 6 × 1000 characters fit into 6000, a seventh would not.
        self::assertCount(6, $chunks);
        self::assertSame(['c01', 'c02', 'c03', 'c04', 'c05', 'c06'], array_map(static fn (ContextChunk $c): string => substr($c->content, 0, 3), $chunks));
        self::assertLessThanOrEqual(6000, array_sum(array_map(static fn (ContextChunk $c): int => mb_strlen($c->content), $chunks)));
        self::assertSame('handbuch', $chunks[0]->collectionName);
        self::assertSame((string) $this->handbook->getId(), $chunks[0]->collectionId);
        self::assertSame('https://example.org/doc/1', $chunks[0]->sourceUrl);
        self::assertSame(1, $chunks[0]->chunkNumber);
        $this->factory->getEmbeddingsRecorder()->assertEmbeddedText('search_query: Wartung', 'nomic-embed-text');
    }

    public function testOversizedRetrievalIsCappedByCount(): void
    {
        $this->hits = $this->chunks(20, 100);

        $chunks = $this->contextRetriever()->retrieve('Wartung', [$this->handbook], 20);

        self::assertCount(8, $chunks);
    }

    public function testTheTurnSendsAndReportsExactlyTheCappedChunks(): void
    {
        $this->hits = $this->chunks(20, 1000);
        $context = $this->contextRetriever()->retrieve('Wartung', [$this->handbook], 20);
        $this->factory->queueAnswer('Alle 500 Stunden.');

        $result = new TurnRunner($this->factory, new StructuredAnswerValidator())->run(new TurnRequest(
            provider: 'anthropic',
            connection: ProviderConnectionFixtures::anthropic(),
            model: FakeProviderFactory::ANTHROPIC_MODEL,
            content: 'Wartung?',
            context: $context,
        ));

        $prompt = (string) $this->factory->getChatProvider()->getRecorded()[0]->systemPrompt?->getContent();
        self::assertStringContainsString('<context>', $prompt);
        foreach ($this->hits as $i => $hit) {
            $sent = $i < 6;
            self::assertSame($sent, str_contains($prompt, $hit->getContent()), sprintf('chunk %d sent: %s', $i + 1, $sent ? 'yes' : 'no'));
        }
        self::assertSame($context, $result->context);
        self::assertSame(['c01', 'c02', 'c03', 'c04', 'c05', 'c06'], array_map(static fn (ContextChunk $c): string => substr($c->toArray()['content'], 0, 3), $result->context));
    }

    public function testASingleOversizedChunkIsShortenedInsteadOfDropped(): void
    {
        $this->hits = $this->chunks(2, 9000);

        $chunks = $this->contextRetriever()->retrieve('Wartung', [$this->handbook], 5);

        self::assertCount(1, $chunks);
        self::assertSame(6000, mb_strlen($chunks[0]->content));
    }

    public function testNoCollectionsMeansNoRetrieval(): void
    {
        $this->hits = $this->chunks(3, 10);

        self::assertSame([], $this->contextRetriever()->retrieve('Wartung', [], 5));
        $this->factory->getEmbeddingsRecorder()->assertNothingEmbedded();
    }

    public function testThePoolIsUncapped(): void
    {
        $this->hits = $this->chunks(10, 100);
        $retriever = $this->contextRetriever();

        $pool = $retriever->pool('Wartung', [$this->handbook], 10);

        self::assertCount(10, $pool);
        self::assertSame(['c01', 'c02', 'c03', 'c04', 'c05', 'c06', 'c07', 'c08', 'c09', 'c10'], array_map(static fn (ContextChunk $c): string => substr($c->content, 0, 3), $pool));
        self::assertSame('handbuch', $pool[9]->collectionName);
        self::assertCount(8, $retriever->retrieve('Wartung', [$this->handbook], 10));
    }

    public function testThePoolIsCutToTheDepth(): void
    {
        $this->hits = $this->chunks(20, 100);

        self::assertCount(5, $this->contextRetriever()->pool('Wartung', [$this->handbook], 5));
    }

    public function testRetrieveIsTheCapOfThePool(): void
    {
        $this->hits = $this->chunks(20, 1000);
        $retriever = $this->contextRetriever();

        $pool = $retriever->pool('Wartung', [$this->handbook], 20);
        $retrieved = $retriever->retrieve('Wartung', [$this->handbook], 20);

        self::assertCount(20, $pool);
        self::assertCount(6, $retrieved);
        self::assertEquals($retriever->cap($pool), $retrieved);
        self::assertEquals(\array_slice($pool, 0, 6), $retrieved);
    }

    public function testVersionLimitsReplaceTheBundleCap(): void
    {
        $this->hits = $this->chunks(20, 1000);
        $retriever = $this->contextRetriever();
        $limits = new ContextLimits(maxChunks: 10, maxChars: 15000, expansionMaxChars: 6000);

        $retrieved = $retriever->retrieve('Wartung', [$this->handbook], 20, limits: $limits);

        // 10 chunks of 1000 characters fit the 15000 budget; the chunk count
        // stops the cap. Without a neighbour source nothing is expanded.
        self::assertCount(10, $retrieved);
        self::assertEquals($retriever->cap($retriever->pool('Wartung', [$this->handbook], 20), $limits), $retrieved);
        self::assertCount(4, $retriever->retrieve('Wartung', [$this->handbook], 20, limits: new ContextLimits(10, 4500, 0)));
    }

    public function testASingleOversizedChunkIsShortenedToTheVersionBudget(): void
    {
        $this->hits = $this->chunks(2, 20000);

        $chunks = $this->contextRetriever()->retrieve('Wartung', [$this->handbook], 5, limits: new ContextLimits(10, 15000, 6000));

        self::assertCount(1, $chunks);
        self::assertSame(15000, mb_strlen($chunks[0]->content));
    }

    public function testThePoolHasTheGuardsOfRetrieve(): void
    {
        $this->hits = $this->chunks(3, 10);
        $retriever = $this->contextRetriever();

        self::assertSame([], $retriever->pool('Wartung', [], 5));
        self::assertSame([], $retriever->pool('Wartung', [$this->handbook], 0));
        self::assertSame([], $retriever->pool('Wartung', [$this->handbook], -1));
        $this->factory->getEmbeddingsRecorder()->assertNothingEmbedded();
    }

    public function testStoredIdsAreLoadedAndAMissingOneIsRejected(): void
    {
        $retriever = $this->contextRetriever();

        self::assertSame([$this->handbook], $retriever->loadCollections([(string) $this->handbook->getId(), $this->handbook]));

        $this->expectException(UnprocessableEntityHttpException::class);
        $retriever->loadCollections([(string) Uuid::v4()]);
    }

    private function contextRetriever(): ContextRetriever
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('find')->willReturnCallback(
            fn (string $class, mixed $id): ?Collection => (string) $id === (string) $this->handbook->getId() ? $this->handbook : null,
        );

        $retriever = new CollectionRetriever(
            new EmbeddingsProviderLocator($this->factory, $this->registry),
            $this->registry,
            new CapTestVectorStore(fn (): array => $this->hits),
            $entityManager,
        );

        return new ContextRetriever($retriever, $entityManager, maxChunks: 8, maxChars: 6000);
    }

    /**
     * @return list<Document> descending scores, content "cNN" padded to $length characters
     */
    private function chunks(int $count, int $length): array
    {
        $documents = [];
        for ($i = 1; $i <= $count; ++$i) {
            $documents[] = (new Document(str_pad(sprintf('c%02d ', $i), $length, 'x')))->setScore(1 - $i / 100)->setMetadata([
                'collection_id' => (string) $this->handbook->getId(),
                'source_url' => 'https://example.org/doc/'.$i,
                'topic' => 'wartung',
                'chunk_number' => $i,
            ]);
        }

        return $documents;
    }
}

/**
 * Store double answering every search with the scripted hits, cut to the
 * requested top-k. The in-memory SQLite connection is never used.
 */
final class CapTestVectorStore extends PgVectorStore
{
    /**
     * @param \Closure(): list<Document> $hits
     */
    public function __construct(private readonly \Closure $hits)
    {
        parent::__construct(new \PDO('sqlite::memory:'), 'embedding', schema: EmbeddingDocumentSchema::schema());
    }

    public function search(SearchRequest $request): iterable
    {
        $hits = [];
        foreach (($this->hits)() as $document) {
            $hits[] = (clone $document)->addMetadata('model', (string) $this->model);
        }

        return \array_slice($hits, 0, $request->topK ?? $this->topK);
    }
}

// - revised 2026-10-14

// - revised 2026-10-14
