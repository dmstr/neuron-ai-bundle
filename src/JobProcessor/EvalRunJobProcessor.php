<?php

// file generated with AI assistance: Claude Code - 2026-10-03 19:10:10 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\JobProcessor;

use Dmstr\NeuronAiBundle\Entity\EvalRun;
use Dmstr\NeuronAiBundle\Evaluation\BatchReport;
use Dmstr\NeuronAiBundle\Evaluation\BatchRunner;
use Dmstr\NeuronAiBundle\Evaluation\EvalRunStatus;
use Dmstr\NeuronAiBundle\Evaluation\RunAggregates;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\Job\JobProcessorInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Runs the batch of an evaluation run for jobs of type
 * {@see EvalRun::JOB_TYPE_START}, queued by `POST /api/eval_runs/start`.
 *
 * Job input: `{"run": "<uuid>", "ownerSubject": "<identifier>"}` (the
 * owner is informational; the run's `ownerSubject` is authoritative). The
 * {@see BatchReport} becomes the job's `resultData`
 * (`{completed, failed, skipped, failures: [{question, error}]}`) and is
 * written with `progress` (percent of the run's questions with a result)
 * after every question. The run's `questionsTotal` is set again by the
 * batch when it takes its question list: |active questions ∪ questions
 * already answered in this run|, so `progress` stays truthful on a redelivery and when
 * questions were archived between start and job. It is a progress
 * figure only: the retrieval denominators come from the stored results.
 *
 * - a run that no longer exists throws: the job fails; a version that is
 *   gone ends the run `failed` and throws as well;
 * - a run that has already ended (`completed`, `cancelled`, `failed`) or
 *   belongs to another job is a no-op with `resultData.skipped`: this
 *   covers a message redelivered after the run ended;
 * - a redelivery of a run that is still pending or running resumes it:
 *   questions that already have a result are skipped
 *   ({@see BatchRunner});
 * - a failing question never fails the job, it becomes a `failed` result.
 *   Anything else that escapes the batch (database errors outside a
 *   question) ends the run `failed` with `finishedAt` and is rethrown, so
 *   the job fails; a retry then finds the run ended and is a no-op. When
 *   the entity manager was closed by the error, the final status is
 *   written through DBAL (only while the run is still `pending` or
 *   `running`, as `TurnPersistence::markFinal()` does for turn records),
 *   so no run is left without a final status;
 * - a run the batch completed gets its `verdictCounts` ({@see RunAggregates}):
 *   all four verdict keys, 0 unless results were already evaluated while
 *   the run was going. A cancelled run keeps its counts as they are (the
 *   evaluate job refreshes them per evaluated result);
 * - a run in mode `retrieval` gets its retrieval aggregates (`recall`,
 *   `mrr` and the four denominators, {@see RunAggregates::refreshRetrieval()})
 *   recomputed from its stored results at every end: completed,
 *   cancelled, and failed as best effort (skipped and logged when the
 *   entity manager is closed). Recomputing keeps them consistent on
 *   cancel and redelivery. A run in mode `agent` keeps them null.
 */
final class EvalRunJobProcessor implements JobProcessorInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly BatchRunner $runner,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly RunAggregates $runAggregates,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function supports(string $jobType): bool
    {
        return EvalRun::JOB_TYPE_START === $jobType;
    }

    public function process(Job $job): void
    {
        $input = $job->getInputData();
        $run = $this->run((string) ($input['run'] ?? ''));

        if (!$run->isActive()) {
            $job->setResultData(['skipped' => \sprintf('the evaluation run has already ended (status "%s")', $run->getStatus())]);

            return;
        }
        $jobId = $run->getJobId();
        if (null !== $jobId && $jobId !== $job->getId()) {
            $job->setResultData(['skipped' => \sprintf('the evaluation run belongs to job %s', $jobId)]);

            return;
        }

        $report = new BatchReport();
        try {
            $this->assertVersionExists($run);
            $run = $this->runner->run($run, $report, function (EvalRun $run, BatchReport $report) use ($job): void {
                $job->setProgress($report->progress($run->getQuestionsTotal()));
                $job->setResultData($report->toArray());
                $this->entityManager->flush();
            });
            if (EvalRunStatus::COMPLETED === $run->getStatus()) {
                $this->runAggregates->refresh($run);
            }
            if (BatchRunner::MODE_RETRIEVAL === $run->getMode()) {
                // The results are flushed by the batch; the refresh reads
                // the table.
                $this->runAggregates->refreshRetrieval($run);
            }
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->markFailed($run->getId());

            throw $e;
        } finally {
            $job->setResultData($report->toArray());
        }
    }

    /**
     * Ends the run `failed` (through DBAL when the entity manager is
     * closed) and refreshes its retrieval aggregates as best effort.
     */
    private function markFailed(Uuid $runId): void
    {
        if (!$this->entityManager->isOpen()) {
            $this->markFailedThroughDbal($runId);
            $this->logger->warning('Evaluation run {run} failed with a closed entity manager; its final status was written through DBAL, its aggregates were not refreshed.', ['run' => (string) $runId]);

            return;
        }

        try {
            $run = $this->entityManager->find(EvalRun::class, $runId);
            if (!$run instanceof EvalRun) {
                return;
            }
            if ($run->isActive()) {
                $run->finish(EvalRunStatus::FAILED, $this->clock->now());
            }
            if (EvalRunStatus::FAILED === $run->getStatus() && BatchRunner::MODE_RETRIEVAL === $run->getMode()) {
                $this->runAggregates->refreshRetrieval($run);
            }
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            // The original failure is the one to report; the status is
            // written through DBAL if the entity manager could not.
            $this->logger->warning('Evaluation run {run}: storing the failed status failed: {message}', ['run' => (string) $runId, 'message' => $e->getMessage(), 'exception' => $e]);
            $this->markFailedThroughDbal($runId);
        }
    }

    /**
     * The DBAL fallback of {@see markFailed()}: `failed` with `finishedAt`,
     * only while the run is still `pending` or `running`.
     */
    private function markFailedThroughDbal(Uuid $runId): void
    {
        try {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE neuron_eval_run SET status = :failed, finished_at = :at WHERE id = :id AND status IN (:pending, :running)',
                [
                    'failed' => EvalRunStatus::FAILED,
                    'at' => $this->clock->now(),
                    'id' => $runId->toRfc4122(),
                    'pending' => EvalRunStatus::PENDING,
                    'running' => EvalRunStatus::RUNNING,
                ],
                ['at' => Types::DATETIMETZ_IMMUTABLE],
            );
        } catch (\Throwable $e) {
            $this->logger->error('Evaluation run {run} could not be marked failed: {message}', ['run' => (string) $runId, 'message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * @throws \RuntimeException when the run's version is gone
     */
    private function assertVersionExists(EvalRun $run): void
    {
        $id = $run->getAgentVersion()->getId();
        // Read from the table: the identity map would answer with the proxy.
        $exists = $this->entityManager->getConnection()->fetchOne('SELECT 1 FROM neuron_agent_version WHERE id = :id', ['id' => $id->toRfc4122()]);
        if (false === $exists) {
            throw new \RuntimeException(\sprintf('Agent version "%s" of evaluation run "%s" does not exist (anymore).', $id, $run->getId()));
        }
    }

    private function run(string $id): EvalRun
    {
        $run = Uuid::isValid($id)
            ? $this->entityManager->find(EvalRun::class, Uuid::fromString($id))
            : null;

        if (!$run instanceof EvalRun) {
            throw new \RuntimeException(\sprintf('Evaluation run "%s" does not exist (anymore).', $id));
        }

        return $run;
    }
}
