<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\ConversationReplay;

use Dmstr\NeuronAiBundle\Anonymous\ConversationLimitException;
use Dmstr\NeuronAiBundle\Anonymous\SystemActor;
use Dmstr\NeuronAiBundle\Conversation\ConversationStatus;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\ConversationReplay;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\JobProcessor\ConversationReplayJobProcessor;
use Dmstr\NeuronAiBundle\Replay\ConversationReplayRunner;
use Dmstr\NeuronAiBundle\Replay\ConversationReplayStatus;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Uid\Uuid;

/**
 * Kernel-free checks of the replay job: the runner replays the
 * frozen source user messages in order under the triggering user as audit
 * actor, resumes after the user messages the replay conversation already
 * holds (redelivery: no duplicate turn), reads the cancel flag before each
 * turn (cancel before and during the replay), ends `partial` when the
 * replay conversation closes before the last message (user-turn limit) or
 * the turn gate refuses, `completed` when the last turn closes it, `failed`
 * for a deleted source message or a stale reference, keeps an `error` turn
 * as replayed, leaves the replay to another worker holding the lock and
 * stops when the row is gone; the job processor skips ended and foreign
 * replays, writes progress and result, fails for a vanished replay and
 * ends the replay `failed` when something escapes the runner.
 *
 * The entity manager is a stub over this test's state: the replay
 * conversation's user messages are a counter the turn callable raises, the
 * source messages a map, and the cancel flag the row the runner reads.
 */
final class ConversationReplayRunnerTest extends TestCase
{
    private const string OWNER = 'eval-admin';

    private Conversation $conversation;

    private ConversationReplay $replay;

    /** @var array<string, Message> source messages by id */
    private array $sourceMessages = [];

    /** User messages the replay conversation holds. */
    private int $userMessages = 0;

    private ?string $cancelRequestedAt = null;

    private bool $rowExists = true;

    private int $flushes = 0;

    private ?int $failingFlush = null;

    /** @var list<array{actor: string, content: string, conversation: string}> */
    private array $turns = [];

    /** @var array<int, \Closure(): void> 1-based turn number => what happens during that turn */
    private array $during = [];

    /** @var array<int, string> 1-based turn number => status of the final record */
    private array $finalStatus = [];

    private TokenStorage $tokenStorage;

    protected function setUp(): void
    {
        $this->tokenStorage = new TokenStorage();
        $agent = new Agent()->setName('Target')->setSlug('replay-target')->setEnabled(true);
        $version = new AgentVersion($agent, 1, Agent::candidateDefaults(), 'test-admin');
        $this->conversation = new Conversation()
            ->setOwnerSubject(self::OWNER)
            ->setOrigin(Conversation::ORIGIN_REPLAY)
            ->setStatus(ConversationStatus::ACTIVE);
        $this->replay = new ConversationReplay($this->conversation, $version, self::OWNER);
        $this->sources('First question', 'Second question', 'Third question');
    }

    public function testReplaysEverySourceMessageInOrderUnderTheOwner(): void
    {
        $checkpoints = 0;
        $result = $this->runner()->run($this->replay, function (ConversationReplay $replay) use (&$checkpoints): void {
            ++$checkpoints;
        });

        self::assertSame($this->replay, $result);
        self::assertSame(['First question', 'Second question', 'Third question'], array_column($this->turns, 'content'));
        self::assertSame([self::OWNER, self::OWNER, self::OWNER], array_column($this->turns, 'actor'));
        self::assertSame(array_fill(0, 3, (string) $this->conversation->getId()), array_column($this->turns, 'conversation'));
        self::assertSame(ConversationReplayStatus::COMPLETED, $this->replay->getStatus());
        self::assertSame(3, $this->replay->getTurnsReplayed());
        self::assertSame(3, $this->replay->getTurnsTotal());
        self::assertNotNull($this->replay->getStartedAt());
        self::assertNotNull($this->replay->getFinishedAt());
        self::assertNull($this->replay->getErrorMessage());
        self::assertSame(4, $checkpoints, 'after the start and after every turn');
        // The worker's own token is restored after every turn.
        self::assertNull($this->tokenStorage->getToken());
    }

    public function testRedeliveryResumesAfterTheMessagesAlreadyReplayed(): void
    {
        $startedAt = new \DateTimeImmutable('2026-10-03 21:00:00');
        $this->replay->setStatus(ConversationReplayStatus::RUNNING)->setStartedAt($startedAt);
        // As if the first delivery died after the second user record.
        $this->userMessages = 2;

        $this->runner()->run($this->replay);

        self::assertSame(['Third question'], array_column($this->turns, 'content'));
        self::assertSame(ConversationReplayStatus::COMPLETED, $this->replay->getStatus());
        self::assertSame(3, $this->replay->getTurnsReplayed());
        self::assertSame($startedAt, $this->replay->getStartedAt());
    }

    public function testRedeliveryOfAFullyReplayedRunningReplayOnlyCompletesIt(): void
    {
        $this->replay->setStatus(ConversationReplayStatus::RUNNING);
        $this->userMessages = 3;

        $this->runner()->run($this->replay);

        self::assertSame([], $this->turns);
        self::assertSame(ConversationReplayStatus::COMPLETED, $this->replay->getStatus());
    }

    public function testCancelBeforeTheFirstTurnRunsNoTurn(): void
    {
        $this->cancelRequestedAt = '2026-10-03 22:00:00+00';

        $this->runner()->run($this->replay);

        self::assertSame([], $this->turns);
        self::assertSame(ConversationReplayStatus::CANCELLED, $this->replay->getStatus());
        self::assertSame(0, $this->replay->getTurnsReplayed());
        self::assertNotNull($this->replay->getFinishedAt());
    }

    public function testCancelDuringTheReplayStopsAfterTheCurrentTurn(): void
    {
        $this->during[1] = function (): void {
            $this->cancelRequestedAt = '2026-10-03 22:00:00+00';
        };

        $this->runner()->run($this->replay);

        self::assertSame(['First question'], array_column($this->turns, 'content'));
        self::assertSame(ConversationReplayStatus::CANCELLED, $this->replay->getStatus());
        self::assertSame(1, $this->replay->getTurnsReplayed());
    }

    public function testUserTurnLimitOfTheTargetEndsThePartialReplay(): void
    {
        // The target allows two user turns: the second closes the conversation.
        $this->during[2] = function (): void {
            $this->conversation
                ->setStatus(ConversationStatus::CLOSED)
                ->setCloseReason(Conversation::CLOSE_REASON_USER_TURN_LIMIT);
        };

        $this->runner()->run($this->replay);

        self::assertSame(['First question', 'Second question'], array_column($this->turns, 'content'));
        self::assertSame(ConversationReplayStatus::PARTIAL, $this->replay->getStatus());
        self::assertSame(2, $this->replay->getTurnsReplayed());
        self::assertSame(3, $this->replay->getTurnsTotal());
        self::assertSame(Conversation::CLOSE_REASON_USER_TURN_LIMIT, $this->conversation->getCloseReason());
    }

    public function testClosingWithTheLastSourceMessageIsCompleted(): void
    {
        $this->during[3] = function (): void {
            $this->conversation->setStatus(ConversationStatus::CLOSED)->setCloseReason(Conversation::CLOSE_REASON_USER_TURN_LIMIT);
        };

        $this->runner()->run($this->replay);

        self::assertCount(3, $this->turns);
        self::assertSame(ConversationReplayStatus::COMPLETED, $this->replay->getStatus());
    }

    public function testTurnGateRefusalEndsThePartialReplay(): void
    {
        $this->during[2] = static function (): void {
            throw ConversationLimitException::create(ConversationLimitException::CODE_USER_TURN_LIMIT, 'The conversation reached its user-turn limit.');
        };

        $this->runner()->run($this->replay);

        self::assertSame(ConversationReplayStatus::PARTIAL, $this->replay->getStatus());
        self::assertSame(1, $this->replay->getTurnsReplayed());
    }

    public function testErrorAndRefusedTurnsCountAsReplayed(): void
    {
        $this->finalStatus[1] = MessageStatus::ERROR;

        $this->runner()->run($this->replay);

        self::assertCount(3, $this->turns);
        self::assertSame(ConversationReplayStatus::COMPLETED, $this->replay->getStatus());
        self::assertSame(3, $this->replay->getTurnsReplayed());
    }

    public function testDeletedSourceEndsTheReplayFailed(): void
    {
        $this->during[1] = function (): void {
            // The source conversation was deleted: its messages cascade.
            $this->sourceMessages = [];
        };

        $this->runner()->run($this->replay);

        self::assertSame(['First question'], array_column($this->turns, 'content'));
        self::assertSame(ConversationReplayStatus::FAILED, $this->replay->getStatus());
        self::assertSame(1, $this->replay->getTurnsReplayed());
        self::assertStringContainsString('source conversation was deleted', (string) $this->replay->getErrorMessage());
    }

    public function testStaleReferenceEndsTheReplayFailedWithItsPath(): void
    {
        $this->during[1] = static function (): void {
            throw new UnprocessableEntityHttpException('configuration/collections/0: the collection no longer exists.');
        };

        $this->runner()->run($this->replay);

        self::assertSame(ConversationReplayStatus::FAILED, $this->replay->getStatus());
        self::assertSame('configuration/collections/0: the collection no longer exists.', $this->replay->getErrorMessage());
        self::assertSame(0, $this->replay->getTurnsReplayed());
    }

    public function testLockHeldByAnotherWorkerLeavesTheReplayRunning(): void
    {
        $this->during[1] = static function (): void {
            throw new ConflictHttpException('Another turn of this conversation is running.');
        };

        $result = $this->runner()->run($this->replay);

        self::assertSame($this->replay, $result);
        self::assertSame(ConversationReplayStatus::RUNNING, $this->replay->getStatus());
        self::assertNull($this->replay->getFinishedAt());
    }

    public function testDeletedReplayConversationStopsWithoutATurn(): void
    {
        $this->rowExists = false;

        self::assertNull($this->runner()->run($this->replay));
        self::assertSame([], $this->turns);
    }

    // --- job processor --------------------------------------------------

    public function testJobRunsTheReplayAndWritesProgressAndResult(): void
    {
        $job = $this->job();
        $this->replay->setJobId($job->getId());

        $this->jobProcessor()->process($job);

        self::assertSame(ConversationReplayStatus::COMPLETED, $this->replay->getStatus());
        self::assertSame(100, $job->getProgress());
        self::assertSame(['status' => 'completed', 'turnsReplayed' => 3, 'turnsTotal' => 3], $job->getResultData());
        self::assertTrue($this->jobProcessor()->supports('conversation_replay'));
        self::assertFalse($this->jobProcessor()->supports('eval_run_start'));
    }

    public function testJobOfAnEndedReplayIsANoOp(): void
    {
        $job = $this->job();
        $this->replay->setJobId($job->getId())->finish(ConversationReplayStatus::COMPLETED, new \DateTimeImmutable());

        $this->jobProcessor()->process($job);

        self::assertSame([], $this->turns);
        self::assertArrayHasKey('skipped', (array) $job->getResultData());
    }

    public function testJobOfAnotherJobIsANoOp(): void
    {
        $this->replay->setJobId('11111111-1111-4111-8111-111111111111');
        $job = $this->job();

        $this->jobProcessor()->process($job);

        self::assertSame([], $this->turns);
        self::assertStringContainsString('belongs to job', (string) ($job->getResultData()['skipped'] ?? ''));
        self::assertSame(ConversationReplayStatus::PENDING, $this->replay->getStatus());
    }

    public function testJobOfAVanishedReplayFails(): void
    {
        $this->rowExists = false;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $this->jobProcessor()->process($this->job());
    }

    public function testAnErrorEscapingTheRunnerEndsTheReplayFailed(): void
    {
        $job = $this->job();
        $this->replay->setJobId($job->getId());
        // The flush after the first turn fails (the start flush is #1).
        $this->failingFlush = 3;

        try {
            $this->jobProcessor()->process($job);
            self::fail('The error must be rethrown.');
        } catch (\RuntimeException $e) {
            self::assertSame('database gone', $e->getMessage());
        }

        self::assertSame(ConversationReplayStatus::FAILED, $this->replay->getStatus());
        self::assertSame('database gone', $this->replay->getErrorMessage());
    }

    // --- helpers --------------------------------------------------------

    private function sources(string ...$contents): void
    {
        $source = new Conversation()->setOwnerSubject('someone');
        $ids = [];
        foreach (array_values($contents) as $i => $content) {
            $message = new Message()
                ->setConversation($source)
                ->setSequence(2 * $i + 1)
                ->setRole(Message::SPEAKER_USER)
                ->setKind(MessageKind::TEXT)
                ->setStatus(MessageStatus::COMPLETE)
                ->setContent($content);
            $this->sourceMessages[(string) $message->getId()] = $message;
            $ids[] = (string) $message->getId();
        }
        $this->replay->setSourceConversation($source)->setSourceMessageIds($ids);
    }

    private function job(): Job
    {
        return new Job()->setType(ConversationReplay::JOB_TYPE)->setInputData(['replay' => (string) $this->replay->getId(), 'ownerSubject' => self::OWNER]);
    }

    private function jobProcessor(): ConversationReplayJobProcessor
    {
        return new ConversationReplayJobProcessor($this->runner(), $this->entityManager(), new MockClock('2026-10-03 22:30:00'));
    }

    private function runner(): ConversationReplayRunner
    {
        $turn = function (Conversation $conversation, string $content): Message {
            $this->turns[] = [
                'actor' => (string) $this->tokenStorage->getToken()?->getUserIdentifier(),
                'content' => $content,
                'conversation' => (string) $conversation->getId(),
            ];
            $number = \count($this->turns);
            // A refusing gate or a stale reference throws before the user
            // record is stored.
            if (isset($this->during[$number])) {
                ($this->during[$number])();
            }
            ++$this->userMessages;

            return new Message()
                ->setConversation($conversation)
                ->setSequence(2 * $number)
                ->setRole(Message::SPEAKER_ASSISTANT)
                ->setKind(MessageKind::TEXT)
                ->setStatus($this->finalStatus[$number] ?? MessageStatus::COMPLETE)
                ->setContent('Answer '.$number);
        };

        return new ConversationReplayRunner(
            $this->entityManager(),
            $this->createStub(ManagerRegistry::class),
            $turn,
            new SystemActor($this->tokenStorage),
            new MockClock('2026-10-03 22:30:00'),
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        $messages = $this->createStub(EntityRepository::class);
        $messages->method('count')->willReturnCallback(function (array $criteria): int {
            self::assertSame($this->conversation, $criteria['conversation'] ?? null);
            self::assertSame(Message::SPEAKER_USER, $criteria['role'] ?? null);

            return $this->userMessages;
        });

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(function (string $sql, array $params): array|false {
            self::assertStringContainsString('neuron_conversation_replay', $sql);
            self::assertSame($this->replay->getId()->toRfc4122(), $params['id']);

            return $this->rowExists ? ['cancel_requested_at' => $this->cancelRequestedAt] : false;
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            fn (string $class): EntityRepository => Message::class === $class ? $messages : self::fail('Unexpected repository '.$class),
        );
        $entityManager->method('find')->willReturnCallback(function (string $class, mixed $id): ?object {
            self::assertInstanceOf(Uuid::class, $id);
            if (Message::class === $class) {
                return $this->sourceMessages[(string) $id] ?? null;
            }
            self::assertSame(ConversationReplay::class, $class);

            return $this->rowExists && $id->equals($this->replay->getId()) ? $this->replay : null;
        });
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('isOpen')->willReturn(true);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
            if ($this->flushes === $this->failingFlush) {
                throw new \RuntimeException('database gone');
            }
        });

        return $entityManager;
    }
}
