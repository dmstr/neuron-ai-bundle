<?php

// file generated with AI assistance: Claude Code - 2026-10-08 20:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Collection;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\Model\ModelCapability;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use PHPUnit\Framework\Attributes\Group;

/**
 * OpenAPI discovery of the collection's embedding model as a catalogue
 * reference: `embeddingModel` is writable and carries the `x-collection`
 * of the embedding models; `embeddingModelName` (the vector label),
 * `embeddingConnection` and the pending fields are read-only; the reindex
 * request body has `model` with the same picker and no `connection`.
 * Boots the kernel; the assertions need no database.
 */
#[Group('database')]
final class CollectionDiscoveryTest extends ApiTestCase
{
    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
    }

    protected static ?bool $alwaysBootKernel = true;

    public function testEmbeddingModelIsAReferenceWithThePickerOfTheEmbeddingModels(): void
    {
        $schemas = $this->openApi()['components']['schemas'];
        self::assertArrayHasKey('Collection.jsonld', $schemas);

        foreach (['Collection', 'Collection.jsonld'] as $name) {
            $properties = self::properties($schemas[$name]);

            self::assertSame(ProviderModel::EMBEDDING_MODEL_COLLECTION_URL, $properties['embeddingModel']['x-collection'] ?? null, $name);
            self::assertSame('iri-reference', $properties['embeddingModel']['format'] ?? null, $name);
            self::assertArrayNotHasKey('readOnly', $properties['embeddingModel'], $name.': embeddingModel is writable');
            foreach (['embeddingModelName', 'embeddingConnection', 'pendingEmbeddingModel', 'pendingEmbeddingModelName', 'pendingEmbeddingConnection'] as $readOnly) {
                self::assertTrue($properties[$readOnly]['readOnly'] ?? false, $name.': '.$readOnly);
            }
            foreach (['embeddingProviderModel', 'embeddingProviderConnection', 'embeddingModelReference', 'pendingEmbeddingProviderModel'] as $hidden) {
                self::assertArrayNotHasKey($hidden, $properties, $name);
            }
        }

        self::assertSame(ProviderModel::modelCollectionUrl(ModelCapability::EMBEDDING), ProviderModel::EMBEDDING_MODEL_COLLECTION_URL);
    }

    public function testReindexBodyTakesTheModelReferenceOnly(): void
    {
        $operation = $this->openApi()['paths']['/api/admin/collections/{id}/reindex']['post'] ?? null;
        self::assertIsArray($operation);

        $schema = $operation['requestBody']['content']['application/json']['schema'];
        self::assertSame(['model'], array_keys($schema['properties']));
        self::assertSame(ProviderModel::EMBEDDING_MODEL_COLLECTION_URL, $schema['properties']['model']['x-collection'] ?? null);
        self::assertSame('iri-reference', $schema['properties']['model']['format'] ?? null);
        self::assertStringContainsString('`connection` is no longer accepted', $operation['description']);
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
     * Properties of a component schema; JSON-LD schemas wrap them in allOf.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, array<string, mixed>>
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
