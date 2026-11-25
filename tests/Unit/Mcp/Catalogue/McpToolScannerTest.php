<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:41:04 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp\Catalogue;

use Dmstr\NeuronAiBundle\Entity\Refs\McpTool;
use Dmstr\NeuronAiBundle\Mcp\McpToolScanner;
use Dmstr\NeuronAiBundle\Testing\ConfigSecretsFixture;
use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use Dmstr\NeuronAiBundle\Tests\Support\InMemoryCatalogueTrait;
use NeuronAI\MCP\McpException;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the catalogue scan and sync with the
 * fake MCP transport and an in-memory entity manager.
 */
final class McpToolScannerTest extends TestCase
{
    use InMemoryCatalogueTrait;

    private const string DOCS = 'https://docs.example.org/mcp';
    private const string WIKI = 'https://wiki.example.org/mcp';
    private const string OLD = 'https://old.example.org/mcp';

    private FakeMcpTransportFactory $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeMcpTransportFactory();
    }

    public function testScanCreatesUpdatesAndRemoves(): void
    {
        $docs = $this->addConnection('docs', self::DOCS);
        $keep = $this->addTool($docs, 'search', 'Search', ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]);
        $change = $this->addTool($docs, 'fetch', 'Fetch a page', ['type' => 'object']);
        $this->addTool($docs, 'gone', 'Vanished tool', ['type' => 'object']);
        $keep->setLastError('earlier failure');

        $this->fake->serveTools([
            // Same content, keys reordered: not a change.
            ['name' => 'search', 'inputSchema' => ['properties' => ['q' => ['type' => 'string']], 'type' => 'object'], 'description' => 'Search'],
            ['name' => 'fetch', 'description' => 'Fetch a page by URL', 'inputSchema' => ['type' => 'object', 'required' => ['url']]],
            ['name' => 'summarize', 'description' => 'Summarize', 'inputSchema' => ['type' => 'object']],
        ], self::DOCS);

        $summary = $this->scanner()->scanAll();

        self::assertSame(1, $summary['created']);
        self::assertSame(1, $summary['updated']);
        self::assertSame(1, $summary['removed']);
        self::assertSame(1, $summary['unchanged']);
        self::assertSame(1, $summary['scanned']);
        self::assertSame(0, $summary['failed']);
        self::assertSame('ok', $summary['connections'][0]['status']);
        self::assertSame((string) $docs->getId(), $summary['connections'][0]['id']);

        $rows = $this->toolsByName($docs);
        self::assertSame(['fetch', 'search', 'summarize'], array_keys($rows));
        self::assertSame($change, $rows['fetch']);
        self::assertSame('Fetch a page by URL', $rows['fetch']->getDescription());
        self::assertSame(['type' => 'object', 'required' => ['url']], $rows['fetch']->getInputSchema());
        self::assertSame('Summarize', $rows['summarize']->getDescription());
        foreach ($rows as $row) {
            self::assertNull($row->getLastError());
            self::assertGreaterThan(new \DateTimeImmutable('2026-01-02'), $row->getSyncedAt());
        }
        self::assertGreaterThan(0, $this->flushes);
        self::assertSame(['initialize', 'notifications/initialized', 'tools/list'], $this->fake->getMethods(self::DOCS));
    }

    public function testScanStoresTitleAnnotationsAndMeta(): void
    {
        $docs = $this->addConnection('docs', self::DOCS);
        $this->fake->serveTools([[
            'name' => 'start_process',
            'title' => 'Start a process',
            'description' => 'Starts a Flowable process instance',
            'inputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false],
            '_meta' => ['flowable/processDefinitionKey' => 'leaveRequest'],
        ], [
            'name' => 'bare',
            'title' => '   ',
            'annotations' => [],
            '_meta' => ['not', 'an', 'object'],
        ], [
            'name' => 'long_title',
            'title' => str_repeat('t', 300),
            'annotations' => (object) ['readOnlyHint' => true],
        ]], self::DOCS);

        $summary = $this->scanner()->scanAll();

        self::assertSame(3, $summary['created']);
        $rows = $this->toolsByName($docs);
        self::assertSame('Start a process', $rows['start_process']->getTitle());
        self::assertSame(['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false], $rows['start_process']->getAnnotations());
        self::assertSame(['flowable/processDefinitionKey' => 'leaveRequest'], $rows['start_process']->getMeta());
        self::assertNull($rows['bare']->getTitle());
        self::assertNull($rows['bare']->getAnnotations());
        self::assertNull($rows['bare']->getMeta());
        self::assertSame(McpTool::MAX_TITLE_LENGTH, mb_strlen((string) $rows['long_title']->getTitle()));
        self::assertSame(['readOnlyHint' => true], $rows['long_title']->getAnnotations());
    }

    public function testChangedAnnotationsCountAsUpdateAndReorderedOnesDoNot(): void
    {
        $docs = $this->addConnection('docs', self::DOCS);
        $hinted = $this->addTool($docs, 'hinted', 'Hinted', ['type' => 'object']);
        $hinted->setAnnotations(['readOnlyHint' => true, 'openWorldHint' => false]);
        $titled = $this->addTool($docs, 'titled', 'Titled', ['type' => 'object']);
        $titled->setTitle('Old title');

        $this->fake->serveTools([
            ['name' => 'hinted', 'description' => 'Hinted', 'inputSchema' => ['type' => 'object'], 'annotations' => ['openWorldHint' => false, 'readOnlyHint' => true]],
            ['name' => 'titled', 'description' => 'Titled', 'inputSchema' => ['type' => 'object'], 'title' => 'New title', '_meta' => ['k' => 'v']],
        ], self::DOCS);

        $summary = $this->scanner()->scanAll();

        self::assertSame(1, $summary['unchanged']);
        self::assertSame(1, $summary['updated']);
        self::assertSame('New title', $titled->getTitle());
        self::assertSame(['k' => 'v'], $titled->getMeta());
    }

    public function testSyncRefreshesTitleAnnotationsAndMeta(): void
    {
        $docs = $this->addConnection('docs', self::DOCS);
        $tool = $this->addTool($docs, 'search', 'Search', ['type' => 'object']);
        $tool->setTitle('Old')->setAnnotations(['readOnlyHint' => false])->setMeta(['old' => true]);
        $this->fake->serveTools([['name' => 'search', 'description' => 'Search', 'inputSchema' => ['type' => 'object'], 'annotations' => ['readOnlyHint' => true]]]);

        self::assertTrue($this->scanner()->syncOne($tool));

        self::assertNull($tool->getTitle());
        self::assertSame(['readOnlyHint' => true], $tool->getAnnotations());
        self::assertNull($tool->getMeta());
    }

    public function testScanHandsTheTransportTheDecryptedToken(): void
    {
        $docs = $this->addConnection('docs', self::DOCS);
        // As stored since dmstr/api-configuration-bundle 0.5: the token is encrypted.
        $docs->setConfigJson(ConfigSecretsFixture::create()->encrypt($docs->getConfigJson() + ['auth_type' => 'bearer', 'token' => 'clear-token']));
        self::assertStringStartsWith('enc:v1:', $docs->getConfigJson()['token']);
        $this->fake->serveTools([['name' => 'search', 'inputSchema' => ['type' => 'object']]], self::DOCS);

        $summary = $this->scanner()->scanAll();

        self::assertSame(0, $summary['failed']);
        self::assertSame('clear-token', $this->fake->getCreatedConfigs()[0]['token']);
    }

    public function testInactiveConnectionIsSkippedWithoutTransport(): void
    {
        $this->addConnection('docs', self::DOCS);
        $old = $this->addConnection('old', self::OLD, active: false);
        $this->addTool($old, 'legacy', 'Legacy');
        $this->fake->serveTools([['name' => 'search', 'inputSchema' => ['type' => 'object']]]);

        $summary = $this->scanner()->scanAll();

        self::assertSame(1, $summary['scanned']);
        self::assertSame(1, $summary['skipped']);
        self::assertSame(['docs', 'old'], array_column($summary['connections'], 'name'));
        self::assertSame('skipped', $summary['connections'][1]['status']);
        self::assertSame(0, $this->fake->getRequestCount(self::OLD));
        self::assertSame([self::DOCS], array_column($this->fake->getCreatedConfigs(), 'url'));
        self::assertSame(['legacy'], array_keys($this->toolsByName($old)));
    }

    public function testFailingConnectionDoesNotStopOthers(): void
    {
        $docs = $this->addConnection('docs', self::DOCS);
        $wiki = $this->addConnection('wiki', self::WIKI);
        $stale = $this->addTool($docs, 'search', 'Search', null, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $this->fake->rejectCredentials(self::DOCS);
        $this->fake->serveTools([['name' => 'page', 'description' => 'Read a page', 'inputSchema' => ['type' => 'object']]], self::WIKI);

        $summary = $this->scanner()->scanAll();

        self::assertSame(1, $summary['failed']);
        self::assertSame(1, $summary['scanned']);
        self::assertSame(1, $summary['created']);
        self::assertSame('error', $summary['connections'][0]['status']);
        self::assertStringContainsString('Authentication failed', (string) $summary['connections'][0]['error']);

        // The failing connection keeps its rows, marked with the error.
        self::assertSame([$stale], array_values($this->toolsByName($docs)));
        self::assertStringContainsString('Authentication failed', (string) $stale->getLastError());
        self::assertEquals(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), $stale->getSyncedAt());
        self::assertSame(['page'], array_keys($this->toolsByName($wiki)));
    }

    public function testMalformedAnswerIsReportedAsFailure(): void
    {
        $this->addConnection('docs', self::DOCS);
        $this->fake->queueResult('tools/list', ['items' => []]);

        $summary = $this->scanner()->scanAll();

        self::assertSame(1, $summary['failed']);
        self::assertSame('error', $summary['connections'][0]['status']);
    }

    public function testScanOfOneConnectionTouchesOnlyThatOne(): void
    {
        $docs = $this->addConnection('docs', self::DOCS);
        $wiki = $this->addConnection('wiki', self::WIKI);
        $this->addTool($wiki, 'page');
        $this->fake->serveTools([['name' => 'search', 'inputSchema' => ['type' => 'object']]]);

        $summary = $this->scanner()->scan([$docs]);

        self::assertSame(['docs'], array_column($summary['connections'], 'name'));
        self::assertSame(0, $this->fake->getRequestCount(self::WIKI));
        self::assertSame(['search'], array_keys($this->toolsByName($docs)));
        self::assertSame(['page'], array_keys($this->toolsByName($wiki)));
    }

    public function testScanAllIgnoresOtherConnectionTypes(): void
    {
        $this->addConnection('repos', 'https://git.example.org/api', type: 'github');

        $summary = $this->scanner()->scanAll();

        self::assertSame([], $summary['connections']);
        self::assertSame(0, $this->fake->getTransportCount());
    }

    public function testNonMcpConnectionPassedExplicitlyIsAnError(): void
    {
        $repos = $this->addConnection('repos', 'https://git.example.org/api', type: 'github');

        $summary = $this->scanner()->scan([$repos]);

        self::assertSame(1, $summary['failed']);
        self::assertSame(0, $this->fake->getTransportCount());
    }

    public function testDuplicateAndOverlongNamesAreReportedAsWarnings(): void
    {
        $docs = $this->addConnection('docs', self::DOCS);
        $this->fake->serveTools([
            ['name' => 'search', 'description' => 'First'],
            ['name' => 'search', 'description' => 'Second'],
            ['name' => str_repeat('x', 191)],
        ]);

        $summary = $this->scanner()->scanAll();

        self::assertSame(1, $summary['created']);
        self::assertCount(2, $summary['connections'][0]['warnings']);
        self::assertSame('First', $this->toolsByName($docs)['search']->getDescription());
        self::assertNull($this->toolsByName($docs)['search']->getInputSchema());
    }

    public function testSyncRefreshesListedTool(): void
    {
        $docs = $this->addConnection('docs', self::DOCS);
        $tool = $this->addTool($docs, 'search', 'Old', ['type' => 'object']);
        $tool->setLastError('earlier failure');
        $this->fake->serveTools([
            ['name' => 'other', 'description' => 'Other'],
            ['name' => 'search', 'description' => 'New', 'inputSchema' => ['type' => 'object', 'required' => ['q']]],
        ]);

        self::assertTrue($this->scanner()->syncOne($tool));

        self::assertSame('New', $tool->getDescription());
        self::assertSame(['type' => 'object', 'required' => ['q']], $tool->getInputSchema());
        self::assertNull($tool->getLastError());
        self::assertGreaterThan(new \DateTimeImmutable('2026-01-02'), $tool->getSyncedAt());
        // Sync creates nothing.
        self::assertSame(['search'], array_keys($this->toolsByName($docs)));
        self::assertSame(1, $this->flushes);
    }

    public function testSyncOfVanishedToolKeepsRecordAndSetsLastError(): void
    {
        $docs = $this->addConnection('docs', self::DOCS);
        $tool = $this->addTool($docs, 'search', 'Search', ['type' => 'object']);
        $this->fake->serveTools([['name' => 'other']]);

        self::assertFalse($this->scanner()->syncOne($tool));

        self::assertSame(['search'], array_keys($this->toolsByName($docs)));
        self::assertStringContainsString('no longer listed', (string) $tool->getLastError());
        self::assertEquals(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), $tool->getSyncedAt());
        self::assertSame('Search', $tool->getDescription());
    }

    public function testSyncRecordsTransportError(): void
    {
        $docs = $this->addConnection('docs', self::DOCS);
        $tool = $this->addTool($docs, 'search');
        $this->fake->failWith(new McpException('Connection refused'));

        self::assertFalse($this->scanner()->syncOne($tool));

        self::assertSame('Connection refused', $tool->getLastError());
        self::assertEquals(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), $tool->getSyncedAt());
    }

    public function testSyncOfInactiveConnectionMakesNoTransportCall(): void
    {
        $old = $this->addConnection('old', self::OLD, active: false);
        $tool = $this->addTool($old, 'legacy');

        self::assertFalse($this->scanner()->syncOne($tool));

        self::assertStringContainsString('inactive', (string) $tool->getLastError());
        self::assertSame(0, $this->fake->getTransportCount());
    }

    private function scanner(): McpToolScanner
    {
        return new McpToolScanner($this->entityManager(), $this->fake, ConfigSecretsFixture::create());
    }
}

// - revised 2026-10-09 06:42:28 UTC
