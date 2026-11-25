<?php

// file generated with AI assistance: Claude Code - 2026-10-03 19:10:10 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\NeuronAiBundle\Entity\EvalRun;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /eval_runs/{id}/cancel` (`eval_run_cancel`): asks a pending or
 * running run to stop and returns it (200).
 *
 * Cancel is a flag: `cancelRequestedAt` is set (a second request keeps the
 * first time) and the batch job
 * ({@see \Dmstr\NeuronAiBundle\JobProcessor\EvalRunJobProcessor}) reads it
 * from the database before each question and ends the run as `cancelled`.
 * A run that has already ended answers 422, as cancelling a collection
 * reindex without a pending model does
 * ({@see CollectionReindexCancelProcessor}).
 *
 * @implements ProcessorInterface<EvalRun, EvalRun>
 */
final class EvalRunCancelProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws UnprocessableEntityHttpException when the run has already ended
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EvalRun
    {
        if (!$data instanceof EvalRun) {
            throw new NotFoundHttpException('Not Found');
        }

        if (!$data->isActive()) {
            throw new UnprocessableEntityHttpException(\sprintf('The evaluation run has already ended (status "%s").', $data->getStatus()));
        }

        if (!$data->isCancelRequested()) {
            $data->setCancelRequestedAt($this->clock->now());
            $this->entityManager->flush();
        }

        return $data;
    }
}
