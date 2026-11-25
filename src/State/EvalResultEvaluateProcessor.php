<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:34:44 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\NeuronAiBundle\Entity\EvalResult;
use Dmstr\NeuronAiBundle\Entity\Evaluation;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvaluationStatus;
use Dmstr\NeuronAiBundle\Evaluation\Judge;
use Dmstr\NeuronAiBundle\JobProcessor\EvalResultEvaluateJobProcessor;
use Dmstr\SymfonyJobQueue\Service\JobQueueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /eval_results/{id}/evaluate` (`eval_result_evaluate`): queues the
 * AI judge on one evaluation result.
 *
 * The operation has already checked `neuronai:eval:write` and loaded the
 * result (404 otherwise); there is no body. Answering 422 before anything
 * is written:
 *  - the result belongs to a retrieval-only run: it measured
 *    retrieval without an answer, whatever its status; checked first, so
 *    it is not reported as a missing conversation;
 *  - the result is not `completed` (a failed or pending result has no
 *    answer to judge);
 *  - the result has no conversation any more (it was deleted);
 *  - the judge setting is empty, or names an unknown or disabled agent or
 *    one without a published version ({@see Judge::judgeVersion()}; the
 *    texts name the setting).
 *
 * Otherwise a pending {@see Evaluation} is stored as the result's latest
 * `evaluation`, a job of type {@see EvalResultEvaluateJobProcessor::JOB_TYPE}
 * is queued with the input `{"evaluation": "<uuid>", "evalResult": "<uuid>"}`,
 * its id is stored as `jobId`, and the evaluation is returned (202). The
 * judge version is resolved again when the job runs; a judge disabled in
 * between fails the evaluation there.
 *
 * @implements ProcessorInterface<EvalResult, Evaluation>
 */
final class EvalResultEvaluateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Judge $judge,
        private readonly EntityManagerInterface $entityManager,
        private readonly JobQueueService $jobQueueService,
    ) {
    }

    /**
     * @throws UnprocessableEntityHttpException when the result cannot be judged or no judge is runnable
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Evaluation
    {
        if (!$data instanceof EvalResult) {
            throw new NotFoundHttpException('Not Found');
        }

        if (true === $data->getEvalRun()?->isRetrievalOnly()) {
            throw new UnprocessableEntityHttpException('Retrieval-only result, nothing to judge: the run measured retrieval without an answer.');
        }
        if (EvalResultStatus::COMPLETED !== $data->getStatus()) {
            throw new UnprocessableEntityHttpException(\sprintf('Only completed evaluation results can be evaluated; this result has status "%s".', $data->getStatus()));
        }
        if (null === $data->getConversation()) {
            throw new UnprocessableEntityHttpException('The conversation of this evaluation result was deleted; there is no answer to evaluate.');
        }
        $this->judge->judgeVersion();

        $evaluation = new Evaluation()
            ->setEvalResult($data)
            ->setStatus(EvaluationStatus::PENDING);
        $this->entityManager->persist($evaluation);
        $data->setEvaluation($evaluation);

        // createAndDispatch() flushes, so the evaluation row exists before
        // the message is dispatched.
        $job = $this->jobQueueService->createAndDispatch(
            EvalResultEvaluateJobProcessor::JOB_TYPE,
            ['evaluation' => (string) $evaluation->getId(), 'evalResult' => (string) $data->getId()],
        );

        $evaluation->setJobId($job->getId());
        $this->entityManager->flush();

        return $evaluation;
    }
}
