<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:41:04 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Mcp\Catalogue;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use PHPUnit\Framework\Attributes\Group;

/**
 * OpenAPI discovery of the tool catalogue: read-only REF resource under
 * /api/refs/mcp_tools with the connection filter, the scan request body from
 * the bundle's scan.input.json (through the bundle's own input-schema
 * resolver) and the sync action. Boots the kernel, needs no database.
 */
#[Group('database')]
final class McpToolOpenApiTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    /** @var array<string, mixed> */
    private array $paths;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        $response = static::createClient()->request('GET', '/api/docs.jsonopenapi', ['headers' => ['Accept' => 'application/vnd.openapi+json']]);
        self::assertResponseIsSuccessful();
        $this->paths = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR)['paths'];
    }

    public function testCollectionIsReadOnlyWithConnectionFilter(): void
    {
        $collection = $this->paths['/api/refs/mcp_tools'] ?? null;
        self::assertIsArray($collection);
        self::assertArrayHasKey('get', $collection);
        self::assertArrayNotHasKey('post', $collection);

        $parameters = array_column($collection['get']['parameters'], 'name');
        self::assertContains('apiConfiguration', $parameters);
        self::assertContains('name', $parameters);
    }

    public function testItemHasNoWriteOperations(): void
    {
        $item = $this->paths['/api/refs/mcp_tools/{id}'] ?? null;
        self::assertIsArray($item);
        self::assertArrayHasKey('get', $item);
        foreach (['put', 'patch', 'delete', 'post'] as $method) {
            self::assertArrayNotHasKey($method, $item, $method);
        }
    }

    public function testScanRequestBodyComesFromTheInputSchema(): void
    {
        $scan = $this->paths['/api/refs/mcp_tools/scan']['post'] ?? null;
        self::assertIsArray($scan);
        self::assertSame('mcp_tool_scan', $scan['operationId']);
        self::assertArrayHasKey('201', $scan['responses']);

        $schema = $scan['requestBody']['content']['application/json']['schema'];
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['apiConfiguration'], array_keys($schema['properties']));
        self::assertSame('iri-reference', $schema['properties']['apiConfiguration']['format']);
        self::assertSame('/api/admin/api_configurations?type=mcp&active=true', $schema['properties']['apiConfiguration']['x-collection']);
        self::assertSame('name', $schema['properties']['apiConfiguration']['x-label-property']);
        self::assertFalse($scan['requestBody']['required']);
    }

    public function testSyncActionExists(): void
    {
        $sync = $this->paths['/api/refs/mcp_tools/{id}/sync']['post'] ?? null;
        self::assertIsArray($sync);
        self::assertSame('mcp_tool_sync', $sync['operationId']);
        self::assertArrayHasKey('200', $sync['responses']);
        self::assertArrayNotHasKey('requestBody', $sync);
    }
}
