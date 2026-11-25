<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:23:53 UTC

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
 * Checks the evaluation judge tables of dmstr/neuron-ai-bundle in
 * the schema the migrations produce
 * on the test database: neuron_evaluation
 * and neuron_suggestion with their nullability and defaults, the indexes,
 * the foreign keys with their ON DELETE actions, the new columns of
 * neuron_eval_result (latest evaluation, human rating author and time), the
 * executed migration and no ORM diff for these tables. The test only
 * inspects that result; it creates nothing itself.
 */
#[Group('database')]
final class EvaluationSchemaTest extends KernelTestCase
{
    private const BUNDLE_EVALUATION = 'Dmstr\\NeuronAiBundle\\Migrations\\Version20261003202353';

    private Connection $connection;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
    }

    public function testEvaluationTablesExist(): void
    {
        $tables = $this->connection->fetchFirstColumn(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'",
        );

        foreach (['neuron_evaluation', 'neuron_suggestion'] as $table) {
            self::assertContains($table, $tables, $table);
        }
    }

    public function testEvaluationColumns(): void
    {
        $columns = $this->columns('neuron_evaluation');

        self::assertSame(['uuid', 'NO', null, null], $columns['id'] ?? null);
        self::assertSame(['uuid', 'NO', null, null], $columns['eval_result_id'] ?? null);
        self::assertSame(['character varying', 'NO', "'pending'::character varying", 20], $columns['status'] ?? null);
        self::assertSame(['character varying', 'YES', null, 20], $columns['verdict'] ?? null);
        self::assertSame(['text', 'YES', null, null], $columns['reasoning'] ?? null);
        self::assertSame(['uuid', 'YES', null, null], $columns['judge_agent_version_id'] ?? null);
        self::assertSame(['character varying', 'YES', null, 255], $columns['model'] ?? null);
        foreach (['tokens_input', 'tokens_output', 'duration_ms'] as $measurement) {
            self::assertSame(['integer', 'YES', null, null], $columns[$measurement] ?? null, $measurement);
        }
        self::assertSame(['text', 'YES', null, null], $columns['error_message'] ?? null);
        self::assertSame(['character varying', 'YES', null, 36], $columns['job_id'] ?? null);
        self::assertSame(['timestamp with time zone', 'NO', null, null], $columns['created_at'] ?? null);
        self::assertSame(['timestamp with time zone', 'NO', null, null], $columns['updated_at'] ?? null);
        self::assertSame(['timestamp with time zone', 'YES', null, null], $columns['finished_at'] ?? null);
        self::assertSame(['uuid', 'YES', null, null], $columns['provider_model_id'] ?? null);
        // 15 original columns and provider_model_id of the model catalogue
        // reference (Version20261008210000).
        self::assertCount(16, $columns);
    }

    public function testSuggestionColumns(): void
    {
        $columns = $this->columns('neuron_suggestion');

        self::assertSame(['uuid', 'NO', null, null], $columns['id'] ?? null);
        self::assertSame(['uuid', 'NO', null, null], $columns['evaluation_id'] ?? null);
        self::assertSame(['character varying', 'NO', null, 20], $columns['type'] ?? null);
        self::assertSame(['character varying', 'NO', null, 100], $columns['category'] ?? null);
        self::assertSame(['text', 'NO', null, null], $columns['description'] ?? null);
        self::assertSame(['text', 'NO', null, null], $columns['action'] ?? null);
        self::assertSame(['character varying', 'NO', null, 20], $columns['priority'] ?? null);
        self::assertSame(['character varying', 'NO', "'open'::character varying", 20], $columns['status'] ?? null);
        self::assertSame(['character varying', 'YES', null, 255], $columns['assignee'] ?? null);
        self::assertSame(['timestamp with time zone', 'NO', null, null], $columns['created_at'] ?? null);
        self::assertSame(['timestamp with time zone', 'NO', null, null], $columns['updated_at'] ?? null);
        self::assertCount(11, $columns);
    }

    public function testResultHasEvaluationAndRatingColumns(): void
    {
        $columns = $this->columns('neuron_eval_result');

        self::assertSame(['uuid', 'YES', null, null], $columns['evaluation_id'] ?? null);
        self::assertSame(['json', 'YES', null, null], $columns['human_rating'] ?? null);
        self::assertSame(['character varying', 'YES', null, 255], $columns['human_rated_by'] ?? null);
        self::assertSame(['timestamp with time zone', 'YES', null, null], $columns['human_rated_at'] ?? null);
        self::assertSame(['character varying', 'YES', null, 64], $columns['question_fingerprint'] ?? null);
        // 16 original columns, the three of the judge, retrieval_status and
        // question_fingerprint.
        self::assertCount(21, $columns);

        $indexes = $this->indexes('neuron_eval_result');
        self::assertArrayHasKey('idx_neuron_eval_result_evaluation', $indexes);
        self::assertStringContainsString('(evaluation_id)', $indexes['idx_neuron_eval_result_evaluation']);
    }

    public function testEvaluationIndexes(): void
    {
        $indexes = $this->indexes('neuron_evaluation');

        self::assertArrayHasKey('idx_neuron_evaluation_eval_result', $indexes);
        self::assertStringContainsString('(eval_result_id)', $indexes['idx_neuron_evaluation_eval_result']);
        self::assertArrayHasKey('idx_neuron_evaluation_status', $indexes);
        self::assertStringContainsString('(status)', $indexes['idx_neuron_evaluation_status']);
        self::assertArrayHasKey('idx_neuron_evaluation_judge_agent_version', $indexes);
        self::assertStringContainsString('(judge_agent_version_id)', $indexes['idx_neuron_evaluation_judge_agent_version']);
    }

    public function testSuggestionIndexes(): void
    {
        $indexes = $this->indexes('neuron_suggestion');

        self::assertArrayHasKey('idx_neuron_suggestion_evaluation', $indexes);
        self::assertStringContainsString('(evaluation_id)', $indexes['idx_neuron_suggestion_evaluation']);
        self::assertArrayHasKey('idx_neuron_suggestion_status', $indexes);
        self::assertStringContainsString('(status)', $indexes['idx_neuron_suggestion_status']);
        self::assertArrayHasKey('idx_neuron_suggestion_assignee', $indexes);
        self::assertStringContainsString('(assignee)', $indexes['idx_neuron_suggestion_assignee']);
    }

    public function testForeignKeysAndTheirDeleteActions(): void
    {
        // confdeltype: 'r' RESTRICT, 'c' CASCADE, 'n' SET NULL.
        self::assertSame(['neuron_eval_result', 'c'], $this->foreignKey('neuron_evaluation', 'eval_result_id'));
        self::assertSame(['neuron_agent_version', 'n'], $this->foreignKey('neuron_evaluation', 'judge_agent_version_id'));
        self::assertSame(['neuron_evaluation', 'c'], $this->foreignKey('neuron_suggestion', 'evaluation_id'));
        self::assertSame(['neuron_evaluation', 'n'], $this->foreignKey('neuron_eval_result', 'evaluation_id'));
        self::assertSame(['neuron_provider_model', 'n'], $this->foreignKey('neuron_evaluation', 'provider_model_id'));
    }

    public function testOrmSchemaToolReportsNoDiffForEvaluationTables(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine.orm.entity_manager');

        $statements = (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata());

        foreach ($statements as $statement) {
            self::assertDoesNotMatchRegularExpression('/neuron_evaluation|neuron_suggestion|neuron_eval_result/', $statement);
        }
    }

    public function testEvaluationMigrationIsExecutedAndNothingIsPending(): void
    {
        /** @var DependencyFactory $factory */
        $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');

        self::assertCount(0, $factory->getMigrationStatusCalculator()->getNewMigrations());

        $executed = $this->connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions');
        self::assertContains(self::BUNDLE_EVALUATION, $executed);
    }

    /**
     * `column_default` is null for a column without a default. PostgreSQL
     * may keep an explicit `DEFAULT NULL` as a cast expression
     * (`NULL::character varying` for VARCHAR(n), seen in CI; likewise
     * `NULL::timestamp with time zone`, `NULL::integer`, `NULL::json`),
     * depending on the type; both forms mean "no default" and are reported
     * as null here, as EvalSchemaTest does.
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
