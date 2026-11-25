<?php

// file generated with AI assistance: Claude Code - 2026-10-02 22:05:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Agent;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Access matrix of agents and versions:
 * anonymous requests get 401 on every route (the firewall trap of
 * section 1: without the firewall patterns the routes would be public),
 * `ROLE_USER` reads and gets 403 on every write, `ROLE_AGENT_ADMIN` alone
 * suffices for all writes, `ROLE_ADMIN` is listed next to it in the map, and PUT and DELETE on
 * an agent do not exist (405).
 *
 * Uses the headers of the test kernel's header authenticator and the fixtures of
 * {@see AgentFixtures}. Needs the migrated test database; runs in CI.
 */
#[Group('database')]
final class AgentAccessTest extends ApiTestCase
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
     * @return iterable<string, array{string, string}>
     */
    public static function reads(): iterable
    {
        yield 'list agents' => ['GET', 'agents'];
        yield 'agent' => ['GET', 'agent'];
        yield 'list versions' => ['GET', 'versions'];
        yield 'version' => ['GET', 'version'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function writes(): iterable
    {
        yield 'create' => ['POST', 'agents'];
        yield 'patch' => ['PATCH', 'agent'];
        yield 'publish' => ['POST', 'publish'];
        yield 'rollback' => ['POST', 'rollback'];
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
        $fixture = $this->fixture();

        $this->send($method, $target, $fixture, null, [TestHeaderAuthenticator::HEADER_AUTH => 'anonymous']);

        self::assertResponseStatusCodeSame(401);
        $this->assertUnchanged($fixture);
    }

    #[DataProvider('reads')]
    public function testUserCanRead(string $method, string $target): void
    {
        $fixture = $this->fixture();

        $this->send($method, $target, $fixture, AgentFixtures::USER);

        self::assertResponseStatusCodeSame(200);
    }

    #[DataProvider('writes')]
    public function testUserCannotWrite(string $method, string $target): void
    {
        $fixture = $this->fixture();

        $this->send($method, $target, $fixture, AgentFixtures::USER);

        self::assertResponseStatusCodeSame(403);
        $this->assertUnchanged($fixture);
    }

    /**
     * @return iterable<string, array{?string, string, string, int}>
     */
    public static function allowedWrites(): iterable
    {
        foreach (['feature role' => AgentFixtures::AGENT_ADMIN, 'admin' => AgentFixtures::ADMIN] as $who => $roles) {
            yield $who.' create' => [$roles, 'POST', 'agents', 201];
            yield $who.' patch' => [$roles, 'PATCH', 'agent', 200];
            yield $who.' publish' => [$roles, 'POST', 'publish', 201];
            yield $who.' rollback' => [$roles, 'POST', 'rollback', 200];
        }
    }

    #[DataProvider('allowedWrites')]
    public function testFeatureRoleAloneAndAdminCanWrite(?string $roles, string $method, string $target, int $status): void
    {
        $fixture = $this->fixture();

        $response = $this->send($method, $target, $fixture, $roles);

        self::assertResponseStatusCodeSame($status);
        if ('publish' === $target) {
            $expected = null === $roles ? TestHeaderAuthenticator::ADMIN_IDENTIFIER : TestHeaderAuthenticator::USER_IDENTIFIER;
            self::assertSame($expected, $response->toArray()['publishedBy']);
            self::assertSame(2, $response->toArray()['versionNumber']);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function absentAgentOperations(): iterable
    {
        yield 'PUT' => ['PUT'];
        yield 'DELETE' => ['DELETE'];
    }

    #[DataProvider('absentAgentOperations')]
    public function testPutAndDeleteOnAnAgentAreNotAllowed(string $method): void
    {
        $fixture = $this->fixture();

        AgentFixtures::request($method, AgentFixtures::AGENTS.'/'.$fixture['agent'], AgentFixtures::ADMIN, 'PUT' === $method ? ['name' => 'Replaced', 'slug' => AgentFixtures::PREFIX.'replaced'] : null);

        self::assertResponseStatusCodeSame(405);
        $this->assertUnchanged($fixture);
    }

    /**
     * An agent with one published version, created as the default admin.
     *
     * @return array{agent: string, version: string, row: array<string, mixed>}
     */
    private function fixture(): array
    {
        $agent = AgentFixtures::createAgent('access');
        $version = AgentFixtures::publish($agent);
        self::assertSame(1, $version['versionNumber'] ?? null);

        return [
            'agent' => $agent,
            'version' => (string) $version['id'],
            'row' => self::agentRow($agent),
        ];
    }

    /**
     * @param array{agent: string, version: string, row: array<string, mixed>} $fixture
     */
    private function assertUnchanged(array $fixture): void
    {
        self::assertSame(1, AgentFixtures::agentCount());
        self::assertSame(1, AgentFixtures::versionCount());
        self::assertSame($fixture['row'], self::agentRow($fixture['agent']));
    }

    /**
     * @return array<string, mixed>
     */
    private static function agentRow(string $id): array
    {
        $row = AgentFixtures::dbal()->fetchAssociative('SELECT * FROM neuron_agent WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @param array{agent: string, version: string, row: array<string, mixed>} $fixture
     * @param array<string, string>                                            $headers
     */
    private function send(string $method, string $target, array $fixture, ?string $roles, array $headers = []): ResponseInterface
    {
        $agent = AgentFixtures::AGENTS.'/'.$fixture['agent'];

        [$uri, $json] = match ($target) {
            'agents' => [AgentFixtures::AGENTS, 'POST' === $method ? ['name' => 'Created', 'slug' => AgentFixtures::PREFIX.'created', 'candidate' => AgentFixtures::validCandidate()] : null],
            'agent' => [$agent, 'PATCH' === $method ? ['name' => 'Renamed'] : null],
            'publish' => [$agent.'/publish', null],
            'rollback' => [$agent.'/rollback', ['version' => $fixture['version']]],
            'versions' => [AgentFixtures::VERSIONS, null],
            'version' => [AgentFixtures::VERSIONS.'/'.$fixture['version'], null],
        };

        $response = AgentFixtures::request($method, $uri, $roles, $json, $headers);
        $response->getStatusCode();

        return $response;
    }
}
