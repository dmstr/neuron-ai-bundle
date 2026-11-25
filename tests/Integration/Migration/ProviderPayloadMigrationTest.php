<?php

// file generated with AI assistance: Claude Code - 2026-10-14 14:05:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Migration;

use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The provider payload migration of the neuron-ai 4.x upgrade (DB, CI
 * only): `Version20261014120000` converts `neuron_message.provider_payload`
 * from the 3.x to the 4.x message serialization and back.
 *
 *  - up(): a `message` without `__meta` gets its metadata keys under
 *    `__meta` (`[]` without metadata); its `tools` entries and the `tool` of
 *    a `tool_call` record lose `parameters` and get `deferred: false` and
 *    `approval`, `approvalReason`, `rejectReason` as null; a stored result
 *    string stays as it is. Rows already in the 4.x shape are not touched.
 *  - down(): the metadata goes back to the top level, the four 4.x keys go,
 *    `parameters` comes back as `[]`, and a 4.x result block array becomes
 *    the JSON string of that array.
 *
 * The json column is rewritten through jsonb, so the comparison ignores the
 * key order of objects. Everything runs in a transaction that tearDown()
 * rolls back.
 */
#[Group('database')]
final class ProviderPayloadMigrationTest extends KernelTestCase
{
    private const string MIGRATION = 'Dmstr\\NeuronAiBundle\\Migrations\\Version20261014120000';

    private const string PREFIX = 'test-provider-payload-migration-';

    private const string OLLAMA = 'e6000000-0000-4000-8000-000000000001';

    private const string LLAMA = 'f6000000-0000-4000-8000-000000000001';

    /** A 3.x tool entry: no 4.x approval keys, `parameters` present. */
    private const array TOOL_3X = [
        'callId' => 'call_1',
        'name' => 'search',
        'description' => 'Full-text search',
        'inputs' => ['query' => 'Spindel'],
        'result' => null,
        'parameters' => [],
    ];

    /** The same entry in the 4.x shape. */
    private const array TOOL_4X = [
        'callId' => 'call_1',
        'name' => 'search',
        'description' => 'Full-text search',
        'inputs' => ['query' => 'Spindel'],
        'result' => null,
        'deferred' => false,
        'approval' => null,
        'approvalReason' => null,
        'rejectReason' => null,
    ];

    private Connection $connection;

    private EntityManagerInterface $entityManager;

    private int $sequence = 0;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->connection->beginTransaction();

        $this->connection->executeStatement(
            "INSERT INTO dmstr_api_configuration (id, name, type, endpoint_type, config_json, active, created_at, updated_at)
             VALUES (?, ?, 'ollama', 'rest', ?, true, NOW(), NOW())",
            [self::OLLAMA, self::PREFIX.'ollama', json_encode(['type' => 'ollama', 'endpoint_type' => 'rest'], \JSON_THROW_ON_ERROR)],
        );
        $this->connection->executeStatement(
            "INSERT INTO neuron_provider_model (id, api_configuration_id, name, endpoint_fingerprint, available, created_at, updated_at)
             VALUES (?, ?, 'llama3.1:latest', 'sha256:test', true, NOW(), NOW())",
            [self::LLAMA, self::OLLAMA],
        );
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testThe3xPayloadIsConvertedAndBack(): void
    {
        $conversation = $this->conversation();
        $toolCallStep = $this->message($conversation, [
            'step' => 1,
            'part' => 'text',
            'message' => [
                'role' => 'assistant',
                'content' => [],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                'type' => 'tool_call',
                'stop_reason' => 'tool_use',
                'citations' => [['source' => 'doc-1']],
                'tools' => [self::TOOL_3X],
            ],
        ]);
        $user = $this->message($conversation, [
            'step' => 0,
            'part' => 'user',
            'message' => ['role' => 'user', 'content' => [['type' => 'text', 'content' => 'Frage', 'meta' => []]]],
        ]);
        $toolCall = $this->message($conversation, [
            'step' => 1,
            'part' => 'tool_call',
            'tool' => ['result' => '[{"type":"text","text":"three hits"}]'] + self::TOOL_3X,
        ]);
        $before = [$toolCallStep => $this->payload($toolCallStep), $user => $this->payload($user), $toolCall => $this->payload($toolCall)];

        $this->migrate('up');

        $message = $this->payload($toolCallStep)['message'];
        self::assertEquals(['stop_reason' => 'tool_use', 'citations' => [['source' => 'doc-1']]], $message['__meta']);
        self::assertArrayNotHasKey('stop_reason', $message);
        self::assertArrayNotHasKey('citations', $message);
        self::assertEquals(['input_tokens' => 10, 'output_tokens' => 5], $message['usage']);
        self::assertSame('tool_call', $message['type']);
        self::assertEquals([self::TOOL_4X], $message['tools']);
        self::assertArrayNotHasKey('parameters', $message['tools'][0]);

        self::assertSame([], $this->payload($user)['message']['__meta'], 'A message without metadata gets an empty __meta.');

        $tool = $this->payload($toolCall)['tool'];
        self::assertEquals(['result' => '[{"type":"text","text":"three hits"}]'] + self::TOOL_4X, $tool);
        self::assertSame(1, $this->payload($toolCall)['step']);

        $this->migrate('down');

        foreach ($before as $id => $payload) {
            self::assertEquals($payload, $this->payload($id), 'down() restores the 3.x shape.');
        }
    }

    public function testRowsIn4xShapeAreNotTouchedByUpAndTheResultBlocksGoBackAsJson(): void
    {
        $conversation = $this->conversation();
        $message4x = [
            '__id' => 'msg_01a13a89-7a77-7fa6-bdba-c7dc9baadff6',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'content' => 'Hi', 'meta' => []]],
            '__meta' => ['stop_reason' => 'end_turn'],
        ];
        $text = $this->message($conversation, ['step' => 1, 'part' => 'text', 'message' => $message4x]);
        $blocks = [['type' => 'text', 'content' => 'three hits', 'meta' => []]];
        $toolCall = $this->message($conversation, ['step' => 1, 'part' => 'tool_call', 'tool' => ['result' => $blocks] + self::TOOL_4X]);
        $textBefore = $this->payload($text);
        $toolBefore = $this->payload($toolCall);

        $this->migrate('up');

        self::assertEquals($textBefore, $this->payload($text));
        self::assertEquals($toolBefore, $this->payload($toolCall));

        $this->migrate('down');

        self::assertEquals(['role' => 'assistant', '__id' => $message4x['__id'], 'content' => $message4x['content'], 'stop_reason' => 'end_turn'], $this->payload($text)['message']);
        $tool = $this->payload($toolCall)['tool'];
        self::assertSame([], $tool['parameters']);
        self::assertIsString($tool['result']);
        self::assertEquals($blocks, json_decode($tool['result'], true));
        foreach (['deferred', 'approval', 'approvalReason', 'rejectReason'] as $key) {
            self::assertArrayNotHasKey($key, $tool);
        }
    }

    /**
     * Runs the statements of the migration's up() or down().
     */
    private function migrate(string $direction): void
    {
        require_once \dirname(__DIR__, 3).'/migrations/Version20261014120000.php';
        $class = self::MIGRATION;
        /** @var AbstractMigration $migration */
        $migration = new $class($this->connection, new NullLogger());
        $migration->{$direction}(new Schema());

        self::assertNotEmpty($migration->getSql());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function conversation(): Conversation
    {
        $entry = $this->entityManager->find(ProviderModel::class, self::LLAMA);
        self::assertInstanceOf(ProviderModel::class, $entry);
        $conversation = new Conversation()
            ->setOwnerSubject(self::PREFIX.'owner')
            ->setProviderModel($entry)
            ->setRetrievalDepth(4);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();

        return $conversation;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function message(Conversation $conversation, array $payload): string
    {
        $message = new Message()
            ->setConversation($conversation)
            ->setRole('user' === $payload['part'] ? Message::SPEAKER_USER : Message::SPEAKER_ASSISTANT)
            ->setKind(MessageKind::TEXT)
            ->setStatus(MessageStatus::COMPLETE)
            ->setSequence($this->sequence++)
            ->setContent('Text')
            ->setProviderPayload($payload);
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        return $message->getId()->toRfc4122();
    }

    /**
     * The stored payload as decoded JSON (bypassing the entity manager).
     *
     * @return array<string, mixed>
     */
    private function payload(string $id): array
    {
        $json = $this->connection->fetchOne('SELECT provider_payload FROM neuron_message WHERE id = ?', [$id]);
        self::assertIsString($json);

        return json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
    }
}
