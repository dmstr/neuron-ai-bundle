<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:35:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Conversation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Conversation\TurnLock;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Lock, orphan sweep and failure handling of the turn: a second database session holding the
 * conversation's advisory lock makes the turn answer 409 without new rows;
 * once released, the next turn runs and marks the pre-inserted `streaming`
 * record `aborted`; a provider failure answers 201 with an `error` record
 * after the kept user record.
 *
 * Needs the migrated test database (CI only); default test identity
 * `test-admin`.
 */
#[Group('database')]
final class ConcurrencyTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private Client $client;

    private ?Conversation $conversation = null;

    private ?Connection $second = null;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->conversation = new Conversation()
            ->setOwnerSubject('test-admin')
            ->setProviderModel(ConversationConnections::model())
            ->setRetrievalDepth(4);
        $this->entityManager()->persist($this->conversation);
        $this->entityManager()->flush();
    }

    protected function tearDown(): void
    {
        $this->second?->close();
        if (null !== $this->conversation) {
            $this->connection()->executeStatement('DELETE FROM neuron_conversation WHERE id = :id', ['id' => (string) $this->conversation->getId()]);
        }
        parent::tearDown();
    }

    public function testHeldLockAnswers409UntilReleased(): void
    {
        $this->insertStreamingRecord();
        $key = TurnLock::keyFor($this->conversation->getId());
        $this->second = DriverManager::getConnection($this->connection()->getParams());
        self::assertTrue((bool) $this->second->fetchOne('SELECT pg_try_advisory_lock(CAST(:key AS BIGINT))', ['key' => $key]));

        $this->turn('Erste Frage');

        self::assertResponseStatusCodeSame(409);
        self::assertCount(1, $this->records());
        $this->factory()->getChatProvider()->assertNothingSent();

        $this->second->close();
        $this->second = null;
        $this->factory()->queueAnswer('Antwort');

        $this->turn('Erste Frage');

        self::assertResponseStatusCodeSame(201);
        $records = $this->records();
        self::assertSame(
            [[1, MessageStatus::ABORTED], [2, MessageStatus::COMPLETE], [3, MessageStatus::COMPLETE]],
            array_map(static fn (Message $m): array => [$m->getSequence(), $m->getStatus()], $records),
        );
        // The lock was released after the turn.
        self::assertTrue((bool) $this->connection()->fetchOne('SELECT pg_try_advisory_lock(CAST(:key AS BIGINT))', ['key' => $key]));
        $this->connection()->fetchOne('SELECT pg_advisory_unlock(CAST(:key AS BIGINT))', ['key' => $key]);
    }

    public function testProviderFailureAnswers201WithAnErrorRecord(): void
    {
        $this->factory()->queueFailure('Overloaded');

        $response = $this->turn('Frage');

        self::assertResponseStatusCodeSame(201);
        $data = $response->toArray();
        self::assertSame(MessageStatus::ERROR, $data['status']);
        self::assertStringContainsString('Overloaded', $data['error']);

        $records = $this->records();
        self::assertSame(
            [[1, Message::SPEAKER_USER, MessageStatus::COMPLETE], [2, Message::SPEAKER_ASSISTANT, MessageStatus::ERROR]],
            array_map(static fn (Message $m): array => [$m->getSequence(), $m->getRole(), $m->getStatus()], $records),
        );
        self::assertSame(MessageKind::TEXT, $records[1]->getKind());

        // The failed turn is not replayed: the next turn sends only its own message.
        $this->factory()->queueAnswer('Antwort');
        $this->turn('Nochmal');
        self::assertResponseStatusCodeSame(201);
        $recorded = $this->factory()->getChatProvider()->getRecorded();
        self::assertCount(1, $recorded[array_key_last($recorded)]->messages);
    }

    private function insertStreamingRecord(): void
    {
        $record = new Message()
            ->setConversation($this->conversation)
            ->setSequence(1)
            ->setRole(Message::SPEAKER_ASSISTANT)
            ->setKind(MessageKind::TEXT)
            ->setStatus(MessageStatus::STREAMING);
        $this->conversation->setNextSequence(2);
        $this->entityManager()->persist($record);
        $this->entityManager()->flush();
    }

    private function turn(string $content): ResponseInterface
    {
        return $this->client->request('POST', '/api/conversations/'.$this->conversation->getId().'/messages', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['content' => $content],
        ]);
    }

    /**
     * @return list<Message> ordered by sequence, fresh from the database
     */
    private function records(): array
    {
        $this->entityManager()->clear();

        /** @var list<Message> $records */
        $records = $this->entityManager()->getRepository(Message::class)->findBy(['conversation' => $this->conversation->getId()], ['sequence' => 'ASC']);

        return $records;
    }

    private function factory(): FakeProviderFactory
    {
        $factory = static::getContainer()->get(ProviderFactory::class);
        self::assertInstanceOf(FakeProviderFactory::class, $factory);

        return $factory;
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');

        return $connection;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }
}
