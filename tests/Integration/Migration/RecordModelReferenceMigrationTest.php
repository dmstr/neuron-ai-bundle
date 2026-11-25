<?php

// file generated with AI assistance: Claude Code - 2026-10-08 20:08:39 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Migration;

use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Entity\EvalResult;
use Dmstr\NeuronAiBundle\Entity\Evaluation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultSource;
use Dmstr\NeuronAiBundle\Evaluation\EvaluationStatus;
use Dmstr\NeuronAiBundle\Provider\ModelReferenceMigrationSql;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The model reference migration of slice 4 (DB, CI only):
 * `Version20261008210000` adds `provider_model_id` to `neuron_message` and
 * `neuron_evaluation` (ON DELETE SET NULL) and fills it where a catalogue
 * entry matches, with the name matching of
 * {@see ModelReferenceMigrationSql::entryId()}, and never adds placeholder
 * entries:
 *
 *  - a message by its `connection_id` and model name (exact name first,
 *    `x` vs. `x:latest`); a user record, a gone connection or a name
 *    without entry stays null;
 *  - an evaluation by its judge version's `configuration.model` when that
 *    entry's name matches the evaluation's model name.
 *
 * The deletion of an entry sets the reference to null and keeps the name.
 * The test runs down() first (the test schema is migrated), then up().
 * Everything runs in a transaction that tearDown() rolls back (PostgreSQL
 * DDL included).
 */
#[Group('database')]
final class RecordModelReferenceMigrationTest extends KernelTestCase
{
    private const string MIGRATION = 'Dmstr\\NeuronAiBundle\\Migrations\\Version20261008210000';

    private const string PREFIX = 'test-record-model-ref-migration-';

    private const string OLLAMA = 'e5000000-0000-4000-8000-000000000001';
    /** A connection id no connection has (any more). */
    private const string GONE = 'e5000000-0000-4000-8000-0000000000ff';

    private const string MISTRAL = 'f5000000-0000-4000-8000-000000000001';
    private const string MISTRAL_LATEST = 'f5000000-0000-4000-8000-000000000002';
    private const string LLAMA31_LATEST = 'f5000000-0000-4000-8000-000000000003';

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
        foreach ([
            [self::MISTRAL, 'mistral'],
            [self::MISTRAL_LATEST, 'mistral:latest'],
            [self::LLAMA31_LATEST, 'llama3.1:latest'],
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

    public function testMessagesGetTheEntryOfTheirConnectionAndName(): void
    {
        $conversation = $this->conversation();
        $exact = $this->message($conversation, self::OLLAMA, 'mistral');
        $tagged = $this->message($conversation, self::OLLAMA, 'llama3.1');
        $unknown = $this->message($conversation, self::OLLAMA, 'phi3');
        $gone = $this->message($conversation, self::GONE, 'mistral');
        $user = $this->message($conversation, null, null);
        $entries = $this->entryCount();

        $this->migrate('down');
        self::assertFalse($this->hasColumn('neuron_message'), 'down() drops the column');
        $this->migrate('up');
        self::assertTrue($this->hasColumn('neuron_message'));

        self::assertSame(self::MISTRAL, $this->referenceOf('neuron_message', $exact), 'The exact name wins over the tagged one.');
        self::assertSame(self::LLAMA31_LATEST, $this->referenceOf('neuron_message', $tagged), 'An untagged name matches the tagged entry.');
        self::assertNull($this->referenceOf('neuron_message', $unknown), 'A name without entry stays without reference.');
        self::assertNull($this->referenceOf('neuron_message', $gone), 'A gone connection has no entries.');
        self::assertNull($this->referenceOf('neuron_message', $user));
        self::assertSame($entries, $this->entryCount(), 'Records get no placeholder entries.');

        // The deletion of an entry keeps the record and its name.
        $this->connection->executeStatement('DELETE FROM neuron_provider_model WHERE id = ?', [self::MISTRAL]);
        self::assertNull($this->referenceOf('neuron_message', $exact));
        self::assertSame('mistral', $this->connection->fetchOne('SELECT model FROM neuron_message WHERE id = ?', [$exact]));
    }

    public function testEvaluationsGetTheEntryOfTheirJudgeVersion(): void
    {
        $result = $this->evalResult();
        $mistralJudge = $this->judgeVersion('mistral', self::MISTRAL_LATEST);
        $llamaJudge = $this->judgeVersion('llama', self::LLAMA31_LATEST);
        $legacyJudge = $this->judgeVersion('legacy', null);

        $sameName = $this->evaluation($result, $mistralJudge, 'mistral:latest');
        $equivalent = $this->evaluation($result, $llamaJudge, 'llama3.1');
        $otherModel = $this->evaluation($result, $llamaJudge, 'mistral');
        $withoutVersion = $this->evaluation($result, null, 'mistral');
        $unmigratedVersion = $this->evaluation($result, $legacyJudge, 'mistral');

        $this->migrate('down');
        self::assertFalse($this->hasColumn('neuron_evaluation'), 'down() drops the column');
        $this->migrate('up');

        self::assertSame(self::MISTRAL_LATEST, $this->referenceOf('neuron_evaluation', $sameName));
        self::assertSame(self::LLAMA31_LATEST, $this->referenceOf('neuron_evaluation', $equivalent), 'x matches x:latest.');
        self::assertNull($this->referenceOf('neuron_evaluation', $otherModel), 'The version\'s entry names another model.');
        self::assertNull($this->referenceOf('neuron_evaluation', $withoutVersion));
        self::assertNull($this->referenceOf('neuron_evaluation', $unmigratedVersion), 'A version whose model is no entry id.');

        $this->connection->executeStatement('DELETE FROM neuron_provider_model WHERE id = ?', [self::MISTRAL_LATEST]);
        self::assertNull($this->referenceOf('neuron_evaluation', $sameName));
        self::assertSame('mistral:latest', $this->connection->fetchOne('SELECT model FROM neuron_evaluation WHERE id = ?', [$sameName]));
    }

    /**
     * Runs the statements of the migration's up() or down().
     */
    private function migrate(string $direction): void
    {
        require_once \dirname(__DIR__, 3).'/migrations/Version20261008210000.php';
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
     * The conversation of the records, on the entry `llama3.1:latest`
     * (`provider_model_id` is NOT NULL; the test deletes the `mistral`
     * entries, which the conversation must not reference).
     */
    private function conversation(): Conversation
    {
        $entry = $this->entityManager->find(ProviderModel::class, self::LLAMA31_LATEST);
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
     * A record in the shape before the model reference: connection id and
     * model name, no entry; null connection = a user record.
     */
    private function message(Conversation $conversation, ?string $connection, ?string $model): string
    {
        $message = new Message()
            ->setConversation($conversation)
            ->setRole(null === $connection ? Message::SPEAKER_USER : Message::SPEAKER_ASSISTANT)
            ->setKind(MessageKind::TEXT)
            ->setStatus(MessageStatus::COMPLETE)
            ->setSequence($this->sequence++)
            ->setContent('Text')
            ->setProvider(null === $connection ? null : 'ollama')
            ->setModel($model)
            ->setConnectionId($connection);
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        return $message->getId()->toRfc4122();
    }

    private function evalResult(): EvalResult
    {
        $question = new EvalQuestion()
            ->setKey(self::PREFIX.'question')
            ->setQuestion('What is the maintenance interval?')
            ->setExpectedAnswer('Every 500 hours.');
        $result = new EvalResult()
            ->setEvalQuestion($question)
            ->setSource(EvalResultSource::SINGLE);
        $this->entityManager->persist($question);
        $this->entityManager->persist($result);
        $this->entityManager->flush();

        return $result;
    }

    /**
     * A published judge version whose `model` is the entry $entry; null
     * keeps the model name of an unmigrated version.
     */
    private function judgeVersion(string $slug, ?string $entry): AgentVersion
    {
        $agent = new Agent()->setName('Judge '.$slug)->setSlug(self::PREFIX.$slug)->setEnabled(true);
        $this->entityManager->persist($agent);
        $version = new AgentVersion($agent, 1, ['model' => $entry ?? 'mistral'] + Agent::candidateDefaults(), 'test-admin');
        $this->entityManager->persist($version);
        $this->entityManager->flush();

        return $version;
    }

    private function evaluation(EvalResult $result, ?AgentVersion $judge, string $model): string
    {
        $evaluation = new Evaluation()
            ->setEvalResult($result)
            ->setStatus(EvaluationStatus::COMPLETED)
            ->setJudgeAgentVersion($judge)
            ->setModel($model);
        $this->entityManager->persist($evaluation);
        $this->entityManager->flush();

        return $evaluation->getId()->toRfc4122();
    }

    private function referenceOf(string $table, string $id): ?string
    {
        $reference = $this->connection->fetchOne(\sprintf('SELECT provider_model_id::text FROM %s WHERE id = ?', $table), [$id]);

        return \is_string($reference) ? $reference : null;
    }

    private function hasColumn(string $table): bool
    {
        return false !== $this->connection->fetchOne(
            "SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = 'provider_model_id'",
            [$table],
        );
    }

    private function entryCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM neuron_provider_model');
    }
}
