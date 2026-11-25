<?php

// file generated with AI assistance: Claude Code - 2026-11-25 22:00:17 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Anonymous\ClientAddressHasher;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Testing\FakeModelLister;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryAnonymousAccessPolicy;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Static helpers of the anonymous conversation tests
 * (`POST /api/conversations/anonymous` and the capability token).
 * Abstract, never run itself; called statically or extended, like
 * {@see AgentFixtures}.
 *
 * The release is {@see InMemoryAnonymousAccessPolicy} of the test kernel:
 * {@see configure()} sets its values (keys of a host's settings section,
 * e.g. `collections`, `model`, `agent`, `creations_per_address_and_hour`)
 * and adds the IRI of the release entry {@see releaseModel()}
 * ({@see FakeProviderFactory::ANTHROPIC_MODEL} on the `anthropic` fixture
 * connection {@see self::RELEASE_CONNECTION}, created on the way) unless
 * the values name `model` themselves (null leaves it unset). No validation
 * of the values happens, as a host's settings would do on save.
 *
 * Requests carry `X-Test-Auth: anonymous`, so the test authenticator adds
 * no identity. Every creation should send its own random documentation
 * address ({@see randomAddress()}, `2001:db8::/32`) as `X-Forwarded-For`
 * from the trusted test client (127.0.0.1), so creation counters never
 * leak between tests or runs.
 *
 * {@see cleanUp()} removes anonymous conversations (`owner_subject LIKE
 * 'anon:%'`), conversation audit entries, fixtures with the prefix
 * {@see self::PREFIX}, the release values and the agents of
 * {@see AgentFixtures::cleanUp()}.
 */
abstract class AnonymousFixtures extends ApiTestCase
{
    use ProviderModelFixtureTrait;

    protected static ?bool $alwaysBootKernel = true;

    public const string URI = '/api/conversations/anonymous';
    public const string PREFIX = 'test-anon-';

    /** The provider connection of the release entry without an agent, see releaseModel(). */
    public const string RELEASE_CONNECTION = self::PREFIX.FakeProviderFactory::ANTHROPIC_CONNECTION;

    /**
     * `POST /api/conversations/anonymous` without identity; $address is
     * sent as `X-Forwarded-For`.
     */
    public static function create(Client $client, ?string $address, string $body = '{}'): ResponseInterface
    {
        $headers = [
            'Accept' => 'application/ld+json',
            'Content-Type' => 'application/ld+json',
            TestHeaderAuthenticator::HEADER_AUTH => TestHeaderAuthenticator::ANONYMOUS,
        ];
        if (null !== $address) {
            $headers['X-Forwarded-For'] = $address;
        }

        return $client->request('POST', self::URI, ['headers' => $headers, 'body' => $body]);
    }

    /**
     * Headers of a request authenticated by a capability token only.
     *
     * @return array<string, string>
     */
    public static function tokenHeaders(string $token): array
    {
        return [
            'Accept' => 'application/ld+json',
            'Content-Type' => 'application/ld+json',
            TestHeaderAuthenticator::HEADER_AUTH => TestHeaderAuthenticator::ANONYMOUS,
            'Authorization' => 'Bearer '.$token,
        ];
    }

    /**
     * Sets release values on {@see InMemoryAnonymousAccessPolicy} (merged
     * over earlier ones). Without `model` in $values, the IRI of the
     * release entry {@see releaseModel()} (created when missing) is set
     * with them; pass null to leave it unset.
     *
     * @param array<string, mixed> $values
     */
    public static function configure(array $values): void
    {
        if (!\array_key_exists('model', $values)) {
            $values['model'] = self::releaseModel()->iri();
        }

        InMemoryAnonymousAccessPolicy::configure($values);
    }

    /**
     * The catalogue entry of the release: {@see FakeProviderFactory::ANTHROPIC_MODEL}
     * on {@see releaseConnection()}, created when missing and made usable
     * ({@see ProviderModelFixtureTrait::catalogueModel()}); removed with its
     * connection.
     */
    public static function releaseModel(): ProviderModel
    {
        return self::catalogueModel(self::entityManager(), self::releaseConnection(), FakeProviderFactory::ANTHROPIC_MODEL);
    }

    /**
     * The `anthropic` connection of the release, created when missing;
     * removed with the {@see self::PREFIX} fixtures.
     */
    public static function releaseConnection(): ApiConfiguration
    {
        $connection = self::entityManager()->getRepository(ApiConfiguration::class)->findOneBy(['name' => self::RELEASE_CONNECTION]);
        if ($connection instanceof ApiConfiguration) {
            return $connection;
        }

        $connection = ProviderConnectionFixtures::anthropic(self::RELEASE_CONNECTION);
        ProviderConnectionFixtures::persist(self::entityManager(), $connection);

        return $connection;
    }

    /**
     * The model lister of the current container; scripts apply to the next
     * request only on a client without kernel reboot.
     */
    public static function modelLister(): FakeModelLister
    {
        $lister = static::getContainer()->get(FakeModelLister::class);
        self::assertInstanceOf(FakeModelLister::class, $lister);

        return $lister;
    }

    /**
     * A random address of the IPv6 documentation range.
     */
    public static function randomAddress(): string
    {
        return \sprintf('2001:db8:%x:%x::%x', random_int(0, 0xFFFF), random_int(0, 0xFFFF), random_int(1, 0xFFFF));
    }

    public static function addressHash(string $address): string
    {
        $key = static::getContainer()->getParameter('dmstr_neuron_ai.anonymous.address_hash_key');
        self::assertIsString($key);

        return (new ClientAddressHasher($key))->hash($address);
    }

    /**
     * @return array<string, mixed> the conversation row
     */
    public static function row(string $id): array
    {
        $row = self::connection()->fetchAssociative('SELECT * FROM neuron_conversation WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row, 'conversation '.$id);

        return $row;
    }

    /**
     * Audit entries of a conversation, oldest first.
     *
     * @return list<array{action: string, username: string|null, data: string|null}>
     */
    public static function auditEntries(string $id): array
    {
        /** @var list<array{action: string, username: string|null, data: string|null}> $entries */
        $entries = self::connection()->fetchAllAssociative(
            'SELECT action, username, data FROM dmstr_log_entries WHERE object_class = :class AND object_id = :id ORDER BY version ASC',
            ['class' => Conversation::class, 'id' => $id],
        );

        return $entries;
    }

    public static function anonymousCount(): int
    {
        return (int) self::connection()->fetchOne("SELECT COUNT(*) FROM neuron_conversation WHERE owner_subject LIKE 'anon:%'");
    }

    /**
     * Removes anonymous conversations (messages cascade), conversation
     * audit entries, the release values, the {@see self::PREFIX} fixtures
     * and the agents, collections and connections of
     * {@see AgentFixtures::cleanUp()}; conversations of any owner on a
     * fixture provider connection go before the connections
     * ({@see AgentFixtures::deleteProviderConnections()}).
     */
    public static function cleanUp(): void
    {
        InMemoryAnonymousAccessPolicy::reset();
        static::bootKernel();
        $connection = self::connection();
        $connection->executeStatement("DELETE FROM neuron_conversation WHERE owner_subject LIKE 'anon:%'");
        $connection->executeStatement('DELETE FROM dmstr_log_entries WHERE object_class = :class', ['class' => Conversation::class]);
        $connection->executeStatement('DELETE FROM neuron_collection WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
        AgentFixtures::deleteProviderConnections($connection, self::PREFIX.'%');
        static::ensureKernelShutdown();

        // After the conversations, which reference agent versions.
        AgentFixtures::cleanUp();
    }

    public static function connection(): Connection
    {
        if (null === static::$kernel) {
            static::bootKernel();
        }
        TestDatabase::migrate(static::$kernel);

        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');

        return $connection;
    }

    public static function entityManager(): EntityManagerInterface
    {
        if (null === static::$kernel) {
            static::bootKernel();
        }
        TestDatabase::migrate(static::$kernel);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    /**
     * Creates and publishes an agent ({@see AgentFixtures::PREFIX}) with a
     * valid candidate plus $overrides, as the default admin.
     *
     * @param string               $slug      without the prefix
     * @param array<string, mixed> $overrides
     *
     * @return array{0: string, 1: array<string, mixed>} the agent id and the published version
     */
    public static function publishedAgent(string $slug, array $overrides = []): array
    {
        $id = AgentFixtures::createAgent($slug, AgentFixtures::validCandidate($overrides));
        $version = AgentFixtures::publish($id);
        self::assertArrayHasKey('@id', $version, json_encode($version, \JSON_THROW_ON_ERROR));

        return [$id, $version];
    }
}
