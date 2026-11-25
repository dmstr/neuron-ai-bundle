<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:16:39 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp\Rag;

use ApiPlatform\Metadata\McpTool;
use Dmstr\NeuronAiBundle\Tests\Support\FixedMcpEndpointPolicy;
use Dmstr\NeuronAiBundle\Tests\Support\InMemoryCollectionReferenceResolver;
use Dmstr\NeuronAiBundle\Tests\Support\InMemoryChunkEmbeddings;
use Dmstr\NeuronAiBundle\Tests\Support\SpyNeighbourSource;
use Dmstr\NeuronAiBundle\Conversation\ContextChunk;
use Dmstr\McpEndpoint\Context\McpEndpointContext;
use Dmstr\McpEndpoint\Entity\McpEndpoint;
use Dmstr\NeuronAiBundle\Mcp\Server\CollectionDocumentCounter;
use Dmstr\NeuronAiBundle\Mcp\Server\Dto\KnowledgeCollection;
use Dmstr\NeuronAiBundle\Mcp\Server\Dto\KnowledgeContextInput;
use Dmstr\NeuronAiBundle\Mcp\Server\Dto\KnowledgeSearchInput;
use Dmstr\NeuronAiBundle\Mcp\Server\EndpointRetrievalScope;
use Dmstr\NeuronAiBundle\Mcp\Server\KnowledgeStoreTable;
use Dmstr\NeuronAiBundle\Mcp\Server\State\KnowledgeCollectionsProvider;
use Dmstr\NeuronAiBundle\Mcp\Server\State\KnowledgeContextProcessor;
use Dmstr\NeuronAiBundle\Mcp\Server\State\KnowledgeSearchProcessor;
use Doctrine\DBAL\Connection;
use Dmstr\NeuronAiBundle\Retrieval\Neighbour;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provider of `rag_list_collections` and processors of `rag_search` and
 * `rag_get_context` without database: the listing skips dead ids and
 * carries the document counts, every refused search returns before
 * KnowledgeSearch is created (its closure fails the test when called), and
 * every refused context lookup returns before the neighbour source is asked
 * (SpyNeighbourSource), without endpoint or scope even before the store is
 * read. Calls that reach the store: RagToolsDatabaseTest (CI).
 */
final class KnowledgeToolStateTest extends TestCase
{
    private const string HANDBOOK_ID = '0199c0de-0000-7000-8000-0000000000a1';
    private const string FAQ_ID = '0199c0de-0000-7000-8000-0000000000a2';
    private const string DEAD_ID = '0199c0de-0000-7000-8000-0000000000de';

    private const string RAW_ID = '0199c0de-0000-7000-8000-0000000000b1';
    private const string BEFORE_ID = '0199c0de-0000-7000-8000-0000000000b0';
    private const string AFTER_ID = '0199c0de-0000-7000-8000-0000000000b2';

    private McpEndpointContext $context;

    private EndpointRetrievalScope $scope;

    protected function setUp(): void
    {
        $this->context = new McpEndpointContext();
        $handbook = InMemoryCollectionReferenceResolver::make('handbook', self::HANDBOOK_ID)->setDescription('Operating manual');
        $this->scope = new EndpointRetrievalScope(
            $this->context,
            new FixedMcpEndpointPolicy(),
            new InMemoryCollectionReferenceResolver(
                $this->createStub(EntityManagerInterface::class),
                $handbook,
                InMemoryCollectionReferenceResolver::make('faq', self::FAQ_ID),
            ),
        );
    }

    public function testListSkipsDeadIdsAndCountsDocuments(): void
    {
        $this->endpoint([self::DEAD_ID, self::HANDBOOK_ID]);
        $counter = new class($this->createStub(Connection::class)) extends CollectionDocumentCounter {
            /** @var list<list<string>> */
            public array $calls = [];

            public function __construct(Connection $connection)
            {
                parent::__construct(new KnowledgeStoreTable($connection, 'store'));
            }

            public function count(array $collectionIds): array
            {
                $this->calls[] = $collectionIds;

                return array_fill_keys($collectionIds, 4);
            }
        };

        $list = (new KnowledgeCollectionsProvider($this->scope, $counter))->provide(new McpTool());

        self::assertEquals([new KnowledgeCollection(self::HANDBOOK_ID, 'handbook', 'Operating manual', 4, 'nomic-embed-text')], $list->collections);
        self::assertSame([[self::HANDBOOK_ID]], $counter->calls);
    }

    public function testListWithoutEndpointIsEmptyAndCountsNothing(): void
    {
        $counter = new class($this->createStub(Connection::class)) extends CollectionDocumentCounter {
            public function __construct(Connection $connection)
            {
                parent::__construct(new KnowledgeStoreTable($connection, 'store'));
            }

            public function count(array $collectionIds): array
            {
                throw new \LogicException('No count without collections.');
            }
        };

        self::assertSame([], (new KnowledgeCollectionsProvider($this->scope, $counter))->provide(new McpTool())->collections);
    }

    public function testSearchOutsideScopeIsNotFoundBeforeSearching(): void
    {
        $this->endpoint([self::HANDBOOK_ID]);

        $this->expectExceptionObject(new NotFoundHttpException('Collection "faq" not found'));

        $this->processor()->process(self::input('faq'), new McpTool());
    }

    public function testSearchOfAnEmptyScopeFindsNothing(): void
    {
        $this->endpoint([self::DEAD_ID]);

        self::assertSame([], $this->processor()->process(self::input(), new McpTool())->hits);
    }

    public function testSearchWithoutEndpointFindsNothing(): void
    {
        self::assertSame([], $this->processor()->process(self::input(), new McpTool())->hits);
    }

    public function testContextWithoutEndpointIsNotFoundWithoutReading(): void
    {
        [$chunks, $neighbours] = $this->contextDoubles();

        $this->assertContextNotFound(self::RAW_ID, $chunks, $neighbours);
        self::assertSame([], $chunks->reads, 'No store read without endpoint.');
    }

    public function testContextOfAnEmptyScopeIsNotFoundWithoutReading(): void
    {
        $this->endpoint([]);
        [$chunks, $neighbours] = $this->contextDoubles();

        $this->assertContextNotFound(self::RAW_ID, $chunks, $neighbours);
        self::assertSame([], $chunks->reads, 'No store read with an empty scope.');
    }

    /** Chunks of a collection outside the scope, unknown ids and a deleted scope collection answer alike. */
    public function testContextOutsideScopeUnknownOrDeadIsNotFound(): void
    {
        $this->endpoint([self::HANDBOOK_ID, self::DEAD_ID]);
        [$chunks, $neighbours] = $this->contextDoubles();
        $chunks->add(self::RAW_ID, self::FAQ_ID, 'FAQ-Absatz', 0);
        $dead = '0199c0de-0000-7000-8000-0000000000d1';
        $chunks->add($dead, self::DEAD_ID, 'Absatz einer gelöschten Collection', 0);

        foreach ([self::RAW_ID, '0199c0de-0000-7000-8000-0000000000ff', $dead] as $id) {
            $this->assertContextNotFound($id, $chunks, $neighbours);
        }
    }

    /** Chunks only of another embedding model (pending reindex target) are not searchable: not found. */
    public function testContextWithoutChunksOfTheCurrentModelIsNotFound(): void
    {
        $this->endpoint([self::HANDBOOK_ID]);
        [$chunks, $neighbours] = $this->contextDoubles();
        $chunks->add(self::RAW_ID, self::HANDBOOK_ID, 'Absatz', 0, 'bge-m3');

        $this->assertContextNotFound(self::RAW_ID, $chunks, $neighbours);
    }

    public function testContextReturnsChunksInOrderAndNeighboursInScope(): void
    {
        $this->endpoint([self::HANDBOOK_ID]);
        [$chunks, $neighbours] = $this->contextDoubles();
        $chunks
            ->add(self::RAW_ID, self::HANDBOOK_ID, 'Teil 2', 1)
            ->add(self::RAW_ID, self::HANDBOOK_ID, 'Teil 2 (neues Modell)', 1, 'bge-m3')
            ->add(self::RAW_ID, self::HANDBOOK_ID, 'Teil 1', 0)
            ->add(self::BEFORE_ID, self::HANDBOOK_ID, 'Vorher', 0)
            // A neighbour whose chunks lie outside the scope is dropped.
            ->add(self::AFTER_ID, self::FAQ_ID, 'Nachher', 0);

        $context = $this->contextProcessor($chunks, $neighbours)->process(self::contextInput(strtoupper(self::RAW_ID)), new McpTool());

        self::assertSame(['Teil 1', 'Teil 2'], array_map(static fn (ContextChunk $chunk): string => $chunk->content, $context->hit));
        foreach ($context->hit as $chunk) {
            self::assertSame(self::HANDBOOK_ID, $chunk->collectionId);
            self::assertSame('handbook', $chunk->collectionName);
            self::assertSame(self::RAW_ID, $chunk->chunkId);
            self::assertSame(KnowledgeContextProcessor::SCORE, $chunk->score);
        }
        self::assertEquals(new Neighbour(self::BEFORE_ID, 'Vorher'), $context->before);
        self::assertNull($context->after);
        self::assertSame([self::RAW_ID], $neighbours->calls, 'Neighbours of the normalized id, asked once.');
    }

    /** Without an application neighbour source the hit comes alone. */
    public function testContextWithoutNeighbourSource(): void
    {
        $this->endpoint([self::HANDBOOK_ID]);
        [$chunks] = $this->contextDoubles();
        $chunks->add(self::RAW_ID, self::HANDBOOK_ID, 'Teil 1', 0);

        $context = (new KnowledgeContextProcessor($this->scope, $chunks))->process(self::contextInput(self::RAW_ID), new McpTool());

        self::assertCount(1, $context->hit);
        self::assertNull($context->before);
        self::assertNull($context->after);
    }

    /**
     * @param list<string> $collectionIds
     */
    private function endpoint(array $collectionIds): void
    {
        $this->context->set((new McpEndpoint())->setSlug('rag')->setScope(['collections' => $collectionIds]));
    }

    private function processor(): KnowledgeSearchProcessor
    {
        return new KnowledgeSearchProcessor($this->scope, static fn () => self::fail('KnowledgeSearch must not be created.'));
    }

    private static function input(?string $collection = null): KnowledgeSearchInput
    {
        $input = new KnowledgeSearchInput();
        $input->query = 'Wartung';
        $input->collection = $collection;

        return $input;
    }

    /**
     * Store chunks and a neighbour source whose neighbours exist (BEFORE_ID,
     * AFTER_ID); whether they are returned depends on their chunks.
     *
     * @return array{InMemoryChunkEmbeddings, SpyNeighbourSource}
     */
    private function contextDoubles(): array
    {
        return [
            new InMemoryChunkEmbeddings($this->createStub(Connection::class)),
            new SpyNeighbourSource(new Neighbour(self::BEFORE_ID, 'Vorher'), new Neighbour(self::AFTER_ID, 'Nachher')),
        ];
    }

    private function contextProcessor(InMemoryChunkEmbeddings $chunks, SpyNeighbourSource $neighbours): KnowledgeContextProcessor
    {
        return new KnowledgeContextProcessor($this->scope, $chunks, $neighbours);
    }

    private function assertContextNotFound(string $chunkId, InMemoryChunkEmbeddings $chunks, SpyNeighbourSource $neighbours): void
    {
        try {
            $this->contextProcessor($chunks, $neighbours)->process(self::contextInput($chunkId), new McpTool());
            self::fail(\sprintf('Chunk %s must be not found.', $chunkId));
        } catch (NotFoundHttpException $exception) {
            self::assertSame(\sprintf('Chunk "%s" not found', $chunkId), $exception->getMessage());
        }
        self::assertSame([], $neighbours->calls, 'A refused call never asks the neighbour source.');
    }

    private static function contextInput(string $chunkId): KnowledgeContextInput
    {
        $input = new KnowledgeContextInput();
        $input->chunkId = $chunkId;

        return $input;
    }
}
// - revised 2026-10-09 16:30:10 UTC
