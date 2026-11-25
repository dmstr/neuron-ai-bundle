<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

/**
 * Kernel-free stand-in for the entity manager of the model catalogue
 * tests, adapted from the MCP tool catalogue's InMemoryCatalogueTrait:
 * connections, catalogue rows and jobs live in arrays, persist() and
 * remove() change them immediately, flush() is only counted. Connection
 * lookups accept a list of types (`findBy(['type' => [...]])`), jobs can be
 * looked up by type and a list of statuses.
 */
trait InMemoryProviderCatalogueTrait
{
    /** @var list<ApiConfiguration> */
    private array $connections = [];

    /** @var array<int, ProviderModel> keyed by spl_object_id() */
    private array $models = [];

    /** @var list<Job> */
    private array $jobs = [];

    private int $flushes = 0;

    private function entityManager(): EntityManagerInterface
    {
        $modelRepository = $this->createStub(EntityRepository::class);
        $modelRepository->method('findBy')->willReturnCallback(fn (array $criteria): array => $this->modelsOf($criteria['apiConfiguration']));

        $connectionRepository = $this->createStub(EntityRepository::class);
        $connectionRepository->method('findBy')->willReturnCallback(function (array $criteria, ?array $orderBy = null, ?int $limit = null): array {
            $types = (array) $criteria['type'];
            $matches = array_values(array_filter(
                $this->connections,
                static fn (ApiConfiguration $c): bool => \in_array($c->getType(), $types, true)
                    && (!isset($criteria['name']) || $c->getName() === $criteria['name']),
            ));
            usort($matches, static fn (ApiConfiguration $a, ApiConfiguration $b): int => strcmp($a->getName(), $b->getName()));

            return \array_slice($matches, 0, $limit);
        });

        $jobRepository = $this->createStub(EntityRepository::class);
        $jobRepository->method('findBy')->willReturnCallback(fn (array $criteria): array => array_values(array_filter(
            $this->jobs,
            static fn (Job $job): bool => $job->getType() === $criteria['type'] && \in_array($job->getStatus(), (array) $criteria['status'], true),
        )));

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(static fn (string $class): EntityRepository => match ($class) {
            ProviderModel::class => $modelRepository,
            ApiConfiguration::class => $connectionRepository,
            Job::class => $jobRepository,
            default => throw new \LogicException('Unexpected repository '.$class),
        });
        $entityManager->method('persist')->willReturnCallback(function (object $object): void {
            if ($object instanceof ProviderModel) {
                $this->models[spl_object_id($object)] = $object;
            } elseif ($object instanceof Job) {
                $this->jobs[] = $object;
            }
        });
        $entityManager->method('remove')->willReturnCallback(function (object $object): void {
            unset($this->models[spl_object_id($object)]);
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });
        $entityManager->method('find')->willReturnCallback(function (string $class, mixed $id): ?object {
            $candidates = ApiConfiguration::class === $class ? $this->connections : array_values($this->models);
            foreach ($candidates as $candidate) {
                if ((string) $candidate->getId() === (string) $id) {
                    return $candidate;
                }
            }

            return null;
        });

        return $entityManager;
    }

    private function addOllama(string $name, string $url = ProviderConnectionFixtures::OLLAMA_URL, bool $active = true): ApiConfiguration
    {
        return $this->connections[] = ProviderConnectionFixtures::ollama($name, $active, $url);
    }

    private function addAnthropic(string $name, bool $active = true): ApiConfiguration
    {
        return $this->connections[] = ProviderConnectionFixtures::anthropic($name, $active);
    }

    private function addMcp(string $name): ApiConfiguration
    {
        return $this->connections[] = (new ApiConfiguration())
            ->setName($name)
            ->setConfigJson(['mcp' => true, 'type' => 'mcp', 'endpoint_type' => 'rest', 'url' => 'https://docs.example.org/mcp', 'transport' => 'streamable-http']);
    }

    /**
     * A stored catalogue row, described from the connection's current endpoint.
     *
     * @param list<string>|null $capabilities
     */
    private function addModel(ApiConfiguration $connection, string $name, ?array $capabilities = null, ?string $digest = null, ?int $maxContextLength = null): ProviderModel
    {
        $model = (new ProviderModel($connection, $name))
            ->setCapabilities($capabilities)
            ->setDigest($digest)
            ->setMaxContextLength($maxContextLength)
            ->setSyncedAt(null === $capabilities ? null : new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $this->models[spl_object_id($model)] = $model;

        return $model;
    }

    /**
     * @return list<ProviderModel> sorted by name
     */
    private function modelsOf(ApiConfiguration $connection): array
    {
        $matches = array_values(array_filter(
            $this->models,
            static fn (ProviderModel $model): bool => $model->getApiConfiguration() === $connection,
        ));
        usort($matches, static fn (ProviderModel $a, ProviderModel $b): int => strcmp($a->getName(), $b->getName()));

        return $matches;
    }

    /**
     * @return array<string, ProviderModel> the connection's rows by model name
     */
    private function modelsByName(ApiConfiguration $connection): array
    {
        $byName = [];
        foreach ($this->modelsOf($connection) as $model) {
            $byName[$model->getName()] = $model;
        }

        return $byName;
    }
}
