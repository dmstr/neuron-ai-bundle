<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:34:45 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Conversation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Admin-panel discovery of conversations and messages: the Hydra entrypoint lists both resources, the OpenAPI
 * document shows the turn action `conversation_turn` with the request body
 * of `Entity/Conversation/turn.input.json`, the filters of both lists, no
 * update operations, and no `providerPayload` in any message schema (the
 * payload group is added at runtime on the item GET only). The Hydra
 * documentation types the sub-resource list `schema:Action` by the URI
 * heuristic of api-platform-utils; only its presence is asserted.
 *
 * The cancel action `conversation_cancel` is a POST without a
 * request body; the streaming and resume routes are classic controller
 * routes (the documented guard-rail exception) and do not appear in the
 * OpenAPI document.
 *
 * Model reference: `model` of the conversation (write and read) carries
 * the `x-collection` of the available chat models; the conversation reads
 * `modelName` and `connection`, which are not writable. Message and
 * evaluation records read `model` (the IRI of the catalogue entry) and
 * `modelName`, without the relation itself.
 *
 * Every request of the test kernel needs the database (group `database`).
 */
#[Group('database')]
final class DiscoveryTest extends ApiTestCase
{
    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
    }

    protected static ?bool $alwaysBootKernel = true;

    public function testEntrypointListsConversationsAndMessages(): void
    {
        $data = static::createClient()->request('GET', '/api', ['headers' => ['Accept' => 'application/ld+json']])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame('/api/conversations', $data['conversation'] ?? null);
        self::assertSame('/api/messages', $data['message'] ?? null);
    }

    public function testConversationOperationsAndFilters(): void
    {
        $paths = $this->openApi()['paths'];

        self::assertSame(['get', 'post'], self::methods($paths['/api/conversations']));
        self::assertSame(['delete', 'get'], self::methods($paths['/api/conversations/{id}']));

        $parameters = array_column($paths['/api/conversations']['get']['parameters'], 'name');
        self::assertContains('ownerSubject', $parameters);
        self::assertContains('status', $parameters);
    }

    public function testTurnRequestBodyComesFromTheInputSchema(): void
    {
        $turn = $this->openApi()['paths']['/api/conversations/{id}/messages']['post'] ?? null;
        self::assertIsArray($turn);
        self::assertSame('conversation_turn', $turn['operationId']);
        self::assertArrayHasKey('201', $turn['responses']);

        $schema = $turn['requestBody']['content']['application/json']['schema'];
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['content'], $schema['required']);
        self::assertSame(['content', 'responseSchema', 'maxToolCalls', 'terminalTools'], array_keys($schema['properties']));
        self::assertSame(1, $schema['properties']['content']['minLength']);
        self::assertSame(['integer', 'null'], $schema['properties']['maxToolCalls']['type']);
        self::assertSame(1, $schema['properties']['maxToolCalls']['minimum']);
        self::assertStringContainsString('maxToolCalls', $turn['description']);
        self::assertStringContainsString('forced_bound', $turn['description']);

        $terminalTools = $schema['properties']['terminalTools'];
        self::assertSame(['array', 'null'], $terminalTools['type']);
        self::assertTrue($terminalTools['uniqueItems']);
        self::assertSame(['type' => 'string', 'minLength' => 1], $terminalTools['items']);
        self::assertStringContainsString('terminalTools', $turn['description']);
        self::assertStringContainsString('terminal_tool', $turn['description']);
    }

    public function testRecordsReadTheModelReferenceAndTheModelName(): void
    {
        $schemas = $this->openApi()['components']['schemas'];

        foreach (['Message.jsonld-message.read', 'Evaluation.jsonld-evaluation.read'] as $name) {
            self::assertArrayHasKey($name, $schemas);
            $properties = self::properties($schemas[$name]);
            self::assertArrayHasKey('model', $properties, $name);
            self::assertStringContainsString('/api/refs/provider_models/<uuid>', (string) ($properties['model']['description'] ?? ''), $name);
            self::assertArrayHasKey('modelName', $properties, $name);
            self::assertArrayNotHasKey('providerModel', $properties, $name);
            self::assertArrayNotHasKey('modelReference', $properties, $name);
        }
    }

    public function testCancelIsABodylessPostAction(): void
    {
        $paths = $this->openApi()['paths'];

        self::assertSame(['post'], self::methods($paths['/api/conversations/{id}/cancel'] ?? []));
        $cancel = $paths['/api/conversations/{id}/cancel']['post'];
        self::assertSame('conversation_cancel', $cancel['operationId']);
        self::assertArrayNotHasKey('requestBody', $cancel);
        self::assertArrayHasKey('200', $cancel['responses']);
    }

    public function testStreamRoutesStayOutOfTheOpenApiDocument(): void
    {
        $paths = array_keys($this->openApi()['paths']);

        self::assertNotContains('/api/conversations/{id}/stream', $paths);
        self::assertSame([], array_values(array_filter($paths, static fn (string $path): bool => str_ends_with($path, '/stream'))));
    }

    public function testMessagesAreReadOnlyListsByConversation(): void
    {
        $paths = $this->openApi()['paths'];

        self::assertSame(['get'], self::methods($paths['/api/messages']));
        self::assertSame(['get'], self::methods($paths['/api/messages/{id}']));
        self::assertSame(['get'], self::methods($paths['/api/conversations/{conversationId}/messages']));

        $parameters = array_column($paths['/api/messages']['get']['parameters'], 'name');
        self::assertContains('conversation', $parameters);
    }

    public function testNoMessageSchemaExposesTheProviderPayload(): void
    {
        $checked = 0;
        foreach ($this->openApi()['components']['schemas'] as $name => $schema) {
            if (!str_starts_with($name, 'Message')) {
                continue;
            }
            self::assertArrayNotHasKey('providerPayload', self::properties($schema), $name);
            ++$checked;
        }

        self::assertGreaterThan(0, $checked, 'No Message schema found.');
    }

    public function testHydraDocumentationDescribesBothClasses(): void
    {
        $response = static::createClient()->request('GET', '/api/docs.jsonld', ['headers' => ['Accept' => 'application/ld+json']]);
        self::assertResponseIsSuccessful();

        $classes = [];
        foreach ($response->toArray()['hydra:supportedClass'] ?? [] as $class) {
            $classes[$class['hydra:title'] ?? ''] = $class;
        }
        self::assertArrayHasKey('Conversation', $classes);
        self::assertArrayHasKey('Message', $classes);

        // The class description mentions the payload; no property exposes it.
        $properties = array_map(
            static fn (array $property): ?string => $property['hydra:title'] ?? null,
            $classes['Message']['hydra:supportedProperty'] ?? [],
        );
        self::assertContains('content', $properties);
        self::assertNotContains('providerPayload', $properties);
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
     * The component schema a `$ref` points to, or the schema itself.
     *
     * @param array<string, mixed> $openApi
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function resolve(array $openApi, array $schema): array
    {
        if (isset($schema['$ref'])) {
            return $openApi['components']['schemas'][substr($schema['$ref'], \strlen('#/components/schemas/'))];
        }

        return $schema;
    }

    /**
     * Properties of a component schema; JSON-LD schemas wrap them in allOf.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function properties(array $schema): array
    {
        $properties = $schema['properties'] ?? [];
        foreach ($schema['allOf'] ?? [] as $part) {
            $properties += $part['properties'] ?? [];
        }

        return $properties;
    }

    /**
     * @param array<string, mixed> $pathItem
     *
     * @return list<string>
     */
    private static function methods(array $pathItem): array
    {
        $methods = array_values(array_intersect(['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'], array_keys($pathItem)));
        sort($methods);

        return $methods;
    }
}
