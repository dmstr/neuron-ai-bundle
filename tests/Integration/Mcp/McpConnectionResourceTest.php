<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:22:22 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Mcp;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Functional test of `mcp` connections through the generic connection
 * resource of dmstr/api-configuration-bundle under
 * /api/admin/api_configurations (the test firewall authenticates as admin),
 * including the health check against the fake MCP transport (over REST and
 * through the console twin). Needs the
 * migrated test database; rows are created with the name prefix
 * `test-mcp-` and removed before and after each test.
 */
#[Group('database')]
final class McpConnectionResourceTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const string COLLECTION = '/api/admin/api_configurations';
    private const string PREFIX = 'test-mcp-';
    private const string URL = 'https://mcp.example.org/mcp';

    private const array CONFIG = [
        'mcp' => true,
        'type' => 'mcp',
        'endpoint_type' => 'rest',
        'url' => self::URL,
        'transport' => 'streamable-http',
    ];

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

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function authVariants(): iterable
    {
        yield 'none' => [self::CONFIG + ['auth_type' => 'none']];
        yield 'bearer' => [self::CONFIG + ['auth_type' => 'bearer', 'token' => 'secret']];
        yield 'header' => [self::CONFIG + ['auth_type' => 'header', 'header_name' => 'X-Api-Key', 'token' => 'secret', 'timeout' => 10]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('authVariants')]
    public function testAdminCreatesMcpConnection(array $config): void
    {
        $response = $this->post(static::createClient(), self::PREFIX.'docs', $config);

        self::assertResponseStatusCodeSame(201);
        $data = $response->toArray();
        self::assertSame(self::PREFIX.'docs', $data['name']);
        self::assertSame('mcp', $data['type']);
        self::assertSame('rest', $data['endpointType']);
        self::assertTrue($data['active']);
        self::assertSame($config['auth_type'], $data['configJson']['auth_type']);
        self::assertSame(1, self::rowCount());
    }

    public function testUserWithoutAdminRoleCannotCreate(): void
    {
        $this->post(static::createClient(), self::PREFIX.'docs', self::CONFIG, [TestHeaderAuthenticator::HEADER_ROLES => 'ROLE_USER']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, self::rowCount());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidConfigs(): iterable
    {
        $withoutUrl = self::CONFIG;
        unset($withoutUrl['url']);

        yield 'missing url' => [$withoutUrl];
        yield 'wrong transport' => [['transport' => 'sse'] + self::CONFIG];
        yield 'bearer without token' => [self::CONFIG + ['auth_type' => 'bearer']];
        yield 'header without header_name' => [self::CONFIG + ['auth_type' => 'header', 'token' => 'secret']];
        yield 'unknown property' => [self::CONFIG + ['command' => 'npx some-server']];
        yield 'non-http url' => [['url' => 'file:///etc/passwd'] + self::CONFIG];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigs')]
    public function testInvalidConfigurationIsRejected(array $config): void
    {
        $response = $this->post(static::createClient(), self::PREFIX.'docs', $config);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('configJson', self::violationPaths($response));
        self::assertSame(0, self::rowCount());
    }

    public function testDuplicateMcpNameIsRejectedOnName(): void
    {
        $client = static::createClient();
        $this->post($client, self::PREFIX.'docs', self::CONFIG);
        self::assertResponseStatusCodeSame(201);

        $response = $this->post($client, self::PREFIX.'docs', ['url' => 'https://other.example.org/mcp'] + self::CONFIG);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('name', self::violationPaths($response));
        self::assertSame(1, self::rowCount());
    }

    public function testRenamingKeepsUniqueness(): void
    {
        $client = static::createClient();
        $this->post($client, self::PREFIX.'docs', self::CONFIG);
        $other = $this->post($client, self::PREFIX.'wiki', self::CONFIG)->toArray();

        // Saving the connection unchanged is no conflict with itself ...
        $client->request('PATCH', $other['@id'], [
            'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['active' => false],
        ]);
        self::assertResponseStatusCodeSame(200);

        // ... taking the name of another mcp connection is.
        $response = $client->request('PATCH', $other['@id'], [
            'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['name' => self::PREFIX.'docs'],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertContains('name', self::violationPaths($response));
    }

    public function testTokenIsStoredEncryptedAndReturnedMasked(): void
    {
        $client = static::createClient();
        $created = $this->post($client, self::PREFIX.'docs', self::CONFIG + ['auth_type' => 'bearer', 'token' => 'clear-secret']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('********', $created->toArray()['configJson']['token']);
        self::assertStringNotContainsString('clear-secret', $created->getContent());
        self::assertStringStartsWith('enc:v1:', self::storedConfig($created->toArray()['id'])['token']);

        $read = $client->request('GET', $created->toArray()['@id'], ['headers' => ['Accept' => 'application/ld+json']]);
        self::assertSame('********', $read->toArray()['configJson']['token']);
        $list = $client->request('GET', self::COLLECTION.'?name='.self::PREFIX.'docs', ['headers' => ['Accept' => 'application/ld+json']]);
        self::assertStringNotContainsString('clear-secret', $list->getContent());
    }

    public function testUpdateKeepsAnOmittedOrMaskedTokenAndNullRemovesIt(): void
    {
        $client = static::createClient();
        $created = $this->post($client, self::PREFIX.'docs', self::CONFIG + ['auth_type' => 'bearer', 'token' => 'clear-secret'])->toArray();
        $stored = self::storedConfig($created['id'])['token'];

        // Omitted: the stored (encrypted) value stays.
        $this->patchConfig($client, $created['@id'], self::CONFIG + ['auth_type' => 'bearer', 'timeout' => 10]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($stored, self::storedConfig($created['id'])['token']);

        // The mask: the stored value stays.
        $this->patchConfig($client, $created['@id'], self::CONFIG + ['auth_type' => 'bearer', 'token' => '********']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($stored, self::storedConfig($created['id'])['token']);

        // null: removed.
        $this->patchConfig($client, $created['@id'], self::CONFIG + ['auth_type' => 'none', 'token' => null]);
        self::assertResponseStatusCodeSame(200);
        self::assertArrayNotHasKey('token', self::storedConfig($created['id']));
    }

    public function testReadsRequireTheAdminRole(): void
    {
        $client = static::createClient();
        $created = $this->post($client, self::PREFIX.'docs', self::CONFIG)->toArray();
        $asUser = ['headers' => ['Accept' => 'application/ld+json', TestHeaderAuthenticator::HEADER_ROLES => 'ROLE_USER']];

        $client->request('GET', self::COLLECTION, $asUser);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', $created['@id'], $asUser);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', $created['@id'].'/health', $asUser);
        self::assertResponseStatusCodeSame(403);
    }

    public function testHealthReportsToolsFromTheFakeTransport(): void
    {
        $client = static::createClient();
        // One kernel for all requests, so the scripted fake stays in place.
        $client->disableReboot();
        $id = $this->post($client, self::PREFIX.'docs', self::CONFIG + ['auth_type' => 'bearer', 'token' => 'secret'])->toArray()['id'];

        $fake = $this->fake();
        $fake->serveServerInfo('docs-server', '2.1.0')->serveTools([
            ['name' => 'search', 'description' => 'Search', 'inputSchema' => ['type' => 'object']],
        ]);

        $data = $this->health($client, $id);

        self::assertResponseIsSuccessful();
        self::assertSame('ok', $data['status']);
        self::assertTrue($data['authenticated']);
        self::assertSame(self::URL, $data['endpoint']);
        self::assertSame('MCP server reachable, 1 tool(s) listed.', $data['message']);
        self::assertSame(1, $data['metadata']['custom']['toolCount']);
        self::assertSame('docs-server', $data['metadata']['custom']['serverName']);
        self::assertSame(['initialize', 'notifications/initialized', 'tools/list'], $fake->getMethods(self::URL));
        self::assertSame('secret', $fake->getCreatedConfigs()[0]['token']);

        $output = $this->healthCommand($id, 0);
        self::assertMatchesRegularExpression('/ToolCount\s+1\b/', $output);
        self::assertMatchesRegularExpression('/ServerName\s+docs-server\b/', $output);
    }

    public function testHealthReportsRejectedCredentials(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $id = $this->post($client, self::PREFIX.'docs', self::CONFIG + ['auth_type' => 'bearer', 'token' => 'wrong'])->toArray()['id'];
        $this->fake()->rejectCredentials();

        $data = $this->health($client, $id);

        self::assertResponseIsSuccessful();
        self::assertSame('error', $data['status']);
        self::assertFalse($data['authenticated']);
        self::assertStringStartsWith('MCP server rejected the credentials: ', $data['message']);
        self::assertSame('UNAUTHENTICATED', $data['error']['code']);
        self::assertMatchesRegularExpression('/Code\s+UNAUTHENTICATED\b/', $this->healthCommand($id, 1));
    }

    public function testHealthReportsUnreachableServer(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $id = $this->post($client, self::PREFIX.'docs', self::CONFIG)->toArray()['id'];
        $this->fake()->failWith(new \RuntimeException('Connection refused'));

        $data = $this->health($client, $id);

        self::assertSame('error', $data['status']);
        self::assertStringStartsWith('MCP server unreachable: ', $data['message']);
        self::assertStringContainsString('Connection refused', $data['message']);
        self::assertSame('UNREACHABLE', $data['error']['code']);
        self::assertMatchesRegularExpression('/Code\s+UNREACHABLE\b/', $this->healthCommand($id, 1));
    }

    public function testHealthReportsMalformedResponse(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $id = $this->post($client, self::PREFIX.'docs', self::CONFIG)->toArray()['id'];
        // One-shot script: one for the REST route, one for the console twin.
        $this->fake()->queueResult('tools/list', ['items' => []])->queueResult('tools/list', ['items' => []]);

        $data = $this->health($client, $id);

        self::assertSame('error', $data['status']);
        self::assertStringStartsWith('MCP protocol error: ', $data['message']);
        self::assertSame('PROTOCOL', $data['error']['code']);
        self::assertMatchesRegularExpression('/Code\s+PROTOCOL\b/', $this->healthCommand($id, 1));
    }

    /**
     * The configJson as stored in the database.
     *
     * @return array<string, mixed>
     */
    public static function storedConfig(string $id): array
    {
        return json_decode((string) self::connection()->fetchOne('SELECT config_json FROM dmstr_api_configuration WHERE id = :id', ['id' => $id]), true, flags: \JSON_THROW_ON_ERROR);
    }

    public static function rowCount(): int
    {
        return (int) self::connection()->fetchOne('SELECT COUNT(*) FROM dmstr_api_configuration WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
    }

    public static function cleanUp(): void
    {
        self::connection()->executeStatement('DELETE FROM dmstr_api_configuration WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
        static::ensureKernelShutdown();
    }

    private static function connection(): Connection
    {
        if (null === static::$kernel) {
            static::bootKernel();
        }

        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');

        return $connection;
    }

    private function fake(): FakeMcpTransportFactory
    {
        $fake = static::getContainer()->get(FakeMcpTransportFactory::class);
        self::assertInstanceOf(FakeMcpTransportFactory::class, $fake);

        return $fake;
    }

    /**
     * The REST health route. Since dmstr/api-configuration-bundle
     * 0.5.0-beta1 the nested `metadata` and `error` arrays keep their keys
     * in jsonld (see McpHealthSerializationTest).
     *
     * @return array<string, mixed>
     */
    private function health(Client $client, string $id): array
    {
        return $client->request('GET', self::COLLECTION.'/'.$id.'/health', ['headers' => ['Accept' => 'application/ld+json']])->toArray();
    }

    /**
     * The console twin `app:api-configuration:health` renders the complete
     * normalized result, including `metadata.custom` and `error.code`; it
     * runs in the client's kernel, so it sees the same scripted fake.
     */
    private function healthCommand(string $id, int $expectedExitCode): string
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:api-configuration:health'));

        self::assertSame($expectedExitCode, $tester->execute(['id' => $id]));

        return $tester->getDisplay();
    }

    /**
     * @param array<string, mixed>  $config
     * @param array<string, string> $headers
     */
    private function post(Client $client, string $name, array $config, array $headers = []): ResponseInterface
    {
        return $client->request('POST', self::COLLECTION, [
            'headers' => ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'] + $headers,
            'json' => ['name' => $name, 'configJson' => $config, 'active' => true],
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function patchConfig(Client $client, string $iri, array $config): ResponseInterface
    {
        return $client->request('PATCH', $iri, [
            'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['configJson' => $config],
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
