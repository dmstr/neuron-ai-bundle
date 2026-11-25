<?php

// file generated with AI assistance: Claude Code - 2026-10-04 10:15:18 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use Dmstr\NeuronAiBundle\Conversation\ContextChunk;
use Dmstr\NeuronAiBundle\Conversation\ContextExpander;
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
use Dmstr\NeuronAiBundle\Retrieval\Neighbour;
use Dmstr\NeuronAiBundle\Retrieval\NeighbourSourceInterface;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronPgvectorStore\PgVectorStore;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\SearchRequest;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of context shaping in
 * {@see ContextRetriever}: the legacy instruction text stays byte for byte,
 * the cap applies the given {@see ContextLimits} (or the bundle cap without
 * them), and {@see ContextRetriever::retrieve()} expands the capped hits
 * within the separate expansion budget only, while {@see ContextRetriever::pool()}
 * stays unexpanded. The store is a scripted double, no database is used.
 */
final class ContextShapingTest extends TestCase
{
    private EmbeddingModelRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new EmbeddingModelRegistry(['nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768]]);
    }

    public function testLegacyInstructionIsByteIdentical(): void
    {
        $chunks = [
            new ContextChunk(content: 'Erster Treffer', score: 0.9, sourceUrl: 'https://example.org/a'),
            new ContextChunk(content: 'Zweiter Treffer', score: 0.8, filename: 'b.pdf'),
            new ContextChunk(content: 'Dritter Treffer', score: 0.7),
        ];
        $expected = "Use the following context from the knowledge collections to answer. If the context does not contain the answer, say so. Reference sources as [n].\n\n"
            ."<context>\n[1] (https://example.org/a)\nErster Treffer\n\n[2] (b.pdf)\nZweiter Treffer\n\n[3] (unknown)\nDritter Treffer\n</context>";

        self::assertSame($expected, ContextRetriever::instructionsFor($chunks));
        self::assertSame($expected, ContextRetriever::instructionsFor($chunks, ContextRetriever::LEGACY_INSTRUCTION));
        self::assertSame('', ContextRetriever::instructionsFor([], 'Anything.'));
    }

    public function testInstructionTextIsUsedLiterally(): void
    {
        $chunks = [new ContextChunk(content: 'Inhalt', score: 1.0, filename: 'a.md')];

        self::assertSame(
            "Antworte nur aus dem Kontext, {{agentName}}.\n\n<context>\n[1] (a.md)\nInhalt\n</context>",
            ContextRetriever::instructionsFor($chunks, 'Antworte nur aus dem Kontext, {{agentName}}.'),
        );
    }

    public function testDefaultInstructionCarriesTheFaithfulnessAndLanguageRule(): void
    {
        self::assertStringContainsString('Answer only from the following context', ContextRetriever::DEFAULT_INSTRUCTION);
        self::assertStringContainsString('Answer in the language of the question.', ContextRetriever::DEFAULT_INSTRUCTION);
        self::assertStringContainsString('Reference sources as [n].', ContextRetriever::DEFAULT_INSTRUCTION);
        self::assertNotSame(ContextRetriever::LEGACY_INSTRUCTION, ContextRetriever::DEFAULT_INSTRUCTION);

        self::assertSame(ContextRetriever::DEFAULT_INSTRUCTION, $this->contextRetriever([])->defaultInstruction());
        self::assertSame('Bundle text.', new ContextRetriever($this->collectionRetriever([]), $this->createStub(EntityManagerInterface::class), defaultInstruction: 'Bundle text.')->defaultInstruction());
    }

    public function testTurnRequestCarriesTheInstructionIntoTheSystemPrompt(): void
    {
        $context = [new ContextChunk(content: 'Inhalt', score: 1.0, filename: 'a.md')];
        $runner = new TurnRunner(new FakeProviderFactory($this->registry), new StructuredAnswerValidator());
        $instructions = new \ReflectionMethod(TurnRunner::class, 'instructions');

        $legacy = new TurnRequest('anthropic', 'model', 'Frage', context: $context, instructions: 'Du bist hilfreich.');
        self::assertSame(ContextRetriever::LEGACY_INSTRUCTION, $legacy->contextInstruction);
        self::assertSame("Du bist hilfreich.\n\n".ContextRetriever::instructionsFor($context), $instructions->invoke($runner, $legacy));

        $request = new TurnRequest('anthropic', 'model', 'Frage', instructions: 'Du bist hilfreich.', contextInstruction: 'Only from the context.');
        $request = $request->withContext($context);
        self::assertSame('Only from the context.', $request->contextInstruction);
        self::assertSame("Du bist hilfreich.\n\nOnly from the context.\n\n<context>\n[1] (a.md)\nInhalt\n</context>", $instructions->invoke($runner, $request));
    }

    public function testCapWithoutLimitsIsTheBundleCap(): void
    {
        $retriever = new ContextRetriever($this->collectionRetriever([]), $this->createStub(EntityManagerInterface::class), maxChunks: 2, maxChars: 100);
        $chunks = [self::chunk('a', 10), self::chunk('b', 10), self::chunk('c', 10)];

        self::assertSame(['a', 'b'], self::firstLetters($retriever->cap($chunks)));
        self::assertSame(['a', 'b'], self::firstLetters($retriever->cap($chunks, null)));
    }

    public function testCapHonoursMaxChunks(): void
    {
        $chunks = [self::chunk('a', 10), self::chunk('b', 10), self::chunk('c', 10), self::chunk('d', 10)];

        $capped = $this->contextRetriever([])->cap($chunks, new ContextLimits(3, 15000, 0));

        self::assertSame(['a', 'b', 'c'], self::firstLetters($capped));
    }

    public function testCapKeepsTheHitsWithinMaxChars(): void
    {
        $chunks = [self::chunk('a', 400), self::chunk('b', 400), self::chunk('c', 400), self::chunk('d', 100)];

        $capped = $this->contextRetriever([])->cap($chunks, new ContextLimits(10, 1000, 0));

        // a + b = 800, c would exceed 1000; the cap stops at the first chunk
        // that does not fit, in ranking order (d is not considered).
        self::assertSame(['a', 'b'], self::firstLetters($capped));
        self::assertLessThanOrEqual(1000, self::totalChars($capped));
    }

    public function testCapShortensAnOversizedFirstHit(): void
    {
        $chunks = [self::chunk('a', 5000), self::chunk('b', 10)];

        $capped = $this->contextRetriever([])->cap($chunks, new ContextLimits(10, 1200, 6000));

        self::assertCount(1, $capped);
        self::assertSame(1200, mb_strlen($capped[0]->content));
        self::assertSame(str_repeat('a', 1200), $capped[0]->content);
    }

    public function testRetrieveWithoutLimitsUsesTheBundleCapAndDoesNotExpand(): void
    {
        $handbook = self::collection();
        $retriever = $this->contextRetriever([
            self::hit('h1', $handbook, 0.9, 'raw-1'),
            self::hit('h2', $handbook, 0.8, 'raw-2'),
            self::hit('h3', $handbook, 0.7, 'raw-3'),
        ], maxChunks: 2, neighbours: self::neighbours(['raw-1' => ['before', 'after']]));

        $chunks = $retriever->retrieve('Wartung', [$handbook], 10);

        self::assertSame(['h1', 'h2'], array_map(static fn (ContextChunk $c): string => $c->content, $chunks));
    }

    public function testRetrieveExpandsTheCappedHitsWithinTheExpansionBudget(): void
    {
        $handbook = self::collection();
        $retriever = $this->contextRetriever([
            self::hit(str_repeat('a', 100), $handbook, 0.9, 'raw-1'),
            self::hit(str_repeat('b', 100), $handbook, 0.8, 'raw-2'),
            self::hit(str_repeat('c', 100), $handbook, 0.7, 'raw-3'),
        ], neighbours: self::neighbours([
            'raw-1' => [str_repeat('x', 48), str_repeat('y', 48)],
            'raw-2' => [str_repeat('z', 48), null],
        ]));
        $limits = new ContextLimits(maxChunks: 2, maxChars: 250, expansionMaxChars: 100);

        $chunks = $retriever->retrieve('Wartung', [$handbook], 10, limits: $limits);

        // Cap first: two hits (200 characters); then 100 characters of
        // neighbours plus separators: before and after of the first hit (50
        // each), nothing left for the second.
        self::assertCount(2, $chunks);
        self::assertSame(str_repeat('x', 48)."\n\n".str_repeat('a', 100)."\n\n".str_repeat('y', 48), $chunks[0]->content);
        self::assertSame(str_repeat('b', 100), $chunks[1]->content);
        self::assertLessThanOrEqual($limits->maxChars + $limits->expansionMaxChars, self::totalChars($chunks));

        // The measured pool is neither capped nor expanded.
        $pool = $retriever->pool('Wartung', [$handbook], 10);
        self::assertSame([str_repeat('a', 100), str_repeat('b', 100), str_repeat('c', 100)], array_map(static fn (ContextChunk $c): string => $c->content, $pool));
    }

    public function testExpansionBudgetZeroIsTheCappedHitsOnly(): void
    {
        $handbook = self::collection();
        $retriever = $this->contextRetriever([
            self::hit('h1', $handbook, 0.9, 'raw-1'),
        ], neighbours: self::neighbours(['raw-1' => ['before', 'after']]));
        $limits = new ContextLimits(8, 6000, 0);

        $chunks = $retriever->retrieve('Wartung', [$handbook], 10, limits: $limits);

        self::assertSame(['h1'], array_map(static fn (ContextChunk $c): string => $c->content, $chunks));
        self::assertEquals($retriever->cap($retriever->pool('Wartung', [$handbook], 10), $limits), $chunks);
    }

    /**
     * @param list<Document> $hits
     */
    private function contextRetriever(array $hits, int $maxChunks = 8, int $maxChars = 6000, ?NeighbourSourceInterface $neighbours = null): ContextRetriever
    {
        return new ContextRetriever(
            $this->collectionRetriever($hits),
            $this->createStub(EntityManagerInterface::class),
            $maxChunks,
            $maxChars,
            new ContextExpander($neighbours),
        );
    }

    /**
     * @param list<Document> $hits
     */
    private function collectionRetriever(array $hits): CollectionRetriever
    {
        return new CollectionRetriever(
            new EmbeddingsProviderLocator(new FakeProviderFactory($this->registry), $this->registry),
            $this->registry,
            new ContextShapingStore($hits),
            $this->createStub(EntityManagerInterface::class),
        );
    }

    /**
     * @param array<string, array{0: ?string, 1: ?string}> $texts chunk id => [before, after]
     */
    private static function neighbours(array $texts): NeighbourSourceInterface
    {
        return new class($texts) implements NeighbourSourceInterface {
            /**
             * @param array<string, array{0: ?string, 1: ?string}> $texts
             */
            public function __construct(private readonly array $texts)
            {
            }

            public function neighbours(string $chunkId): array
            {
                [$before, $after] = $this->texts[$chunkId] ?? [null, null];

                return [
                    'before' => null === $before ? null : new Neighbour($chunkId.'-before', $before),
                    'after' => null === $after ? null : new Neighbour($chunkId.'-after', $after),
                ];
            }
        };
    }

    private static function collection(): Collection
    {
        return new Collection()->setName('handbuch')->setEmbeddingModel('nomic-embed-text')->setEmbeddingDimension(768)->setEmbeddingProviderConnection(ProviderConnectionFixtures::ollama());
    }

    private static function hit(string $content, Collection $collection, float $score, string $chunkId): Document
    {
        return (new Document($content))
            ->setScore($score)
            ->setMetadata(['collection_id' => (string) $collection->getId(), 'chunk_id' => $chunkId]);
    }

    private static function chunk(string $letter, int $length): ContextChunk
    {
        return new ContextChunk(content: str_repeat($letter, $length), score: 1.0);
    }

    /**
     * @param list<ContextChunk> $chunks
     *
     * @return list<string>
     */
    private static function firstLetters(array $chunks): array
    {
        return array_map(static fn (ContextChunk $c): string => mb_substr($c->content, 0, 1), $chunks);
    }

    /**
     * @param list<ContextChunk> $chunks
     */
    private static function totalChars(array $chunks): int
    {
        return array_sum(array_map(static fn (ContextChunk $c): int => mb_strlen($c->content), $chunks));
    }
}

/**
 * Store double: answers every vector search with the scripted hits in their
 * order, cut to the scoped top-k. The in-memory SQLite connection is never
 * used.
 */
final class ContextShapingStore extends PgVectorStore
{
    /**
     * @param list<Document> $hits
     */
    public function __construct(private readonly array $hits)
    {
        parent::__construct(new \PDO('sqlite::memory:'), 'embedding', schema: EmbeddingDocumentSchema::schema());
    }

    public function search(SearchRequest $request): iterable
    {
        return \array_slice(array_map(static fn (Document $hit): Document => clone $hit, $this->hits), 0, $request->topK ?? $this->topK);
    }
}

// - revised 2026-10-14
