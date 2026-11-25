<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\JobProcessor;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\ProviderModelScanner;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\Job\JobProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Runs the model catalogue scan for jobs of type `provider_model_scan`,
 * queued by `POST /api/refs/provider_models/scan` or by a change of a
 * provider connection through the API.
 *
 * Job input: `{}` scans every provider connection (inactive ones are
 * skipped), `{"apiConfiguration": "<uuid>"}` only that connection. The scan
 * summary (see {@see ProviderModelScanner}) becomes the job's `resultData`.
 *
 * Failure follows the job queue's contract (throwing marks the job failed,
 * keeps `resultData` and lets messenger retry): the processor throws when
 * the referenced connection no longer exists, or when every connection it
 * tried to scan failed. A partial failure or a connection skipped because
 * another scan holds its lock completes the job.
 */
final class ProviderModelScanJobProcessor implements JobProcessorInterface
{
    public function __construct(
        private readonly ProviderModelScanner $scanner,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(string $jobType): bool
    {
        return ProviderModel::JOB_TYPE === $jobType;
    }

    public function process(Job $job): void
    {
        $reference = $job->getInputData()['apiConfiguration'] ?? null;

        if (null === $reference || '' === $reference) {
            $summary = $this->scanner->scanAll();
        } else {
            $summary = $this->scanner->scan([$this->connection((string) $reference)]);
        }

        $job->setResultData($summary);

        if ($summary['failed'] > 0 && 0 === $summary['scanned']) {
            $errors = array_map(
                static fn (array $connection): string => \sprintf('%s: %s', $connection['name'], $connection['error']),
                array_values(array_filter($summary['connections'], static fn (array $connection): bool => ProviderModelScanner::STATUS_ERROR === $connection['status'])),
            );

            throw new \RuntimeException('Provider model scan failed. '.implode('; ', $errors));
        }
    }

    private function connection(string $id): ApiConfiguration
    {
        $connection = Uuid::isValid($id)
            ? $this->entityManager->find(ApiConfiguration::class, Uuid::fromString($id))
            : null;

        if (!$connection instanceof ApiConfiguration) {
            throw new \RuntimeException(\sprintf('Provider connection "%s" does not exist (anymore).', $id));
        }

        return $connection;
    }
}
