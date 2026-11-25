<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:34:45 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Conversation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\DoctrineAuditLog\Entity\LogEntry;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Creating and deleting a conversation is audited with the acting user
 * (Gedmo Loggable on Conversation, actor from the test kernel's actor provider), and
 * the messages go with the conversation (database cascade, not audited).
 * Owners delete their own conversations, holders of
 * `neuronai:conversation:delete_any` (default `ROLE_ADMIN`) every conversation.
 *
 * Relies on `X-Test-User` and the firewall rows like {@see AccessTest},
 * whose fixtures and helpers it uses. Needs the migrated test database;
 * runs in CI. Log entries are cleaned by `objectClass`.
 */
#[Group('database')]
final class DeletionAuditTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        AccessTest::cleanUp();
    }

    protected function tearDown(): void
    {
        AccessTest::cleanUp();
        parent::tearDown();
    }

    public function testOwnerDeletionIsAuditedAndRemovesTheMessages(): void
    {
        $id = $this->create(AccessTest::USER_A);
        $conversation = $this->find($id);
        AccessTest::message($conversation, 1);
        AccessTest::message($conversation, 2);
        self::assertSame(2, AccessTest::messageCount($conversation));

        AccessTest::send(static::createClient(), 'DELETE', '/api/conversations/'.$id, AccessTest::USER_A);
        self::assertResponseStatusCodeSame(204);

        self::assertSame(0, AccessTest::conversationCount());
        self::assertSame(0, AccessTest::messageCount($conversation));

        $entries = $this->entries($id);
        self::assertSame(['create', 'remove'], array_map(static fn (LogEntry $entry): string => $entry->getAction(), $entries));
        self::assertSame([AccessTest::USER_A, AccessTest::USER_A], array_map(static fn (LogEntry $entry): ?string => $entry->getUsername(), $entries));
        foreach ($entries as $entry) {
            self::assertSame(Conversation::class, $entry->getObjectClass());
        }
    }

    public function testDeletionByAHolderOfDeleteAnyIsAuditedWithTheHolder(): void
    {
        $id = $this->create(AccessTest::USER_A);
        AccessTest::message($this->find($id), 1);

        AccessTest::send(static::createClient(), 'DELETE', '/api/conversations/'.$id, AccessTest::ADMIN);
        self::assertResponseStatusCodeSame(204);

        self::assertSame(0, AccessTest::conversationCount());
        $entries = $this->entries($id);
        self::assertSame(['create', 'remove'], array_map(static fn (LogEntry $entry): string => $entry->getAction(), $entries));
        self::assertSame([AccessTest::USER_A, AccessTest::ADMIN], array_map(static fn (LogEntry $entry): ?string => $entry->getUsername(), $entries));
    }

    public function testRejectedDeletionIsNotAudited(): void
    {
        $id = $this->create(AccessTest::USER_A);

        AccessTest::send(static::createClient(), 'DELETE', '/api/conversations/'.$id, AccessTest::USER_B);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(1, AccessTest::conversationCount());
        self::assertSame(['create'], array_map(static fn (LogEntry $entry): string => $entry->getAction(), $this->entries($id)));
    }

    /**
     * Creates a conversation through the API, so the create entry carries
     * the acting user.
     *
     * @return string the conversation id
     */
    private function create(string $identifier): string
    {
        $response = AccessTest::send(static::createClient(), 'POST', '/api/conversations', $identifier, AccessTest::createBody(['title' => 'Audit']));
        self::assertResponseStatusCodeSame(201);

        return (string) $response->toArray()['id'];
    }

    private function find(string $id): Conversation
    {
        $conversation = $this->entityManager()->find(Conversation::class, $id);
        self::assertInstanceOf(Conversation::class, $conversation);

        return $conversation;
    }

    /**
     * Log entries of the conversation, oldest first.
     *
     * @return list<LogEntry>
     */
    private function entries(string $id): array
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();

        return array_values($entityManager->getRepository(LogEntry::class)->findBy(
            ['objectClass' => Conversation::class, 'objectId' => $id],
            ['version' => 'ASC'],
        ));
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }
}
