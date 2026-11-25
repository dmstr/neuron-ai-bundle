<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:26 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Conversation\Anonymous;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousConversationPurger;
use Dmstr\NeuronAiBundle\Anonymous\CapabilityToken;
use Dmstr\NeuronAiBundle\Anonymous\PurgeAnonymousConversationsHandler;
use Dmstr\NeuronAiBundle\Anonymous\PurgeAnonymousConversationsMessage;
use Dmstr\NeuronAiBundle\Anonymous\PurgeReport;
use Dmstr\NeuronAiBundle\Conversation\ConversationStatus;
use Dmstr\NeuronAiBundle\Conversation\TurnLock;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Tests\Integration\Conversation\AccessTest;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Support\AnonymousFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * The scheduled purge of anonymous conversations
 * ({@see AnonymousConversationPurger}): retention close and deletion after
 * the deletion delay, both audited under `system:neuron:purge`; untouched
 * conversations of users; idempotence; a conversation whose advisory lock
 * a second database session holds is skipped; the message handler, the
 * routing of the message to `maintenance` and the console command.
 *
 * Retention and deletion delay are set to one day through the settings
 * API. Fixtures are written through the entity manager; their times are
 * moved back with plain UPDATE statements. The purger runs on the real
 * clock.
 *
 * Needs the migrated test database (CI only). Cleanup as in
 * {@see AnonymousFixtures} (anonymous conversations, conversation
 * audit entries, the settings row) plus the user conversations of
 * {@see AccessTest}.
 */
#[Group('database')]
final class PurgeTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private Client $client;

    private ?Connection $second = null;

    protected function setUp(): void
    {
        // Counts or scans all rows: start from an empty database.
        TestDatabase::migrate(static::bootKernel());
        TestDatabase::purge(TestDatabase::connection(static::$kernel));
        // AccessTest's conversations run on the fixture provider connection,
        // which AnonymousFixtures::cleanUp() removes.
        AccessTest::cleanUp();
        AnonymousFixtures::cleanUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        AnonymousFixtures::configure(['retention_days' => 1, 'deletion_delay_days' => 1]);
    }

    protected function tearDown(): void
    {
        $this->second?->close();
        $this->second = null;
        // AccessTest's conversations run on the fixture provider connection,
        // which AnonymousFixtures::cleanUp() removes.
        AccessTest::cleanUp();
        AnonymousFixtures::cleanUp();
        parent::tearDown();
    }

    public function testClosesExpiredAndDeletesDueConversationsOnly(): void
    {
        [$expired, $expiredToken] = $this->anonymousConversation();
        $this->moveBack($expired, 'created_at', 2);
        [$fresh] = $this->anonymousConversation();
        [$due, $dueToken] = $this->anonymousConversation(ConversationStatus::CLOSED);
        $this->moveBack($due, 'closed_at', 2);
        [$recent] = $this->anonymousConversation(ConversationStatus::CLOSED);
        $user = $this->userConversation(10);
        $before = [
            $fresh => AnonymousFixtures::row($fresh),
            $recent => AnonymousFixtures::row($recent),
            $user => AnonymousFixtures::row($user),
        ];

        $report = $this->purger()->run();

        self::assertTrue($report->ran);
        self::assertSame([1, 1, 0], [$report->closed, $report->deleted, $report->skipped]);

        $row = AnonymousFixtures::row($expired);
        self::assertSame(ConversationStatus::CLOSED, $row['status']);
        self::assertSame(Conversation::CLOSE_REASON_RETENTION, $row['close_reason']);
        self::assertNotNull($row['closed_at']);
        self::assertSame(1, $this->messageCount($expired));

        self::assertFalse($this->exists($due));
        self::assertSame(0, $this->messageCount($due));

        foreach ($before as $id => $row) {
            self::assertEquals($row, AnonymousFixtures::row($id), $id);
            self::assertSame(1, $this->messageCount($id), $id);
        }

        $closes = $this->auditEntries($expired, 'update');
        self::assertCount(1, $closes);
        self::assertSame(AnonymousConversationPurger::ACTOR, $closes[0]['username']);
        $removals = $this->auditEntries($due, 'remove');
        self::assertCount(1, $removals);
        self::assertSame(AnonymousConversationPurger::ACTOR, $removals[0]['username']);

        // The token of the deleted conversation is gone, the closed one
        // still reads its conversation.
        $this->client->request('GET', '/api/conversations/'.$due, ['headers' => AnonymousFixtures::tokenHeaders($dueToken)]);
        self::assertResponseStatusCodeSame(401);
        $read = $this->client->request('GET', '/api/conversations/'.$expired, ['headers' => AnonymousFixtures::tokenHeaders($expiredToken)]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(ConversationStatus::CLOSED, $read->toArray()['status']);

        // Idempotent: the second run finds nothing to do.
        $counts = $this->counts();
        $again = $this->purger()->run();

        self::assertTrue($again->ran);
        self::assertTrue($again->isEmpty(), (string) $again);
        self::assertSame($counts, $this->counts());
    }

    public function testConversationWhoseLockIsHeldIsSkipped(): void
    {
        [$due] = $this->anonymousConversation(ConversationStatus::CLOSED);
        $this->moveBack($due, 'closed_at', 2);
        $key = TurnLock::keyFor(Uuid::fromString($due));
        $this->second = DriverManager::getConnection(AnonymousFixtures::connection()->getParams());
        self::assertTrue((bool) $this->second->fetchOne('SELECT pg_try_advisory_lock(CAST(:key AS BIGINT))', ['key' => $key]));

        $report = $this->purger()->run();

        self::assertSame([0, 0, 1], [$report->closed, $report->deleted, $report->skipped]);
        self::assertTrue($this->exists($due));

        $this->second->fetchOne('SELECT pg_advisory_unlock(CAST(:key AS BIGINT))', ['key' => $key]);
        $this->second->close();
        $this->second = null;

        $report = $this->purger()->run();

        self::assertSame([0, 1, 0], [$report->closed, $report->deleted, $report->skipped]);
        self::assertFalse($this->exists($due));
    }

    public function testHandlerRunsThePurge(): void
    {
        [$due] = $this->anonymousConversation(ConversationStatus::CLOSED);
        $this->moveBack($due, 'closed_at', 2);

        $handler = static::getContainer()->get(PurgeAnonymousConversationsHandler::class);
        self::assertInstanceOf(PurgeAnonymousConversationsHandler::class, $handler);
        $handler(new PurgeAnonymousConversationsMessage());

        self::assertFalse($this->exists($due));
    }

    public function testDispatchedMessageIsRoutedToTheMaintenanceTransport(): void
    {
        [$due] = $this->anonymousConversation(ConversationStatus::CLOSED);
        $this->moveBack($due, 'closed_at', 2);
        $transport = static::getContainer()->get('messenger.transport.maintenance');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
        $bus = static::getContainer()->get('messenger.default_bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        $bus->dispatch(new PurgeAnonymousConversationsMessage());

        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(PurgeAnonymousConversationsMessage::class, $sent[0]->getMessage());
        // Queued for a worker, not handled here.
        self::assertTrue($this->exists($due));
    }

    public function testCommandRunsThePurgeAndPrintsTheReport(): void
    {
        [$due] = $this->anonymousConversation(ConversationStatus::CLOSED);
        $this->moveBack($due, 'closed_at', 2);
        $kernel = static::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('neuron:anonymous:purge'));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Anonymous conversations: '.new PurgeReport(0, 1, 0).'.', $tester->getDisplay());
        self::assertFalse($this->exists($due));
    }

    /**
     * An anonymous conversation with one message and a known capability
     * token, written through the entity manager (cleared afterwards). A
     * closed one is closed now with reason `retention`.
     *
     * @return array{string, string} conversation id and token
     */
    private function anonymousConversation(string $status = ConversationStatus::ACTIVE): array
    {
        $token = (new CapabilityToken())->generate();
        $conversation = (new Conversation())
            ->setAnonymous(true)
            ->setTokenHash(CapabilityToken::hash($token))
            ->setAddressHash(str_repeat('b', 64))
            ->setProviderModel(AnonymousFixtures::releaseModel())
            ->setRetrievalDepth(4)
            ->setStatus($status);
        $conversation->setOwnerSubject($conversation->anonymousOwnerSubject());
        if (ConversationStatus::CLOSED === $status) {
            $conversation->setClosedAt(new \DateTimeImmutable())->setCloseReason(Conversation::CLOSE_REASON_RETENTION);
        }

        $entityManager = AnonymousFixtures::entityManager();
        $entityManager->persist($conversation);
        $entityManager->flush();
        AccessTest::message($conversation, 1);
        $entityManager->clear();

        return [(string) $conversation->getId(), $token];
    }

    /**
     * A conversation of AccessTest::USER_A with one message, created
     * $daysAgo days ago.
     */
    private function userConversation(int $daysAgo): string
    {
        $conversation = AccessTest::conversation(AccessTest::USER_A);
        AccessTest::message($conversation, 1);
        AnonymousFixtures::entityManager()->clear();
        $id = (string) $conversation->getId();
        $this->moveBack($id, 'created_at', $daysAgo);

        return $id;
    }

    /**
     * Sets $column (`created_at` or `closed_at`) to $days days before now.
     */
    private function moveBack(string $id, string $column, int $days): void
    {
        \assert(\in_array($column, ['created_at', 'closed_at'], true));
        AnonymousFixtures::connection()->executeStatement(
            \sprintf("UPDATE neuron_conversation SET %s = NOW() - CAST(:days AS INTEGER) * INTERVAL '1 day' WHERE id = :id", $column),
            ['days' => $days, 'id' => $id],
        );
    }

    private function purger(): AnonymousConversationPurger
    {
        $purger = static::getContainer()->get(AnonymousConversationPurger::class);
        self::assertInstanceOf(AnonymousConversationPurger::class, $purger);

        return $purger;
    }

    private function exists(string $id): bool
    {
        return false !== AnonymousFixtures::connection()->fetchOne('SELECT 1 FROM neuron_conversation WHERE id = :id', ['id' => $id]);
    }

    private function messageCount(string $id): int
    {
        return (int) AnonymousFixtures::connection()->fetchOne('SELECT COUNT(*) FROM neuron_message WHERE conversation_id = :id', ['id' => $id]);
    }

    /**
     * @return list<array{action: string, username: string|null, data: string|null}>
     */
    private function auditEntries(string $id, string $action): array
    {
        return array_values(array_filter(AnonymousFixtures::auditEntries($id), static fn (array $entry): bool => $action === $entry['action']));
    }

    /**
     * @return array{int, int} conversation and message rows
     */
    private function counts(): array
    {
        $connection = AnonymousFixtures::connection();

        return [
            (int) $connection->fetchOne('SELECT COUNT(*) FROM neuron_conversation'),
            (int) $connection->fetchOne('SELECT COUNT(*) FROM neuron_message'),
        ];
    }
}
