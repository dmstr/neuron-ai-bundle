<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\ConversationReplay;

use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Schema of the conversation replay (migration `Version20261003220000`)
 * as the migrations create it: the delete rules of the three
 * foreign keys at database level (the source with SET NULL, because the
 * anonymous purge deletes with DQL and no ORM cascade runs; the replay
 * conversation with CASCADE; the target version with RESTRICT), the named
 * indexes, and an empty ORM schema diff for the table . Needs the migrated
 * test database; runs in CI.
 */
#[Group('database')]
final class ConversationReplaySchemaTest extends KernelTestCase
{
    private const string TABLE = 'neuron_conversation_replay';

    private Connection $connection;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function foreignKeys(): iterable
    {
        // confdeltype: c = cascade, n = set null, r = restrict
        yield 'replay conversation' => ['conversation_id', 'neuron_conversation', 'c'];
        yield 'source' => ['source_conversation_id', 'neuron_conversation', 'n'];
        yield 'target version' => ['target_version_id', 'neuron_agent_version', 'r'];
    }

    #[DataProvider('foreignKeys')]
    public function testForeignKeyDeleteRules(string $column, string $references, string $rule): void
    {
        self::assertSame([$references, $rule], array_values($this->connection->fetchNumeric(
            "SELECT confrelid::regclass::text, confdeltype FROM pg_constraint
             WHERE conrelid = '".self::TABLE."'::regclass AND contype = 'f'
               AND conkey = ARRAY[(SELECT attnum FROM pg_attribute WHERE attrelid = '".self::TABLE."'::regclass AND attname = :column)]",
            ['column' => $column],
        ) ?: []));
    }

    public function testNamedIndexes(): void
    {
        $indexes = $this->connection->fetchAllKeyValue(
            "SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = :table",
            ['table' => self::TABLE],
        );

        self::assertStringContainsString('UNIQUE INDEX', $indexes['uniq_neuron_conversation_replay_conversation'] ?? '');
        self::assertStringContainsString('(conversation_id)', $indexes['uniq_neuron_conversation_replay_conversation'] ?? '');
        foreach (['source', 'target_version', 'owner_subject', 'status'] as $suffix) {
            self::assertArrayHasKey('idx_neuron_conversation_replay_'.$suffix, $indexes);
        }
        foreach (array_keys($indexes) as $name) {
            self::assertStringStartsNotWith('idx_c957', strtolower((string) $name), 'no implicit index');
        }
    }

    public function testOrmSchemaToolReportsNoDiffForTheReplayTable(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine.orm.entity_manager');

        $statements = (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata());

        foreach ($statements as $statement) {
            self::assertStringNotContainsString(self::TABLE, $statement);
        }
    }
}
