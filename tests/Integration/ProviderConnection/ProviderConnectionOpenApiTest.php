<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\ProviderConnection;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Testing\ConfigSecretsFixture;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Admin-panel discovery of the provider connection types: every
 * ApiConfiguration component schema describes configJson with exactly one
 * anyOf branch of type `ollama` and one of type `anthropic`, each the
 * schema file verbatim (so `api_key` keeps its `writeOnly` marker), next to
 * the `mcp` branch.
 */
#[Group('database')]
final class ProviderConnectionOpenApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
    }

    protected static ?bool $alwaysBootKernel = true;

    public function testConfigJsonHasOneBranchPerProviderType(): void
    {
        $response = static::createClient()->request('GET', '/api/docs.jsonopenapi', ['headers' => ['Accept' => 'application/vnd.openapi+json']]);
        self::assertResponseIsSuccessful();
        $openApi = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $schemas = ConfigSecretsFixture::bundleSchemas();

        $checked = 0;
        foreach ($openApi['components']['schemas'] as $name => $schema) {
            if (!str_starts_with($name, 'ApiConfiguration')) {
                continue;
            }
            $properties = $schema['properties'] ?? [];
            foreach ($schema['allOf'] ?? [] as $part) {
                $properties += $part['properties'] ?? [];
            }
            $branches = $properties['configJson']['anyOf'] ?? null;
            if (null === $branches) {
                continue;
            }

            $types = array_map(static fn (array $branch): ?string => $branch['properties']['type']['const'] ?? null, $branches);
            foreach (['mcp'] as $other) {
                self::assertContains($other, $types, $name);
            }
            foreach (['ollama', 'anthropic'] as $type) {
                $matching = array_values(array_filter($branches, static fn (array $branch): bool => $type === ($branch['properties']['type']['const'] ?? null)));
                self::assertCount(1, $matching, $name.' '.$type);
                $expected = json_decode((string) file_get_contents($schemas[$type]), true, 512, \JSON_THROW_ON_ERROR);
                self::assertSame($expected, $matching[0], $name.' '.$type);
            }
            ++$checked;
        }

        self::assertGreaterThan(0, $checked, 'No ApiConfiguration schema with configJson found.');
    }
}
