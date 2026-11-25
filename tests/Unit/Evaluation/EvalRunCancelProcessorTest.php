<?php

// file generated with AI assistance: Claude Code - 2026-10-03 19:10:10 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Evaluation;

use ApiPlatform\Metadata\Post;
use Dmstr\NeuronAiBundle\Entity\EvalRun;
use Dmstr\NeuronAiBundle\Evaluation\EvalRunStatus;
use Dmstr\NeuronAiBundle\State\EvalRunCancelProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Kernel-free checks of `POST /eval_runs/{id}/cancel`: a pending or
 * running run gets `cancelRequestedAt` (a repeated request keeps the first
 * time and does not flush again), an ended run answers 422 unchanged.
 */
final class EvalRunCancelProcessorTest extends TestCase
{
    private int $flushes = 0;

    private function processor(MockClock $clock): EvalRunCancelProcessor
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        return new EvalRunCancelProcessor($entityManager, $clock);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function activeStatuses(): iterable
    {
        yield 'pending' => [EvalRunStatus::PENDING];
        yield 'running' => [EvalRunStatus::RUNNING];
    }

    #[DataProvider('activeStatuses')]
    public function testFlagsAnActiveRunOnce(string $status): void
    {
        $clock = new MockClock('2026-10-03 19:00:00');
        $processor = $this->processor($clock);
        $run = new EvalRun()->setStatus($status);

        self::assertSame($run, $processor->process($run, new Post(name: EvalRun::OPERATION_CANCEL)));
        self::assertEquals(new \DateTimeImmutable('2026-10-03 19:00:00'), $run->getCancelRequestedAt());
        self::assertTrue($run->isCancelRequested());
        self::assertSame($status, $run->getStatus());
        self::assertSame(1, $this->flushes);

        $clock->modify('+5 minutes');
        $processor->process($run, new Post(name: EvalRun::OPERATION_CANCEL));
        self::assertEquals(new \DateTimeImmutable('2026-10-03 19:00:00'), $run->getCancelRequestedAt());
        self::assertSame(1, $this->flushes);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function endedStatuses(): iterable
    {
        yield 'completed' => [EvalRunStatus::COMPLETED];
        yield 'cancelled' => [EvalRunStatus::CANCELLED];
        yield 'failed' => [EvalRunStatus::FAILED];
    }

    #[DataProvider('endedStatuses')]
    public function testEndedRunAnswers422(string $status): void
    {
        $run = new EvalRun()->setStatus($status);

        try {
            $this->processor(new MockClock())->process($run, new Post(name: EvalRun::OPERATION_CANCEL));
            self::fail('Expected a 422.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertStringContainsString($status, $e->getMessage());
        }
        self::assertNull($run->getCancelRequestedAt());
        self::assertSame(0, $this->flushes);
    }

    public function testFinishAcceptsFinalStatusesOnly(): void
    {
        $at = new \DateTimeImmutable('2026-10-03 19:30:00');
        $run = new EvalRun()->finish(EvalRunStatus::CANCELLED, $at);
        self::assertSame(EvalRunStatus::CANCELLED, $run->getStatus());
        self::assertSame($at, $run->getFinishedAt());
        self::assertFalse($run->isActive());

        $this->expectException(\InvalidArgumentException::class);
        new EvalRun()->finish(EvalRunStatus::RUNNING, $at);
    }
}
