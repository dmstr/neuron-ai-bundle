<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:21:48 UTC

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
 * Checks the conversation tables of dmstr/neuron-ai-bundle in the schema
 * that the migrations produce on the test database
 * ({@see \Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase::migrate()}): both tables, the unique message sequence per
 * conversation, the cascading foreign key, the columns and indexes of
 * anonymous conversations, the executed bundle migrations, no
 * pending migration and no ORM diff for either table; further the
 * stream event table (composite index, cascading foreign key), the cancel
 * flag of a conversation, the nullable close reason of a message record,
 * the nullable integer max_user_turns and the nullable JSON variables of a
 * conversation and the nullable JSON frame of a message record, each with
 * its migration. The test only inspects that result; it creates nothing
 * itself.
 */
#[Group('database')]
final class ConversationSchemaTest extends KernelTestCase
{
    private const BUNDLE_CONVERSATION = 'Dmstr\\NeuronAiBundle\\Migrations\\Version20261001152148';
    private const BUNDLE_ANONYMOUS = 'Dmstr\\NeuronAiBundle\\Migrations\\Version20261001215419';
    private const BUNDLE_STREAMING = 'Dmstr\\NeuronAiBundle\\Migrations\\Version20261001220000';
    private const BUNDLE_CLOSE_REASON = 'Dmstr\\NeuronAiBundle\\Migrations\\Version20261002060217';
    private const BUNDLE_USER_TURN_LIMIT = 'Dmstr\\NeuronAiBundle\\Migrations\\Version20261003065857';
    private const BUNDLE_VARIABLES = 'Dmstr\\NeuronAiBundle\\Migrations\\Version20261003132755';
    private const BUNDLE_FRAME = 'Dmstr\\NeuronAiBundle\\Migrations\\Version20261003203019';

    private Connection $connection;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
    }

    public function testConversationTablesExist(): void
    {
        $tables = $this->connection->fetchFirstColumn(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'",
        );

        self::assertContains('neuron_conversation', $tables);
        self::assertContains('neuron_message', $tables);
        self::assertContains('neuron_stream_event', $tables);
    }

    public function testMessageSequenceIsUniquePerConversation(): void
    {
        $indexes = $this->connection->fetchAllKeyValue(
            "SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'neuron_message'",
        );

        self::assertArrayHasKey('uniq_neuron_message_conversation_sequence', $indexes);
        self::assertStringContainsString('UNIQUE INDEX', $indexes['uniq_neuron_message_conversation_sequence']);
        self::assertStringContainsString('(conversation_id, sequence)', $indexes['uniq_neuron_message_conversation_sequence']);
        self::assertArrayHasKey('idx_neuron_message_conversation', $indexes);
        self::assertArrayHasKey('idx_neuron_message_status', $indexes);
    }

    public function testConversationIndexes(): void
    {
        $indexes = $this->connection->fetchAllKeyValue(
            "SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'neuron_conversation'",
        );

        self::assertArrayHasKey('idx_neuron_conversation_owner_subject', $indexes);
        self::assertStringContainsString('(owner_subject)', $indexes['idx_neuron_conversation_owner_subject']);
        self::assertArrayHasKey('idx_neuron_conversation_status', $indexes);
        self::assertStringContainsString('(status)', $indexes['idx_neuron_conversation_status']);
    }

    public function testMessageReferencesConversationWithCascade(): void
    {
        self::assertSame(['neuron_conversation', 'c'], array_values($this->connection->fetchNumeric(
            "SELECT confrelid::regclass::text, confdeltype FROM pg_constraint
             WHERE conrelid = 'neuron_message'::regclass AND contype = 'f'
               AND conkey = ARRAY[(SELECT attnum FROM pg_attribute WHERE attrelid = 'neuron_message'::regclass AND attname = 'conversation_id')]",
        ) ?: []));
    }

    public function testStreamEventIndexAndCascade(): void
    {
        $indexes = $this->connection->fetchAllKeyValue(
            "SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'neuron_stream_event'",
        );

        self::assertArrayHasKey('idx_neuron_stream_event_conversation', $indexes);
        self::assertStringContainsString('(conversation_id, id)', $indexes['idx_neuron_stream_event_conversation']);

        self::assertSame(['neuron_conversation', 'c'], array_values($this->connection->fetchNumeric(
            "SELECT confrelid::regclass::text, confdeltype FROM pg_constraint
             WHERE conrelid = 'neuron_stream_event'::regclass AND contype = 'f'
               AND conkey = ARRAY[(SELECT attnum FROM pg_attribute WHERE attrelid = 'neuron_stream_event'::regclass AND attname = 'conversation_id')]",
        ) ?: []));
    }

    public function testConversationHasANullableCancelFlag(): void
    {
        self::assertSame(['timestamp with time zone', 'YES'], array_values($this->connection->fetchNumeric(
            "SELECT data_type, is_nullable FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'neuron_conversation' AND column_name = 'cancel_requested_at'",
        ) ?: []));
    }

    public function testMessageHasANullableCloseReason(): void
    {
        self::assertSame(['character varying', '20', 'YES'], array_values($this->connection->fetchNumeric(
            "SELECT data_type, character_maximum_length::text, is_nullable FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'neuron_message' AND column_name = 'close_reason'",
        ) ?: []));
    }

    public function testConversationHasANullableMaxUserTurns(): void
    {
        $column = $this->connection->fetchNumeric(
            "SELECT data_type, column_default, is_nullable FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'neuron_conversation' AND column_name = 'max_user_turns'",
        ) ?: [];

        self::assertCount(3, $column, 'max_user_turns is missing');
        [$type, $default, $nullable] = $column;
        self::assertSame(['integer', 'YES'], [$type, $nullable]);
        // `DEFAULT NULL` may be reported as `NULL::integer`.
        self::assertContains($default, [null, 'NULL::integer']);
    }

    public function testConversationHasANullableVariablesColumn(): void
    {
        $column = $this->connection->fetchNumeric(
            "SELECT data_type, column_default, is_nullable FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'neuron_conversation' AND column_name = 'variables'",
        ) ?: [];

        self::assertCount(3, $column, 'variables is missing');
        [$type, $default, $nullable] = $column;
        self::assertSame(['json', 'YES'], [$type, $nullable]);
        // `DEFAULT NULL` may be reported as `NULL::json`.
        self::assertContains($default, [null, 'NULL::json']);
    }

    public function testMessageHasANullableFrameColumn(): void
    {
        $column = $this->connection->fetchNumeric(
            "SELECT data_type, column_default, is_nullable FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'neuron_message' AND column_name = 'frame'",
        ) ?: [];

        self::assertCount(3, $column, 'frame is missing');
        [$type, $default, $nullable] = $column;
        self::assertSame(['json', 'YES'], [$type, $nullable]);
        // `DEFAULT NULL` may be reported as `NULL::json`.
        self::assertContains($default, [null, 'NULL::json']);
    }

    public function testToolErrorDefaultsToFalse(): void
    {
        self::assertSame(['false', 'NO'], array_values($this->connection->fetchNumeric(
            "SELECT column_default, is_nullable FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'neuron_message' AND column_name = 'tool_error'",
        ) ?: []));
    }

    public function testAnonymousColumns(): void
    {
        $columns = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT column_name, data_type, character_maximum_length, column_default, is_nullable FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'neuron_conversation'",
        ) as $column) {
            $length = null === $column['character_maximum_length'] ? null : (int) $column['character_maximum_length'];
            $columns[$column['column_name']] = [$column['data_type'], $length, $column['column_default'], $column['is_nullable']];
        }

        self::assertSame(['boolean', null, 'false', 'NO'], $columns['anonymous'] ?? null);
        self::assertNullableString($columns, 'token_hash', 64);
        self::assertNullableString($columns, 'address_hash', 64);
        self::assertSame(['integer', null, '0', 'NO'], $columns['turn_count'] ?? null);
        self::assertSame(['integer', null, '0', 'NO'], $columns['tokens_used'] ?? null);
        // Like the nullable VARCHAR columns: `DEFAULT NULL` may be reported
        // as `NULL::timestamp with time zone`.
        self::assertArrayHasKey('closed_at', $columns);
        [$closedType, $closedLength, $closedDefault, $closedNullable] = $columns['closed_at'];
        self::assertSame(['timestamp with time zone', null, 'YES'], [$closedType, $closedLength, $closedNullable], 'closed_at');
        self::assertContains($closedDefault, [null, 'NULL::timestamp with time zone'], 'closed_at');
        self::assertNullableString($columns, 'close_reason', 20);
        // The status keeps its length; `closed` fits.
        self::assertSame(['character varying', 20, null, 'NO'], $columns['status'] ?? null);
    }

    /**
     * A nullable VARCHAR column added with `DEFAULT NULL` reports the default
     * as `NULL::character varying` in information_schema; one without an
     * explicit default reports null. Both mean the same.
     *
     * @param array<string, array{0: mixed, 1: ?int, 2: mixed, 3: mixed}> $columns
     */
    private static function assertNullableString(array $columns, string $name, int $length): void
    {
        self::assertArrayHasKey($name, $columns);
        [$type, $actualLength, $default, $nullable] = $columns[$name];

        self::assertSame('character varying', $type, $name);
        self::assertSame($length, $actualLength, $name);
        self::assertSame('YES', $nullable, $name);
        self::assertContains($default, [null, 'NULL::character varying'], $name);
    }

    public function testAnonymousIndexes(): void
    {
        $indexes = $this->connection->fetchAllKeyValue(
            "SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'neuron_conversation'",
        );

        self::assertArrayHasKey('uniq_neuron_conversation_token_hash', $indexes);
        self::assertStringContainsString('UNIQUE INDEX', $indexes['uniq_neuron_conversation_token_hash']);
        self::assertStringContainsString('(token_hash)', $indexes['uniq_neuron_conversation_token_hash']);
        self::assertArrayHasKey('idx_neuron_conversation_anonymous_closed_at', $indexes);
        self::assertStringContainsString('(anonymous, closed_at)', $indexes['idx_neuron_conversation_anonymous_closed_at']);
    }

    public function testOrmSchemaToolReportsNoDiffForConversationTables(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine.orm.entity_manager');

        $statements = (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata());

        foreach ($statements as $statement) {
            self::assertDoesNotMatchRegularExpression('/neuron_conversation|neuron_message|neuron_stream_event/', $statement);
        }
    }

    public function testConversationMigrationIsExecutedAndNothingIsPending(): void
    {
        /** @var DependencyFactory $factory */
        $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');

        self::assertCount(0, $factory->getMigrationStatusCalculator()->getNewMigrations());

        $executed = $this->connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions');
        self::assertContains(self::BUNDLE_CONVERSATION, $executed);
        self::assertContains(self::BUNDLE_ANONYMOUS, $executed);
        self::assertContains(self::BUNDLE_STREAMING, $executed);
        self::assertContains(self::BUNDLE_CLOSE_REASON, $executed);
        self::assertContains(self::BUNDLE_USER_TURN_LIMIT, $executed);
        self::assertContains(self::BUNDLE_VARIABLES, $executed);
        self::assertContains(self::BUNDLE_FRAME, $executed);
    }
}
