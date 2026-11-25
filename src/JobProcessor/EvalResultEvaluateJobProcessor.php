<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:34:44 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\JobProcessor;

use Dmstr\NeuronAiBundle\Anonymous\SystemActor;
use Dmstr\NeuronAiBundle\Entity\EvalResult;
use Dmstr\NeuronAiBundle\Entity\Evaluation;
use Dmstr\NeuronAiBundle\Entity\Suggestion;
use Dmstr\NeuronAiBundle\Evaluation\BatchRunner;
use Dmstr\NeuronAiBundle\Evaluation\EvaluationStatus;
use Dmstr\NeuronAiBundle\Evaluation\Judge;
use Dmstr\NeuronAiBundle\Evaluation\JudgeException;
use Dmstr\NeuronAiBundle\Evaluation\JudgeVerdict;
use Dmstr\NeuronAiBundle\Evaluation\RunAggregates;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\Job\JobProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Runs the AI judge for jobs of type {@see self::JOB_TYPE}, queued by
 * `POST /api/eval_results/{id}/evaluate`
 * ({@see \Dmstr\NeuronAiBundle\State\EvalResultEvaluateProcessor}).
 *
 * Job input: `{"evaluation": "<uuid>", "evalResult": "<uuid>"}` (the result
 * is informational; the evaluation's `evalResult` is authoritative).
 *
 * - an evaluation that no longer exists throws: the job fails. Nothing
 *   else throws;
 * - an evaluation that is no longer `pending`, or belongs to another job,
 *   is a no-op with `resultData.skipped`: a redelivered message neither
 *   judges again nor duplicates suggestions;
 * - {@see Judge::judge()} succeeds: the evaluation becomes `completed`
 *   with verdict, reasoning, judge version, model, tokens, duration and
 *   `finishedAt`, and one {@see Suggestion} per suggestion of the verdict;
 * - the judge fails ({@see JudgeException}: judge not runnable any more,
 *   provider failure, `schema_errors`, verdict-schema violation; any
 *   other exception of the judge is treated alike): the
 *   evaluation becomes `failed` with `errorMessage` and `finishedAt`, no
 *   suggestion is stored, and the {@see EvalResult} keeps everything but
 *   its `evaluation` link, which already points here.
 *
 * Either outcome is written with ONE flush at the end (no partial write),
 * under the system actor {@see BatchRunner::ACTOR}, because the worker has
 * no user token. The job's `resultData` is `{evaluation, status, verdict?,
 * suggestions?, error?}`.
 *
 * After either outcome, when the result belongs to a batch run, the run's
 * `verdictCounts` are recomputed ({@see RunAggregates}) and written with a
 * second flush under the same system actor (the service wiring always
 * passes {@see RunAggregates}; null skips this, for kernel-free tests). A
 * failed evaluation refreshes
 * them too: it is the result's latest evaluation now, so a verdict of an
 * earlier evaluation of that result no longer counts.
 */
final class EvalResultEvaluateJobProcessor implements JobProcessorInterface
{
    public const string JOB_TYPE = EvalResult::OPERATION_EVALUATE;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly Judge $judge,
        private readonly EntityManagerInterface $entityManager,
        private readonly SystemActor $systemActor,
        private readonly ClockInterface $clock,
        private readonly ?RunAggregates $runAggregates = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function supports(string $jobType): bool
    {
        return self::JOB_TYPE === $jobType;
    }

    /**
     * @throws \RuntimeException when the evaluation does not exist (any more)
     */
    public function process(Job $job): void
    {
        $input = $job->getInputData();
        $evaluation = $this->evaluation((string) ($input['evaluation'] ?? ''));

        if (EvaluationStatus::PENDING !== $evaluation->getStatus()) {
            $job->setResultData(['skipped' => \sprintf('the evaluation has already ended (status "%s")', $evaluation->getStatus())]);

            return;
        }
        $jobId = $evaluation->getJobId();
        if (null !== $jobId && $jobId !== $job->getId()) {
            $job->setResultData(['skipped' => \sprintf('the evaluation belongs to job %s', $jobId)]);

            return;
        }

        try {
            $verdict = $this->judge->judge($evaluation->getEvalResult());
        } catch (\Throwable $e) {
            // JudgeException is the expected failure; anything else that
            // escapes the judge fails the evaluation the same way.
            $message = $e instanceof JudgeException || '' === $e->getMessage() ? $e->getMessage() : 'The judge failed: '.$e->getMessage();
            $message = JudgeVerdict::limit('' !== $message ? $message : $e::class);
            $this->logger->warning('Evaluation {evaluation} failed: {message}', [
                'evaluation' => (string) $evaluation->getId(),
                'message' => $message,
                'exception' => $e,
            ]);
            $this->fail($evaluation, $message);
            $job->setResultData(['evaluation' => (string) $evaluation->getId(), 'status' => EvaluationStatus::FAILED, 'error' => $message]);
            $this->flush();
            $this->refreshRun($evaluation);

            return;
        }

        $suggestions = $this->complete($evaluation, $verdict);
        $job->setResultData([
            'evaluation' => (string) $evaluation->getId(),
            'status' => EvaluationStatus::COMPLETED,
            'verdict' => $verdict->verdict,
            'suggestions' => \count($suggestions),
        ]);
        $this->flush();

        // The evaluation is stored completed from here on, so the aggregate
        // query sees the verdict.
        $this->refreshRun($evaluation);
    }

    /**
     * Fills the evaluation from the verdict and persists its suggestions;
     * not flushed.
     *
     * @return list<Suggestion>
     */
    private function complete(Evaluation $evaluation, JudgeVerdict $verdict): array
    {
        $evaluation
            ->setStatus(EvaluationStatus::COMPLETED)
            ->setVerdict($verdict->verdict)
            ->setReasoning($verdict->reasoning)
            ->setJudgeAgentVersion($verdict->judgeVersion)
            ->setModel('' !== $verdict->model ? mb_substr($verdict->model, 0, 255) : null)
            ->setProviderModel($verdict->providerModel)
            ->setTokensInput($verdict->tokensInput)
            ->setTokensOutput($verdict->tokensOutput)
            ->setDurationMs($verdict->durationMs)
            ->setErrorMessage(null)
            ->setFinishedAt($this->clock->now());

        $suggestions = [];
        foreach ($verdict->suggestions as $item) {
            $suggestion = new Suggestion()
                ->setEvaluation($evaluation)
                ->setType($item->type)
                ->setCategory($item->category)
                ->setDescription($item->description)
                ->setAction($item->action)
                ->setPriority($item->priority);
            $this->entityManager->persist($suggestion);
            $suggestions[] = $suggestion;
        }

        return $suggestions;
    }

    /**
     * Marks the evaluation failed; not flushed.
     */
    private function fail(Evaluation $evaluation, string $message): void
    {
        $evaluation
            ->setStatus(EvaluationStatus::FAILED)
            ->setErrorMessage(JudgeVerdict::limit($message))
            ->setFinishedAt($this->clock->now());
    }

    /**
     * Recomputes and stores the verdict counts of the result's run, if
     * any; called after the outcome was flushed, so the aggregate query
     * reads it.
     */
    private function refreshRun(Evaluation $evaluation): void
    {
        $run = $evaluation->getEvalResult()->getEvalRun();
        if (null === $run || null === $this->runAggregates) {
            return;
        }

        $this->runAggregates->refresh($run);
        $this->flush();
    }

    /**
     * The single write of an outcome, under the system actor.
     */
    private function flush(): void
    {
        $this->systemActor->run(BatchRunner::ACTOR, function (): void {
            $this->entityManager->flush();
        });
    }

    /**
     * @throws \RuntimeException when the evaluation does not exist (any more)
     */
    private function evaluation(string $id): Evaluation
    {
        $evaluation = Uuid::isValid($id)
            ? $this->entityManager->find(Evaluation::class, Uuid::fromString($id))
            : null;

        if (!$evaluation instanceof Evaluation) {
            throw new \RuntimeException(\sprintf('Evaluation "%s" does not exist (anymore).', $id));
        }

        return $evaluation;
    }
}
