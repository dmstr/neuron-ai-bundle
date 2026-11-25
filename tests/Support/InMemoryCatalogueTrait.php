<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:41:04 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Entity\Refs\McpTool;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

/**
 * Kernel-free stand-in for the entity manager of the catalogue tests:
 * connections, catalogue rows and jobs live in arrays, persist() and
 * remove() change them immediately, flush() is only counted.
 */
trait InMemoryCatalogueTrait
{
    /** @var list<ApiConfiguration> */
    private array $connections = [];

    /** @var array<int, McpTool> keyed by spl_object_id() */
    private array $tools = [];

    /** @var list<Job> */
    private array $jobs = [];

    private int $flushes = 0;

    private function entityManager(): EntityManagerInterface
    {
        $toolRepository = $this->createStub(EntityRepository::class);
        $toolRepository->method('findBy')->willReturnCallback(fn (array $criteria): array => $this->toolsOf($criteria['apiConfiguration']));

        $connectionRepository = $this->createStub(EntityRepository::class);
        $connectionRepository->method('findBy')->willReturnCallback(function (array $criteria, ?array $orderBy = null, ?int $limit = null): array {
            $matches = array_values(array_filter(
                $this->connections,
                static fn (ApiConfiguration $c): bool => $c->getType() === $criteria['type']
                    && (!isset($criteria['name']) || $c->getName() === $criteria['name']),
            ));
            usort($matches, static fn (ApiConfiguration $a, ApiConfiguration $b): int => strcmp($a->getName(), $b->getName()));

            return \array_slice($matches, 0, $limit);
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(static fn (string $class): EntityRepository => match ($class) {
            McpTool::class => $toolRepository,
            ApiConfiguration::class => $connectionRepository,
            default => throw new \LogicException('Unexpected repository '.$class),
        });
        $entityManager->method('persist')->willReturnCallback(function (object $object): void {
            if ($object instanceof McpTool) {
                $this->tools[spl_object_id($object)] = $object;
            } elseif ($object instanceof Job) {
                $this->jobs[] = $object;
            }
        });
        $entityManager->method('remove')->willReturnCallback(function (object $object): void {
            unset($this->tools[spl_object_id($object)]);
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });
        $entityManager->method('find')->willReturnCallback(function (string $class, mixed $id): ?object {
            $candidates = ApiConfiguration::class === $class ? $this->connections : array_values($this->tools);
            foreach ($candidates as $candidate) {
                if ((string) $candidate->getId() === (string) $id) {
                    return $candidate;
                }
            }

            return null;
        });

        return $entityManager;
    }

    private function addConnection(string $name, string $url, bool $active = true, string $type = 'mcp'): ApiConfiguration
    {
        $connection = (new ApiConfiguration())
            ->setName($name)
            ->setConfigJson(['mcp' => true, 'type' => $type, 'endpoint_type' => 'rest', 'url' => $url, 'transport' => 'streamable-http'])
            ->setActive($active);
        $this->connections[] = $connection;

        return $connection;
    }

    /**
     * @param array<string, mixed>|null $inputSchema
     */
    private function addTool(ApiConfiguration $connection, string $name, ?string $description = null, ?array $inputSchema = null, ?\DateTimeImmutable $syncedAt = null): McpTool
    {
        $tool = (new McpTool($connection, $name))
            ->setDescription($description)
            ->setInputSchema($inputSchema)
            ->setSyncedAt($syncedAt ?? new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $this->tools[spl_object_id($tool)] = $tool;

        return $tool;
    }

    /**
     * @return list<McpTool> sorted by name
     */
    private function toolsOf(ApiConfiguration $connection): array
    {
        $matches = array_values(array_filter(
            $this->tools,
            static fn (McpTool $tool): bool => $tool->getApiConfiguration() === $connection,
        ));
        usort($matches, static fn (McpTool $a, McpTool $b): int => strcmp($a->getName(), $b->getName()));

        return $matches;
    }

    /**
     * @return array<string, McpTool> the connection's rows by tool name
     */
    private function toolsByName(ApiConfiguration $connection): array
    {
        $byName = [];
        foreach ($this->toolsOf($connection) as $tool) {
            $byName[$tool->getName()] = $tool;
        }

        return $byName;
    }
}
