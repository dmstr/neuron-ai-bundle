<?php

// file generated with AI assistance: Claude Code - 2026-10-03 18:38:08 UTC

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
 * Checks the evaluation tables of dmstr/neuron-ai-bundle in the
 * schema the migrations produce on the test database: the three tables
 * with their nullability and defaults, the unique question key, the unique
 * (run, question) pair of the results (the batch job's idempotence
 * guarantee), the indexes, the foreign keys with their ON DELETE actions,
 * the new neuron_conversation.origin column with its index, the retrieval
 * columns (neuron_eval_result.retrieval_status, the run
 * aggregates recall, mrr and the four denominators), the
 * neuron_eval_run.retrieval_only flag, the executed
 * migrations and no ORM diff for these tables. The test only inspects that
 * result; it creates nothing itself.
 */
#[Group('database')]
final class EvalSchemaTest extends KernelTestCase
{
    private const BUNDLE_EVAL = 'Dmstr\\NeuronAiBundle\\Migrations\\Version20261003183808';

    private const BUNDLE_RETRIEVAL_METRICS = 'Dmstr\\NeuronAiBundle\\Migrations\\Version20261003222135';

    private const BUNDLE_RETRIEVAL_ONLY = 'Dmstr\\NeuronAiBundle\\Migrations\\Version20261003233000';

    private Connection $connection;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
    }

    public function testEvalTablesExist(): void
    {
        $tables = $this->connection->fetchFirstColumn(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'",
        );

        foreach (['neuron_eval_question', 'neuron_eval_run', 'neuron_eval_result'] as $table) {
            self::assertContains($table, $tables, $table);
        }
    }

    public function testQuestionColumns(): void
    {
        $columns = $this->columns('neuron_eval_question');

        self::assertSame(['uuid', 'NO', null, null], $columns['id'] ?? null);
        self::assertSame(['character varying', 'NO', null, 100], $columns['key'] ?? null);
        self::assertSame(['text', 'NO', null, null], $columns['question'] ?? null);
        self::assertSame(['json', 'YES', null, null], $columns['request_data'] ?? null);
        self::assertSame(['text', 'NO', null, null], $columns['expected_answer'] ?? null);
        self::assertSame(['character varying', 'NO', "'active'::character varying", 20], $columns['status'] ?? null);
        self::assertSame(['json', 'YES', null, null], $columns['markers'] ?? null);
        self::assertSame(['boolean', 'YES', null, null], $columns['negative'] ?? null);
        self::assertSame(['timestamp with time zone', 'NO', null, null], $columns['created_at'] ?? null);
        self::assertSame(['timestamp with time zone', 'NO', null, null], $columns['updated_at'] ?? null);
        self::assertCount(10, $columns);
    }

    public function testRunColumns(): void
    {
        $columns = $this->columns('neuron_eval_run');

        self::assertSame(['uuid', 'NO', null, null], $columns['id'] ?? null);
        self::assertSame(['uuid', 'NO', null, null], $columns['agent_version_id'] ?? null);
        self::assertSame(['character varying', 'NO', null, 190], $columns['owner_subject'] ?? null);
        self::assertSame(['character varying', 'NO', "'pending'::character varying", 20], $columns['status'] ?? null);
        self::assertSame(['character varying', 'YES', null, 20], $columns['mode'] ?? null);
        self::assertSame(['boolean', 'NO', 'false', null], $columns['retrieval_only'] ?? null);
        self::assertSame(['character varying', 'YES', null, 36], $columns['job_id'] ?? null);
        self::assertSame(['timestamp with time zone', 'YES', null, null], $columns['cancel_requested_at'] ?? null);
        foreach (['questions_total', 'tokens_total', 'interactions_total'] as $counter) {
            self::assertSame(['integer', 'NO', '0', null], $columns[$counter] ?? null, $counter);
        }
        self::assertSame(['json', 'YES', null, null], $columns['verdict_counts'] ?? null);
        self::assertSame(['double precision', 'YES', null, null], $columns['recall'] ?? null);
        self::assertSame(['double precision', 'YES', null, null], $columns['mrr'] ?? null);
        foreach (['questions_measured', 'questions_negative', 'questions_unmeasured', 'questions_retrieval_failed'] as $denominator) {
            self::assertSame(['integer', 'YES', null, null], $columns[$denominator] ?? null, $denominator);
        }
        self::assertSame(['timestamp with time zone', 'YES', null, null], $columns['started_at'] ?? null);
        self::assertSame(['timestamp with time zone', 'YES', null, null], $columns['finished_at'] ?? null);
        self::assertSame(['timestamp with time zone', 'NO', null, null], $columns['created_at'] ?? null);
        self::assertSame(['timestamp with time zone', 'NO', null, null], $columns['updated_at'] ?? null);
        self::assertCount(22, $columns);
    }

    public function testResultColumns(): void
    {
        $columns = $this->columns('neuron_eval_result');

        self::assertSame(['uuid', 'NO', null, null], $columns['id'] ?? null);
        self::assertSame(['uuid', 'YES', null, null], $columns['eval_run_id'] ?? null);
        self::assertSame(['uuid', 'NO', null, null], $columns['eval_question_id'] ?? null);
        self::assertSame(['uuid', 'YES', null, null], $columns['conversation_id'] ?? null);
        self::assertSame(['character varying', 'NO', null, 20], $columns['source'] ?? null);
        self::assertSame(['character varying', 'NO', "'pending'::character varying", 20], $columns['status'] ?? null);
        self::assertSame(['text', 'YES', null, null], $columns['error_message'] ?? null);
        foreach (['duration_ms', 'tokens_input', 'tokens_output', 'interactions', 'first_relevant_rank'] as $measurement) {
            self::assertSame(['integer', 'YES', null, null], $columns[$measurement] ?? null, $measurement);
        }
        self::assertSame(['json', 'YES', null, null], $columns['human_rating'] ?? null);
        self::assertSame(['boolean', 'YES', null, null], $columns['recall_hit'] ?? null);
        self::assertSame(['character varying', 'YES', null, 64], $columns['question_fingerprint'] ?? null);
        self::assertSame(['character varying', 'YES', null, 20], $columns['retrieval_status'] ?? null);
        self::assertSame(['timestamp with time zone', 'NO', null, null], $columns['created_at'] ?? null);
        self::assertSame(['timestamp with time zone', 'NO', null, null], $columns['updated_at'] ?? null);
        self::assertCount(21, $columns);
    }

    public function testQuestionKeyIsUnique(): void
    {
        $indexes = $this->indexes('neuron_eval_question');

        self::assertArrayHasKey('uniq_neuron_eval_question_key', $indexes);
        self::assertStringContainsString('UNIQUE INDEX', $indexes['uniq_neuron_eval_question_key']);
        self::assertStringContainsString('(key)', $indexes['uniq_neuron_eval_question_key']);
    }

    public function testRunIndexes(): void
    {
        $indexes = $this->indexes('neuron_eval_run');

        self::assertArrayHasKey('idx_neuron_eval_run_status', $indexes);
        self::assertStringContainsString('(status)', $indexes['idx_neuron_eval_run_status']);
        self::assertArrayHasKey('idx_neuron_eval_run_owner_subject', $indexes);
        self::assertStringContainsString('(owner_subject)', $indexes['idx_neuron_eval_run_owner_subject']);
        self::assertArrayHasKey('idx_neuron_eval_run_agent_version', $indexes);
        self::assertStringContainsString('(agent_version_id)', $indexes['idx_neuron_eval_run_agent_version']);
    }

    public function testResultRunAndQuestionAreUniqueTogether(): void
    {
        $indexes = $this->indexes('neuron_eval_result');

        self::assertArrayHasKey('uniq_neuron_eval_result_run_question', $indexes);
        self::assertStringContainsString('UNIQUE INDEX', $indexes['uniq_neuron_eval_result_run_question']);
        self::assertStringContainsString('(eval_run_id, eval_question_id)', $indexes['uniq_neuron_eval_result_run_question']);
        // Not partial: NULL runs are distinct for PostgreSQL anyway.
        self::assertStringNotContainsString('WHERE', $indexes['uniq_neuron_eval_result_run_question']);

        self::assertArrayHasKey('idx_neuron_eval_result_run', $indexes);
        self::assertStringContainsString('(eval_run_id)', $indexes['idx_neuron_eval_result_run']);
        self::assertArrayHasKey('idx_neuron_eval_result_question', $indexes);
        self::assertStringContainsString('(eval_question_id)', $indexes['idx_neuron_eval_result_question']);
        self::assertArrayHasKey('idx_neuron_eval_result_conversation', $indexes);
        self::assertStringContainsString('(conversation_id)', $indexes['idx_neuron_eval_result_conversation']);
    }

    public function testForeignKeysAndTheirDeleteActions(): void
    {
        // confdeltype: 'r' RESTRICT, 'c' CASCADE, 'n' SET NULL.
        self::assertSame(['neuron_agent_version', 'r'], $this->foreignKey('neuron_eval_run', 'agent_version_id'));
        self::assertSame(['neuron_eval_run', 'c'], $this->foreignKey('neuron_eval_result', 'eval_run_id'));
        self::assertSame(['neuron_eval_question', 'r'], $this->foreignKey('neuron_eval_result', 'eval_question_id'));
        self::assertSame(['neuron_conversation', 'n'], $this->foreignKey('neuron_eval_result', 'conversation_id'));
    }

    public function testConversationHasIndexedOrigin(): void
    {
        $columns = $this->columns('neuron_conversation');
        self::assertSame(['character varying', 'YES', null, 20], $columns['origin'] ?? null);

        $indexes = $this->indexes('neuron_conversation');
        self::assertArrayHasKey('idx_neuron_conversation_origin', $indexes);
        self::assertStringContainsString('(origin)', $indexes['idx_neuron_conversation_origin']);
    }

    public function testOrmSchemaToolReportsNoDiffForEvalTables(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine.orm.entity_manager');

        $statements = (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata());

        foreach ($statements as $statement) {
            self::assertDoesNotMatchRegularExpression('/neuron_eval_question|neuron_eval_run|neuron_eval_result|neuron_conversation/', $statement);
        }
    }

    public function testEvalMigrationIsExecutedAndNothingIsPending(): void
    {
        /** @var DependencyFactory $factory */
        $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');

        self::assertCount(0, $factory->getMigrationStatusCalculator()->getNewMigrations());

        $executed = $this->connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions');
        self::assertContains(self::BUNDLE_EVAL, $executed);
        self::assertContains(self::BUNDLE_RETRIEVAL_METRICS, $executed);
        self::assertContains(self::BUNDLE_RETRIEVAL_ONLY, $executed);
    }

    /**
     * `column_default` is null for a column without a default. PostgreSQL
     * may keep an explicit `DEFAULT NULL` as a cast expression
     * (`NULL::character varying` for VARCHAR(n), seen in CI; likewise
     * `NULL::timestamp with time zone`, `NULL::integer`, `NULL::json`),
     * depending on the type; both forms mean "no default" and are reported
     * as null here, as ConversationSchemaTest accepts either.
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
