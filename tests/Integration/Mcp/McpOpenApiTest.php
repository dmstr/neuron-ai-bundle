<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:22:22 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Mcp;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Admin-panel discovery of the `mcp` connection type: every ApiConfiguration
 * component schema describes configJson with exactly one anyOf branch of
 * type `mcp`. Boots the kernel like the other functional tests; runs in CI.
 */
#[Group('database')]
final class McpOpenApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
    }

    protected static ?bool $alwaysBootKernel = true;

    public function testConfigJsonHasExactlyOneMcpBranch(): void
    {
        $response = static::createClient()->request('GET', '/api/docs.jsonopenapi', ['headers' => ['Accept' => 'application/vnd.openapi+json']]);
        self::assertResponseIsSuccessful();
        $openApi = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $checked = 0;
        foreach ($openApi['components']['schemas'] as $name => $schema) {
            if (!str_starts_with($name, 'ApiConfiguration')) {
                continue;
            }
            $branches = self::properties($schema)['configJson']['anyOf'] ?? null;
            if (null === $branches) {
                continue;
            }

            $mcp = array_values(array_filter(
                $branches,
                static fn (array $branch): bool => 'mcp' === ($branch['properties']['type']['const'] ?? null),
            ));
            self::assertCount(1, $mcp, $name);
            self::assertSame('MCP server', $mcp[0]['title'], $name);
            self::assertFalse($mcp[0]['additionalProperties'], $name);
            self::assertTrue($mcp[0]['properties']['mcp']['const'], $name);
            self::assertSame('rest', $mcp[0]['properties']['endpoint_type']['const'], $name);
            self::assertArrayNotHasKey('section_key', $mcp[0]['properties'], $name);
            ++$checked;
        }

        self::assertGreaterThan(0, $checked, 'No ApiConfiguration schema with configJson found.');
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
}
