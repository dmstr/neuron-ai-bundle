<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:37:11 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\JobProcessor;

use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Knowledge\CollectionLockedException;
use Dmstr\NeuronAiBundle\Reindex\CollectionReindexer;
use Dmstr\NeuronAiBundle\Reindex\ReindexReport;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\Job\JobProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/**
 * Runs the reindex for jobs of type `collection_reindex`, queued by
 * `POST /api/admin/collections/{id}/reindex`.
 *
 * Job input: `{"collection": "<uuid>", "model": "<target model>"}`. The
 * {@see ReindexReport} becomes the job's `resultData` and is written with
 * the progress every {@see CollectionReindexer::FLUSH_EVERY} documents.
 *
 * The job is a no-op (`resultData.skipped`) when the collection no longer
 * has the job's model as pending model, or when another job has taken over
 * the reindex: this covers a redelivered message after completion or
 * cancel.
 *
 * Failure follows the job queue's contract: throwing marks the job failed,
 * keeps `resultData` and lets messenger retry. A retry resumes, because
 * documents that already have rows of the target model are skipped.
 *
 * A locked collection ({@see CollectionLockedException} from the
 * reindexer, at the start or during the run) ends the job `failed` with
 * `Reindex of collection "<name>" to "<model>" stopped: Collection
 * "<name>" is locked` and without a retry: a retry would meet the same lock.
 */
final class CollectionReindexJobProcessor implements JobProcessorInterface
{
    public function __construct(
        private readonly CollectionReindexer $reindexer,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(string $jobType): bool
    {
        return Collection::JOB_TYPE_REINDEX === $jobType;
    }

    public function process(Job $job): void
    {
        $input = $job->getInputData();
        $collection = $this->collection((string) ($input['collection'] ?? ''));
        $model = (string) ($input['model'] ?? '');

        $jobId = $collection->getReindexJobId();
        if ($collection->getPendingEmbeddingModel() !== $model || (null !== $jobId && $jobId !== $job->getId())) {
            $job->setResultData(['skipped' => \sprintf('no pending reindex to %s', $model)]);

            return;
        }

        $report = new ReindexReport();
        try {
            $this->reindexer->run($collection, $model, $report, function (ReindexReport $report) use ($job): void {
                $job->setProgress($report->progress());
                $job->setResultData($report->toArray());
                $this->entityManager->flush();
            });
        } catch (CollectionLockedException $e) {
            throw new UnrecoverableMessageHandlingException(\sprintf('Reindex of collection "%s" to "%s" stopped: %s', $e->getCollectionName(), $model, $e->getMessage()), 0, $e);
        } finally {
            $job->setResultData($report->toArray());
        }
    }

    private function collection(string $id): Collection
    {
        $collection = Uuid::isValid($id)
            ? $this->entityManager->find(Collection::class, Uuid::fromString($id))
            : null;

        if (!$collection instanceof Collection) {
            throw new \RuntimeException(\sprintf('Collection "%s" does not exist (anymore).', $id));
        }

        return $collection;
    }
}
// - revised 2026-10-13 19:06:18 UTC
