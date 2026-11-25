<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:41:04 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Mcp\Catalogue;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Entity\Refs\McpTool;
use Dmstr\NeuronAiBundle\Tests\Support\CatalogueDatabaseTrait;
use Dmstr\SymfonyJobQueue\Message\JobMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Functional test of the tool catalogue under /api/refs/mcp_tools against
 * the fake MCP transport: scan (job, validation before the job, per
 * connection), sync, filters and the absence of write
 * operations. Needs the migrated test database (CI only); the test firewall
 * authenticates as admin.
 */
#[Group('database')]
final class McpToolResourceTest extends ApiTestCase
{
    use CatalogueDatabaseTrait;

    protected static ?bool $alwaysBootKernel = true;

    private const string DOCS = 'https://docs.cat.example.org/mcp';
    private const string WIKI = 'https://wiki.cat.example.org/mcp';
    private const string OLD = 'https://old.cat.example.org/mcp';

    public function testScanOfAllActiveConnections(): void
    {
        $client = $this->client();
        $docs = $this->connection('docs', self::DOCS);
        $wiki = $this->connection('wiki', self::WIKI);
        $old = $this->connection('old', self::OLD, active: false);
        $this->tool($docs, 'fetch', 'Fetch a page', ['type' => 'object']);
        $this->tool($docs, 'gone', 'Vanished', ['type' => 'object']);
        $this->tool($old, 'legacy', 'Legacy');

        $fake = $this->fake();
        $fake->serveTools([
            ['name' => 'fetch', 'description' => 'Fetch a page by URL', 'inputSchema' => ['type' => 'object', 'required' => ['url']]],
            ['name' => 'search', 'description' => 'Search', 'inputSchema' => ['type' => 'object']],
        ], self::DOCS);
        $fake->serveTools([['name' => 'page', 'description' => 'Read a page', 'inputSchema' => ['type' => 'object']]], self::WIKI);

        $this->postScan($client, '{}');

        self::assertResponseStatusCodeSame(201);
        $data = $client->getResponse()->toArray();
        self::assertStringStartsWith('/api/admin/jobs/', $data['@id']);
        self::assertSame('mcp_tool_scan', $data['type']);
        self::assertSame(1, self::jobCount());
        // Nothing is scanned before the job runs.
        self::assertSame(0, $fake->getTransportCount());

        $jobId = (string) self::dbal()->fetchOne('SELECT id FROM dmstr_job WHERE type = :type', ['type' => McpTool::JOB_TYPE]);
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $messages = array_filter(array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()), static fn (object $m): bool => $m instanceof JobMessage);
        self::assertContains($jobId, array_map(static fn (JobMessage $m): string => $m->getJobId(), $messages));

        $job = $this->runJob($jobId);

        $docsRows = self::rows($docs);
        self::assertSame(['fetch', 'search'], array_keys($docsRows));
        self::assertSame('Fetch a page by URL', $docsRows['fetch']['description']);
        self::assertSame(['type' => 'object', 'required' => ['url']], json_decode((string) $docsRows['fetch']['input_schema'], true));
        self::assertNull($docsRows['search']['last_error']);
        self::assertSame(['page'], array_keys(self::rows($wiki)));
        self::assertSame(['legacy'], array_keys(self::rows($old)));
        self::assertSame(0, $fake->getRequestCount(self::OLD));

        $byName = array_column($job->getResultData()['connections'], null, 'name');
        self::assertSame('skipped', $byName[self::PREFIX.'old']['status']);
        self::assertSame(['created' => 1, 'updated' => 1, 'removed' => 1, 'unchanged' => 0], array_intersect_key($byName[self::PREFIX.'docs'], ['created' => 0, 'updated' => 0, 'removed' => 0, 'unchanged' => 0]));
    }

    public function testScanOfOneConnection(): void
    {
        $client = $this->client();
        $docs = $this->connection('docs', self::DOCS);
        $wiki = $this->connection('wiki', self::WIKI);
        $this->fake()->serveTools([['name' => 'search', 'inputSchema' => ['type' => 'object']]]);

        $this->postScan($client, json_encode(['apiConfiguration' => self::CONNECTIONS.'/'.$docs->getId()], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        self::assertResponseStatusCodeSame(201);

        $jobId = (string) self::dbal()->fetchOne('SELECT id FROM dmstr_job WHERE type = :type', ['type' => McpTool::JOB_TYPE]);
        $job = $this->runJob($jobId);

        self::assertSame(['apiConfiguration' => (string) $docs->getId()], $job->getInputData());
        self::assertSame(['search'], array_keys(self::rows($docs)));
        self::assertSame([], self::rows($wiki));
        self::assertSame([self::DOCS], array_values(array_unique(array_column($this->fake()->getCreatedConfigs(), 'url'))));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedConnections(): iterable
    {
        yield 'unknown' => ['missing'];
        yield 'inactive' => ['old'];
        yield 'non-mcp' => ['repos'];
    }

    #[DataProvider('rejectedConnections')]
    public function testScanWithUnusableConnectionIsRejectedWithoutJob(string $which): void
    {
        $client = $this->client();
        $references = [
            'missing' => self::PREFIX.'missing',
            'old' => $this->connection('old', self::OLD, active: false)->getName(),
            'repos' => self::CONNECTIONS.'/'.$this->connection('repos', 'https://git.example.org/api', type: 'github')->getId(),
        ];

        $this->postScan($client, json_encode(['apiConfiguration' => $references[$which]], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, self::jobCount());
        self::assertSame(0, $this->fake()->getTransportCount());
    }

    public function testScanWithUnknownPropertyIsRejectedWithoutJob(): void
    {
        $client = $this->client();

        $this->postScan($client, '{"connection": "docs"}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, self::jobCount());
    }

    public function testScanWithMalformedJsonIsRejectedWithoutJob(): void
    {
        $client = $this->client();

        $this->postScan($client, '{"apiConfiguration":');

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, self::jobCount());
    }

    public function testSyncRefreshesOneTool(): void
    {
        $client = $this->client();
        $docs = $this->connection('docs', self::DOCS);
        $tool = $this->tool($docs, 'search', 'Old', ['type' => 'object']);
        $this->fake()->serveTools([
            ['name' => 'search', 'description' => 'New', 'inputSchema' => ['type' => 'object', 'required' => ['q']]],
            ['name' => 'other', 'description' => 'Not created by sync'],
        ], self::DOCS);

        $data = $client->request('POST', self::COLLECTION.'/'.$tool->getId().'/sync', ['headers' => ['Accept' => 'application/ld+json']])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame('New', $data['description']);
        self::assertSame(['type' => 'object', 'required' => ['q']], $data['inputSchema']);
        self::assertNull($data['lastError'] ?? null);
        self::assertSame(self::CONNECTIONS.'/'.$docs->getId(), $data['apiConfiguration']);
        self::assertSame(['search'], array_keys(self::rows($docs)));
        self::assertSame('New', self::rows($docs)['search']['description']);
    }

    public function testSyncOfVanishedToolKeepsRecordAndSetsLastError(): void
    {
        $client = $this->client();
        $docs = $this->connection('docs', self::DOCS);
        $tool = $this->tool($docs, 'search', 'Search', ['type' => 'object']);
        $syncedAt = self::rows($docs)['search']['synced_at'];
        $this->fake()->serveTools([['name' => 'other']], self::DOCS);

        $data = $client->request('POST', self::COLLECTION.'/'.$tool->getId().'/sync', ['headers' => ['Accept' => 'application/ld+json']])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('no longer listed', $data['lastError']);
        $row = self::rows($docs)['search'];
        self::assertStringContainsString('no longer listed', (string) $row['last_error']);
        self::assertSame($syncedAt, $row['synced_at']);
    }

    public function testSyncRecordsTransportError(): void
    {
        $client = $this->client();
        $docs = $this->connection('docs', self::DOCS);
        $tool = $this->tool($docs, 'search');
        $this->fake()->rejectCredentials(self::DOCS);

        $data = $client->request('POST', self::COLLECTION.'/'.$tool->getId().'/sync', ['headers' => ['Accept' => 'application/ld+json']])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('Authentication failed', $data['lastError']);
    }

    public function testSyncOfUnknownToolIsNotFound(): void
    {
        $client = $this->client();

        $client->request('POST', self::COLLECTION.'/00000000-0000-4000-8000-000000000000/sync', ['headers' => ['Accept' => 'application/ld+json']]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testCollectionFiltersByConnectionAndName(): void
    {
        $client = $this->client();
        $docs = $this->connection('docs', self::DOCS);
        $wiki = $this->connection('wiki', self::WIKI);
        $this->tool($docs, 'search');
        $this->tool($docs, 'fetch');
        $this->tool($wiki, 'search');

        $byConnection = $client->request('GET', self::COLLECTION.'?apiConfiguration='.rawurlencode(self::CONNECTIONS.'/'.$docs->getId()), ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame(['fetch', 'search'], self::sortedNames($byConnection));

        $byBoth = $client->request('GET', self::COLLECTION.'?apiConfiguration='.$wiki->getId().'&name=search', ['headers' => ['Accept' => 'application/ld+json']])->toArray();
        self::assertSame(['search'], self::sortedNames($byBoth));
        // Collection responses embed to-one relations with their label (api-platform-utils-bundle `relation_labels`).
        self::assertSame(self::CONNECTIONS.'/'.$wiki->getId(), $byBoth['hydra:member'][0]['apiConfiguration']['@id'] ?? null);
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
        $docs = $this->connection('docs', self::DOCS);
        $tool = $this->tool($docs, 'search', 'Search');

        $uri = $onItem ? self::COLLECTION.'/'.$tool->getId() : self::COLLECTION;
        $contentType = 'PATCH' === $method ? 'application/merge-patch+json' : 'application/ld+json';
        $client->request($method, $uri, [
            'headers' => ['Content-Type' => $contentType, 'Accept' => 'application/ld+json'],
            'json' => ['apiConfiguration' => self::CONNECTIONS.'/'.$docs->getId(), 'name' => 'injected', 'description' => 'changed'],
        ]);

        self::assertContains($client->getResponse()->getStatusCode(), [404, 405]);
        self::assertSame(['search'], array_keys(self::rows($docs)));
        self::assertSame('Search', self::rows($docs)['search']['description']);
    }

    public function testDeletingTheConnectionRemovesItsTools(): void
    {
        $client = $this->client();
        $docs = $this->connection('docs', self::DOCS);
        $this->tool($docs, 'search');

        $client->request('DELETE', self::CONNECTIONS.'/'.$docs->getId());

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], self::rows($docs));
    }

    /**
     * @param array<string, mixed> $collection
     *
     * @return list<string>
     */
    private static function sortedNames(array $collection): array
    {
        $names = array_column($collection['hydra:member'], 'name');
        sort($names);

        return $names;
    }
}
