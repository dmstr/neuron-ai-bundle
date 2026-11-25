<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\ProviderConnection;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Testing\FakeModelLister;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Functional test of `ollama` and `anthropic` connections through the
 * generic connection resource of dmstr/api-configuration-bundle 0.5 under
 * /api/admin/api_configurations (the test firewall authenticates as admin):
 * schema validation, name uniqueness across both types, encryption and
 * masking of `api_key` and of the `ollama` secrets `token` and
 * `client_secret`, admin-only reads, health through the fake model
 * lister, and the delete guard for agent versions.
 *
 * Needs the migrated test database (CI only). Connections carry the name
 * prefix `test-provider-`, agents the slug prefix `test-provider-`; both
 * are removed before and after each test.
 */
#[Group('database')]
final class ProviderConnectionResourceTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const string COLLECTION = '/api/admin/api_configurations';
    private const string PREFIX = 'test-provider-';
    private const string KEY = 'sk-ant-resource-placeholder-31337';
    private const string TOKEN = 'ollama-resource-placeholder-token-31337';
    private const string CLIENT_SECRET = 'ollama-resource-placeholder-secret-31337';

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

    public function testAdminCreatesAnOllamaConnection(): void
    {
        $response = $this->post(static::createClient(), self::PREFIX.'local', ProviderConnectionFixtures::ollamaConfig('http://ollama:11434/api', ['timeout' => 60]));

        self::assertResponseStatusCodeSame(201);
        $data = $response->toArray();
        self::assertSame('ollama', $data['type']);
        self::assertSame('rest', $data['endpointType']);
        self::assertSame('http://ollama:11434/api', $data['configJson']['url']);
        self::assertSame(60, $data['configJson']['timeout']);
    }

    public function testAnthropicKeyIsStoredEncryptedAndReturnedMasked(): void
    {
        $client = static::createClient();
        $created = $this->post($client, self::PREFIX.'claude', ProviderConnectionFixtures::anthropicConfig(self::KEY));

        self::assertResponseStatusCodeSame(201);
        self::assertSame('********', $created->toArray()['configJson']['api_key']);
        self::assertStringNotContainsString(self::KEY, $created->getContent());
        self::assertStringStartsWith('enc:v1:', self::storedConfig($created->toArray()['id'])['api_key']);

        $read = $client->request('GET', $created->toArray()['@id'], ['headers' => ['Accept' => 'application/ld+json']]);
        self::assertSame('********', $read->toArray()['configJson']['api_key']);
    }

    public function testUpdateWithoutTheKeyKeepsIt(): void
    {
        $client = static::createClient();
        $created = $this->post($client, self::PREFIX.'claude', ProviderConnectionFixtures::anthropicConfig(self::KEY))->toArray();
        $stored = self::storedConfig($created['id'])['api_key'];
        $config = ProviderConnectionFixtures::anthropicConfig(options: ['max_tokens' => 2048]);
        unset($config['api_key']);

        $client->request('PATCH', $created['@id'], [
            'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['configJson' => $config, 'active' => false],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($stored, self::storedConfig($created['id'])['api_key']);
        self::assertSame(2048, self::storedConfig($created['id'])['max_tokens']);
    }

    public function testOllamaClientSecretIsStoredEncryptedAndReturnedMasked(): void
    {
        $client = static::createClient();
        $created = $this->post($client, self::PREFIX.'oidc', ProviderConnectionFixtures::ollamaClientCredentialsConfig(self::CLIENT_SECRET, options: ['scope' => 'openid']));

        self::assertResponseStatusCodeSame(201);
        $data = $created->toArray()['configJson'];
        self::assertSame('********', $data['client_secret']);
        self::assertSame('client_credentials', $data['auth_type']);
        self::assertSame(ProviderConnectionFixtures::TOKEN_URL, $data['token_url']);
        self::assertSame(ProviderConnectionFixtures::CLIENT_ID, $data['client_id']);
        self::assertSame('openid', $data['scope']);
        self::assertStringNotContainsString(self::CLIENT_SECRET, $created->getContent());
        self::assertStringStartsWith('enc:v1:', self::storedConfig($created->toArray()['id'])['client_secret']);

        $read = $client->request('GET', $created->toArray()['@id'], ['headers' => ['Accept' => 'application/ld+json']]);
        self::assertSame('********', $read->toArray()['configJson']['client_secret']);
        self::assertStringNotContainsString(self::CLIENT_SECRET, $read->getContent());
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function secretUpdates(): iterable
    {
        yield 'with the mask' => [true];
        yield 'without the key' => [false];
    }

    #[DataProvider('secretUpdates')]
    public function testUpdateKeepsTheOllamaClientSecret(bool $sendMask): void
    {
        $client = static::createClient();
        $created = $this->post($client, self::PREFIX.'oidc', ProviderConnectionFixtures::ollamaClientCredentialsConfig(self::CLIENT_SECRET))->toArray();
        $stored = self::storedConfig($created['id'])['client_secret'];
        $config = ProviderConnectionFixtures::ollamaClientCredentialsConfig('********', options: ['scope' => 'ollama']);
        if (!$sendMask) {
            unset($config['client_secret']);
        }

        $client->request('PATCH', $created['@id'], [
            'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['configJson' => $config],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($stored, self::storedConfig($created['id'])['client_secret']);
        self::assertSame('ollama', self::storedConfig($created['id'])['scope']);
    }

    public function testOllamaBearerTokenIsStoredEncryptedAndReturnedMasked(): void
    {
        $client = static::createClient();
        $created = $this->post($client, self::PREFIX.'proxy', ProviderConnectionFixtures::ollamaBearerConfig(self::TOKEN));

        self::assertResponseStatusCodeSame(201);
        self::assertSame('********', $created->toArray()['configJson']['token']);
        self::assertStringNotContainsString(self::TOKEN, $created->getContent());
        $stored = self::storedConfig($created->toArray()['id'])['token'];
        self::assertStringStartsWith('enc:v1:', $stored);

        $client->request('PATCH', $created->toArray()['@id'], [
            'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['configJson' => ProviderConnectionFixtures::ollamaBearerConfig('********', options: ['timeout' => 30])],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($stored, self::storedConfig($created->toArray()['id'])['token']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidConfigs(): iterable
    {
        $withoutUrl = ProviderConnectionFixtures::ollamaConfig();
        unset($withoutUrl['url']);
        $withoutKey = ProviderConnectionFixtures::anthropicConfig();
        unset($withoutKey['api_key']);

        yield 'ollama without url' => [$withoutUrl];
        yield 'ollama url without /api' => [ProviderConnectionFixtures::ollamaConfig('http://ollama:11434')];
        yield 'ollama num_ctx' => [ProviderConnectionFixtures::ollamaConfig(options: ['num_ctx' => 8192])];
        yield 'anthropic without api_key' => [$withoutKey];
        yield 'ollama bearer token with CRLF' => [ProviderConnectionFixtures::ollamaBearerConfig("abc\r\nX-Injected: 1")];
        yield 'ollama client_credentials http token_url' => [['token_url' => 'http://auth.fake.test/token'] + ProviderConnectionFixtures::ollamaClientCredentialsConfig()];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigs')]
    public function testInvalidConfigurationIsRejected(array $config): void
    {
        $response = $this->post(static::createClient(), self::PREFIX.'broken', $config);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('configJson', self::violationPaths($response));
        self::assertSame(0, self::rowCount());
    }

    public function testNamesAreUniqueAcrossOllamaAndAnthropic(): void
    {
        $client = static::createClient();
        $this->post($client, self::PREFIX.'main', ProviderConnectionFixtures::ollamaConfig());
        self::assertResponseStatusCodeSame(201);

        $response = $this->post($client, self::PREFIX.'main', ProviderConnectionFixtures::anthropicConfig());

        self::assertResponseStatusCodeSame(422);
        self::assertContains('name', self::violationPaths($response));
        self::assertStringNotContainsString(ProviderConnectionFixtures::PLACEHOLDER_API_KEY, $response->getContent(false));
    }

    public function testAnMcpConnectionMayShareTheName(): void
    {
        $client = static::createClient();
        $this->post($client, self::PREFIX.'shared', ['mcp' => true, 'type' => 'mcp', 'endpoint_type' => 'rest', 'url' => 'https://mcp.example.org/mcp', 'transport' => 'streamable-http']);
        self::assertResponseStatusCodeSame(201);

        $this->post($client, self::PREFIX.'shared', ProviderConnectionFixtures::ollamaConfig());

        self::assertResponseStatusCodeSame(201);
    }

    public function testReadsRequireTheAdminRole(): void
    {
        $client = static::createClient();
        $created = $this->post($client, self::PREFIX.'claude', ProviderConnectionFixtures::anthropicConfig())->toArray();
        $asUser = ['headers' => ['Accept' => 'application/ld+json', TestHeaderAuthenticator::HEADER_ROLES => 'ROLE_USER']];

        $client->request('GET', $created['@id'], $asUser);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', $created['@id'].'/health', $asUser);
        self::assertResponseStatusCodeSame(403);
    }

    public function testHealthUsesTheModelLister(): void
    {
        $client = static::createClient();
        // One kernel for all requests, so the scripted fake stays in place.
        $client->disableReboot();
        $ollama = $this->post($client, self::PREFIX.'local', ProviderConnectionFixtures::ollamaConfig())->toArray();
        $claude = $this->post($client, self::PREFIX.'claude', ProviderConnectionFixtures::anthropicConfig(self::KEY))->toArray();
        $lister = static::getContainer()->get(FakeModelLister::class);
        self::assertInstanceOf(FakeModelLister::class, $lister);

        $lister->offer(['llama3.2:latest', 'nomic-embed-text:latest']);
        $ok = $this->health($client, $ollama['id']);
        self::assertSame('ok', $ok['status']);
        self::assertSame(ProviderConnectionFixtures::OLLAMA_URL, $ok['endpoint']);
        self::assertSame(2, $ok['metadata']['custom']['modelCount']);

        $lister->unauthenticated();
        $rejected = $this->health($client, $claude['id']);
        self::assertSame('error', $rejected['status']);
        self::assertSame('UNAUTHENTICATED', $rejected['error']['code']);

        $lister->unreachable();
        $unreachable = $this->health($client, $claude['id']);
        self::assertSame('UNREACHABLE', $unreachable['error']['code']);
        self::assertStringNotContainsString(self::KEY, json_encode([$ok, $rejected, $unreachable], \JSON_THROW_ON_ERROR));
    }

    public function testUnreferencedConnectionCanBeDeleted(): void
    {
        $client = static::createClient();
        $created = $this->post($client, self::PREFIX.'local', ProviderConnectionFixtures::ollamaConfig())->toArray();

        $client->request('DELETE', $created['@id']);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, self::rowCount());
    }

    public function testConnectionReferencedByAnAgentVersionIsNotDeleted(): void
    {
        $client = static::createClient();
        $created = $this->post($client, self::PREFIX.'local', ProviderConnectionFixtures::ollamaConfig())->toArray();
        $versionId = self::insertAgentVersion('support', 3, $created['id']);

        $response = $client->request('DELETE', $created['@id'], ['headers' => ['Accept' => 'application/ld+json']]);

        self::assertResponseStatusCodeSame(409);
        $detail = $response->toArray(false)['detail'] ?? '';
        self::assertStringContainsString('Provider connection "'.self::PREFIX.'local" is still referenced by agent version 3 of "'.self::PREFIX.'support" (/api/agent_versions/'.$versionId.')', $detail);
        self::assertSame(1, self::rowCount());
    }

    /**
     * A version the model reference migration could not rewrite still
     * names the connection itself.
     */
    public function testConnectionNamedByAnUnmigratedAgentVersionIsNotDeleted(): void
    {
        $client = static::createClient();
        $created = $this->post($client, self::PREFIX.'local', ProviderConnectionFixtures::ollamaConfig())->toArray();
        $versionId = self::insertAgentVersion('legacy', 1, $created['id'], legacy: true);

        $response = $client->request('DELETE', $created['@id'], ['headers' => ['Accept' => 'application/ld+json']]);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('agent version 1 of "'.self::PREFIX.'legacy" (/api/agent_versions/'.$versionId.')', $response->toArray(false)['detail'] ?? '');
        self::assertSame(1, self::rowCount());
    }

    public function testEntityManagerRemovalIsRefusedToo(): void
    {
        static::bootKernel();
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = ProviderConnectionFixtures::ollama(self::PREFIX.'local');
        ProviderConnectionFixtures::persist($entityManager, $connection);
        self::insertAgentVersion('support', 1, $connection->getId()->toRfc4122());

        try {
            $entityManager->remove($connection);
            self::fail('Expected a ConflictHttpException.');
        } catch (ConflictHttpException $e) {
            self::assertStringContainsString('agent version 1 of "'.self::PREFIX.'support"', $e->getMessage());
        }

        $entityManager->clear();
        self::assertSame(1, self::rowCount());
    }

    public function testOtherConnectionTypesAreNotGuarded(): void
    {
        static::bootKernel();
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $mcp = new ApiConfiguration()->setName(self::PREFIX.'docs')->setConfigJson(['mcp' => true, 'type' => 'mcp', 'endpoint_type' => 'rest', 'url' => 'https://mcp.example.org/mcp', 'transport' => 'streamable-http']);
        ProviderConnectionFixtures::persist($entityManager, $mcp);
        // Even a version naming it as `connection` does not block an mcp connection.
        self::insertAgentVersion('support', 1, $mcp->getId()->toRfc4122(), legacy: true);

        $entityManager->remove($mcp);
        $entityManager->flush();

        self::assertSame(0, self::rowCount());
    }

    /**
     * An agent with one published version whose configuration references
     * a model catalogue entry of the connection (inserted with it), or,
     * with $legacy, names the connection and a model name as a version
     * the model reference migration could not rewrite; inserted directly
     * (the publish path is WP2a). The entry cascades with the connection.
     *
     * @return string the version id
     */
    private static function insertAgentVersion(string $slug, int $versionNumber, string $connectionId, bool $legacy = false): string
    {
        $database = self::dbal();
        $agentId = Uuid::v4()->toRfc4122();
        $versionId = Uuid::v4()->toRfc4122();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:sP');
        $configuration = ['connection' => $connectionId, 'model' => 'llama3.2'];
        if (!$legacy) {
            $modelId = Uuid::v4()->toRfc4122();
            $database->insert('neuron_provider_model', ['id' => $modelId, 'api_configuration_id' => $connectionId, 'name' => 'llama3.2:latest', 'endpoint_fingerprint' => str_repeat('0', 64), 'available' => 'true', 'created_at' => $now, 'updated_at' => $now]);
            $configuration = ['model' => $modelId];
        }

        $database->insert('neuron_agent', ['id' => $agentId, 'name' => 'Provider test', 'slug' => self::PREFIX.$slug, 'enabled' => 'true', 'candidate' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        $database->insert('neuron_agent_version', [
            'id' => $versionId,
            'agent_id' => $agentId,
            'version_number' => $versionNumber,
            'configuration' => json_encode($configuration, \JSON_THROW_ON_ERROR),
            'published_at' => $now,
            'published_by' => 'test',
        ]);

        return $versionId;
    }

    /**
     * @return array<string, mixed>
     */
    private static function storedConfig(string $id): array
    {
        return json_decode((string) self::dbal()->fetchOne('SELECT config_json FROM dmstr_api_configuration WHERE id = :id', ['id' => $id]), true, flags: \JSON_THROW_ON_ERROR);
    }

    private static function rowCount(): int
    {
        return (int) self::dbal()->fetchOne('SELECT COUNT(*) FROM dmstr_api_configuration WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
    }

    private static function cleanUp(): void
    {
        $database = self::dbal();
        $prefix = ['prefix' => self::PREFIX.'%'];
        $database->executeStatement('DELETE FROM neuron_agent_version WHERE agent_id IN (SELECT id FROM neuron_agent WHERE slug LIKE :prefix)', $prefix);
        $database->executeStatement('DELETE FROM neuron_agent WHERE slug LIKE :prefix', $prefix);
        $database->executeStatement('DELETE FROM dmstr_api_configuration WHERE name LIKE :prefix', $prefix);
        static::ensureKernelShutdown();
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

    /**
     * @return array<string, mixed>
     */
    private function health(Client $client, string $id): array
    {
        return $client->request('GET', self::COLLECTION.'/'.$id.'/health', ['headers' => ['Accept' => 'application/ld+json']])->toArray();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function post(Client $client, string $name, array $config): ResponseInterface
    {
        return $client->request('POST', self::COLLECTION, [
            'headers' => ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['name' => $name, 'configJson' => $config, 'active' => true],
        ]);
    }

    /**
     * @return list<string>
     */
    private static function violationPaths(ResponseInterface $response): array
    {
        return array_column($response->toArray(false)['violations'] ?? [], 'propertyPath');
    }
}
