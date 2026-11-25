<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:41:04 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Mcp\Catalogue;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\CatalogueDatabaseTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Roles of the tool catalogue: every authenticated user reads, scan and
 * sync need ROLE_MCP_ADMIN (granted to ROLE_ADMIN through the role
 * hierarchy); anonymous requests get 401. Uses the headers of the test kernel's header
 * authenticator. Needs the migrated test database (CI only).
 */
#[Group('database')]
final class McpToolAccessTest extends ApiTestCase
{
    use CatalogueDatabaseTrait;

    protected static ?bool $alwaysBootKernel = true;

    private const string URL = 'https://access.cat.example.org/mcp';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function reads(): iterable
    {
        yield 'list' => ['GET', 'collection'];
        yield 'item' => ['GET', 'item'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function writes(): iterable
    {
        yield 'scan' => ['POST', 'scan'];
        yield 'sync' => ['POST', 'sync'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function allOperations(): iterable
    {
        yield from self::reads();
        yield from self::writes();
    }

    #[DataProvider('allOperations')]
    public function testAnonymousIsUnauthorized(string $method, string $target): void
    {
        $this->send($method, $target, [TestHeaderAuthenticator::HEADER_AUTH => 'anonymous']);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(0, self::jobCount());
    }

    #[DataProvider('reads')]
    public function testUserCanRead(string $method, string $target): void
    {
        $this->send($method, $target, [TestHeaderAuthenticator::HEADER_ROLES => 'ROLE_USER']);

        self::assertResponseStatusCodeSame(200);
    }

    #[DataProvider('writes')]
    public function testUserWithoutFeatureRoleCannotScanOrSync(string $method, string $target): void
    {
        $this->send($method, $target, [TestHeaderAuthenticator::HEADER_ROLES => 'ROLE_USER']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, self::jobCount());
        self::assertSame(0, $this->fake()->getTransportCount());
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function allowedWrites(): iterable
    {
        yield 'feature role scan' => ['ROLE_MCP_ADMIN', 'scan', 201];
        yield 'feature role sync' => ['ROLE_MCP_ADMIN', 'sync', 200];
        yield 'admin scan' => ['', 'scan', 201];
        yield 'admin sync' => ['', 'sync', 200];
    }

    #[DataProvider('allowedWrites')]
    public function testFeatureRoleAndAdminCanScanAndSync(string $roles, string $target, int $status): void
    {
        $this->send('POST', $target, '' === $roles ? [] : [TestHeaderAuthenticator::HEADER_ROLES => $roles]);

        self::assertResponseStatusCodeSame($status);
        self::assertSame('scan' === $target ? 1 : 0, self::jobCount());
    }

    /**
     * @param array<string, string> $headers
     */
    private function send(string $method, string $target, array $headers): void
    {
        $client = $this->client();
        $connection = $this->connection('access', self::URL);
        $tool = $this->tool($connection, 'search', 'Search', ['type' => 'object']);
        $this->fake()->serveTools([['name' => 'search', 'description' => 'Search', 'inputSchema' => ['type' => 'object']]], self::URL);

        $uri = match ($target) {
            'collection' => self::COLLECTION,
            'item' => self::COLLECTION.'/'.$tool->getId(),
            'scan' => self::COLLECTION.'/scan',
            'sync' => self::COLLECTION.'/'.$tool->getId().'/sync',
        };

        $options = ['headers' => ['Accept' => 'application/ld+json'] + $headers];
        if ('scan' === $target) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode(['apiConfiguration' => $connection->getName()], \JSON_THROW_ON_ERROR);
        }

        $client->request($method, $uri, $options);
    }
}
