<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\ProviderConnection\Catalogue;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\OpenApi\OpenApiTag;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use PHPUnit\Framework\Attributes\Group;

/**
 * OpenAPI discovery of the model catalogue: read-only REF resource under
 * /api/refs/provider_models with the connection, name, family,
 * available, apiConfiguration.active and capability filters (the capability parameter declared exactly once),
 * the scan request body from the bundle's scan.input.json, the sync action,
 * the model options schema and no endpoint fingerprint in the schema. Boots the kernel, needs no
 * database.
 */
#[Group('database')]
final class ProviderModelOpenApiTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    /** @var array<string, mixed> */
    private array $document;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        $response = static::createClient()->request('GET', '/api/docs.jsonopenapi', ['headers' => ['Accept' => 'application/vnd.openapi+json']]);
        self::assertResponseIsSuccessful();
        $this->document = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    public function testCollectionIsReadOnlyWithFilters(): void
    {
        $collection = $this->document['paths']['/api/refs/provider_models'] ?? null;
        self::assertIsArray($collection);
        self::assertArrayHasKey('get', $collection);
        self::assertArrayNotHasKey('post', $collection);
        self::assertSame([OpenApiTag::CORE], $collection['get']['tags']);

        $parameters = array_column($collection['get']['parameters'], 'name');
        foreach (['apiConfiguration', 'name', 'family', 'available', 'apiConfiguration.active', 'capability', 'capability[]'] as $parameter) {
            self::assertContains($parameter, $parameters, $parameter);
        }
        self::assertSame(1, \count(array_keys($parameters, 'capability', true)), 'capability is declared once');
    }

    public function testItemHasNoWriteOperations(): void
    {
        $item = $this->document['paths']['/api/refs/provider_models/{id}'] ?? null;
        self::assertIsArray($item);
        self::assertArrayHasKey('get', $item);
        foreach (['put', 'patch', 'delete', 'post'] as $method) {
            self::assertArrayNotHasKey($method, $item, $method);
        }
    }

    public function testSchemaExposesStaleAndSamplingButNotTheFingerprint(): void
    {
        $schemas = $this->document['components']['schemas'];
        $properties = [];
        foreach ($schemas as $name => $schema) {
            if (str_starts_with((string) $name, 'ProviderModel')) {
                $properties += $schema['properties'] ?? [];
                foreach ($schema['allOf'] ?? [] as $part) {
                    $properties += $part['properties'] ?? [];
                }
            }
        }

        self::assertArrayHasKey('capabilities', $properties);
        self::assertArrayHasKey('maxContextLength', $properties);
        self::assertArrayHasKey('supportsSampling', $properties);
        self::assertArrayHasKey('stale', $properties);
        self::assertArrayNotHasKey('endpointFingerprint', $properties);
    }

    public function testScanRequestBodyComesFromTheInputSchema(): void
    {
        $scan = $this->document['paths']['/api/refs/provider_models/scan']['post'] ?? null;
        self::assertIsArray($scan);
        self::assertSame('provider_model_scan', $scan['operationId']);
        self::assertSame([OpenApiTag::CORE], $scan['tags']);
        self::assertArrayHasKey('201', $scan['responses']);

        $schema = $scan['requestBody']['content']['application/json']['schema'];
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['apiConfiguration'], array_keys($schema['properties']));
        self::assertSame('iri-reference', $schema['properties']['apiConfiguration']['format']);
        self::assertSame('/api/admin/api_configurations?type[]=ollama&type[]=anthropic&active=true', $schema['properties']['apiConfiguration']['x-collection']);
        self::assertFalse($scan['requestBody']['required']);
    }

    public function testSyncActionExists(): void
    {
        $sync = $this->document['paths']['/api/refs/provider_models/{id}/sync']['post'] ?? null;
        self::assertIsArray($sync);
        self::assertSame('provider_model_sync', $sync['operationId']);
        self::assertSame([OpenApiTag::CORE], $sync['tags']);
        self::assertArrayHasKey('200', $sync['responses']);
        self::assertArrayNotHasKey('requestBody', $sync);
    }

    public function testOptionsSchemaReadIsDocumented(): void
    {
        $read = $this->document['paths']['/api/refs/provider_models/{id}/options_schema']['get'] ?? null;
        self::assertIsArray($read);
        self::assertSame('provider_model_options_schema', $read['operationId']);
        self::assertSame([OpenApiTag::CORE], $read['tags']);
        self::assertArrayHasKey('200', $read['responses']);
        self::assertArrayHasKey('404', $read['responses']);
        self::assertStringContainsString('modelOptions', $read['description']);
    }
}
