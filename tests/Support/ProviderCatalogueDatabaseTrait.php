<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Testing\FakeModelDescriber;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\Job\JobProcessorRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fixtures of the database-backed model catalogue tests (ApiTestCase).
 * Needs the migrated test database ({@see \Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase::migrate()}, group `database`). Connections are created
 * directly through the entity manager with the name prefix
 * `test-prov-cat-`; cleanUp() removes them (their catalogue rows cascade)
 * and every job of type `provider_model_scan`.
 */
trait ProviderCatalogueDatabaseTrait
{
    private const string PREFIX = 'test-prov-cat-';
    private const string COLLECTION = '/api/refs/provider_models';
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
        $connection->executeStatement('DELETE FROM dmstr_job WHERE type = :type', ['type' => ProviderModel::JOB_TYPE]);
        $connection->executeStatement('DELETE FROM dmstr_api_configuration WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
        static::ensureKernelShutdown();
    }

    /**
     * A client whose kernel is kept across requests, so the scripted fake
     * describer and the entities created here stay in place.
     */
    private function client(): Client
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function fake(): FakeModelDescriber
    {
        $fake = static::getContainer()->get(FakeModelDescriber::class);
        self::assertInstanceOf(FakeModelDescriber::class, $fake);

        return $fake;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    /**
     * An `ollama` connection (or an `anthropic` one with $anthropic).
     *
     * @param string $name without the prefix
     */
    private function connection(string $name, string $url = ProviderConnectionFixtures::OLLAMA_URL, bool $active = true, bool $anthropic = false): ApiConfiguration
    {
        $connection = $anthropic
            ? ProviderConnectionFixtures::anthropic(self::PREFIX.$name, $active)
            : ProviderConnectionFixtures::ollama(self::PREFIX.$name, $active, $url);
        ProviderConnectionFixtures::persist($this->entityManager(), $connection);

        return $connection;
    }

    /**
     * A catalogue row described from the connection's current endpoint.
     *
     * @param list<string>|null $capabilities
     */
    private function model(ApiConfiguration $connection, string $name, ?array $capabilities = null, ?string $family = null): ProviderModel
    {
        $model = (new ProviderModel($connection, $name))
            ->setCapabilities($capabilities)
            ->setFamily($family)
            ->setSyncedAt(null === $capabilities ? null : new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $this->entityManager()->persist($model);
        $this->entityManager()->flush();

        return $model;
    }

    /**
     * Catalogue rows of a connection, straight from the database.
     *
     * @return array<string, array<string, mixed>> keyed by model name
     */
    private static function rows(ApiConfiguration $connection): array
    {
        $rows = self::dbal()->fetchAllAssociative(
            'SELECT * FROM neuron_provider_model WHERE api_configuration_id = :id ORDER BY name',
            ['id' => (string) $connection->getId()],
        );

        return array_column($rows, null, 'name');
    }

    private static function jobCount(): int
    {
        return (int) self::dbal()->fetchOne('SELECT COUNT(*) FROM dmstr_job WHERE type = :type', ['type' => ProviderModel::JOB_TYPE]);
    }

    /**
     * @return list<array<string, mixed>> input data of the queued scan jobs, oldest first
     */
    private static function jobInputs(): array
    {
        return array_map(
            static fn (string $json): array => json_decode($json, true, 512, \JSON_THROW_ON_ERROR),
            self::dbal()->fetchFirstColumn('SELECT input_data FROM dmstr_job WHERE type = :type ORDER BY created_at', ['type' => ProviderModel::JOB_TYPE]),
        );
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
