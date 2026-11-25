<?php

// file generated with AI assistance: Claude Code - 2026-10-13 23:25:27 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Agent;

use Dmstr\NeuronAiBundle\Agent\Preview\AgentPreviewPurgeReport;
use Dmstr\NeuronAiBundle\Agent\Preview\AgentPreviewPurger;
use Dmstr\NeuronAiBundle\Agent\Preview\AgentPreviewRetentionInterface;
use Dmstr\NeuronAiBundle\Agent\Preview\PurgeAgentPreviewsMessage;
use Dmstr\NeuronAiBundle\Anonymous\SystemActor;
use Dmstr\NeuronAiBundle\Command\AgentPreviewsPurgeCommand;
use Dmstr\NeuronAiBundle\Conversation\TurnLock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

/**
 * The parts of {@see AgentPreviewPurger} that need no database: without a
 * retention port a run only warns, a retention of 0 touches nothing, the
 * report text, and the option handling of `neuron:agent:previews:purge`.
 * Selection, skip rules and deletion are covered by
 * the integration test `AgentPreviewPurgeTest`.
 */
final class AgentPreviewPurgerTest extends TestCase
{
    public function testWithoutRetentionPortARunWarnsAndDoesNothing(): void
    {
        $logger = $this->logger();
        $purger = $this->purger(null, $logger);

        $report = $purger->run();

        self::assertFalse($report->ran);
        self::assertTrue($report->isEmpty());
        self::assertSame(['warning'], array_column($logger->records, 0));
        self::assertStringContainsString('not purged', $logger->records[0][1]);
        self::assertSame(AgentPreviewRetentionInterface::class, $logger->records[0][2]['interface']);
    }

    public function testRetentionZeroPurgesNothing(): void
    {
        $purger = $this->purger($this->retention(0));

        $report = $purger->run();

        self::assertTrue($report->ran);
        self::assertSame(0, $report->retentionDays);
        self::assertTrue($report->isEmpty());
        self::assertSame(0, $report->deleted);
    }

    public function testNegativeRetentionIsTreatedAsZero(): void
    {
        $report = $this->purger($this->retention(-3))->run(true);

        self::assertTrue($report->ran);
        self::assertTrue($report->dryRun);
        self::assertSame(0, $report->retentionDays);
        self::assertTrue($report->isEmpty());
    }

    public function testReportText(): void
    {
        $report = new AgentPreviewPurgeReport(
            candidates: 4,
            deleted: 2,
            skipped: [AgentPreviewPurgeReport::SKIP_ACTIVE_EVAL_RUN => 1, AgentPreviewPurgeReport::SKIP_REPLAY_TARGET => 1],
            evalRuns: 3,
            conversations: 5,
            retentionDays: 7,
        );

        self::assertSame(2, $report->skippedTotal());
        self::assertFalse($report->isEmpty());
        self::assertSame('candidates 4, deleted 2 (eval runs 3, conversations 5), skipped 2 (active_eval_run: 1, replay_target: 1)', (string) $report);
        self::assertSame('dry run: candidates 0, deleted 0 (eval runs 0, conversations 0), skipped 0', (string) new AgentPreviewPurgeReport(dryRun: true));

        $notRun = AgentPreviewPurgeReport::notRun(true);
        self::assertFalse($notRun->ran);
        self::assertTrue($notRun->dryRun);
    }

    public function testCommandRefusesDryRunWithAsync(): void
    {
        $bus = $this->bus();
        $tester = new CommandTester(new AgentPreviewsPurgeCommand($this->purger(null), $bus));

        $status = $tester->execute(['--dry-run' => true, '--async' => true]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame([], $bus->dispatched);
    }

    public function testCommandDispatchesTheMessageWithAsync(): void
    {
        $bus = $this->bus();
        $tester = new CommandTester(new AgentPreviewsPurgeCommand($this->purger(null), $bus));

        $tester->execute(['--async' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertCount(1, $bus->dispatched);
        self::assertInstanceOf(PurgeAgentPreviewsMessage::class, $bus->dispatched[0]);
    }

    public function testCommandWarnsWithoutRetentionPort(): void
    {
        $tester = new CommandTester(new AgentPreviewsPurgeCommand($this->purger(null)));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('no agent preview retention', $tester->getDisplay());
    }

    public function testCommandReportsRetentionZero(): void
    {
        $tester = new CommandTester(new AgentPreviewsPurgeCommand($this->purger($this->retention(0))));

        $tester->execute(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('retention of agent previews is 0', $tester->getDisplay());
    }

    /**
     * A purger whose entity manager and turn lock fail the test when they
     * are used: the cases here must not reach the database.
     */
    private function purger(?AgentPreviewRetentionInterface $retention, ?AbstractLogger $logger = null): AgentPreviewPurger
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method(self::anything());
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method(self::anything());

        return new AgentPreviewPurger(
            $entityManager,
            new TurnLock($connection),
            new SystemActor(new TokenStorage()),
            $retention,
            new MockClock('2026-10-13 12:00:00 UTC'),
            $logger,
        );
    }

    private function retention(int $days): AgentPreviewRetentionInterface
    {
        return new class($days) implements AgentPreviewRetentionInterface {
            public function __construct(private readonly int $days)
            {
            }

            public function retentionDays(): int
            {
                return $this->days;
            }
        };
    }

    /**
     * @return AbstractLogger&object{records: list<array{string, string, array<string, mixed>}>}
     */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{string, string, array<string, mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
    }

    /**
     * @return MessageBusInterface&object{dispatched: list<object>}
     */
    private function bus(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            /** @var list<object> */
            public array $dispatched = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->dispatched[] = $message;

                return new Envelope($message, $stamps);
            }
        };
    }
}
