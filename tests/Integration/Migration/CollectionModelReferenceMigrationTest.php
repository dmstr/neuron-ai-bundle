<?php

// file generated with AI assistance: Claude Code - 2026-10-08 20:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Migration;

use Dmstr\NeuronAiBundle\Provider\ModelReferenceMigrationSql;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\AbortMigration;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The data part of the model reference migration of slice 3 (DB, CI
 * only): `Version20261008200000` (bundle) gives every collection with a
 * connection and a label the catalogue entry of that pair in
 * `embedding_model_id`, and the pending pair of a running reindex its
 * entry in `pending_embedding_model_id`, with the building blocks of
 * {@see ModelReferenceMigrationSql}: the exact name first, the label
 * `nomic-embed-text` vs. the entry `nomic-embed-text:latest`, placeholder
 * entries (one per pair, untagged Ollama names with `:latest`); a pending
 * label without a pending connection stays without a pending entry. The
 * labels stay as they were, and `embedding_model_id` ends NOT NULL. A
 * collection without a connection makes up() abort before any statement
 * ran, with the count and the query that lists it. The test runs down()
 * first (the test schema is migrated, its column NOT NULL), writes the
 * collections in the shape before the migration, then runs up().
 *
 * Everything runs in a transaction that tearDown() rolls back (PostgreSQL
 * DDL included), so the statements, which touch every row of the table,
 * leave the test database as it was.
 */
#[Group('database')]
final class CollectionModelReferenceMigrationTest extends KernelTestCase
{
    private const string MIGRATION = 'Dmstr\\NeuronAiBundle\\Migrations\\Version20261008200000';

    private const string PREFIX = 'test-coll-model-ref-migration-';

    private const string OLLAMA = 'e4000000-0000-4000-8000-000000000001';
    private const string REMOTE = 'e4000000-0000-4000-8000-000000000002';

    private const string NOMIC_LATEST = 'f4000000-0000-4000-8000-000000000001';
    private const string MXBAI = 'f4000000-0000-4000-8000-000000000002';
    private const string MXBAI_LATEST = 'f4000000-0000-4000-8000-000000000003';

    private Connection $connection;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection->beginTransaction();

        foreach ([self::OLLAMA, self::REMOTE] as $id) {
            $this->connection->executeStatement(
                "INSERT INTO dmstr_api_configuration (id, name, type, endpoint_type, config_json, active, created_at, updated_at)
                 VALUES (?, ?, 'ollama', 'rest', ?, true, NOW(), NOW())",
                [$id, self::PREFIX.substr($id, -1), json_encode(['type' => 'ollama', 'endpoint_type' => 'rest'], \JSON_THROW_ON_ERROR)],
            );
        }

        foreach ([
            [self::NOMIC_LATEST, 'nomic-embed-text:latest'],
            [self::MXBAI, 'mxbai-embed-large'],
            [self::MXBAI_LATEST, 'mxbai-embed-large:latest'],
        ] as [$id, $name]) {
            $this->connection->executeStatement(
                "INSERT INTO neuron_provider_model (id, api_configuration_id, name, endpoint_fingerprint, available, created_at, updated_at)
                 VALUES (?, ?, ?, 'sha256:test', true, NOW(), NOW())",
                [$id, self::OLLAMA, $name],
            );
        }
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testCollectionsGetTheEntriesOfTheirCurrentAndPendingPairs(): void
    {
        $this->migrate('down');
        self::assertFalse($this->hasColumn('embedding_model_id'), 'down() drops the columns');
        self::assertFalse($this->hasColumn('pending_embedding_model_id'));

        $tagged = $this->collection('tagged', self::OLLAMA, 'nomic-embed-text');
        $exact = $this->collection('exact', self::OLLAMA, 'mxbai-embed-large');
        $remoteA = $this->collection('remote-a', self::REMOTE, 'nomic-embed-text');
        $remoteB = $this->collection('remote-b', self::REMOTE, 'nomic-embed-text', 'mxbai-embed-large', self::OLLAMA);
        $pendingPlaceholder = $this->collection('pending', self::OLLAMA, 'nomic-embed-text', 'bge-m3', self::REMOTE);
        $pendingWithoutConnection = $this->collection('pending-legacy', self::OLLAMA, 'nomic-embed-text', 'mxbai-embed-large', null);

        $this->migrate('up');
        self::assertTrue($this->hasColumn('embedding_model_id'));
        self::assertTrue($this->hasColumn('pending_embedding_model_id'));
        self::assertSame('NO', $this->nullable('embedding_model_id'), 'The current entry is NOT NULL.');
        self::assertSame('YES', $this->nullable('pending_embedding_model_id'), 'A pending target exists only during a reindex.');

        self::assertSame([self::NOMIC_LATEST, null], $this->entriesOf($tagged), 'The label nomic-embed-text matches the entry nomic-embed-text:latest.');
        self::assertSame([self::MXBAI, null], $this->entriesOf($exact), 'The exact name wins over the tagged one.');

        $remoteNomic = $this->entry(self::REMOTE, 'nomic-embed-text:latest');
        self::assertNotNull($remoteNomic, 'An untagged label gets a placeholder with :latest.');
        self::assertSame([false, ModelReferenceMigrationSql::PLACEHOLDER_FINGERPRINT], [$remoteNomic['available'], $remoteNomic['endpoint_fingerprint']]);
        self::assertSame([$remoteNomic['id'], null], $this->entriesOf($remoteA));
        self::assertSame([$remoteNomic['id'], self::MXBAI], $this->entriesOf($remoteB), 'One placeholder per pair; the pending pair gets its entry.');

        $bge = $this->entry(self::REMOTE, 'bge-m3:latest');
        self::assertNotNull($bge, 'A pending pair without an entry gets a placeholder.');
        self::assertSame([self::NOMIC_LATEST, $bge['id']], $this->entriesOf($pendingPlaceholder));

        self::assertSame([self::NOMIC_LATEST, null], $this->entriesOf($pendingWithoutConnection), 'A pending label without a pending connection gets no entry.');

        // The labels stay as they were: rows, chunk ids and indexes are keyed by them.
        self::assertSame(
            ['embedding_model' => 'nomic-embed-text', 'pending_embedding_model' => 'mxbai-embed-large'],
            $this->connection->fetchAssociative('SELECT embedding_model, pending_embedding_model FROM neuron_collection WHERE id = ?', [$remoteB]),
        );
    }

    /**
     * A collection whose current model cannot get an entry (no connection,
     * stored before provider connections) is not deleted: up() aborts
     * before any statement ran, names the count and the query that lists
     * it, and the operator removes it before migrating again.
     */
    public function testUpAbortsForACollectionWithoutConnection(): void
    {
        $this->migrate('down');
        $this->collection('fine', self::OLLAMA, 'nomic-embed-text');
        $legacy = $this->collection('legacy', null, 'nomic-embed-text');
        $entries = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM neuron_provider_model');

        try {
            $this->migrate('up');
            self::fail('Expected an AbortMigration.');
        } catch (AbortMigration $e) {
            self::assertStringStartsWith('1 collection(s) cannot get an entry of the model catalogue', $e->getMessage());
            self::assertStringContainsString('neuron_collection.embedding_model_id cannot become NOT NULL', $e->getMessage());
            $query = substr($e->getMessage(), (int) strpos($e->getMessage(), 'List them with: ') + \strlen('List them with: '));
            self::assertStringStartsWith('SELECT c.id, c.name FROM neuron_collection c WHERE ', $query);
            $listed = array_column($this->connection->fetchAllAssociative('SELECT x.id::text AS id FROM ('.$query.') x'), 'id');
            self::assertSame([$legacy], $listed, 'The query of the message lists exactly the collections in the way.');
        }

        self::assertFalse($this->hasColumn('embedding_model_id'), 'Nothing ran.');
        self::assertSame($entries, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM neuron_provider_model'), 'No placeholder was inserted.');

        // Once the operator removed it, the migration runs.
        $this->connection->executeStatement('DELETE FROM neuron_collection WHERE id = ?', [$legacy]);
        $this->migrate('up');
        self::assertSame('NO', $this->nullable('embedding_model_id'));
    }

    /**
     * Runs the statements of the migration's up() or down().
     */
    private function migrate(string $direction): void
    {
        require_once \dirname(__DIR__, 3).'/migrations/Version20261008200000.php';
        $class = self::MIGRATION;
        /** @var AbstractMigration $migration */
        $migration = new $class($this->connection, new NullLogger());
        $migration->{$direction}(new Schema());

        self::assertNotEmpty($migration->getSql());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /**
     * A collection in the shape before the model reference: connection and
     * label, optionally a pending label and connection, no entries. Only
     * after down(): the migrated schema has the NOT NULL entry column.
     */
    private function collection(string $name, ?string $connection, string $label, ?string $pending = null, ?string $pendingConnection = null): string
    {
        $id = (string) $this->connection->fetchOne('SELECT gen_random_uuid()::text');
        $this->connection->executeStatement(
            "INSERT INTO neuron_collection (id, name, embedding_model, embedding_dimension, pending_embedding_model, embedding_connection_id, pending_embedding_connection_id, keyword_language, created_at, updated_at)
             VALUES (?, ?, ?, 768, ?, ?, ?, 'german', NOW(), NOW())",
            [$id, self::PREFIX.$name, $label, $pending, $connection, $pendingConnection],
        );

        return $id;
    }

    /**
     * @return array{?string, ?string} the current and the pending entry of the collection
     */
    private function entriesOf(string $collection): array
    {
        $row = $this->connection->fetchAssociative('SELECT embedding_model_id::text AS current, pending_embedding_model_id::text AS pending FROM neuron_collection WHERE id = ?', [$collection]);
        self::assertIsArray($row);

        return [\is_string($row['current']) ? $row['current'] : null, \is_string($row['pending']) ? $row['pending'] : null];
    }

    /**
     * `YES` or `NO`: whether the column of `neuron_collection` is nullable.
     */
    private function nullable(string $column): string
    {
        return (string) $this->connection->fetchOne(
            "SELECT is_nullable FROM information_schema.columns WHERE table_name = 'neuron_collection' AND column_name = ?",
            [$column],
        );
    }

    private function hasColumn(string $column): bool
    {
        return false !== $this->connection->fetchOne(
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'neuron_collection' AND column_name = ?",
            [$column],
        );
    }

    /**
     * The catalogue entry of a connection and an exact name.
     *
     * @return array{id: string, available: bool, endpoint_fingerprint: string}|null
     */
    private function entry(string $connection, string $name): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id::text AS id, available, endpoint_fingerprint FROM neuron_provider_model WHERE api_configuration_id = ? AND name = ?',
            [$connection, $name],
        );
        if (false === $row) {
            return null;
        }

        return ['id' => (string) $row['id'], 'available' => (bool) $row['available'], 'endpoint_fingerprint' => (string) $row['endpoint_fingerprint']];
    }
}
