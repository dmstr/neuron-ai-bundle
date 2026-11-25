<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\ProviderConnection\Catalogue;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\Model\ModelCapability;
use Dmstr\NeuronAiBundle\Provider\ProviderModelScanner;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\ProviderCatalogueDatabaseTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Lock\LockFactory;

/**
 * Functional test of the model catalogue under /api/refs/provider_models
 * against the fake model describer: scan (job, job reuse, validation before
 * the job), sync (409 while locked), the filters including
 * capability and `apiConfiguration.active`, the model picker collection, `stale` after a URL change, the model options schema, the
 * cascade on connection delete and the scan trigger on provider connection
 * writes through the API.
 * Needs the migrated test database (CI only); the test firewall
 * authenticates as admin.
 */
#[Group('database')]
final class ProviderModelResourceTest extends ApiTestCase
{
    use ProviderCatalogueDatabaseTrait;

    protected static ?bool $alwaysBootKernel = true;

    public function testScanQueuesOneJobAndTheJobMirrorsTheConnection(): void
    {
        $client = $this->client();
        $remote = $this->connection('remote');
        $this->model($remote, 'gone:latest', ['completion']);
        $this->fake()->describe(
            $remote->getName(),
            ['name' => 'llama3.1:8b', 'family' => 'llama', 'capabilities' => ['completion', 'tools'], 'maxContextLength' => 131072, 'supportsSampling' => true],
            ['name' => 'nomic-embed-text:latest', 'capabilities' => ['embedding'], 'maxContextLength' => 2048],
        );

        $this->postScan($client, json_encode(['apiConfiguration' => $remote->getName()], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $data = $client->getResponse()->toArray();
        self::assertSame('provider_model_scan', $data['type']);
        self::assertSame(1, self::jobCount());
        // Nothing is described before the job runs.
        self::assertSame([], $this->fake()->getDescribedConnections());

        // A second identical scan while the first is pending returns the same job.
        $this->postScan($client, json_encode(['apiConfiguration' => $remote->getName()], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        self::assertSame($data['@id'], $client->getResponse()->toArray()['@id']);
        self::assertSame(1, self::jobCount());

        $job = $this->runJob(substr((string) $data['@id'], strrpos((string) $data['@id'], '/') + 1));

        self::assertSame(['apiConfiguration' => (string) $remote->getId()], $job->getInputData());
        $rows = self::rows($remote);
        self::assertSame(['gone:latest', 'llama3.1:8b', 'nomic-embed-text:latest'], array_keys($rows));
        self::assertFalse($rows['gone:latest']['available']);
        self::assertTrue($rows['llama3.1:8b']['available']);
        self::assertSame(['completion', 'tools'], json_decode((string) $rows['llama3.1:8b']['capabilities'], true));
        self::assertSame(131072, $rows['llama3.1:8b']['max_context_length']);
        self::assertTrue($rows['llama3.1:8b']['supports_sampling']);
        self::assertNotNull($rows['llama3.1:8b']['synced_at']);
        self::assertSame(1, $job->getResultData()['unavailable']);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function rejectedBodies(): iterable
    {
        yield 'unknown connection' => ['{"apiConfiguration": "test-prov-cat-missing"}', 422];
        yield 'inactive connection' => ['{"apiConfiguration": "test-prov-cat-old"}', 422];
        yield 'unknown property' => ['{"connection": "x"}', 422];
        yield 'malformed json' => ['{"apiConfiguration":', 400];
    }

    #[DataProvider('rejectedBodies')]
    public function testScanIsValidatedBeforeAJobExists(string $body, int $status): void
    {
        $client = $this->client();
        $this->connection('old', active: false);

        $this->postScan($client, $body);

        self::assertResponseStatusCodeSame($status);
        self::assertSame(0, self::jobCount());
    }

    public function testSyncRefreshesOneModel(): void
    {
        $client = $this->client();
        $remote = $this->connection('remote');
        $model = $this->model($remote, 'llama3.1:8b', ['completion']);
        $this->fake()->describe($remote->getName(), ['name' => 'llama3.1:8b', 'capabilities' => ['completion', 'tools'], 'maxContextLength' => 131072, 'providerData' => ['parameters' => ['num_ctx' => 8192, 'stop' => ['<|eot_id|>']]]], ['name' => 'other:latest']);

        $data = $client->request('POST', self::COLLECTION.'/'.$model->getId().'/sync', ['headers' => ['Accept' => 'application/ld+json']])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['completion', 'tools'], $data['capabilities']);
        self::assertSame(131072, $data['maxContextLength']);
        self::assertSame(8192, $data['defaultContextLength']);
        self::assertSame(['num_ctx' => 8192, 'stop' => ['<|eot_id|>']], $data['providerData']['parameters']);
        self::assertFalse($data['stale']);
        self::assertArrayNotHasKey('endpointFingerprint', $data);
        self::assertNull($data['lastError'] ?? null);
        self::assertSame(self::CONNECTIONS.'/'.$remote->getId(), $data['apiConfiguration']);
        self::assertSame(['llama3.1:8b'], array_keys(self::rows($remote)));
    }

    public function testSyncOfAModelNoLongerOfferedKeepsTheRow(): void
    {
        $client = $this->client();
        $remote = $this->connection('remote');
        $model = $this->model($remote, 'gone:latest', ['completion', 'tools']);

        $data = $client->request('POST', self::COLLECTION.'/'.$model->getId().'/sync', ['headers' => ['Accept' => 'application/ld+json']])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(ProviderModelScanner::NOT_OFFERED, $data['lastError']);
        self::assertSame(['completion', 'tools'], $data['capabilities']);
    }

    public function testSyncWhileAScanRunsIsAConflict(): void
    {
        $client = $this->client();
        $remote = $this->connection('remote');
        $model = $this->model($remote, 'llama3.1:8b', ['completion']);
        /** @var LockFactory $locks */
        $locks = static::getContainer()->get('lock.factory');
        $held = $locks->createLock(ProviderModelScanner::LOCK_PREFIX.$remote->getId());
        self::assertTrue($held->acquire());

        $client->request('POST', self::COLLECTION.'/'.$model->getId().'/sync', ['headers' => ['Accept' => 'application/ld+json']]);

        self::assertResponseStatusCodeSame(409);
        $held->release();
    }

    public function testCollectionFilters(): void
    {
        $client = $this->client();
        $remote = $this->connection('remote');
        $claude = $this->connection('claude', anthropic: true);
        $this->model($remote, 'llama3.1:8b', ['completion', 'tools'], 'llama');
        $this->model($remote, 'llama3.2-vision:11b', ['completion', 'vision'], 'mllama');
        $this->model($remote, 'gemma4:e4b', ['audio', 'completion', 'thinking', 'tools', 'vision'], 'gemma4');
        $this->model($remote, 'unknown:latest');
        $this->model($claude, 'claude-sonnet-5-5', ['completion', 'thinking', 'tools', 'vision']);

        $byConnection = self::names($this->get($client, '?apiConfiguration='.rawurlencode(self::CONNECTIONS.'/'.$remote->getId())));
        self::assertSame(['gemma4:e4b', 'llama3.1:8b', 'llama3.2-vision:11b', 'unknown:latest'], $byConnection);

        $tools = self::names($this->get($client, '?apiConfiguration='.$remote->getId().'&capability=tools'));
        self::assertSame(['gemma4:e4b', 'llama3.1:8b'], $tools);

        $toolsAndVision = self::names($this->get($client, '?apiConfiguration='.$remote->getId().'&capability[]=tools&capability[]=vision'));
        self::assertSame(['gemma4:e4b'], $toolsAndVision);

        $byFamily = self::names($this->get($client, '?family=mllama&apiConfiguration='.$remote->getId()));
        self::assertSame(['llama3.2-vision:11b'], $byFamily);

        $none = self::names($this->get($client, '?apiConfiguration='.$remote->getId().'&capability=embedding'));
        self::assertSame([], $none);

        $client->request('GET', self::COLLECTION.'?capability=telepathy', ['headers' => ['Accept' => 'application/ld+json']]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testModelPickerOffersOnlyUsableEntries(): void
    {
        $client = $this->client();
        $remote = $this->connection('remote');
        $inactive = $this->connection('inactive', active: false);
        $this->model($remote, 'picker-usable:latest', ['completion']);
        $this->model($remote, 'picker-embedding:latest', ['embedding']);
        $this->model($remote, 'picker-gone:latest', ['completion'])->setAvailable(false);
        $this->model($inactive, 'picker-inactive:latest', ['completion']);
        $this->entityManager()->flush();

        $data = $client->request('GET', ProviderModel::modelCollectionUrl(ModelCapability::COMPLETION), ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        self::assertResponseIsSuccessful();

        $offered = array_values(array_filter(self::names($data), static fn (string $name): bool => str_starts_with($name, 'picker-')));
        self::assertSame(['picker-usable:latest'], $offered);
    }

    /**
     * The picker's type-ahead appends `search=<input>` to its `x-collection`
     * (`x-search-property`): a typed part of the name and a pasted UUID
     * prefix both find the entry; `name` stays exact.
     */
    public function testModelPickerSearchesByNameAndUuid(): void
    {
        $client = $this->client();
        $remote = $this->connection('remote');
        $this->model($remote, 'llama3.1:8b', ['completion']);
        $llama32 = $this->model($remote, 'llama3.2:latest', ['completion']);
        $this->model($remote, 'gemma4:e4b', ['completion']);
        $this->entityManager()->flush();

        // The picker's query string, filtered to this test's connection.
        $picker = substr(ProviderModel::modelCollectionUrl(ModelCapability::COMPLETION), \strlen(ProviderModel::COLLECTION_PATH)).'&apiConfiguration='.$remote->getId();
        self::assertSame(['llama3.1:8b', 'llama3.2:latest'], self::names($this->get($client, $picker.'&search=LLAMA3')));

        $uuid = (string) $llama32->getId();
        self::assertSame(['llama3.2:latest'], self::names($this->get($client, $picker.'&search='.substr($uuid, 0, 13))), 'UUID prefix with a hyphen');
        self::assertSame(['llama3.2:latest'], self::names($this->get($client, $picker.'&search='.strtoupper(str_replace('-', '', substr($uuid, 0, 13))))), 'UUID prefix without hyphens');
        self::assertSame([], self::names($this->get($client, $picker.'&search=100%25')), 'LIKE wildcards are literal');
        self::assertSame([], self::names($this->get($client, '?apiConfiguration='.$remote->getId().'&name=llama3')), 'name stays exact');
    }

    public function testRowTurnsStaleWhenTheConnectionUrlChanges(): void
    {
        $client = $this->client();
        $remote = $this->connection('remote');
        $model = $this->model($remote, 'llama3.1:8b', ['completion', 'tools']);

        self::assertFalse($this->get($client, '/'.$model->getId())['stale']);

        $client->request('PATCH', self::CONNECTIONS.'/'.$remote->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['configJson' => ProviderConnectionFixtures::ollamaConfig('https://ollama.moved.example.org/api')],
        ]);
        self::assertResponseIsSuccessful();

        self::assertTrue($this->get($client, '/'.$model->getId())['stale']);
    }

    public function testOptionsSchemaFollowsTheCatalogueRow(): void
    {
        $client = $this->client();
        $remote = $this->connection('remote');
        $model = $this->model($remote, 'qwen3:14b', ['completion', 'thinking', 'tools'])->setMaxContextLength(40960);
        $plain = $this->model($remote, 'llama3.1:8b', ['completion', 'tools']);
        $this->entityManager()->flush();

        $data = $this->get($client, '/'.$model->getId().'/options_schema');
        self::assertSame('qwen3:14b', $data['model']);
        self::assertSame($remote->getName(), $data['connection']);
        self::assertSame('ollama', $data['type']);
        self::assertFalse($data['stale']);
        self::assertSame('https://json-schema.org/draft/2020-12/schema', $data['schema']['$schema']);
        self::assertSame(['temperature', 'topP', 'topK', 'maxTokens', 'repeatPenalty', 'seed', 'numCtx', 'think'], array_keys($data['schema']['properties']));
        self::assertSame(40960, $data['schema']['properties']['numCtx']['maximum']);
        self::assertFalse($data['schema']['additionalProperties']);

        self::assertArrayNotHasKey('think', $this->get($client, '/'.$plain->getId().'/options_schema')['schema']['properties']);

        $client->request('GET', self::COLLECTION.'/00000000-0000-4000-8000-000000000000/options_schema', ['headers' => ['Accept' => 'application/ld+json']]);
        self::assertResponseStatusCodeSame(404);
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
        $client = $this->client();
        $remote = $this->connection('remote');
        $model = $this->model($remote, 'llama3.1:8b', ['completion']);

        $uri = $onItem ? self::COLLECTION.'/'.$model->getId() : self::COLLECTION;
        $contentType = 'PATCH' === $method ? 'application/merge-patch+json' : 'application/ld+json';
        $client->request($method, $uri, [
            'headers' => ['Content-Type' => $contentType, 'Accept' => 'application/ld+json'],
            'json' => ['apiConfiguration' => self::CONNECTIONS.'/'.$remote->getId(), 'name' => 'injected', 'capabilities' => ['tools']],
        ]);

        self::assertContains($client->getResponse()->getStatusCode(), [404, 405]);
        self::assertSame(['llama3.1:8b'], array_keys(self::rows($remote)));
    }

    public function testDeletingAnUnreferencedConnectionRemovesItsModels(): void
    {
        $client = $this->client();
        $remote = $this->connection('remote');
        $this->model($remote, 'llama3.1:8b', ['completion']);

        $client->request('DELETE', self::CONNECTIONS.'/'.$remote->getId());

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], self::rows($remote));
    }

    public function testCreatingAProviderConnectionThroughTheApiQueuesAScan(): void
    {
        $client = $this->client();

        $created = $client->request('POST', self::CONNECTIONS, [
            'headers' => ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['name' => self::PREFIX.'created', 'configJson' => ProviderConnectionFixtures::ollamaConfig('http://ollama.created.example.org:11434/api'), 'active' => true],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);

        self::assertSame([['apiConfiguration' => $created['id']]], self::jobInputs());
    }

    public function testUrlChangeQueuesAScanAndRenameDoesNot(): void
    {
        $client = $this->client();
        $remote = $this->connection('remote');

        $client->request('PATCH', self::CONNECTIONS.'/'.$remote->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['name' => self::PREFIX.'renamed'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(0, self::jobCount());

        $client->request('PATCH', self::CONNECTIONS.'/'.$remote->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['configJson' => ProviderConnectionFixtures::ollamaConfig('https://ollama.moved.example.org/api')],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame([['apiConfiguration' => (string) $remote->getId()]], self::jobInputs());
    }

    /**
     * @return array<string, mixed>
     */
    private function get(Client $client, string $suffix): array
    {
        $data = $client->request('GET', self::COLLECTION.$suffix, ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        self::assertResponseIsSuccessful();

        return $data;
    }

    /**
     * @param array<string, mixed> $collection
     *
     * @return list<string>
     */
    private static function names(array $collection): array
    {
        $names = array_column($collection['hydra:member'] ?? $collection['member'] ?? [], 'name');
        sort($names);

        return $names;
    }
}
