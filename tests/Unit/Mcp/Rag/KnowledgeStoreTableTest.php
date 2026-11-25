<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:52:48 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp\Rag;

use Dmstr\NeuronAiBundle\Tests\Support\RecordingPostgreSqlDriver;
use Dmstr\NeuronAiBundle\Mcp\Server\CollectionDocumentCounter;
use Dmstr\NeuronAiBundle\Mcp\Server\KnowledgeStoreTable;
use Dmstr\NeuronAiBundle\Mcp\Server\ChunkEmbeddings;
use PHPUnit\Framework\TestCase;

/**
 * The real store table reads of the knowledge MCP tools without database:
 * {@see KnowledgeStoreTable}, {@see ChunkEmbeddings} and
 * {@see CollectionDocumentCounter} on a real DBAL connection over
 * {@see RecordingPostgreSqlDriver}, so DBAL's own code runs (method lookup
 * on the connection, SQL parser, parameter expansion) and the rows are
 * shaped like pdo_pgsql returns them. The unit tests of the tools use
 * doubles for these classes; RagToolsDatabaseTest needs PostgreSQL.
 */
final class KnowledgeStoreTableTest extends TestCase
{
    private const string TABLE = 'embedding';

    private const string CHUNK = '0199c8a0-1111-7aaa-8bbb-000000000001';

    private const string HANDBOOK = '0199c8a0-2222-7aaa-8bbb-000000000002';

    private const string FAQ = '0199c8a0-3333-7aaa-8bbb-000000000003';

    public function testEmbeddingsSelectsByChunkAndMapsPostgresRows(): void
    {
        $driver = new RecordingPostgreSqlDriver(static fn (): array => [
            [
                'id' => '0199c8a0-4444-7aaa-8bbb-000000000004',
                'content' => 'Die Spindel muss alle 500 Stunden gewartet werden.',
                'source_type' => 'chunk',
                'source_name' => self::CHUNK,
                // jsonb comes back as text, keys in jsonb order
                'metadata' => '{"topic": "wartung", "chunk_id": "'.self::CHUNK.'", "source_url": "https://example.org/handbuch/wartung", "chunk_number": 1, "collection_id": "'.self::HANDBOOK.'"}',
                'model' => 'nomic-embed-text',
            ],
        ]);

        $embeddings = (new ChunkEmbeddings(new KnowledgeStoreTable($driver->connection(), self::TABLE)))->embeddings(self::CHUNK);

        self::assertSame([[
            'sql' => 'SELECT id, content, source_type, source_name, metadata, model FROM embedding WHERE metadata @> CAST(? AS jsonb)',
            'params' => [1 => '{"chunk_id":"'.self::CHUNK.'"}'],
        ]], $driver->queries);

        self::assertCount(1, $embeddings);
        self::assertSame('0199c8a0-4444-7aaa-8bbb-000000000004', $embeddings[0]->getId());
        self::assertSame('Die Spindel muss alle 500 Stunden gewartet werden.', $embeddings[0]->getContent());
        self::assertSame('chunk', $embeddings[0]->getSourceType());
        self::assertSame(self::CHUNK, $embeddings[0]->getSourceName());
        self::assertSame([
            'topic' => 'wartung',
            'chunk_id' => self::CHUNK,
            'source_url' => 'https://example.org/handbuch/wartung',
            'chunk_number' => 1,
            'collection_id' => self::HANDBOOK,
            'model' => 'nomic-embed-text',
        ], $embeddings[0]->getMetadata());
        self::assertNull($embeddings[0]->getScore());
    }

    public function testEmbeddingsOfAnUnknownChunkAreEmpty(): void
    {
        $driver = new RecordingPostgreSqlDriver(static fn (): array => []);

        self::assertSame([], (new ChunkEmbeddings(new KnowledgeStoreTable($driver->connection(), self::TABLE)))->embeddings(self::CHUNK));
        self::assertCount(1, $driver->queries);
    }

    public function testCollectionIdsSelectsTheDistinctCollectionsOfAChunk(): void
    {
        $driver = new RecordingPostgreSqlDriver(static fn (): array => [
            ['collection_id' => self::HANDBOOK],
            ['collection_id' => null],
            ['collection_id' => ''],
        ]);

        $ids = (new ChunkEmbeddings(new KnowledgeStoreTable($driver->connection(), self::TABLE)))->collectionIds(self::CHUNK);

        self::assertSame([self::HANDBOOK], $ids);
        self::assertSame([[
            'sql' => "SELECT DISTINCT metadata->>'collection_id' AS collection_id FROM embedding WHERE metadata @> CAST(? AS jsonb)",
            'params' => [1 => '{"chunk_id":"'.self::CHUNK.'"}'],
        ]], $driver->queries);
    }

    public function testCounterCountsEveryCollectionWithItsOwnContainmentQuery(): void
    {
        // count() comes back as int from pdo_pgsql; the faq has no rows.
        $driver = new RecordingPostgreSqlDriver(static fn (string $sql, array $params): array => str_contains((string) $params[1], self::HANDBOOK) ? [['count' => 2]] : [['count' => 0]]);

        $counts = (new CollectionDocumentCounter(new KnowledgeStoreTable($driver->connection(), self::TABLE)))->count([self::HANDBOOK, self::FAQ]);

        self::assertSame([self::HANDBOOK => 2, self::FAQ => 0], $counts);
        self::assertCount(2, $driver->queries);
        foreach ([self::HANDBOOK, self::FAQ] as $index => $id) {
            $query = $driver->queries[$index];
            self::assertMatchesRegularExpression('/^SELECT\s+count\(DISTINCT COALESCE\(.+\)\)\s+FROM embedding WHERE metadata @> CAST\(\? AS jsonb\)$/s', $query['sql']);
            self::assertSame([1 => '{"collection_id":"'.$id.'"}'], $query['params']);
        }
    }

    public function testCounterWithoutCollectionsDoesNotQuery(): void
    {
        $driver = new RecordingPostgreSqlDriver(static fn (): array => self::fail('no query expected'));

        self::assertSame([], (new CollectionDocumentCounter(new KnowledgeStoreTable($driver->connection(), self::TABLE)))->count([]));
    }
}

// - revised 2026-10-14
