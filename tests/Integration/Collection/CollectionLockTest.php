<?php

// file generated with AI assistance: Claude Code - 2026-10-13 18:45:18 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Collection;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Knowledge\CollectionLockedException;
use Dmstr\NeuronAiBundle\Knowledge\CollectionLockGuard;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\ProviderModelFixtureTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Collection lock through the API (`POST /api/admin/collections/{id}/lock`
 * and `.../unlock`) and the Doctrine listener behind PATCH and DELETE.
 *
 * The test firewall authenticates as `test-admin` with ROLE_ADMIN, which
 * may lock and unlock; an editor locks but does not unlock. `X-Test-Roles`
 * gives other roles (see
 * {@see TestHeaderAuthenticator}). Collections and connections of this
 * suite carry the prefix {@see PREFIX}; collections are removed by SQL,
 * past the lock listener.
 */
#[Group('database')]
class CollectionLockTest extends ApiTestCase
{
    use ProviderModelFixtureTrait;

    protected static ?bool $alwaysBootKernel = true;

    private const string PREFIX = 'test-lock-';
    private const string NAME = self::PREFIX.'docs';

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

    public function testAdminLocksTheCollection(): void
    {
        $client = static::createClient();
        $collection = self::collection();
        $iri = self::iri($collection);

        $data = $this->action($client, $iri.'/lock')->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($data['locked']);
        self::assertNotNull($data['lockedAt']);
        self::assertSame(TestHeaderAuthenticator::ADMIN_IDENTIFIER, $data['lockedBy']);
        $row = self::row($collection);
        self::assertNotNull($row['locked_at']);
        self::assertSame(TestHeaderAuthenticator::ADMIN_IDENTIFIER, $row['locked_by']);

        // Audited with the actor (Gedmo loggable on lockedAt and lockedBy).
        $entries = self::dbal()->fetchAllAssociative(
            "SELECT action, username FROM dmstr_log_entries WHERE object_class = :class AND object_id = :id AND action = 'update'",
            ['class' => Collection::class, 'id' => $collection->getId()->toRfc4122()],
        );
        self::assertSame([['action' => 'update', 'username' => TestHeaderAuthenticator::ADMIN_IDENTIFIER]], $entries);
    }

    public function testLockingALockedCollectionChangesNothing(): void
    {
        $client = static::createClient();
        $iri = self::iri(self::collection());
        $first = $this->action($client, $iri.'/lock')->toArray();

        $second = $this->action($client, $iri.'/lock', ['X-Test-User' => 'another-admin'])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame($first['lockedAt'], $second['lockedAt']);
        self::assertSame(TestHeaderAuthenticator::ADMIN_IDENTIFIER, $second['lockedBy']);
    }

    public function testAUserCannotLock(): void
    {
        $client = static::createClient();
        $collection = self::collection();

        $this->action($client, self::iri($collection).'/lock', [TestHeaderAuthenticator::HEADER_ROLES => 'ROLE_USER']);

        self::assertResponseStatusCodeSame(403);
        self::assertNull(self::row($collection)['locked_at']);
    }

    public function testAnEditorLocksButCannotUnlock(): void
    {
        $client = static::createClient();
        $collection = self::collection();
        $iri = self::iri($collection);
        $editor = [TestHeaderAuthenticator::HEADER_ROLES => 'ROLE_EDITOR'];
        $this->action($client, $iri.'/lock', $editor);
        self::assertResponseIsSuccessful();

        $this->action($client, $iri.'/unlock', $editor);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull(self::row($collection)['locked_at']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unlockRoles(): iterable
    {
        yield 'unlock role' => ['ROLE_USER,ROLE_COLLECTION_UNLOCK'];
        yield 'admin (hierarchy)' => ['ROLE_ADMIN'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unlockRoles')]
    public function testTheUnlockRoleUnlocks(string $roles): void
    {
        $client = static::createClient();
        $collection = self::collection();
        $iri = self::iri($collection);
        $this->action($client, $iri.'/lock');

        $data = $this->action($client, $iri.'/unlock', [TestHeaderAuthenticator::HEADER_ROLES => $roles])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($data['locked']);
        self::assertNull($data['lockedAt']);
        self::assertNull($data['lockedBy']);
        self::assertSame(['locked_at' => null, 'locked_by' => null], self::row($collection));

        // Unlocking an unlocked collection changes nothing.
        $this->action($client, $iri.'/unlock', [TestHeaderAuthenticator::HEADER_ROLES => $roles]);
        self::assertResponseStatusCodeSame(200);

        // Unlocked, it can be changed again.
        $this->patch($client, $iri, ['description' => 'Wieder offen']);
        self::assertResponseStatusCodeSame(200);
    }

    public function testPatchOfALockedCollectionIsLocked(): void
    {
        $client = static::createClient();
        $collection = self::collection();
        $iri = self::iri($collection);
        $this->action($client, $iri.'/lock');

        $response = $this->patch($client, $iri, ['description' => 'Geändert']);

        self::assertResponseStatusCodeSame(423);
        self::assertSame(\sprintf('Collection "%s" is locked', self::NAME), $response->toArray(false)['detail'] ?? null);
        self::assertNull(self::dbal()->fetchOne('SELECT description FROM neuron_collection WHERE id = :id', ['id' => $collection->getId()->toRfc4122()]));
    }

    public function testDeleteOfALockedCollectionIsLocked(): void
    {
        $client = static::createClient();
        $collection = self::collection();
        $iri = self::iri($collection);
        $this->action($client, $iri.'/lock');

        $response = $client->request('DELETE', $iri, ['headers' => ['Accept' => 'application/ld+json']]);

        self::assertResponseStatusCodeSame(423);
        self::assertSame(\sprintf('Collection "%s" is locked', self::NAME), $response->toArray(false)['detail'] ?? null);
        self::assertNotFalse(self::row($collection));
    }

    public function testTheLockIsNotWritable(): void
    {
        $client = static::createClient();
        $collection = self::collection();

        $data = $this->patch($client, self::iri($collection), ['lockedAt' => '2026-01-01T00:00:00+00:00', 'lockedBy' => 'someone', 'locked' => true])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($data['locked']);
        self::assertNull($data['lockedAt']);
        self::assertNull($data['lockedBy']);
        self::assertSame(['locked_at' => null, 'locked_by' => null], self::row($collection));
    }

    /**
     * A worker that loaded the collection before it was locked still holds
     * an unlocked entity: the guard and the listener read the stored row.
     */
    public function testAStaleEntityIsGuardedByTheStoredLock(): void
    {
        $collection = self::collection();
        $guard = new CollectionLockGuard(self::dbal());
        self::assertTrue($guard->isWritable($collection));

        self::dbal()->executeStatement(
            "UPDATE neuron_collection SET locked_at = now(), locked_by = 'elsewhere' WHERE id = :id",
            ['id' => $collection->getId()->toRfc4122()],
        );
        self::assertFalse($collection->isLocked(), 'The entity is stale.');
        self::assertFalse($guard->isWritable($collection));

        try {
            $guard->assertWritable($collection);
            self::fail('The guard did not refuse the locked collection.');
        } catch (CollectionLockedException $e) {
            self::assertSame(423, $e->getStatusCode());
        }

        $collection->setDescription('Stale write');
        $this->expectException(CollectionLockedException::class);
        self::entityManager()->flush();
    }

    public function testANewCollectionIsWritable(): void
    {
        $collection = new Collection()->setName(self::PREFIX.'new');

        self::assertTrue(new CollectionLockGuard(self::dbal())->isWritable($collection));
    }

    /**
     * @param array<string, string> $headers
     */
    private function action(Client $client, string $uri, array $headers = []): ResponseInterface
    {
        return $client->request('POST', $uri, [
            'headers' => ['Accept' => 'application/ld+json'] + $headers,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patch(Client $client, string $iri, array $body): ResponseInterface
    {
        return $client->request('PATCH', $iri, [
            'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => $body,
        ]);
    }

    private static function collection(): Collection
    {
        $entityManager = self::entityManager();
        $connection = ProviderConnectionFixtures::ollama(self::PREFIX.'fake-ollama');
        ProviderConnectionFixtures::persist($entityManager, $connection);
        $collection = new Collection()
            ->setName(self::NAME)
            ->setEmbeddingModel('nomic-embed-text')
            ->setEmbeddingProviderModel(self::catalogueModel($entityManager, $connection, 'nomic-embed-text:latest'));
        $entityManager->persist($collection);
        $entityManager->flush();

        return $collection;
    }

    private static function iri(Collection $collection): string
    {
        return '/api/admin/collections/'.$collection->getId()->toRfc4122();
    }

    /**
     * @return array{locked_at: ?string, locked_by: ?string}|false
     */
    private static function row(Collection $collection): array|false
    {
        return self::dbal()->fetchAssociative(
            'SELECT locked_at, locked_by FROM neuron_collection WHERE id = :id',
            ['id' => $collection->getId()->toRfc4122()],
        );
    }

    private static function cleanUp(): void
    {
        $database = self::dbal();
        $prefix = ['prefix' => self::PREFIX.'%'];
        $database->executeStatement(
            'DELETE FROM dmstr_log_entries WHERE object_class = :class AND object_id IN (SELECT id::text FROM neuron_collection WHERE name LIKE :prefix)',
            ['class' => Collection::class] + $prefix,
        );
        // By SQL, past the lock listener; collections first: they reference the connections.
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
