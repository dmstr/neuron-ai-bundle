<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\ProviderConnection\Catalogue;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\ProviderCatalogueDatabaseTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Roles of the model catalogue: every authenticated user reads, scan and
 * sync need ROLE_ADMIN (no feature role; agent authors with
 * ROLE_AGENT_ADMIN read but cannot scan); anonymous requests get 401. Uses
 * the headers of the test kernel's header authenticator. Needs the migrated test
 * database (CI only).
 */
#[Group('database')]
final class ProviderModelAccessTest extends ApiTestCase
{
    use ProviderCatalogueDatabaseTrait;

    protected static ?bool $alwaysBootKernel = true;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function reads(): iterable
    {
        yield 'list' => ['GET', 'collection'];
        yield 'item' => ['GET', 'item'];
        yield 'options schema' => ['GET', 'options_schema'];
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

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function readers(): iterable
    {
        foreach (['ROLE_USER', 'ROLE_AGENT_ADMIN'] as $role) {
            foreach (self::reads() as $name => [$method, $target]) {
                yield $role.' '.$name => [$role, $method, $target];
            }
        }
    }

    #[DataProvider('readers')]
    public function testUsersAndAgentAuthorsCanRead(string $role, string $method, string $target): void
    {
        $this->send($method, $target, [TestHeaderAuthenticator::HEADER_ROLES => $role]);

        self::assertResponseStatusCodeSame(200);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function forbiddenWrites(): iterable
    {
        foreach (['ROLE_USER', 'ROLE_AGENT_ADMIN'] as $role) {
            foreach (self::writes() as $name => [$method, $target]) {
                yield $role.' '.$name => [$role, $method, $target];
            }
        }
    }

    #[DataProvider('forbiddenWrites')]
    public function testOnlyAdminsCanScanOrSync(string $role, string $method, string $target): void
    {
        $this->send($method, $target, [TestHeaderAuthenticator::HEADER_ROLES => $role]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, self::jobCount());
        self::assertSame([], $this->fake()->getDescribedConnections());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function adminWrites(): iterable
    {
        yield 'scan' => ['scan', 201];
        yield 'sync' => ['sync', 200];
    }

    #[DataProvider('adminWrites')]
    public function testAdminCanScanAndSync(string $target, int $status): void
    {
        $this->send('POST', $target, []);

        self::assertResponseStatusCodeSame($status);
        self::assertSame('scan' === $target ? 1 : 0, self::jobCount());
    }

    /**
     * @param array<string, string> $headers
     */
    private function send(string $method, string $target, array $headers): void
    {
        $client = $this->client();
        $connection = $this->connection('access');
        $model = $this->model($connection, 'llama3.1:8b', ['completion', 'tools']);
        $this->fake()->describe($connection->getName(), ['name' => 'llama3.1:8b', 'capabilities' => ['completion', 'tools']]);

        $uri = match ($target) {
            'collection' => self::COLLECTION,
            'item' => self::COLLECTION.'/'.$model->getId(),
            'scan' => self::COLLECTION.'/scan',
            'sync' => self::COLLECTION.'/'.$model->getId().'/sync',
            'options_schema' => self::COLLECTION.'/'.$model->getId().'/options_schema',
        };

        $options = ['headers' => ['Accept' => 'application/ld+json'] + $headers];
        if ('scan' === $target) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode(['apiConfiguration' => $connection->getName()], \JSON_THROW_ON_ERROR);
        }

        $client->request($method, $uri, $options);
    }
}
