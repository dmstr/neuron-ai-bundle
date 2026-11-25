<?php

// file generated with AI assistance: Claude Code - 2026-10-02 21:49:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Migration;

use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Checks the agent tables of dmstr/neuron-ai-bundle in the
 * schema the migrations produce
 * on the test database: both tables, the
 * unique slug, the unique version number per agent, the foreign keys
 * between agent and version (no cascade) and from
 * neuron_conversation.agent_version to the version, the nullable integer
 * current_version_number (the audited mirror), the executed migration,
 * no pending migration and no ORM diff for the three tables. The test only
 * inspects that result; it creates nothing itself.
 */
#[Group('database')]
final class AgentSchemaTest extends KernelTestCase
{
    private const BUNDLE_AGENT = 'Dmstr\\NeuronAiBundle\\Migrations\\Version20261002214946';

    private Connection $connection;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
    }

    public function testAgentTablesExist(): void
    {
        $tables = $this->connection->fetchFirstColumn(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'",
        );

        self::assertContains('neuron_agent', $tables);
        self::assertContains('neuron_agent_version', $tables);
    }

    public function testAgentSlugIsUnique(): void
    {
        $indexes = $this->indexes('neuron_agent');

        self::assertArrayHasKey('uniq_neuron_agent_slug', $indexes);
        self::assertStringContainsString('UNIQUE INDEX', $indexes['uniq_neuron_agent_slug']);
        self::assertStringContainsString('(slug)', $indexes['uniq_neuron_agent_slug']);
        self::assertArrayHasKey('idx_neuron_agent_current_version', $indexes);
        self::assertStringContainsString('(current_version_id)', $indexes['idx_neuron_agent_current_version']);
    }

    public function testVersionNumberIsUniquePerAgent(): void
    {
        $indexes = $this->indexes('neuron_agent_version');

        // Partial since the preview versions (Version20261013210000): the
        // number is unique among published versions, the candidate hash
        // among previews.
        self::assertArrayHasKey('uniq_neuron_agent_version_agent_number', $indexes);
        self::assertStringContainsString('UNIQUE INDEX', $indexes['uniq_neuron_agent_version_agent_number']);
        self::assertStringContainsString('(agent_id, version_number)', $indexes['uniq_neuron_agent_version_agent_number']);
        self::assertStringEndsWith("WHERE ((kind)::text = 'published'::text)", $indexes['uniq_neuron_agent_version_agent_number']);
        self::assertArrayHasKey('uniq_neuron_agent_version_agent_hash', $indexes);
        self::assertStringContainsString('UNIQUE INDEX', $indexes['uniq_neuron_agent_version_agent_hash']);
        self::assertStringContainsString('(agent_id, candidate_hash)', $indexes['uniq_neuron_agent_version_agent_hash']);
        self::assertStringEndsWith("WHERE ((kind)::text = 'preview'::text)", $indexes['uniq_neuron_agent_version_agent_hash']);
        self::assertArrayHasKey('idx_neuron_agent_version_agent', $indexes);
        self::assertStringContainsString('(agent_id)', $indexes['idx_neuron_agent_version_agent']);
    }

    public function testKindDefaultsToPublishedAndCandidateHashIsNullable(): void
    {
        self::assertSame(["'published'::character varying", 'NO', 'character varying', '16'], array_values($this->connection->fetchNumeric(
            "SELECT column_default, is_nullable, data_type, character_maximum_length::text FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'neuron_agent_version' AND column_name = 'kind'",
        ) ?: []));
        self::assertSame(['YES', 'character varying', '64'], array_values($this->connection->fetchNumeric(
            "SELECT is_nullable, data_type, character_maximum_length::text FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'neuron_agent_version' AND column_name = 'candidate_hash'",
        ) ?: []));
    }

    public function testEnabledDefaultsToTrue(): void
    {
        self::assertSame(['true', 'NO'], array_values($this->connection->fetchNumeric(
            "SELECT column_default, is_nullable FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'neuron_agent' AND column_name = 'enabled'",
        ) ?: []));
    }

    public function testCurrentVersionNumberIsNullableInteger(): void
    {
        self::assertSame(['integer', 'YES'], array_values($this->connection->fetchNumeric(
            "SELECT data_type, is_nullable FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'neuron_agent' AND column_name = 'current_version_number'",
        ) ?: []));
    }

    public function testVersionReferencesAgentWithoutCascade(): void
    {
        // confdeltype 'a': NO ACTION.
        self::assertSame(['neuron_agent', 'a'], $this->foreignKey('neuron_agent_version', 'agent_id'));
    }

    public function testAgentReferencesItsCurrentVersionWithoutCascade(): void
    {
        self::assertSame(['neuron_agent_version', 'a'], $this->foreignKey('neuron_agent', 'current_version_id'));
    }

    public function testConversationReferencesTheAgentVersion(): void
    {
        self::assertSame(['neuron_agent_version', 'a'], $this->foreignKey('neuron_conversation', 'agent_version'));

        $indexes = $this->indexes('neuron_conversation');
        self::assertArrayHasKey('idx_604506e26d4fb169', $indexes);
        self::assertStringContainsString('(agent_version)', $indexes['idx_604506e26d4fb169']);
    }

    public function testOrmSchemaToolReportsNoDiffForAgentTables(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine.orm.entity_manager');

        $statements = (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata());

        foreach ($statements as $statement) {
            self::assertDoesNotMatchRegularExpression('/neuron_agent|neuron_agent_version|neuron_conversation/', $statement);
        }
    }

    public function testAgentMigrationIsExecutedAndNothingIsPending(): void
    {
        /** @var DependencyFactory $factory */
        $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');

        self::assertCount(0, $factory->getMigrationStatusCalculator()->getNewMigrations());

        $executed = $this->connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions');
        self::assertContains(self::BUNDLE_AGENT, $executed);
    }

    /**
     * @return array<string, string> index name (as PostgreSQL stores it, i.e. lower case) => definition
     */
    private function indexes(string $table): array
    {
        return $this->connection->fetchAllKeyValue(
            "SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = ?",
            [$table],
        );
    }

    /**
     * Referenced table and ON DELETE action of the single-column foreign key.
     *
     * @return list<string>
     */
    private function foreignKey(string $table, string $column): array
    {
        return array_values($this->connection->fetchNumeric(
            "SELECT confrelid::regclass::text, confdeltype::text FROM pg_constraint
             WHERE conrelid = ?::regclass AND contype = 'f'
               AND conkey = ARRAY[(SELECT attnum FROM pg_attribute WHERE attrelid = ?::regclass AND attname = ?)]",
            [$table, $table, $column],
        ) ?: []);
    }
}
