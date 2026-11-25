<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:17:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Retrieval;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Conversation\ContextChunk;
use Dmstr\NeuronAiBundle\Conversation\ContextRetriever;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingConnectionException;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingDocumentSchema;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingsProviderLocator;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Retrieval\CollectionRetriever;
use Dmstr\NeuronAiBundle\Retrieval\RankFusion;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronPgvectorStore\PgVectorStore;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\SearchRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Kernel-free checks of the multi-model retrieval: a scripted store double
 * (no PostgreSQL) answers per model, the fake provider factory records the
 * query embeddings (and which connection each provider was built for), and
 * a stubbed entity manager returns (id, model, connection) of all
 * collections ({@see $all}) and finds their connections. Collections embed
 * through the `ollama` connection {@see $ollama} unless a test names
 * another. The pool of
 * {@see ContextRetriever::pool()} is checked over the same double.
 *
 * The double also answers keywordSearch() from a script per
 * model (recorded separately, so the vector-only assertions stay as they
 * were); the hybrid search fuses both lists of every collection with
 * {@see RankFusion}. The SQL of the keyword search itself is covered by the
 * store's integration test.
 */
final class CollectionRetrieverTest extends TestCase
{
    private EmbeddingModelRegistry $registry;

    private FakeProviderFactory $factory;

    /** @var \ArrayObject<int, array{model: ?string, dimension: ?int, filters: array<string, scalar>, topK: int, length: int}> */
    private \ArrayObject $storeCalls;

    /** @var \ArrayObject<int, array{model: ?string, filters: array<string, scalar>, topK: int, query: string, language: string}> */
    private \ArrayObject $keywordCalls;

    /** @var list<Collection> every stored collection, for the search without collections */
    private array $all = [];

    private ApiConfiguration $ollama;

    private ApiConfiguration $remote;

    protected function setUp(): void
    {
        $this->registry = new EmbeddingModelRegistry([
            'nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768, 'query_prefix' => 'search_query: '],
            'mxbai-embed-large' => ['provider' => 'ollama', 'dimension' => 1024, 'query_prefix' => 'Represent: '],
        ]);
        $this->factory = new FakeProviderFactory($this->registry);
        $this->storeCalls = new \ArrayObject();
        $this->keywordCalls = new \ArrayObject();
        $this->ollama = ProviderConnectionFixtures::ollama();
        $this->remote = ProviderConnectionFixtures::ollama('fake-ollama-remote', url: 'http://remote.fake.test:11434/api');
    }

    public function testEmbedsOncePerModelAndQueriesEachCollection(): void
    {
        $handbook = $this->collection('handbuch', 'nomic-embed-text');
        $faq = $this->collection('faq', 'nomic-embed-text');
        $english = $this->collection('docs-en', 'mxbai-embed-large');

        $retriever = $this->retriever([
            'nomic-embed-text' => [
                self::hit('h1', $handbook, 0.91),
                self::hit('h2', $handbook, 0.40),
                self::hit('f1', $faq, 0.75),
            ],
            'mxbai-embed-large' => [
                self::hit('e1', $english, 0.88),
                self::hit('e2', $english, 0.10),
            ],
        ]);

        $documents = $retriever->search('Wartung', [$handbook, $faq, $english], 3, ['topic' => 'wartung']);

        // One query embedding per distinct model, with the model's prefix.
        self::assertSame([
            ['model' => 'nomic-embed-text', 'text' => 'search_query: Wartung'],
            ['model' => 'mxbai-embed-large', 'text' => 'Represent: Wartung'],
        ], $this->factory->getEmbeddingsRecorder()->getRecorded());

        // One store query per collection, scoped to its model and filtered
        // by its id plus the extra filters.
        self::assertSame([
            ['model' => 'nomic-embed-text', 'dimension' => 768, 'filters' => ['topic' => 'wartung', 'collection_id' => (string) $handbook->getId()], 'topK' => CollectionRetriever::candidates(3), 'length' => 768],
            ['model' => 'nomic-embed-text', 'dimension' => 768, 'filters' => ['topic' => 'wartung', 'collection_id' => (string) $faq->getId()], 'topK' => CollectionRetriever::candidates(3), 'length' => 768],
            ['model' => 'mxbai-embed-large', 'dimension' => 1024, 'filters' => ['topic' => 'wartung', 'collection_id' => (string) $english->getId()], 'topK' => CollectionRetriever::candidates(3), 'length' => 1024],
        ], $this->storeCalls->getArrayCopy());

        // Merged by score descending and cut to topK.
        self::assertSame(['h1', 'e1', 'f1'], array_map(static fn (Document $d): string => $d->getContent(), $documents));
        self::assertSame(['nomic-embed-text', 'mxbai-embed-large', 'nomic-embed-text'], array_map(static fn (Document $d): string => $d->getMetadata()['model'], $documents));
        self::assertSame((string) $english->getId(), $documents[1]->getMetadata()['collection_id']);
    }

    public function testThePoolOfTwoCollectionsWithTwoModelsIsCutAfterTheMerge(): void
    {
        $handbook = $this->collection('handbuch', 'nomic-embed-text');
        $english = $this->collection('docs-en', 'mxbai-embed-large');

        $retriever = $this->retriever([
            'nomic-embed-text' => [
                self::hit('h1', $handbook, 0.95),
                self::hit('h2', $handbook, 0.90),
                self::hit('h3', $handbook, 0.85),
                self::hit('h4', $handbook, 0.20),
            ],
            'mxbai-embed-large' => [
                self::hit('e1', $english, 0.80),
                self::hit('e2', $english, 0.10),
            ],
        ]);

        $pool = new ContextRetriever($retriever, $this->createStub(EntityManagerInterface::class))
            ->pool('Wartung', [$handbook, $english], 3);

        // Each collection is queried with top-k = depth; the merge of
        // h1..h3 and e1, e2 is cut to the depth afterwards, so the second
        // collection contributes nothing although it has hits. A cut per
        // collection would have kept e1.
        self::assertSame(['h1', 'h2', 'h3'], array_map(static fn (ContextChunk $c): string => $c->content, $pool));
        self::assertSame(array_fill(0, 2, CollectionRetriever::candidates(3)), array_map(static fn (array $call): int => $call['topK'], $this->storeCalls->getArrayCopy()));
        self::assertSame(['handbuch', 'handbuch', 'handbuch'], array_map(static fn (ContextChunk $c): ?string => $c->collectionName, $pool));

        // One query embedding per distinct model.
        self::assertSame([
            ['model' => 'nomic-embed-text', 'text' => 'search_query: Wartung'],
            ['model' => 'mxbai-embed-large', 'text' => 'Represent: Wartung'],
        ], $this->factory->getEmbeddingsRecorder()->getRecorded());

        // Interleaved scores keep the merged order across models.
        $this->storeCalls->exchangeArray([]);
        $pool = new ContextRetriever($retriever, $this->createStub(EntityManagerInterface::class))
            ->pool('Wartung', [$english, $handbook], 5);
        self::assertSame(['h1', 'h2', 'h3', 'e1', 'h4'], array_map(static fn (ContextChunk $c): string => $c->content, $pool));
        self::assertSame(['docs-en', 'handbuch'], [$pool[3]->collectionName, $pool[4]->collectionName]);
    }

    public function testWithoutCollectionsQueriesEachCurrentModelOnce(): void
    {
        $handbook = $this->collection('handbuch', 'nomic-embed-text');
        $english = $this->collection('docs-en', 'mxbai-embed-large');
        // A model no longer in the registry is skipped.
        $this->all = [$handbook, $english, $this->collection('old', 'retired-model')];

        $retriever = $this->retriever([
            'nomic-embed-text' => [self::hit('h1', $handbook, 0.5), self::hit('h2', $handbook, 0.2)],
            'mxbai-embed-large' => [self::hit('e1', $english, 0.7)],
        ]);

        $documents = $retriever->search('Frage', [], 10, ['topic' => 'service']);

        self::assertSame(
            [['mxbai-embed-large', ['topic' => 'service']], ['nomic-embed-text', ['topic' => 'service']]],
            array_map(static fn (array $call): array => [$call['model'], $call['filters']], $this->storeCalls->getArrayCopy()),
        );
        self::assertCount(2, $this->factory->getEmbeddingsRecorder()->getRecorded());
        self::assertSame(['e1', 'h1', 'h2'], array_map(static fn (Document $d): string => $d->getContent(), $documents));
    }

    /**
     * Two connections serve the same model: the query is embedded once per
     * connection, and each embedding only ranks the collections of its own
     * connection (filtered queries); a model used on one connection only
     * keeps its unfiltered query.
     */
    public function testWithoutCollectionsEmbedsOncePerConnectionOfTheSameModel(): void
    {
        $local = $this->collection('lokal', 'nomic-embed-text');
        $remote = $this->collection('remote', 'nomic-embed-text', $this->remote);
        $english = $this->collection('docs-en', 'mxbai-embed-large');
        $this->all = [$local, $remote, $english];

        $retriever = $this->retriever([
            'nomic-embed-text' => [self::hit('l1', $local, 0.6), self::hit('r1', $remote, 0.9)],
            'mxbai-embed-large' => [self::hit('e1', $english, 0.7)],
        ]);

        $documents = $retriever->search('Frage', [], 10);

        self::assertSame([
            ['provider' => 'ollama', 'connection' => 'fake-ollama', 'model' => 'mxbai-embed-large'],
            ['provider' => 'ollama', 'connection' => 'fake-ollama', 'model' => 'nomic-embed-text'],
            ['provider' => 'ollama', 'connection' => 'fake-ollama-remote', 'model' => 'nomic-embed-text'],
        ], $this->factory->getRequestedEmbeddings());
        self::assertSame([
            ['model' => 'mxbai-embed-large', 'text' => 'Represent: Frage'],
            ['model' => 'nomic-embed-text', 'text' => 'search_query: Frage'],
            ['model' => 'nomic-embed-text', 'text' => 'search_query: Frage'],
        ], $this->factory->getEmbeddingsRecorder()->getRecorded());
        self::assertSame(
            [['mxbai-embed-large', []], ['nomic-embed-text', ['collection_id' => (string) $local->getId()]], ['nomic-embed-text', ['collection_id' => (string) $remote->getId()]]],
            array_map(static fn (array $call): array => [$call['model'], $call['filters']], $this->storeCalls->getArrayCopy()),
        );
        // Every hit once.
        self::assertSame(['r1', 'e1', 'l1'], self::contents($documents));
    }

    /**
     * Without collections, a collection without connection (stored before
     * provider connections) or on an inactive connection is skipped instead
     * of failing the search; the other collections of its model are then
     * searched filtered, so its vectors are not ranked.
     */
    public function testWithoutCollectionsSkipsStaleAndInactiveCollections(): void
    {
        $handbook = $this->collection('handbuch', 'nomic-embed-text');
        $legacy = $this->collection('legacy', 'nomic-embed-text', false);
        $off = $this->collection('off', 'mxbai-embed-large', ProviderConnectionFixtures::ollama('fake-ollama-off', active: false));
        $this->all = [$handbook, $legacy, $off];

        $retriever = $this->retriever([
            'nomic-embed-text' => [self::hit('h1', $handbook, 0.5), self::hit('x1', $legacy, 0.9)],
            'mxbai-embed-large' => [self::hit('o1', $off, 0.9)],
        ]);

        $documents = $retriever->search('Frage', [], 10);

        self::assertSame(['h1'], self::contents($documents));
        self::assertSame(
            [['nomic-embed-text', ['collection_id' => (string) $handbook->getId()]]],
            array_map(static fn (array $call): array => [$call['model'], $call['filters']], $this->storeCalls->getArrayCopy()),
        );
        self::assertSame([['provider' => 'ollama', 'connection' => 'fake-ollama', 'model' => 'nomic-embed-text']], $this->factory->getRequestedEmbeddings());
    }

    public function testGivenCollectionsOfOneModelOnTwoConnectionsEmbedOncePerConnection(): void
    {
        $local = $this->collection('lokal', 'nomic-embed-text');
        $remote = $this->collection('remote', 'nomic-embed-text', $this->remote);
        $retriever = $this->retriever(['nomic-embed-text' => [self::hit('l1', $local, 0.6), self::hit('r1', $remote, 0.9)]]);

        $documents = $retriever->search('Frage', [$local, $remote], 5);

        self::assertSame(['fake-ollama', 'fake-ollama-remote'], array_column($this->factory->getRequestedEmbeddings(), 'connection'));
        self::assertCount(2, $this->factory->getEmbeddingsRecorder()->getRecorded());
        self::assertSame(['r1', 'l1'], self::contents($documents));
    }

    /**
     * @return iterable<string, array{bool, string, string}>
     */
    public static function unusableCollections(): iterable
    {
        yield 'no connection, vector' => [false, EmbeddingConnectionException::MISSING, 'has no embedding connection'];
        yield 'no connection, hybrid' => [true, EmbeddingConnectionException::MISSING, 'has no embedding connection'];
        yield 'inactive connection, vector' => [false, EmbeddingConnectionException::INACTIVE, 'is inactive'];
        yield 'inactive connection, hybrid' => [true, EmbeddingConnectionException::INACTIVE, 'is inactive'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableCollections')]
    public function testAGivenCollectionWithoutUsableConnectionFailsWith422(bool $hybrid, string $reason, string $message): void
    {
        $collection = $this->collection('handbuch', 'nomic-embed-text', EmbeddingConnectionException::MISSING === $reason ? false : ProviderConnectionFixtures::ollama('fake-ollama-off', active: false));

        try {
            $this->retriever([])->search('Frage', [$collection], 5, [], $hybrid);
            self::fail('Expected an EmbeddingConnectionException.');
        } catch (EmbeddingConnectionException $e) {
            self::assertSame($reason, $e->reason);
            self::assertSame(422, $e->getStatusCode());
            self::assertStringContainsString($message, $e->getMessage());
        }

        self::assertCount(0, $this->storeCalls);
        $this->factory->getEmbeddingsRecorder()->assertNothingEmbedded();
    }

    public function testNoCollectionsAtAllYieldsNoQuery(): void
    {
        $documents = $this->retriever([])->search('Frage', [], 5);

        self::assertSame([], $documents);
        self::assertCount(0, $this->storeCalls);
        $this->factory->getEmbeddingsRecorder()->assertNothingEmbedded();
    }

    /**
     * Hybrid off is the vector path: the explicit `false` gives the same
     * documents, scores and order as the call without the parameters, and
     * no keyword search runs. The weight is ignored then.
     */
    public function testHybridOffIsTheVectorPath(): void
    {
        $handbook = $this->collection('handbuch', 'nomic-embed-text');
        $english = $this->collection('docs-en', 'mxbai-embed-large');
        $retriever = $this->retriever([
            'nomic-embed-text' => [self::hit('h1', $handbook, 0.95), self::hit('h2', $handbook, 0.30)],
            'mxbai-embed-large' => [self::hit('e1', $english, 0.80)],
        ], [
            'nomic-embed-text' => [self::hit('k1', $handbook, 0.9)],
        ]);

        $plain = $retriever->search('Wartung', [$handbook, $english], 3);
        $off = $retriever->search('Wartung', [$handbook, $english], 3, [], false, 0.9);

        self::assertSame(['h1', 'e1', 'h2'], self::contents($plain));
        self::assertSame([0.95, 0.80, 0.30], array_map(static fn (Document $d): float => $d->getScore(), $plain));
        self::assertEquals($plain, $off);
        self::assertCount(0, $this->keywordCalls);
        self::assertSame(array_fill(0, 4, CollectionRetriever::candidates(3)), array_map(static fn (array $call): int => $call['topK'], $this->storeCalls->getArrayCopy()));
    }

    /**
     * An exact term the vector side misses: the keyword hit is not among
     * the vector candidates but enters the fused list; both lists are
     * queried with the candidate count, scoped like the vector search.
     */
    public function testHybridFindsAnExactTermTheVectorSideMisses(): void
    {
        $handbook = $this->collection('handbuch', 'nomic-embed-text');
        $vector = [];
        for ($i = 1; $i <= 25; ++$i) {
            $vector[] = self::hit(\sprintf('v%02d', $i), $handbook, 1.0 - $i / 100, \sprintf('00000000-0000-7000-8000-%012d', $i));
        }
        $exact = self::hit('Typ STC-1000', $handbook, 0.7, '00000000-0000-7000-8000-999999999999');

        $retriever = $this->retriever(['nomic-embed-text' => $vector], ['nomic-embed-text' => [$exact]]);
        $documents = $retriever->search('STC-1000', [$handbook], 3, ['topic' => 'wartung'], true, 0.5);

        // v01 and the exact hit tie at 0.5/61, the id decides; v02 follows.
        self::assertSame(['v01', 'Typ STC-1000', 'v02'], self::contents($documents));
        self::assertSame([0.5 / 61, 0.5 / 61, 0.5 / 62], array_map(static fn (Document $d): float => $d->getScore(), $documents));
        self::assertSame('nomic-embed-text', $documents[1]->getMetadata()['model']);
        self::assertSame((string) $handbook->getId(), $documents[1]->getMetadata()['collection_id']);

        $filters = ['topic' => 'wartung', 'collection_id' => (string) $handbook->getId()];
        self::assertSame([['model' => 'nomic-embed-text', 'dimension' => 768, 'filters' => $filters, 'topK' => 20, 'length' => 768]], $this->storeCalls->getArrayCopy());
        self::assertSame([['model' => 'nomic-embed-text', 'filters' => $filters, 'topK' => 20, 'query' => 'STC-1000', 'language' => 'german']], $this->keywordCalls->getArrayCopy());

        // Without the keyword list the exact term is no candidate at all.
        self::assertNotContains('Typ STC-1000', self::contents(\array_slice($vector, 0, 20)));
    }

    public function testCandidatesAreTwiceTheDepthAndAtLeastTwenty(): void
    {
        self::assertSame(20, CollectionRetriever::candidates(1));
        self::assertSame(20, CollectionRetriever::candidates(10));
        self::assertSame(30, CollectionRetriever::candidates(15));

        $handbook = $this->collection('handbuch', 'nomic-embed-text');
        $this->retriever([])->search('Frage', [$handbook], 15, [], true);

        self::assertSame([30], array_map(static fn (array $call): int => $call['topK'], $this->storeCalls->getArrayCopy()));
        self::assertSame([30], array_map(static fn (array $call): int => $call['topK'], $this->keywordCalls->getArrayCopy()));
    }

    /**
     * A german and an english collection with different models: each is
     * keyword-searched in its own language, there is still one query
     * embedding per model, and the result is one fused pool with hits of
     * both collections.
     */
    public function testTwoLanguagesAndTwoModelsGiveOneFusedPool(): void
    {
        $handbook = $this->collection('handbuch', 'nomic-embed-text');
        $faq = $this->collection('faq', 'nomic-embed-text');
        $english = $this->collection('docs-en', 'mxbai-embed-large')->setKeywordLanguage('english');

        $h1 = self::hit('h1', $handbook, 0.9, '00000000-0000-7000-8000-000000000001');
        $f1 = self::hit('f1', $faq, 0.6, '00000000-0000-7000-8000-000000000002');
        $e1 = self::hit('e1', $english, 0.8, '00000000-0000-7000-8000-000000000003');
        $e2 = self::hit('e2', $english, 0.2, '00000000-0000-7000-8000-000000000004');

        $retriever = $this->retriever(
            ['nomic-embed-text' => [$h1, $f1], 'mxbai-embed-large' => [$e1, $e2]],
            ['nomic-embed-text' => [$h1], 'mxbai-embed-large' => [$e2, $e1]],
        );

        $pool = new ContextRetriever($retriever, $this->createStub(EntityManagerInterface::class))
            ->pool('Maintenance Wartung', [$handbook, $faq, $english], 4, true, 0.5);

        // h1: vector 1 + keyword 1; e1: vector 1 + keyword 2;
        // e2: vector 2 + keyword 1; f1: vector 1 of the faq only.
        self::assertSame(['h1', 'e1', 'e2', 'f1'], array_map(static fn (ContextChunk $c): string => $c->content, $pool));
        self::assertSame(['handbuch', 'docs-en', 'docs-en', 'faq'], array_map(static fn (ContextChunk $c): ?string => $c->collectionName, $pool));
        self::assertEqualsWithDelta(1 / 61, $pool[0]->score, 1e-15);
        self::assertSame($pool[1]->score, $pool[2]->score);

        self::assertSame(
            [['nomic-embed-text', 'german'], ['nomic-embed-text', 'german'], ['mxbai-embed-large', 'english']],
            array_map(static fn (array $call): array => [$call['model'], $call['language']], $this->keywordCalls->getArrayCopy()),
        );
        self::assertSame([
            ['model' => 'nomic-embed-text', 'text' => 'search_query: Maintenance Wartung'],
            ['model' => 'mxbai-embed-large', 'text' => 'Represent: Maintenance Wartung'],
        ], $this->factory->getEmbeddingsRecorder()->getRecorded());
    }

    /**
     * Weight 0 ranks by the vector lists only, weight 1 by the keyword
     * lists only; hits only in the zero-weighted list are dropped.
     */
    public function testWeightZeroIsTheVectorOrderAndWeightOneTheKeywordOrder(): void
    {
        $handbook = $this->collection('handbuch', 'nomic-embed-text');
        $a = self::hit('a', $handbook, 0.9, '00000000-0000-7000-8000-00000000000a');
        $b = self::hit('b', $handbook, 0.8, '00000000-0000-7000-8000-00000000000b');
        $c = self::hit('c', $handbook, 0.7, '00000000-0000-7000-8000-00000000000c');
        $d = self::hit('d', $handbook, 0.6, '00000000-0000-7000-8000-00000000000d');

        $retriever = $this->retriever(['nomic-embed-text' => [$a, $b, $c]], ['nomic-embed-text' => [$d, $c, $a]]);

        self::assertSame(['a', 'b', 'c'], self::contents($retriever->search('q', [$handbook], 5, [], true, 0.0)));
        self::assertSame(['d', 'c', 'a'], self::contents($retriever->search('q', [$handbook], 5, [], true, 1.0)));
        self::assertSame(['a', 'b', 'c'], self::contents($retriever->search('q', [$handbook], 5)));
    }

    /**
     * During a reindex a collection has rows of its old and its new model.
     * Both lists are scoped to the current model, so no row of the old
     * model appears, and a chunk found by both lists appears once.
     */
    public function testReindexKeepsTheCurrentModelOnlyWithoutDuplicates(): void
    {
        $handbook = $this->collection('handbuch', 'mxbai-embed-large');
        $current = self::hit('neu', $handbook, 0.9, '00000000-0000-7000-8000-000000000001');
        $stale = self::hit('alt', $handbook, 0.99, '00000000-0000-7000-8000-000000000002');

        $retriever = $this->retriever(
            ['mxbai-embed-large' => [$current], 'nomic-embed-text' => [$stale]],
            ['mxbai-embed-large' => [$current], 'nomic-embed-text' => [$stale]],
        );
        $documents = $retriever->search('Wartung', [$handbook], 5, [], true);

        self::assertSame(['neu'], self::contents($documents));
        self::assertSame(['mxbai-embed-large'], array_column($this->storeCalls->getArrayCopy(), 'model'));
        self::assertSame(['mxbai-embed-large'], array_column($this->keywordCalls->getArrayCopy(), 'model'));
        self::assertSame([['model' => 'mxbai-embed-large', 'text' => 'Represent: Wartung']], $this->factory->getEmbeddingsRecorder()->getRecorded());
    }

    public function testHybridWithoutCollectionsFallsBackToTheVectorSearch(): void
    {
        $handbook = $this->collection('handbuch', 'nomic-embed-text');
        $this->all = [$handbook];
        $retriever = $this->retriever(['nomic-embed-text' => [self::hit('h1', $handbook, 0.5)]], ['nomic-embed-text' => [self::hit('k1', $handbook, 0.5)]]);

        $documents = $retriever->search('Frage', [], 5, [], true);

        self::assertSame(['h1'], self::contents($documents));
        self::assertSame(0.5, $documents[0]->getScore());
        self::assertCount(0, $this->keywordCalls);
    }

    public function testHybridWeightOutsideTheRangeIsRejected(): void
    {
        $handbook = $this->collection('handbuch', 'nomic-embed-text');

        $this->expectException(\InvalidArgumentException::class);

        $this->retriever([])->search('Frage', [$handbook], 5, [], true, 1.5);
    }

    /**
     * @param list<Document> $documents
     *
     * @return list<string>
     */
    private static function contents(array $documents): array
    {
        return array_map(static fn (Document $d): string => $d->getContent(), $documents);
    }

    /**
     * @param array<string, list<Document>> $results        scripted vector hits per model
     * @param array<string, list<Document>> $keywordResults scripted keyword hits per model
     */
    private function retriever(array $results, array $keywordResults = []): CollectionRetriever
    {
        $query = $this->createStub(Query::class);
        $query->method('getScalarResult')->willReturnCallback(fn (): array => array_map(
            static fn (Collection $collection): array => [
                'id' => $collection->getId(),
                'model' => $collection->getEmbeddingModel(),
                'connection' => $collection->getEmbeddingProviderConnection()?->getId()->toRfc4122(),
            ],
            $this->all,
        ));

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('createQuery')->willReturn($query);
        $entityManager->method('find')->willReturnCallback(function (string $class, mixed $id): ?ApiConfiguration {
            foreach ($this->all as $collection) {
                $connection = $collection->getEmbeddingProviderConnection();
                if (ApiConfiguration::class === $class && $id instanceof Uuid && null !== $connection && $connection->getId()->equals($id)) {
                    return $connection;
                }
            }

            return null;
        });

        return new CollectionRetriever(
            new EmbeddingsProviderLocator($this->factory, $this->registry),
            $this->registry,
            new ScriptedVectorStore($this->storeCalls, $results, $this->keywordCalls, $keywordResults),
            $entityManager,
        );
    }

    /**
     * @param ApiConfiguration|false|null $connection null = {@see $ollama}, false = none (stored before provider connections)
     */
    private function collection(string $name, string $model, ApiConfiguration|false|null $connection = null): Collection
    {
        return new Collection()
            ->setName($name)
            ->setEmbeddingModel($model)
            ->setEmbeddingDimension($model === 'mxbai-embed-large' ? 1024 : 768)
            ->setEmbeddingProviderConnection(false === $connection ? null : $connection ?? $this->ollama);
    }

    private static function hit(string $content, Collection $collection, float $score, ?string $id = null): Document
    {
        $document = (new Document($content))
            ->setScore($score)
            ->setMetadata(['collection_id' => (string) $collection->getId()]);
        if (null !== $id) {
            $document->setId($id);
        }

        return $document;
    }
}

/**
 * Store double: keeps the real scoping (forModel/withFilters/withTopK are
 * immutable clones, the filters validated against the embedding schema)
 * and answers search() and keywordSearch() from
 * scripts instead of PostgreSQL. The in-memory SQLite connection is never
 * used.
 */
final class ScriptedVectorStore extends PgVectorStore
{
    /**
     * @param \ArrayObject<int, array<string, mixed>> $calls   shared by all clones
     * @param array<string, list<Document>>           $results        hits per model
     * @param \ArrayObject<int, array<string, mixed>> $keywordCalls   shared by all clones
     * @param array<string, list<Document>>           $keywordResults keyword hits per model
     */
    public function __construct(
        private readonly \ArrayObject $calls,
        private readonly array $results,
        private readonly \ArrayObject $keywordCalls = new \ArrayObject(),
        private readonly array $keywordResults = [],
    ) {
        parent::__construct(new \PDO('sqlite::memory:'), 'embedding', schema: EmbeddingDocumentSchema::schema());
    }

    public function search(SearchRequest $request): iterable
    {
        $this->calls[] = [
            'model' => $this->model,
            'dimension' => $this->dimension,
            'filters' => $this->filters,
            'topK' => $request->topK ?? $this->topK,
            'length' => \count($request->embedding),
        ];

        return $this->scripted($this->results);
    }

    public function keywordSearch(string $query, string $language): iterable
    {
        self::assertValidKeywordLanguage($language);
        $this->keywordCalls[] = [
            'model' => $this->model,
            'filters' => $this->filters,
            'topK' => $this->topK,
            'query' => $query,
            'language' => $language,
        ];

        return $this->scripted($this->keywordResults);
    }

    /**
     * @param array<string, list<Document>> $results
     *
     * @return list<Document>
     */
    private function scripted(array $results): array
    {
        $hits = [];
        foreach ($results[$this->model] ?? [] as $document) {
            if (isset($this->filters['collection_id']) && $document->getMetadata()['collection_id'] !== $this->filters['collection_id']) {
                continue;
            }
            $hit = (clone $document)->addMetadata('model', (string) $this->model);
            $hits[] = $hit;
        }

        return \array_slice($hits, 0, $this->topK);
    }
}

// - revised 2026-10-14
