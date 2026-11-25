<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:41:04 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Entity\Refs\McpTool;
use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\Job\JobProcessorRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fixtures of the database-backed catalogue tests (ApiTestCase). Needs the
 * migrated test database ({@see \Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase::migrate()}, group `database`). Connections are created directly through the
 * entity manager with the name prefix `test-mcp-cat-`; cleanUp() removes
 * them (their catalogue rows cascade) and every job of type `mcp_tool_scan`.
 */
trait CatalogueDatabaseTrait
{
    private const string PREFIX = 'test-mcp-cat-';
    private const string COLLECTION = '/api/refs/mcp_tools';
    private const string CONNECTIONS = '/api/admin/api_configurations';

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        self::cleanUp();
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        parent::tearDown();
    }

    public static function cleanUp(): void
    {
        $connection = self::dbal();
        $connection->executeStatement('DELETE FROM dmstr_job WHERE type = :type', ['type' => McpTool::JOB_TYPE]);
        $connection->executeStatement('DELETE FROM dmstr_api_configuration WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
        static::ensureKernelShutdown();
    }

    /**
     * A client whose kernel is kept across requests, so the scripted fake
     * transport and the entities created here stay in place.
     */
    private function client(): Client
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function fake(): FakeMcpTransportFactory
    {
        $fake = static::getContainer()->get(FakeMcpTransportFactory::class);
        self::assertInstanceOf(FakeMcpTransportFactory::class, $fake);

        return $fake;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    /**
     * @param string $name without the prefix
     */
    private function connection(string $name, string $url, bool $active = true, string $type = 'mcp'): ApiConfiguration
    {
        $connection = (new ApiConfiguration())
            ->setName(self::PREFIX.$name)
            ->setConfigJson(['mcp' => true, 'type' => $type, 'endpoint_type' => 'rest', 'url' => $url, 'transport' => 'streamable-http', 'auth_type' => 'none'])
            ->setActive($active);
        $this->entityManager()->persist($connection);
        $this->entityManager()->flush();

        return $connection;
    }

    /**
     * @param array<string, mixed>|null $inputSchema
     */
    private function tool(ApiConfiguration $connection, string $name, ?string $description = null, ?array $inputSchema = null): McpTool
    {
        $tool = (new McpTool($connection, $name))
            ->setDescription($description)
            ->setInputSchema($inputSchema)
            ->setSyncedAt(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $this->entityManager()->persist($tool);
        $this->entityManager()->flush();

        return $tool;
    }

    /**
     * Catalogue rows of a connection, straight from the database.
     *
     * @return array<string, array<string, mixed>> keyed by tool name
     */
    private static function rows(ApiConfiguration $connection): array
    {
        $rows = self::dbal()->fetchAllAssociative(
            'SELECT * FROM neuron_mcp_tool WHERE api_configuration_id = :id ORDER BY name',
            ['id' => (string) $connection->getId()],
        );

        return array_column($rows, null, 'name');
    }

    private static function jobCount(): int
    {
        return (int) self::dbal()->fetchOne('SELECT COUNT(*) FROM dmstr_job WHERE type = :type', ['type' => McpTool::JOB_TYPE]);
    }

    /**
     * Runs a queued scan job the way the job queue's message handler does
     * (tests route `async` to an in-memory transport, so no worker runs).
     */
    private function runJob(string $jobId): Job
    {
        $job = $this->entityManager()->find(Job::class, $jobId);
        self::assertInstanceOf(Job::class, $job);

        /** @var JobProcessorRegistry $registry */
        $registry = static::getContainer()->get(JobProcessorRegistry::class);
        $registry->getProcessor($job->getType())->process($job);
        $job->setStatus('completed');
        $this->entityManager()->flush();

        return $job;
    }

    /**
     * @param array<string, string> $headers
     */
    private function postScan(Client $client, string $body, array $headers = []): void
    {
        $client->request('POST', self::COLLECTION.'/scan', [
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/ld+json'] + $headers,
            'body' => $body,
        ]);
    }

    private static function dbal(): Connection
    {
        if (null === static::$kernel) {
            static::bootKernel();
        }
        TestDatabase::migrate(static::$kernel);

        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');

        return $connection;
    }
}
