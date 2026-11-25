<?php

// file generated with AI assistance: Claude Code - 2026-09-30 22:07:50 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Mcp\Seed;

use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `neuron:mcp:connection:seed` against the database: create, no-op, update
 * in place, authentication variants and rejected input. Needs the migrated
 * test database (CI only). Rows carry the name prefix `test-mcp-seed-` and
 * are removed before and after each test; the fake MCP transport proves
 * that the seed never contacts a server.
 */
#[Group('database')]
final class McpConnectionSeedFunctionalTest extends KernelTestCase
{
    private const string PREFIX = 'test-mcp-seed-';
    private const string URL = 'https://seed.example.org/mcp';

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        self::bootKernel();
        self::cleanUp();
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        parent::tearDown();
    }

    public function testFirstRunCreatesAnActiveConnection(): void
    {
        $tester = $this->command();

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => self::PREFIX.'docs', 'url' => self::URL]), $tester->getDisplay());
        self::assertStringContainsString('created', $tester->getDisplay());

        $rows = self::rows();
        self::assertCount(1, $rows);
        self::assertSame('mcp', $rows[0]['type']);
        self::assertSame('rest', $rows[0]['endpoint_type']);
        self::assertTrue((bool) $rows[0]['active']);
        self::assertEquals([
            'mcp' => true,
            'type' => 'mcp',
            'endpoint_type' => 'rest',
            'url' => self::URL,
            'transport' => 'streamable-http',
            'auth_type' => 'none',
        ], self::config($rows[0]));
        self::assertSame(0, $this->fake()->getRequestCount());
        self::assertSame([], $this->fake()->getCreatedConfigs());
    }

    public function testSecondRunIsANoOp(): void
    {
        $tester = $this->command();
        $input = ['name' => self::PREFIX.'docs', 'url' => self::URL, '--auth' => 'bearer', '--token' => 'secret'];
        self::assertSame(Command::SUCCESS, $tester->execute($input), $tester->getDisplay());
        $before = self::rows();

        // A fresh kernel reads the row back from the database, as a second
        // setup run would.
        self::ensureKernelShutdown();
        self::bootKernel();
        $tester = $this->command();

        self::assertSame(Command::SUCCESS, $tester->execute($input), $tester->getDisplay());
        self::assertStringContainsString('unchanged', $tester->getDisplay());
        $after = self::rows();
        self::assertCount(1, $after);
        self::assertSame($before[0]['id'], $after[0]['id']);
        self::assertSame($before[0]['updated_at'], $after[0]['updated_at']);
    }

    public function testChangedUrlUpdatesInPlace(): void
    {
        $tester = $this->command();
        self::assertSame(Command::SUCCESS, $tester->execute(['name' => self::PREFIX.'docs', 'url' => self::URL]), $tester->getDisplay());
        $before = self::rows();

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => self::PREFIX.'docs', 'url' => 'https://moved.example.org/mcp']), $tester->getDisplay());
        self::assertStringContainsString('updated', $tester->getDisplay());

        $after = self::rows();
        self::assertCount(1, $after);
        self::assertSame($before[0]['id'], $after[0]['id']);
        self::assertSame('https://moved.example.org/mcp', self::config($after[0])['url']);
    }

    public function testBearerAndHeaderVariantsStoreTheTokenFields(): void
    {
        $tester = $this->command();

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => self::PREFIX.'bearer', 'url' => self::URL, '--auth' => 'bearer', '--token' => 'secret']), $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute(['name' => self::PREFIX.'header', 'url' => self::URL, '--auth' => 'header', '--header-name' => 'X-Api-Key', '--token' => 'key', '--timeout' => '10']), $tester->getDisplay());

        $rows = array_column(self::rows(), null, 'name');
        $bearer = self::config($rows[self::PREFIX.'bearer']);
        self::assertSame('bearer', $bearer['auth_type']);
        self::assertSame('secret', $bearer['token']);
        self::assertArrayNotHasKey('header_name', $bearer);

        $header = self::config($rows[self::PREFIX.'header']);
        self::assertSame('header', $header['auth_type']);
        self::assertSame('X-Api-Key', $header['header_name']);
        self::assertSame('key', $header['token']);
        self::assertSame(10, $header['timeout']);
    }

    public function testClientCredentialsStoreTheSecretEncryptedAndAreIdempotent(): void
    {
        $tester = $this->command();
        $input = [
            'name' => self::PREFIX.'flowable',
            'url' => 'https://tasks.example.org/mcp',
            '--auth' => 'client_credentials',
            '--token-url' => 'https://auth.example.org/realms/example/protocol/openid-connect/token',
            '--client-id' => 'mcp-agent',
            '--client-secret' => 'mcp-agent-test-secret',
            '--restricted' => true,
        ];

        self::assertSame(Command::SUCCESS, $tester->execute($input), $tester->getDisplay());
        $rows = self::rows();
        self::assertCount(1, $rows);
        $stored = self::storedConfig($rows[0]);
        self::assertStringStartsWith('enc:v1:', $stored['client_secret']);
        self::assertSame('mcp-agent', $stored['client_id']);
        self::assertTrue($stored['restricted']);
        self::assertSame('mcp-agent-test-secret', self::config($rows[0])['client_secret']);

        self::assertSame(Command::SUCCESS, $tester->execute($input), $tester->getDisplay());
        self::assertStringContainsString('unchanged', $tester->getDisplay());
        self::assertSame($rows[0]['updated_at'], self::rows()[0]['updated_at']);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidInput(): iterable
    {
        yield 'bearer without token' => [['--auth' => 'bearer']];
        yield 'header without header name' => [['--auth' => 'header', '--token' => 'key']];
        yield 'non-http url' => [['url' => 'ftp://seed.example.org/mcp']];
        yield 'unknown auth' => [['--auth' => 'basic']];
        yield 'other transport' => [['--transport' => 'stdio']];
        yield 'token without auth' => [['--token' => 'secret']];
        yield 'client credentials without secret' => [['--auth' => 'client_credentials', '--token-url' => 'https://auth.example.org/token', '--client-id' => 'a']];
    }

    /**
     * @param array<string, string> $input
     */
    #[DataProvider('invalidInput')]
    public function testInvalidInputCreatesNothing(array $input): void
    {
        $tester = $this->command();

        self::assertSame(Command::FAILURE, $tester->execute($input + ['name' => self::PREFIX.'docs', 'url' => self::URL]));
        self::assertSame([], self::rows());
    }

    public function testInvalidUpdateKeepsTheStoredConnection(): void
    {
        $tester = $this->command();
        self::assertSame(Command::SUCCESS, $tester->execute(['name' => self::PREFIX.'docs', 'url' => self::URL]), $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['name' => self::PREFIX.'docs', 'url' => 'ftp://seed.example.org/mcp']));

        $rows = self::rows();
        self::assertCount(1, $rows);
        self::assertSame(self::URL, self::config($rows[0])['url']);
    }

    private function command(): CommandTester
    {
        $application = new Application(static::$kernel);

        return new CommandTester($application->find('neuron:mcp:connection:seed'));
    }

    private function fake(): FakeMcpTransportFactory
    {
        $fake = static::getContainer()->get(FakeMcpTransportFactory::class);
        self::assertInstanceOf(FakeMcpTransportFactory::class, $fake);

        return $fake;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(): array
    {
        return self::dbal()->fetchAllAssociative(
            'SELECT * FROM dmstr_api_configuration WHERE name LIKE :prefix ORDER BY name',
            ['prefix' => self::PREFIX.'%'],
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function config(array $row): array
    {
        $stored = self::storedConfig($row);
        if (isset($stored['token'])) {
            // dmstr/api-configuration-bundle 0.5 encrypts the secret at rest.
            self::assertStringStartsWith('enc:v1:', $stored['token']);
        }

        return self::getContainer()->get(ConfigSecrets::class)->decrypt($stored);
    }

    /**
     * The configJson as stored, secrets encrypted.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function storedConfig(array $row): array
    {
        return json_decode((string) $row['config_json'], true, 512, \JSON_THROW_ON_ERROR);
    }

    private static function cleanUp(): void
    {
        self::dbal()->executeStatement('DELETE FROM dmstr_api_configuration WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
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
