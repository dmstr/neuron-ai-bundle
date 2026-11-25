<?php

// file generated with AI assistance: Claude Code - 2026-10-03 21:15:00 UTC

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
 * Checks the feedback table of dmstr/neuron-ai-bundle in the
 * schema the migrations produce
 * on the test database: neuron_feedback
 * with its nullability, the indexes, the foreign keys with their ON DELETE
 * actions (conversation CASCADE, message SET NULL), the review columns
 * `status` and `review_note`, the executed migration
 * and no ORM diff for the table. The test only inspects that result; it
 * creates nothing itself.
 */
#[Group('database')]
final class FeedbackSchemaTest extends KernelTestCase
{
    private const BUNDLE_FEEDBACK = 'Dmstr\\NeuronAiBundle\\Migrations\\Version20261003211500';

    private Connection $connection;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
    }

    public function testFeedbackColumns(): void
    {
        $columns = $this->columns('neuron_feedback');

        self::assertSame(['uuid', 'NO', null, null], $columns['id'] ?? null);
        self::assertSame(['uuid', 'NO', null, null], $columns['conversation_id'] ?? null);
        self::assertSame(['uuid', 'YES', null, null], $columns['message_id'] ?? null);
        self::assertSame(['character varying', 'YES', null, 10], $columns['rating'] ?? null);
        self::assertSame(['character varying', 'YES', null, 255], $columns['reason'] ?? null);
        self::assertSame(['text', 'YES', null, null], $columns['comment'] ?? null);
        self::assertSame(['character varying', 'NO', null, 20], $columns['source'] ?? null);
        self::assertSame(['character varying', 'NO', null, 255], $columns['subject'] ?? null);
        self::assertSame(['timestamp with time zone', 'NO', null, null], $columns['created_at'] ?? null);
        self::assertSame(['character varying', 'NO', "'open'::character varying", 20], $columns['status'] ?? null);
        self::assertSame(['text', 'YES', null, null], $columns['review_note'] ?? null);
        self::assertCount(11, $columns);
    }

    public function testFeedbackIndexes(): void
    {
        $indexes = $this->connection->fetchAllKeyValue(
            "SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'neuron_feedback'",
        );

        self::assertArrayHasKey('idx_neuron_feedback_conversation', $indexes);
        self::assertStringContainsString('(conversation_id)', $indexes['idx_neuron_feedback_conversation']);
        self::assertArrayHasKey('idx_neuron_feedback_message', $indexes);
        self::assertStringContainsString('(message_id)', $indexes['idx_neuron_feedback_message']);
    }

    public function testForeignKeysAndTheirDeleteActions(): void
    {
        // confdeltype: 'r' RESTRICT, 'c' CASCADE, 'n' SET NULL.
        self::assertSame(['neuron_conversation', 'c'], $this->foreignKey('neuron_feedback', 'conversation_id'));
        self::assertSame(['neuron_message', 'n'], $this->foreignKey('neuron_feedback', 'message_id'));
    }

    public function testOrmSchemaToolReportsNoDiffForFeedbackTable(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine.orm.entity_manager');

        $statements = (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata());

        foreach ($statements as $statement) {
            self::assertDoesNotMatchRegularExpression('/neuron_feedback/', $statement);
        }
    }

    public function testFeedbackMigrationIsExecutedAndNothingIsPending(): void
    {
        /** @var DependencyFactory $factory */
        $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');

        self::assertCount(0, $factory->getMigrationStatusCalculator()->getNewMigrations());

        $executed = $this->connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions');
        self::assertContains(self::BUNDLE_FEEDBACK, $executed);
    }

    /**
     * `column_default` is null for a column without a default, also when
     * PostgreSQL keeps an explicit `DEFAULT NULL` as a cast expression
     * (`NULL::character varying`), as in EvaluationSchemaTest.
     *
     * @return array<string, list<mixed>> column name => [data_type, is_nullable, column_default, character_maximum_length]
     */
    private function columns(string $table): array
    {
        $columns = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT column_name, data_type, is_nullable, column_default, character_maximum_length FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = ?",
            [$table],
        ) as $row) {
            $columns[(string) $row['column_name']] = [
                $row['data_type'],
                $row['is_nullable'],
                \is_string($row['column_default']) && 1 === preg_match('/^NULL::[a-z ]+$/', $row['column_default']) ? null : $row['column_default'],
                null === $row['character_maximum_length'] ? null : (int) $row['character_maximum_length'],
            ];
        }

        return $columns;
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
