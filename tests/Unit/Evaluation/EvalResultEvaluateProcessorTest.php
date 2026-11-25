<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:34:44 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Evaluation;

use ApiPlatform\Metadata\Post;
use Dmstr\NeuronAiBundle\Agent\AgentReferenceResolver;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Anonymous\SystemActor;
use Dmstr\NeuronAiBundle\Chat\ChatService;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Entity\EvalResult;
use Dmstr\NeuronAiBundle\Entity\EvalRun;
use Dmstr\NeuronAiBundle\Entity\Evaluation;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvaluationSettingsInterface;
use Dmstr\NeuronAiBundle\Evaluation\EvaluationStatus;
use Dmstr\NeuronAiBundle\Evaluation\Judge;
use Dmstr\NeuronAiBundle\JobProcessor\EvalResultEvaluateJobProcessor;
use Dmstr\NeuronAiBundle\State\EvalResultEvaluateProcessor;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\JobQueueService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

/**
 * Kernel-free checks of `POST /eval_results/{id}/evaluate` and of the
 * paths of its job processor that do not reach the provider: the 422
 * refusals (result of a retrieval-only run, result not completed, no
 * conversation, judge setting empty, unknown, disabled or unpublished) before anything is persisted or
 * queued, the pending evaluation with its queued job on success, and in
 * the job a vanished evaluation (throws), a redelivery (no-op) and a judge
 * that became unrunnable (failed evaluation, one flush, nothing
 * persisted). Entity manager and job queue are test doubles over in-memory
 * agents; the verdict path runs in EvalResultEvaluateTest (CI).
 */
final class EvalResultEvaluateProcessorTest extends TestCase
{
    private ?string $judgeSetting = 'judge';

    /** @var list<Agent> */
    private array $agents = [];

    /** @var list<object> */
    private array $persisted = [];

    private int $flushes = 0;

    /** @var list<array{string, array<string, mixed>}> */
    private array $dispatched = [];

    private ?Job $job = null;

    /** @var array<string, Evaluation> */
    private array $evaluations = [];

    public function testQueuesAPendingEvaluationAsTheLatestOfTheResult(): void
    {
        $this->agent('judge');
        $result = self::evalResult();

        $evaluation = $this->process($result);

        self::assertSame([$evaluation], $this->persisted);
        self::assertSame(EvaluationStatus::PENDING, $evaluation->getStatus());
        self::assertSame($result, $evaluation->getEvalResult());
        self::assertSame($evaluation, $result->getEvaluation());
        self::assertSame([[EvalResultEvaluateJobProcessor::JOB_TYPE, ['evaluation' => (string) $evaluation->getId(), 'evalResult' => (string) $result->getId()]]], $this->dispatched);
        self::assertSame('eval_result_evaluate', EvalResultEvaluateJobProcessor::JOB_TYPE);
        self::assertInstanceOf(Job::class, $this->job);
        self::assertSame($this->job->getId(), $evaluation->getJobId());
        self::assertSame(1, $this->flushes);
    }

    public function testRefusesUnrunnableJudgesAndResultsBeforeWriting(): void
    {
        $this->agent('judge');
        $this->agent('off', enabled: false);
        $this->agent('candidate', published: false);

        foreach ([
            'empty setting' => [null, self::evalResult(), 'No judge agent is configured (setting "evaluation.judge_agent")'],
            'unknown agent' => ['nobody', self::evalResult(), 'Judge agent of setting "evaluation.judge_agent": Unknown agent "nobody".'],
            'disabled agent' => ['off', self::evalResult(), 'Judge agent of setting "evaluation.judge_agent": Agent "off" is disabled.'],
            'unpublished agent' => ['candidate', self::evalResult(), 'Judge agent of setting "evaluation.judge_agent": Agent "candidate" has no published version.'],
            'failed result' => ['judge', self::evalResult(EvalResultStatus::FAILED), 'this result has status "failed"'],
            'pending result' => ['judge', self::evalResult(EvalResultStatus::PENDING), 'this result has status "pending"'],
            'deleted conversation' => ['judge', self::evalResult(withConversation: false), 'conversation of this evaluation result was deleted'],
        ] as $case => [$setting, $result, $message]) {
            $this->judgeSetting = $setting;
            try {
                $this->process($result);
                self::fail('Expected a 422: '.$case);
            } catch (UnprocessableEntityHttpException $e) {
                self::assertStringContainsString($message, $e->getMessage(), $case);
            }
            self::assertSame([], $this->persisted, $case);
            self::assertSame([], $this->dispatched, $case);
            self::assertSame(0, $this->flushes, $case);
            self::assertNull($result->getEvaluation(), $case);
        }
    }

    public function testRefusesResultsOfARetrievalOnlyRunWhateverTheirStatus(): void
    {
        $this->agent('judge');
        $run = new EvalRun()->setRetrievalOnly(true);

        foreach ([
            'completed, no conversation' => self::evalResult(withConversation: false),
            'failed, no conversation' => self::evalResult(EvalResultStatus::FAILED, withConversation: false),
            'pending, no conversation' => self::evalResult(EvalResultStatus::PENDING, withConversation: false),
            'completed with conversation' => self::evalResult(),
        ] as $case => $result) {
            $result->setEvalRun($run);
            try {
                $this->process($result);
                self::fail('Expected a 422: '.$case);
            } catch (UnprocessableEntityHttpException $e) {
                self::assertSame('Retrieval-only result, nothing to judge: the run measured retrieval without an answer.', $e->getMessage(), $case);
            }
            self::assertSame([], $this->persisted, $case);
            self::assertSame([], $this->dispatched, $case);
            self::assertSame(0, $this->flushes, $case);
            self::assertNull($result->getEvaluation(), $case);
        }
    }

    public function testJudgesResultsOfANormalRun(): void
    {
        $this->agent('judge');
        $result = self::evalResult()->setEvalRun(new EvalRun());

        $evaluation = $this->process($result);

        self::assertSame($evaluation, $result->getEvaluation());
        self::assertSame(1, $this->flushes);
    }

    public function testJobThrowsOnlyForAVanishedEvaluation(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $this->jobProcessor()->process(new Job()->setType(EvalResultEvaluateJobProcessor::JOB_TYPE)->setInputData(['evaluation' => '00000000-0000-4000-8000-000000000000']));
    }

    public function testJobSkipsEndedEvaluationsAndForeignJobs(): void
    {
        $ended = $this->evaluation(EvaluationStatus::COMPLETED);
        $job = new Job()->setType(EvalResultEvaluateJobProcessor::JOB_TYPE)->setInputData(['evaluation' => (string) $ended->getId()]);
        $this->jobProcessor()->process($job);
        self::assertStringContainsString('already ended', (string) ($job->getResultData()['skipped'] ?? ''));

        $foreign = $this->evaluation(EvaluationStatus::PENDING)->setJobId('another-job');
        $job = new Job()->setType(EvalResultEvaluateJobProcessor::JOB_TYPE)->setInputData(['evaluation' => (string) $foreign->getId()]);
        $this->jobProcessor()->process($job);
        self::assertStringContainsString('another-job', (string) ($job->getResultData()['skipped'] ?? ''));
        self::assertSame(EvaluationStatus::PENDING, $foreign->getStatus());

        self::assertSame(0, $this->flushes);
        self::assertSame([], $this->persisted);
    }

    public function testJobFailsTheEvaluationWhenTheJudgeIsNoLongerRunnable(): void
    {
        $this->agent('judge', enabled: false);
        $job = new Job()->setType(EvalResultEvaluateJobProcessor::JOB_TYPE);
        $evaluation = $this->evaluation(EvaluationStatus::PENDING)->setJobId($job->getId());
        $job->setInputData(['evaluation' => (string) $evaluation->getId()]);
        $result = $evaluation->getEvalResult();
        $before = [$result->getStatus(), $result->getErrorMessage(), $result->getDurationMs(), $result->getConversation()];

        $this->jobProcessor()->process($job);

        self::assertSame(EvaluationStatus::FAILED, $evaluation->getStatus());
        self::assertStringContainsString('is disabled', (string) $evaluation->getErrorMessage());
        self::assertNull($evaluation->getVerdict());
        self::assertNull($evaluation->getJudgeAgentVersion());
        self::assertEquals(new \DateTimeImmutable('2026-10-03 12:00:00'), $evaluation->getFinishedAt());
        self::assertSame(EvaluationStatus::FAILED, $job->getResultData()['status'] ?? null);
        self::assertSame([], $this->persisted);
        self::assertSame(1, $this->flushes);
        self::assertSame($before, [$result->getStatus(), $result->getErrorMessage(), $result->getDurationMs(), $result->getConversation()]);
    }

    // --- doubles --------------------------------------------------------

    private function process(EvalResult $result): Evaluation
    {
        $processor = new EvalResultEvaluateProcessor($this->judge(), $this->entityManager(), $this->jobQueue());

        return $processor->process($result, new Post(name: EvalResult::OPERATION_EVALUATE));
    }

    private function jobProcessor(): EvalResultEvaluateJobProcessor
    {
        return new EvalResultEvaluateJobProcessor($this->judge(), $this->entityManager(), new SystemActor(new TokenStorage()), new MockClock('2026-10-03 12:00:00'));
    }

    private function judge(): Judge
    {
        $settings = $this->createStub(EvaluationSettingsInterface::class);
        $settings->method('judgeAgent')->willReturnCallback(fn (): ?string => $this->judgeSetting);

        // The mapper and the chat are never reached on the paths tested here.
        $mapper = new \ReflectionClass(VersionTurnMapper::class)->newInstanceWithoutConstructor();

        return new Judge($settings, new AgentReferenceResolver($this->entityManager()), $mapper, $this->createStub(ChatService::class), $this->entityManager());
    }

    private function entityManager(): EntityManagerInterface
    {
        $agents = $this->createStub(EntityRepository::class);
        $agents->method('findOneBy')->willReturnCallback(function (array $criteria): ?Agent {
            foreach ($this->agents as $agent) {
                if ($agent->getSlug() === ($criteria['slug'] ?? null)) {
                    return $agent;
                }
            }

            return null;
        });
        $agents->method('find')->willReturn(null);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            fn (string $class): EntityRepository => Agent::class === $class ? $agents : self::fail('Unexpected repository '.$class),
        );
        $entityManager->method('find')->willReturnCallback(function (string $class, mixed $id): ?Evaluation {
            self::assertSame(Evaluation::class, $class);

            return $this->evaluations[(string) $id] ?? null;
        });
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        return $entityManager;
    }

    private function jobQueue(): JobQueueService
    {
        $jobQueue = $this->createStub(JobQueueService::class);
        $jobQueue->method('createAndDispatch')->willReturnCallback(function (string $type, array $input): Job {
            $this->dispatched[] = [$type, $input];
            $this->job = new Job()->setType($type)->setInputData($input);

            return $this->job;
        });

        return $jobQueue;
    }

    // --- fixtures -------------------------------------------------------

    private function agent(string $slug, bool $enabled = true, bool $published = true): void
    {
        $agent = new Agent()->setName('Judge '.$slug)->setSlug($slug)->setEnabled($enabled);
        if ($published) {
            $agent->setCurrentVersion(new AgentVersion($agent, 1, Agent::candidateDefaults(), 'test-admin'));
        }
        $this->agents[] = $agent;
    }

    private static function evalResult(string $status = EvalResultStatus::COMPLETED, bool $withConversation = true): EvalResult
    {
        $question = new EvalQuestion()->setKey('q')->setQuestion('Question?')->setExpectedAnswer('Answer.');

        return new EvalResult()
            ->setEvalQuestion($question)
            ->setConversation($withConversation ? new Conversation() : null)
            ->setStatus($status)
            ->setDurationMs(10);
    }

    private function evaluation(string $status): Evaluation
    {
        $evaluation = new Evaluation()->setEvalResult(self::evalResult())->setStatus($status);
        $this->evaluations[(string) $evaluation->getId()] = $evaluation;

        return $evaluation;
    }
}
