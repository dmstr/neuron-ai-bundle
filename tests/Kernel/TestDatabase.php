<?php

// file generated with AI assistance: Claude Code - 2026-11-25 21:50:20 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Kernel;

use Dmstr\NeuronPgvectorStore\PgVectorStore;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Schema and isolation of the test database (tests of the group
 * `database`).
 *
 * {@see self::migrate()} creates the schema once per PHPUnit process: all
 * registered migrations (`doctrine:migrations:migrate`) of
 * dmstr/api-configuration-bundle, dmstr/symfony-job-queue-bundle,
 * dmstr/doctrine-audit-log-bundle and this
 * bundle's `Dmstr\NeuronAiBundle\Migrations\*`, then the store table
 * {@see TestKernel::STORE_TABLE} with `PgVectorStore::setupTable()` (the
 * extension `vector`, the table and its keyword indexes; no migration owns
 * it). Already executed migrations are skipped and the table is created
 * only when missing, so a database reused between runs stays valid.
 *
 * Isolation: all database tests share one database. A test that depends on
 * the set of all rows of a table (lists, counts, "the only connection")
 * calls {@see self::purge()} in setUp(); tests that only read their own
 * fixtures clean up by name prefix as before. {@see self::truncate()}
 * empties single tables.
 *
 *     protected function setUp(): void
 *     {
 *         static::bootKernel();
 *         TestDatabase::migrate(static::$kernel);
 *         TestDatabase::purge(TestDatabase::connection(static::$kernel));
 *     }
 */
final class TestDatabase
{
    /**
     * Tables the tests write. The schema and the migrations metadata stay;
     * TRUNCATE ... CASCADE takes care of the order.
     */
    public const array TABLES = [
        TestKernel::STORE_TABLE,
        'neuron_agent',
        'neuron_agent_version',
        'neuron_artifact',
        'neuron_collection',
        'neuron_conversation',
        'neuron_conversation_replay',
        'neuron_eval_question',
        'neuron_eval_result',
        'neuron_eval_run',
        'neuron_evaluation',
        'neuron_feedback',
        'neuron_mcp_tool',
        'neuron_message',
        'neuron_provider_model',
        'neuron_stream_event',
        'neuron_suggestion',
        'dmstr_job',
        'dmstr_log_entries',
        'dmstr_api_configuration',
    ];

    private static bool $migrated = false;

    public static function migrate(KernelInterface $kernel): void
    {
        if (self::$migrated) {
            return;
        }

        self::run($kernel);
        self::setUpStoreTable($kernel);
        self::$migrated = true;
    }

    /**
     * Runs `doctrine:migrations:migrate` unconditionally and returns its
     * output; throws when the command fails.
     */
    public static function run(KernelInterface $kernel): string
    {
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $exitCode = $application->run(new ArrayInput([
            'command' => 'doctrine:migrations:migrate',
            '--no-interaction' => true,
            '--allow-no-migration' => true,
        ]), $output);

        $display = $output->fetch();
        if (0 !== $exitCode) {
            throw new \RuntimeException('Running the migrations failed: '.$display);
        }

        return $display;
    }

    /**
     * Creates the store table with the store of the container
     * (`PgVectorStore::setupTable()`, idempotent).
     */
    public static function setUpStoreTable(KernelInterface $kernel): void
    {
        $store = $kernel->getContainer()->get('test.service_container')->get(PgVectorStore::class);
        if (!$store instanceof PgVectorStore) {
            throw new \LogicException('The container holds no PgVectorStore.');
        }

        $store->setupTable();
    }

    /**
     * Deletes every row of {@see self::TABLES} in one statement (TRUNCATE
     * ... CASCADE also empties the reference rows of other bundles that
     * point at them). Tables that do not exist are skipped.
     */
    public static function purge(Connection $connection): void
    {
        self::truncate($connection, ...self::TABLES);
    }

    /**
     * Empties the given tables (and, through CASCADE, the rows that
     * reference them); missing tables are skipped.
     */
    public static function truncate(Connection $connection, string ...$tables): void
    {
        $existing = array_values(array_filter(
            $tables,
            static fn (string $table): bool => null !== $connection->fetchOne('SELECT to_regclass(:table)::text', ['table' => $table]),
        ));
        if ([] === $existing) {
            return;
        }

        $connection->executeStatement('TRUNCATE TABLE '.implode(', ', array_map($connection->quoteIdentifier(...), $existing)).' CASCADE');
    }

    public static function connection(KernelInterface $kernel): Connection
    {
        $connection = $kernel->getContainer()->get('test.service_container')->get('doctrine.dbal.default_connection');
        if (!$connection instanceof Connection) {
            throw new \LogicException('The container holds no DBAL connection.');
        }

        return $connection;
    }
}
