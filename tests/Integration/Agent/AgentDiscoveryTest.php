<?php

// file generated with AI assistance: Claude Code - 2026-10-02 22:05:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Agent;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\Model\ModelCapability;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Admin-panel discovery of agents and versions: the Hydra entrypoint lists `agent` and
 * `agentVersion`; the OpenAPI document shows GET/POST on the agent list,
 * GET/PATCH on the item (no PUT, no DELETE; the PATCH description says the
 * candidate is replaced as a whole), the bodyless `agent_publish` action, the
 * `agent_rollback` action with the request body of
 * `Entity/Agent/rollback.input.json`, the filters of both lists, and only
 * GET on the version paths. Follows the conversation DiscoveryTest.
 * The `candidate` of the agent (read and write) and the
 * `configuration` of a version carry the typed properties of
 * `Entity/Agent/candidate.json` instead of an untyped string map.
 *
 * Boots the kernel; the assertions need no database.
 */
#[Group('database')]
final class AgentDiscoveryTest extends ApiTestCase
{
    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
    }

    protected static ?bool $alwaysBootKernel = true;

    public function testEntrypointListsAgentsAndVersions(): void
    {
        $data = static::createClient()->request('GET', '/api', ['headers' => ['Accept' => 'application/ld+json']])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame(AgentFixtures::AGENTS, $data['agent'] ?? null);
        self::assertSame(AgentFixtures::VERSIONS, $data['agentVersion'] ?? null);
    }

    public function testAgentOperationsAndFilters(): void
    {
        $paths = $this->openApi()['paths'];

        self::assertSame(['get', 'post'], self::methods($paths[AgentFixtures::AGENTS] ?? []));
        self::assertSame(['get', 'patch'], self::methods($paths[AgentFixtures::AGENTS.'/{id}'] ?? []));
        self::assertStringContainsString('replaced as a whole', $paths[AgentFixtures::AGENTS.'/{id}']['patch']['description']);

        $parameters = array_column($paths[AgentFixtures::AGENTS]['get']['parameters'], 'name');
        self::assertContains('slug', $parameters);
        self::assertContains('enabled', $parameters);
    }

    public function testPublishIsABodylessPostAction(): void
    {
        $paths = $this->openApi()['paths'];

        self::assertSame(['post'], self::methods($paths[AgentFixtures::AGENTS.'/{id}/publish'] ?? []));
        $publish = $paths[AgentFixtures::AGENTS.'/{id}/publish']['post'];
        self::assertSame('agent_publish', $publish['operationId']);
        self::assertArrayNotHasKey('requestBody', $publish);
        self::assertArrayHasKey('201', $publish['responses']);
    }

    public function testRollbackRequestBodyComesFromTheInputSchema(): void
    {
        $paths = $this->openApi()['paths'];

        self::assertSame(['post'], self::methods($paths[AgentFixtures::AGENTS.'/{id}/rollback'] ?? []));
        $rollback = $paths[AgentFixtures::AGENTS.'/{id}/rollback']['post'];
        self::assertSame('agent_rollback', $rollback['operationId']);
        self::assertArrayHasKey('200', $rollback['responses']);

        $schema = $rollback['requestBody']['content']['application/json']['schema'] ?? null;
        self::assertIsArray($schema);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['version'], $schema['required']);
        self::assertSame(['version'], array_keys($schema['properties']));
        self::assertSame('string', $schema['properties']['version']['type']);
    }

    public function testVersionsAreReadOnlyWithFilters(): void
    {
        $paths = $this->openApi()['paths'];

        self::assertSame(['get'], self::methods($paths[AgentFixtures::VERSIONS] ?? []));
        self::assertSame(['get'], self::methods($paths[AgentFixtures::VERSIONS.'/{id}'] ?? []));

        $parameters = array_column($paths[AgentFixtures::VERSIONS]['get']['parameters'], 'name');
        self::assertContains('agent', $parameters);
        self::assertContains('order[versionNumber]', $parameters);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function configurationSchemas(): iterable
    {
        yield 'agent write' => ['Agent-agent.write', 'candidate'];
        yield 'agent merge patch' => ['Agent-agent.write.jsonMergePatch', 'candidate'];
        yield 'agent read' => ['Agent.jsonld-agent.read', 'candidate'];
        yield 'version read' => ['AgentVersion.jsonld-agent_version.read', 'configuration'];
    }

    #[DataProvider('configurationSchemas')]
    public function testConfigurationSchemaHasTheCandidateProperties(string $component, string $field): void
    {
        $schema = $this->openApi()['components']['schemas'][$component] ?? null;
        self::assertIsArray($schema, $component);
        $property = self::property($schema, $field);

        self::assertSame('object', $property['type']);
        self::assertFalse($property['additionalProperties']);
        self::assertSame(array_keys(Agent::candidateSchema()['properties']), array_keys($property['properties']));
        self::assertSame('object', $property['properties']['modelOptions']['type']);
        self::assertSame('number', $property['properties']['modelOptions']['properties']['temperature']['type']);
        self::assertSame('boolean', $property['properties']['hybrid']['type']);
        self::assertSame('Instructions', $property['properties']['instructions']['title']);
        self::assertSame(['type' => 'string', 'x-format' => 'textarea'], $property['properties']['instructions']['additionalProperties']);
        self::assertArrayNotHasKey('connection', $property['properties']);
        self::assertSame(ProviderModel::modelCollectionUrl(ModelCapability::COMPLETION), $property['properties']['model']['x-collection'] ?? null, 'the model picker');
        self::assertSame('iri-reference', $property['properties']['model']['format'] ?? null, 'the type-ahead resolves only on format iri-reference');
    }

    public function testCandidateDescriptionSaysPublishMakesItEffective(): void
    {
        $property = self::property($this->openApi()['components']['schemas']['Agent-agent.write'], 'candidate');

        self::assertStringContainsString('publish', strtolower($property['description']));
        self::assertStringContainsString(AgentVersion::CONFIGURATION_DESCRIPTION, json_encode(
            self::property($this->openApi()['components']['schemas']['AgentVersion.jsonld-agent_version.read'], 'configuration'),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        ));
    }

    public function testHydraDocumentationDescribesBothClasses(): void
    {
        $response = static::createClient()->request('GET', '/api/docs.jsonld', ['headers' => ['Accept' => 'application/ld+json']]);
        self::assertResponseIsSuccessful();

        $titles = array_map(static fn (array $class): string => $class['hydra:title'] ?? '', $response->toArray()['hydra:supportedClass'] ?? []);
        self::assertContains('Agent', $titles);
        self::assertContains('AgentVersion', $titles);
    }

    /**
     * @return array<string, mixed>
     */
    private function openApi(): array
    {
        $response = static::createClient()->request('GET', '/api/docs.jsonopenapi', ['headers' => ['Accept' => 'application/vnd.openapi+json']]);
        self::assertResponseIsSuccessful();

        return json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * A property of a component schema, also inside the `allOf` of the
     * JSON-LD variants.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function property(array $schema, string $field): array
    {
        foreach ([$schema, ...($schema['allOf'] ?? [])] as $part) {
            if (isset($part['properties'][$field])) {
                return $part['properties'][$field];
            }
        }

        self::fail(sprintf('No property "%s" in the schema.', $field));
    }

    /**
     * The HTTP methods of an OpenAPI path item, sorted.
     *
     * @param array<string, mixed> $pathItem
     *
     * @return list<string>
     */
    public static function methods(array $pathItem): array
    {
        $methods = array_values(array_intersect(['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'], array_keys($pathItem)));
        sort($methods);

        return $methods;
    }
}
