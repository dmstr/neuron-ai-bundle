<?php

// file generated with AI assistance: Claude Code - 2026-09-30 05:42:49 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Collection;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Support\ProviderModelFixtureTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional test of the neuron-ai-bundle Collection resource under
 * /api/admin/collections (the test firewall authenticates as admin).
 *
 * Provider connections of this suite carry the prefix {@see PREFIX}: the
 * active `ollama` connection {@see CONNECTION}, a second one
 * ({@see REMOTE}), an inactive one ({@see INACTIVE}) and an `anthropic`
 * one ({@see ANTHROPIC}). They are created per test after the collections
 * were truncated, with the catalogue entries the tests reference
 * ({@see $entries}: `nomic-embed-text:latest`, `mxbai-embed-large` and the
 * unregistered `all-minilm` on {@see CONNECTION}, `nomic-embed-text:latest`
 * on {@see REMOTE} and {@see INACTIVE}, a chat model on {@see ANTHROPIC}),
 * and deleted after them (the collections reference connections and
 * entries by foreign keys without cascade; the entries cascade with their
 * connection). Every valid collection references `nomic-embed-text:latest`
 * on {@see CONNECTION}, which is labelled `nomic-embed-text`.
 */
#[Group('database')]
class CollectionResourceTest extends ApiTestCase
{
    use ProviderModelFixtureTrait;

    protected static ?bool $alwaysBootKernel = true;

    private const string PREFIX = 'test-collection-';
    private const string CONNECTION = self::PREFIX.'fake-ollama';
    private const string REMOTE = self::PREFIX.'fake-ollama-remote';
    private const string INACTIVE = self::PREFIX.'fake-ollama-off';
    private const string ANTHROPIC = self::PREFIX.'fake-anthropic';

    /** @var array<string, array{id: string, iri: string}> catalogue entries by key: nomic, mxbai, minilm, remote, inactive, claude */
    private array $entries = [];

    protected function setUp(): void
    {
        // Counts or scans all rows: start from an empty database.
        TestDatabase::migrate(static::bootKernel());
        TestDatabase::purge(TestDatabase::connection(static::$kernel));
        $this->truncate();
        static::bootKernel();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $connection = ProviderConnectionFixtures::ollama(self::CONNECTION);
        $remote = ProviderConnectionFixtures::ollama(self::REMOTE, url: 'http://remote.fake.test:11434/api');
        $inactive = ProviderConnectionFixtures::ollama(self::INACTIVE, active: false);
        $anthropic = ProviderConnectionFixtures::anthropic(self::ANTHROPIC);
        ProviderConnectionFixtures::persist($entityManager, $connection, $remote, $inactive, $anthropic);
        foreach ([
            'nomic' => [$connection, 'nomic-embed-text:latest'],
            'mxbai' => [$connection, 'mxbai-embed-large'],
            'minilm' => [$connection, 'all-minilm:latest'],
            'remote' => [$remote, 'nomic-embed-text:latest'],
            'inactive' => [$inactive, 'nomic-embed-text:latest'],
            'claude' => [$anthropic, 'claude-haiku-4-5-20251001'],
        ] as $key => [$owner, $name]) {
            $entry = self::catalogueModel($entityManager, $owner, $name);
            $this->entries[$key] = ['id' => $entry->getId()->toRfc4122(), 'iri' => $entry->iri()];
        }
        static::ensureKernelShutdown();
    }

    /**
     * The body of a valid collection: `docs-de` on `nomic-embed-text:latest`
     * of {@see CONNECTION}, with the dimension.
     *
     * @return array<string, mixed>
     */
    private function valid(): array
    {
        return ['name' => 'docs-de', 'embeddingModel' => $this->entries['nomic']['iri'], 'embeddingDimension' => 768];
    }

    protected function tearDown(): void
    {
        $this->truncate();
        parent::tearDown();
    }

    public function testCreateListFilterAndDelete(): void
    {
        $client = static::createClient();

        $created = $this->post($client, $this->valid() + ['description' => 'Deutsche Dokumentation']);
        self::assertResponseStatusCodeSame(201);
        $data = $created->toArray();
        self::assertNotEmpty($data['id']);
        self::assertSame('docs-de', $data['name']);
        self::assertSame('Deutsche Dokumentation', $data['description']);
        self::assertSame($this->entries['nomic']['iri'], $data['embeddingModel']);
        self::assertSame('nomic-embed-text', $data['embeddingModelName'], 'The label is the registered name of nomic-embed-text:latest.');
        self::assertSame(768, $data['embeddingDimension']);
        self::assertSame(self::CONNECTION, $data['embeddingConnection']);
        self::assertSame(
            ['embedding_model' => 'nomic-embed-text', 'embedding_model_id' => $this->entries['nomic']['id'], 'embedding_connection_id' => $this->connectionId(self::CONNECTION)],
            $this->connection()->fetchAssociative('SELECT embedding_model, embedding_model_id::text AS embedding_model_id, embedding_connection_id::text AS embedding_connection_id FROM neuron_collection WHERE id = :id', ['id' => $data['id']]),
        );
        $iri = $data['@id'];
        self::assertSame('/api/admin/collections/'.$data['id'], $iri);

        $this->post($client, ['name' => 'docs-en'] + $this->valid());
        self::assertResponseStatusCodeSame(201);

        $list = $client->request('GET', '/api/admin/collections')->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame(2, $list['hydra:totalItems']);
        self::assertContains('docs-de', array_column($list['hydra:member'], 'name'));

        $filtered = $client->request('GET', '/api/admin/collections?name=docs-de')->toArray();
        self::assertSame(1, $filtered['hydra:totalItems']);
        self::assertSame('docs-de', $filtered['hydra:member'][0]['name']);

        $client->request('DELETE', $iri);
        self::assertResponseStatusCodeSame(204);
        $client->request('GET', $iri);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'uppercase' => ['Docs'];
        yield 'space' => ['with space'];
        yield 'leading dash' => ['-leading'];
        yield 'too long' => [str_repeat('a', 191)];
        yield 'blank' => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidNames')]
    public function testInvalidNameIsRejected(string $name): void
    {
        $response = $this->post(static::createClient(), ['name' => $name] + $this->valid());

        self::assertResponseStatusCodeSame(422);
        self::assertContains('name', array_column($response->toArray(false)['violations'], 'propertyPath'));
    }

    public function testDuplicateNameIsRejected(): void
    {
        $client = static::createClient();
        $this->post($client, $this->valid());
        self::assertResponseStatusCodeSame(201);

        $this->post($client, $this->valid());
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnUnregisteredModelIsRejectedWithTheKnownModels(): void
    {
        $response = $this->post(static::createClient(), ['embeddingModel' => $this->entries['minilm']['iri']] + $this->valid());

        self::assertResponseStatusCodeSame(422);
        $violation = $this->violation($response->toArray(false), 'embeddingModel');
        self::assertStringContainsString('Model "all-minilm:latest" of connection "'.self::CONNECTION.'" is no registered embedding model', $violation);
        self::assertStringContainsString('"nomic-embed-text"', $violation);
        self::assertStringContainsString('"mxbai-embed-large"', $violation);
    }

    public function testDimensionIsDerivedFromTheModel(): void
    {
        $response = $this->post(static::createClient(), ['name' => 'docs-de', 'embeddingModel' => $this->entries['nomic']['iri']]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(768, $response->toArray()['embeddingDimension']);
    }

    public function testWrongDimensionIsRejected(): void
    {
        $response = $this->post(static::createClient(), ['embeddingDimension' => 1024] + $this->valid());

        self::assertResponseStatusCodeSame(422);
        $violation = $this->violation($response->toArray(false), 'embeddingDimension');
        self::assertStringContainsString('1024', $violation);
        self::assertStringContainsString('768', $violation);
    }

    public function testModelIsRequired(): void
    {
        $response = $this->post(static::createClient(), ['name' => 'docs-de']);

        self::assertResponseStatusCodeSame(422);
        $violation = $this->violation($response->toArray(false), 'embeddingModel');
        self::assertStringContainsString('The embedding model is required', $violation);
        self::assertStringContainsString('"nomic-embed-text"', $violation);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM neuron_collection'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusableModels(): iterable
    {
        yield 'a model name' => ['nomic-embed-text', 'Unknown model "nomic-embed-text"'];
        yield 'an unknown iri' => ['/api/refs/provider_models/0199a1b2-0000-4000-8000-000000000000', 'Unknown model'];
        yield 'a model of an inactive connection' => ['inactive', 'the connection is inactive'];
        yield 'a model of an anthropic connection' => ['claude', 'cannot serve "embedding"'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableModels')]
    public function testAnUnusableModelIsRejected(string $reference, string $message): void
    {
        $response = $this->post(static::createClient(), ['embeddingModel' => $this->entries[$reference]['iri'] ?? $reference] + $this->valid());

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, $this->violation($response->toArray(false), 'embeddingModel'));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM neuron_collection'));
    }

    public function testModelIsResolvedFromAFullOrPartialUuidAndShownAsIri(): void
    {
        $client = static::createClient();
        $remote = $this->entries['remote'];

        $byUuid = $this->post($client, ['embeddingModel' => $remote['id']] + $this->valid());
        self::assertResponseStatusCodeSame(201);
        self::assertSame($remote['iri'], $byUuid->toArray()['embeddingModel']);
        self::assertSame(self::REMOTE, $byUuid->toArray()['embeddingConnection'], 'The entry names the connection.');

        $byPrefix = $this->post($client, ['name' => 'docs-en', 'embeddingModel' => substr($remote['id'], 0, 13)] + $this->valid());
        self::assertResponseStatusCodeSame(201);
        self::assertSame($remote['iri'], $client->request('GET', $byPrefix->toArray()['@id'])->toArray()['embeddingModel']);
        $id = $this->connectionId(self::REMOTE);
        self::assertSame(
            [$id, $id],
            $this->connection()->fetchFirstColumn('SELECT embedding_connection_id FROM neuron_collection ORDER BY name'),
        );
    }

    public function testListIsFilteredByEmbeddingConnection(): void
    {
        $client = static::createClient();
        $this->post($client, $this->valid());
        self::assertResponseStatusCodeSame(201);
        $this->post($client, ['name' => 'docs-remote', 'embeddingModel' => $this->entries['remote']['iri']] + $this->valid());
        self::assertResponseStatusCodeSame(201);

        $remote = '/api/admin/api_configurations/'.$this->connectionId(self::REMOTE);
        $byRemote = $client->request('GET', '/api/admin/collections?embeddingProviderConnection='.rawurlencode($remote))->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame(['docs-remote'], array_column($byRemote['hydra:member'], 'name'));

        $anthropic = '/api/admin/api_configurations/'.$this->connectionId(self::ANTHROPIC);
        $byAnthropic = $client->request('GET', '/api/admin/collections?embeddingProviderConnection='.rawurlencode($anthropic))->toArray();
        self::assertSame(0, $byAnthropic['hydra:totalItems']);
    }

    /**
     * `embeddingConnection` is read-only: the entry names the connection;
     * a request that sends it changes nothing.
     */
    public function testTheConnectionIsReadOnly(): void
    {
        $client = static::createClient();
        $created = $this->post($client, ['embeddingConnection' => self::REMOTE] + $this->valid());
        self::assertResponseStatusCodeSame(201);
        $iri = $created->toArray()['@id'];
        self::assertSame(self::CONNECTION, $created->toArray()['embeddingConnection']);

        $this->patch($client, $iri, ['embeddingConnection' => self::REMOTE, 'description' => 'neu']);

        self::assertResponseIsSuccessful();
        $data = $client->request('GET', $iri)->toArray();
        self::assertSame(self::CONNECTION, $data['embeddingConnection']);
        self::assertSame('neu', $data['description']);
    }

    public function testModelChangeRequiresAReindex(): void
    {
        $client = static::createClient();
        $iri = $this->post($client, $this->valid())->toArray()['@id'];
        self::assertResponseStatusCodeSame(201);

        // Another model, and the same label on another connection.
        foreach (['mxbai', 'remote'] as $other) {
            $response = $this->patch($client, $iri, ['embeddingModel' => $this->entries[$other]['iri']]);

            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString(
                'Changing the embedding model requires a reindex: POST /api/admin/collections/{id}/reindex',
                $this->violation($response->toArray(false), 'embeddingModel'),
            );
        }
        self::assertSame($this->entries['nomic']['iri'], $client->request('GET', $iri)->toArray()['embeddingModel']);

        // Sending back the current entry is no change; the label is read-only.
        $this->patch($client, $iri, ['embeddingModel' => $this->entries['nomic']['iri'], 'embeddingModelName' => 'mxbai-embed-large', 'description' => 'neu']);
        self::assertResponseIsSuccessful();
        $data = $client->request('GET', $iri)->toArray();
        self::assertSame('neu', $data['description']);
        self::assertSame('nomic-embed-text', $data['embeddingModelName']);
    }

    public function testCreatingACollectionCreatesTheIndexOfItsModel(): void
    {
        $response = $this->post(static::createClient(), ['name' => 'docs-en', 'embeddingModel' => $this->entries['mxbai']['iri']]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1024, $response->toArray()['embeddingDimension']);

        // idx_embedding_vector_ + substr(sha1('mxbai-embed-large'), 0, 12)
        $definition = $this->connection()->fetchOne(
            "SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'idx_embedding_vector_d281fab09939'",
        );
        self::assertIsString($definition);
        self::assertStringContainsString('vector(1024)', $definition);
        self::assertStringContainsString("'mxbai-embed-large'", $definition);
    }

    public function testReindexStateIsReadOnly(): void
    {
        $response = $this->post(static::createClient(), $this->valid() + [
            'pendingEmbeddingModel' => $this->entries['mxbai']['iri'],
            'pendingEmbeddingModelName' => 'mxbai-embed-large',
            'pendingEmbeddingConnection' => self::REMOTE,
            'reindexJobId' => '0199a000-0000-4000-8000-000000000000',
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = $response->toArray();
        // API Platform omits null properties from the output, so the keys may
        // be absent; a serialized non-null value would still fail here.
        self::assertNull($data['pendingEmbeddingModel'] ?? null);
        self::assertNull($data['pendingEmbeddingModelName'] ?? null);
        self::assertNull($data['pendingEmbeddingConnection'] ?? null);
        self::assertNull($data['reindexJobId'] ?? null);
        // The stored row is the authoritative proof that the input was ignored.
        self::assertSame(
            ['pending_embedding_model' => null, 'pending_embedding_model_id' => null, 'pending_embedding_connection_id' => null, 'reindex_job_id' => null],
            $this->connection()->fetchAssociative(
                'SELECT pending_embedding_model, pending_embedding_model_id, pending_embedding_connection_id, reindex_job_id FROM neuron_collection WHERE id = :id',
                ['id' => $data['id']],
            ),
        );
    }

    public function testKeywordLanguageDefaultsToGerman(): void
    {
        $client = static::createClient();
        $response = $this->post($client, $this->valid());

        self::assertResponseStatusCodeSame(201);
        $data = $response->toArray();
        self::assertSame('german', $data['keywordLanguage']);
        self::assertSame('german', $client->request('GET', $data['@id'])->toArray()['keywordLanguage']);
        self::assertSame('german', $this->connection()->fetchOne(
            'SELECT keyword_language FROM neuron_collection WHERE id = :id',
            ['id' => $data['id']],
        ));
    }

    public function testKeywordLanguageIsSetOnCreateAndChangedByPatch(): void
    {
        $client = static::createClient();
        $created = $this->post($client, $this->valid() + ['keywordLanguage' => 'english']);

        self::assertResponseStatusCodeSame(201);
        $iri = $created->toArray()['@id'];
        self::assertSame('english', $created->toArray()['keywordLanguage']);
        self::assertSame('english', $client->request('GET', $iri)->toArray()['keywordLanguage']);

        $patched = $this->patch($client, $iri, ['keywordLanguage' => 'german']);

        self::assertResponseIsSuccessful();
        self::assertSame('german', $patched->toArray()['keywordLanguage']);
        self::assertSame('german', $client->request('GET', $iri)->toArray()['keywordLanguage']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeywordLanguages(): iterable
    {
        yield 'unsupported language' => ['french'];
        yield 'sql injection attempt' => ["german'; DROP TABLE x; --"];
        yield 'blank' => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidKeywordLanguages')]
    public function testInvalidKeywordLanguageIsRejectedOnCreate(string $language): void
    {
        $response = $this->post(static::createClient(), $this->valid() + ['keywordLanguage' => $language]);

        self::assertResponseStatusCodeSame(422);
        $this->violation($response->toArray(false), 'keywordLanguage');
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM neuron_collection'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidKeywordLanguages')]
    public function testInvalidKeywordLanguageIsRejectedOnPatch(string $language): void
    {
        $client = static::createClient();
        $iri = $this->post($client, $this->valid())->toArray()['@id'];
        self::assertResponseStatusCodeSame(201);

        $response = $this->patch($client, $iri, ['keywordLanguage' => $language]);

        self::assertResponseStatusCodeSame(422);
        $this->violation($response->toArray(false), 'keywordLanguage');
        self::assertSame('german', $client->request('GET', $iri)->toArray()['keywordLanguage']);
    }

    public function testNoRouteOutsideAdminPrefix(): void
    {
        static::createClient()->request('GET', '/api/collections');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(Client $client, array $body): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return $client->request('POST', '/api/admin/collections', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => $body,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patch(Client $client, string $iri, array $body): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return $client->request('PATCH', $iri, [
            'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => $body,
        ]);
    }

    /**
     * @param array<string, mixed> $error
     */
    private function violation(array $error, string $propertyPath): string
    {
        foreach ($error['violations'] ?? [] as $violation) {
            if ($violation['propertyPath'] === $propertyPath) {
                return $violation['message'];
            }
        }

        self::fail(sprintf('No violation for "%s" in: %s', $propertyPath, json_encode($error)));
    }

    private function connection(): Connection
    {
        return static::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function connectionId(string $name): string
    {
        $id = $this->connection()->fetchOne('SELECT id FROM dmstr_api_configuration WHERE name = :name', ['name' => $name]);
        self::assertIsString($id, \sprintf('No connection "%s".', $name));

        return $id;
    }

    /**
     * Empties the collections (of every suite) and deletes this suite's
     * connections afterwards, their catalogue entries with them: the
     * collections reference both.
     */
    private function truncate(): void
    {
        static::bootKernel();
        $this->connection()->executeStatement('TRUNCATE neuron_collection CASCADE');
        $this->connection()->executeStatement('DELETE FROM dmstr_api_configuration WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
        // Indexes of models other than the migration's default model are
        // created at runtime; drop them so every test starts without them.
        $this->connection()->executeStatement('DROP INDEX IF EXISTS idx_embedding_vector_d281fab09939');
        static::ensureKernelShutdown();
    }
}
