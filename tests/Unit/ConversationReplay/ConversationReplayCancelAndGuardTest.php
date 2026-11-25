<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\ConversationReplay;

use ApiPlatform\Metadata\Post;
use Dmstr\NeuronAiBundle\Anonymous\ConversationLimitException;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\ConversationReplay;
use Dmstr\NeuronAiBundle\Replay\ConversationReplayGuard;
use Dmstr\NeuronAiBundle\Replay\ConversationReplayStatus;
use Dmstr\NeuronAiBundle\State\ConversationReplayCancelProcessor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Kernel-free checks of the cancel operation of a conversation replay
 * (flag set once, 422 after the end) and of the guard against manual turns
 * on a replay conversation: 422 with the
 * existing code `conversation_closed` while the replay is pending or
 * running, nothing for an ended replay, an ordinary or evaluation
 * conversation (which is not even looked up).
 */
final class ConversationReplayCancelAndGuardTest extends TestCase
{
    private int $flushes = 0;

    /** @var list<string> */
    private array $queries = [];

    public function testCancelSetsTheFlagOnceAndKeepsTheStatus(): void
    {
        $replay = self::replay();
        $clock = new MockClock('2026-10-03 22:00:00');
        $processor = new ConversationReplayCancelProcessor($this->entityManager(false), $clock);

        $result = $processor->process($replay, new Post(name: ConversationReplay::OPERATION_CANCEL));

        self::assertSame($replay, $result);
        self::assertEquals(new \DateTimeImmutable('2026-10-03 22:00:00'), $replay->getCancelRequestedAt());
        self::assertSame(ConversationReplayStatus::PENDING, $replay->getStatus());
        self::assertSame(1, $this->flushes);

        $clock->modify('+1 minute');
        $processor->process($replay, new Post(name: ConversationReplay::OPERATION_CANCEL));
        self::assertEquals(new \DateTimeImmutable('2026-10-03 22:00:00'), $replay->getCancelRequestedAt());
        self::assertSame(1, $this->flushes);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function finalStatuses(): iterable
    {
        foreach ([ConversationReplayStatus::COMPLETED, ConversationReplayStatus::PARTIAL, ConversationReplayStatus::CANCELLED, ConversationReplayStatus::FAILED] as $status) {
            yield $status => [$status];
        }
    }

    #[DataProvider('finalStatuses')]
    public function testCancelOfAnEndedReplayAnswers422(string $status): void
    {
        $replay = self::replay()->finish($status, new \DateTimeImmutable());
        $processor = new ConversationReplayCancelProcessor($this->entityManager(false), new MockClock());

        try {
            $processor->process($replay, new Post(name: ConversationReplay::OPERATION_CANCEL));
            self::fail('An ended replay cannot be cancelled.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertStringContainsString($status, $e->getMessage());
        }
        self::assertNull($replay->getCancelRequestedAt());
        self::assertSame(0, $this->flushes);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function activeStatuses(): iterable
    {
        yield 'pending' => [ConversationReplayStatus::PENDING];
        yield 'running' => [ConversationReplayStatus::RUNNING];
    }

    #[DataProvider('activeStatuses')]
    public function testGuardRefusesManualTurnsWhileTheReplayRuns(string $status): void
    {
        $conversation = new Conversation()->setOwnerSubject('eval-admin')->setOrigin(Conversation::ORIGIN_REPLAY);

        try {
            new ConversationReplayGuard($this->entityManager($status))->assertAcceptsManualTurn($conversation);
            self::fail('A manual turn on a running replay must be refused.');
        } catch (ConversationLimitException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertSame(ConversationLimitException::CODE_CLOSED, $e->getErrorCode());
            self::assertStringContainsString($status, (string) $e->getDetail());
        }
        self::assertCount(1, $this->queries);
    }

    #[DataProvider('finalStatuses')]
    public function testGuardAcceptsManualTurnsAfterTheReplay(string $status): void
    {
        $conversation = new Conversation()->setOwnerSubject('eval-admin')->setOrigin(Conversation::ORIGIN_REPLAY);

        new ConversationReplayGuard($this->entityManager($status))->assertAcceptsManualTurn($conversation);

        self::assertCount(1, $this->queries);
    }

    public function testGuardIgnoresOtherOriginsAndMissingRecords(): void
    {
        $guard = new ConversationReplayGuard($this->entityManager(ConversationReplayStatus::RUNNING));
        $guard->assertAcceptsManualTurn(new Conversation()->setOwnerSubject('a'));
        $guard->assertAcceptsManualTurn(new Conversation()->setOwnerSubject('a')->setOrigin(Conversation::ORIGIN_EVAL));
        self::assertSame([], $this->queries, 'no lookup for other origins');

        new ConversationReplayGuard($this->entityManager(false))->assertAcceptsManualTurn(new Conversation()->setOwnerSubject('a')->setOrigin(Conversation::ORIGIN_REPLAY));
        self::assertCount(1, $this->queries);
    }

    private static function replay(): ConversationReplay
    {
        $agent = new Agent()->setName('Target')->setSlug('replay-target')->setEnabled(true);

        return new ConversationReplay(
            new Conversation()->setOwnerSubject('eval-admin')->setOrigin(Conversation::ORIGIN_REPLAY),
            new AgentVersion($agent, 1, Agent::candidateDefaults(), 'test-admin'),
            'eval-admin',
        );
    }

    /**
     * @param string|false $status the stored replay status the guard reads; false: no record
     */
    private function entityManager(string|false $status): EntityManagerInterface
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(function (string $sql) use ($status): string|false {
            self::assertStringContainsString('neuron_conversation_replay', $sql);
            $this->queries[] = $sql;

            return $status;
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        return $entityManager;
    }
}
