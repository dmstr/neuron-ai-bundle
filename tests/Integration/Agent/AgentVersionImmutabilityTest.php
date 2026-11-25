<?php

// file generated with AI assistance: Claude Code - 2026-10-02 22:05:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Agent;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * A published version cannot be changed: there is no write operation on `/api/agent_versions`
 * (POST, PUT, PATCH and DELETE answer 404 or 405 and the row stays as it
 * was, for the admin as well), the OpenAPI document shows only `get` on
 * both version paths, and the class has no method starting with `set`.
 * Follows `McpToolResourceTest::testWriteOperationsAreAbsent`.
 *
 * Uses the fixtures of {@see AgentFixtures}. Needs the migrated test
 * database; runs in CI.
 */
#[Group('database')]
final class AgentVersionImmutabilityTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        AgentFixtures::cleanUp();
    }

    protected function tearDown(): void
    {
        AgentFixtures::cleanUp();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function absentWriteOperations(): iterable
    {
        yield 'POST collection' => ['POST', false];
        yield 'PUT item' => ['PUT', true];
        yield 'PATCH item' => ['PATCH', true];
        yield 'DELETE item' => ['DELETE', true];
    }

    #[DataProvider('absentWriteOperations')]
    public function testWriteOperationsAreAbsent(string $method, bool $onItem): void
    {
        $agent = AgentFixtures::createAgent('immutable');
        $version = AgentFixtures::publish($agent);
        $row = AgentFixtures::versionRow((string) $version['id']);

        $uri = $onItem ? AgentFixtures::VERSIONS.'/'.$version['id'] : AgentFixtures::VERSIONS;
        $json = 'DELETE' === $method ? null : [
            'agent' => AgentFixtures::AGENTS.'/'.$agent,
            'versionNumber' => 7,
            'configuration' => AgentFixtures::validCandidate(['maxToolCalls' => 9]),
            'publishedBy' => 'injected',
        ];
        $response = AgentFixtures::request($method, $uri, AgentFixtures::ADMIN, $json);

        self::assertContains($response->getStatusCode(), [404, 405]);
        self::assertSame($row, AgentFixtures::versionRow((string) $version['id']));
        self::assertSame(1, AgentFixtures::versionCount());
    }

    public function testOpenApiDocumentsOnlyReadsOfVersions(): void
    {
        $response = static::createClient()->request('GET', '/api/docs.jsonopenapi', ['headers' => ['Accept' => 'application/vnd.openapi+json']]);
        self::assertResponseIsSuccessful();
        $paths = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR)['paths'];

        self::assertSame(['get'], AgentDiscoveryTest::methods($paths[AgentFixtures::VERSIONS] ?? []));
        self::assertSame(['get'], AgentDiscoveryTest::methods($paths[AgentFixtures::VERSIONS.'/{id}'] ?? []));
    }

    public function testVersionHasNoSetter(): void
    {
        $setters = array_values(array_filter(
            array_map(static fn (\ReflectionMethod $method): string => $method->getName(), new \ReflectionClass(AgentVersion::class)->getMethods()),
            static fn (string $name): bool => str_starts_with(strtolower($name), 'set'),
        ));

        self::assertSame([], $setters);
    }
}
