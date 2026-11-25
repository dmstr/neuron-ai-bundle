<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\JobQueueService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Queues a model catalogue scan job (type `provider_model_scan`) for all
 * provider connections or for one, used by the scan action and by the scan
 * trigger on connection changes.
 *
 * The scan action ({@see queue()}) returns a `pending` or `running` scan job
 * with the same input instead of queueing another one. The trigger on a
 * connection change ({@see queueAfterChange()}) reuses only a `pending`
 * job: a running one may already have read the old URL, so its rows would
 * carry the old endpoint fingerprint and stay stale. The worker consumes
 * jobs one after the other, so the new job runs after the old one; with
 * parallel workers it can find the old scan still holding the
 * per-connection lock ({@see ProviderModelScanner}) and report the
 * connection as skipped, and a manual scan is needed. Reuse is a
 * convenience, not the safety net: the lock is.
 */
final class ProviderModelScanQueue
{
    public function __construct(
        private readonly JobQueueService $jobQueueService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param ApiConfiguration|null $connection null = all provider connections
     */
    public function queue(?ApiConfiguration $connection = null): Job
    {
        return $this->queueReusing(null === $connection ? [] : self::inputOf($connection), ['pending', 'running']);
    }

    /**
     * Queues the scan of a connection whose endpoint changed (or that was
     * created or activated); reuses only a pending job.
     */
    public function queueAfterChange(ApiConfiguration $connection): Job
    {
        return $this->queueReusing(self::inputOf($connection), ['pending']);
    }

    /**
     * @param array<string, string> $input
     * @param list<string>          $statuses the job states that may be reused
     */
    private function queueReusing(array $input, array $statuses): Job
    {
        return $this->openJob($input, $statuses)
            ?? $this->jobQueueService->createAndDispatch(ProviderModel::JOB_TYPE, $input, ProviderModel::OPERATION_SCAN);
    }

    /**
     * @return array<string, string>
     */
    private static function inputOf(ApiConfiguration $connection): array
    {
        return ['apiConfiguration' => (string) $connection->getId()];
    }

    /**
     * A scan job in one of the given states with the same input.
     *
     * @param array<string, string> $input
     * @param list<string>          $statuses
     */
    private function openJob(array $input, array $statuses): ?Job
    {
        $jobs = $this->entityManager->getRepository(Job::class)->findBy(
            ['type' => ProviderModel::JOB_TYPE, 'status' => $statuses],
            ['createdAt' => 'DESC'],
        );

        foreach ($jobs as $job) {
            if ($job instanceof Job && $job->getInputData() == $input) {
                return $job;
            }
        }

        return null;
    }
}
