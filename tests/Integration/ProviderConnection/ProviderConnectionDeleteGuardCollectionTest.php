<?php

// file generated with AI assistance: Claude Code - 2026-10-05 20:27:34 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\ProviderConnection;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Support\ProviderModelFixtureTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The delete guard for collections: deleting a provider
 * connection that collections embed through, as their current connection
 * or as the target of a pending reindex, answers 409 naming them by name
 * and IRI, through the API and through the EntityManager; with more than
 * ten collections nine are listed and the rest counted; once the
 * collections are gone the connection can be deleted (204).
 *
 * Collections reference a catalogue entry of the connection
 * (`nomic-embed-text:latest`, the pending target `mxbai-embed-large`) next
 * to the connection itself; a reference through the entry alone is found
 * as well, so the cascade from the connection to its entries never runs
 * into the keys of the collection.
 *
 * Needs the migrated test database (CI only). Connections and
 * collections carry the name prefix `test-guard-coll-`; the collections
 * are removed before the connections (foreign keys without cascade; the
 * entries cascade with their connection).
 */
#[Group('database')]
final class ProviderConnectionDeleteGuardCollectionTest extends ApiTestCase
{
    use ProviderModelFixtureTrait;

    protected static ?bool $alwaysBootKernel = true;

    private const string PREFIX = 'test-guard-coll-';

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

    public function testConnectionOfACollectionIsNotDeletedThroughTheApi(): void
    {
        $client = static::createClient();
        $connection = ProviderConnectionFixtures::ollama(self::PREFIX.'local');
        ProviderConnectionFixtures::persist(self::entityManager(), $connection);
        $collection = self::collection('docs', $connection);

        $response = $client->request('DELETE', '/api/admin/api_configurations/'.$connection->getId()->toRfc4122(), ['headers' => ['Accept' => 'application/ld+json']]);

        self::assertResponseStatusCodeSame(409);
        $detail = (string) ($response->toArray(false)['detail'] ?? '');
        self::assertStringContainsString('Provider connection "'.self::PREFIX.'local" is still referenced by collection "'.self::PREFIX.'docs" (/api/admin/collections/'.$collection->getId()->toRfc4122().')', $detail);
        self::assertSame(1, self::connectionCount());

        // Without the collection the connection goes.
        self::dbal()->executeStatement('DELETE FROM neuron_collection WHERE id = :id', ['id' => $collection->getId()->toRfc4122()]);
        $client->request('DELETE', '/api/admin/api_configurations/'.$connection->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, self::connectionCount());
    }

    public function testTheTargetOfAPendingReindexIsAReferenceToo(): void
    {
        static::bootKernel();
        $current = ProviderConnectionFixtures::ollama(self::PREFIX.'local');
        $target = ProviderConnectionFixtures::ollama(self::PREFIX.'remote', url: 'http://remote.fake.test:11434/api');
        ProviderConnectionFixtures::persist(self::entityManager(), $current, $target);
        $collection = self::collection('docs', $current);
        $collection->setPendingEmbeddingModel('mxbai-embed-large')->setPendingEmbeddingProviderModel(self::catalogueModel(self::entityManager(), $target, 'mxbai-embed-large'));
        self::entityManager()->flush();

        try {
            self::entityManager()->remove($target);
            self::fail('Expected a ConflictHttpException.');
        } catch (ConflictHttpException $e) {
            self::assertStringContainsString('Provider connection "'.self::PREFIX.'remote" is still referenced by pending reindex of collection "'.self::PREFIX.'docs" (/api/admin/collections/'.$collection->getId()->toRfc4122().')', $e->getMessage());
        }

        self::entityManager()->clear();
        self::assertSame(2, self::connectionCount());
    }

    /**
     * A collection whose connection columns are empty but whose entries
     * belong to the connection (current and pending): the delete would
     * cascade the entries away against the collection's keys, so it is a
     * reference.
     */
    public function testAReferenceThroughTheCatalogueEntryAloneIsFound(): void
    {
        static::bootKernel();
        $connection = ProviderConnectionFixtures::ollama(self::PREFIX.'local');
        $target = ProviderConnectionFixtures::ollama(self::PREFIX.'remote', url: 'http://remote.fake.test:11434/api');
        ProviderConnectionFixtures::persist(self::entityManager(), $connection, $target);
        $current = self::collection('current', $connection);
        $pending = self::collection('pending', $connection);
        $pending->setPendingEmbeddingModel('mxbai-embed-large')->setPendingEmbeddingProviderModel(self::catalogueModel(self::entityManager(), $target, 'mxbai-embed-large'));
        self::entityManager()->flush();
        self::dbal()->executeStatement('UPDATE neuron_collection SET embedding_connection_id = NULL, pending_embedding_connection_id = NULL WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
        self::entityManager()->clear();

        foreach ([[$connection, 'collection "'.self::PREFIX.'current" (/api/admin/collections/'.$current->getId()->toRfc4122().')'], [$target, 'pending reindex of collection "'.self::PREFIX.'pending" (/api/admin/collections/'.$pending->getId()->toRfc4122().')']] as [$deleted, $reference]) {
            $managed = self::entityManager()->find(ApiConfiguration::class, $deleted->getId());
            self::assertInstanceOf(ApiConfiguration::class, $managed);
            try {
                self::entityManager()->remove($managed);
                self::fail('Expected a ConflictHttpException.');
            } catch (ConflictHttpException $e) {
                self::assertStringContainsString('is still referenced by '.$reference, $e->getMessage());
            }
            self::entityManager()->clear();
        }

        self::assertSame(2, self::connectionCount());
    }

    public function testEntityManagerRemovalListsNineCollectionsAndCountsTheRest(): void
    {
        static::bootKernel();
        $connection = ProviderConnectionFixtures::ollama(self::PREFIX.'local');
        ProviderConnectionFixtures::persist(self::entityManager(), $connection);
        for ($i = 0; $i < 12; ++$i) {
            self::collection(\sprintf('docs-%02d', $i), $connection);
        }

        try {
            self::entityManager()->remove($connection);
            self::fail('Expected a ConflictHttpException.');
        } catch (ConflictHttpException $e) {
            self::assertSame(9, substr_count($e->getMessage(), '(/api/admin/collections/'));
            self::assertStringContainsString('collection "'.self::PREFIX.'docs-00"', $e->getMessage());
            self::assertStringContainsString('; 3 more collections.', $e->getMessage());
        }

        self::entityManager()->clear();
        self::assertSame(1, self::connectionCount());
    }

    /**
     * A collection on the entry `nomic-embed-text:latest` of $connection
     * (labelled `nomic-embed-text`).
     */
    private static function collection(string $name, ApiConfiguration $connection): Collection
    {
        $collection = new Collection()
            ->setName(self::PREFIX.$name)
            ->setEmbeddingModel('nomic-embed-text')
            ->setEmbeddingProviderModel(self::catalogueModel(self::entityManager(), $connection, 'nomic-embed-text:latest'));
        self::entityManager()->persist($collection);
        self::entityManager()->flush();

        return $collection;
    }

    private static function connectionCount(): int
    {
        return (int) self::dbal()->fetchOne('SELECT COUNT(*) FROM dmstr_api_configuration WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
    }

    private static function cleanUp(): void
    {
        $database = self::dbal();
        $prefix = ['prefix' => self::PREFIX.'%'];
        // Collections first: they reference the connections.
        $database->executeStatement('DELETE FROM neuron_collection WHERE name LIKE :prefix', $prefix);
        $database->executeStatement('DELETE FROM dmstr_api_configuration WHERE name LIKE :prefix', $prefix);
        static::ensureKernelShutdown();
    }

    private static function entityManager(): EntityManagerInterface
    {
        if (null === static::$kernel) {
            static::bootKernel();
        }
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    private static function dbal(): Connection
    {
        if (null === static::$kernel) {
            static::bootKernel();
        }
        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');

        return $connection;
    }
}
