<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:41:04 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp\Catalogue;

use Dmstr\NeuronAiBundle\JobProcessor\McpToolScanJobProcessor;
use Dmstr\NeuronAiBundle\Mcp\McpToolScanner;
use Dmstr\NeuronAiBundle\Testing\ConfigSecretsFixture;
use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use Dmstr\NeuronAiBundle\Tests\Support\InMemoryCatalogueTrait;
use Dmstr\SymfonyJobQueue\Entity\Job;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the `mcp_tool_scan` job processor: job type,
 * dispatching to the scanner and the job result / failure contract.
 */
final class McpToolScanJobProcessorTest extends TestCase
{
    use InMemoryCatalogueTrait;

    private const string DOCS = 'https://docs.example.org/mcp';
    private const string WIKI = 'https://wiki.example.org/mcp';

    private FakeMcpTransportFactory $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeMcpTransportFactory();
    }

    public function testSupportsOnlyTheScanJobType(): void
    {
        $processor = $this->processor();

        self::assertTrue($processor->supports('mcp_tool_scan'));
        self::assertFalse($processor->supports('project_scan'));
    }

    public function testEmptyInputScansAllConnectionsAndStoresTheSummary(): void
    {
        $docs = $this->addConnection('docs', self::DOCS);
        $wiki = $this->addConnection('wiki', self::WIKI);
        $this->fake->serveTools([['name' => 'search', 'inputSchema' => ['type' => 'object']]]);
        $job = self::job([]);

        $this->processor()->process($job);

        self::assertSame(2, $job->getResultData()['scanned']);
        self::assertSame(2, $job->getResultData()['created']);
        self::assertSame(['search'], array_keys($this->toolsByName($docs)));
        self::assertSame(['search'], array_keys($this->toolsByName($wiki)));
    }

    public function testConnectionInputScansOnlyThatConnection(): void
    {
        $docs = $this->addConnection('docs', self::DOCS);
        $this->addConnection('wiki', self::WIKI);
        $job = self::job(['apiConfiguration' => (string) $docs->getId()]);

        $this->processor()->process($job);

        self::assertSame(['docs'], array_column($job->getResultData()['connections'], 'name'));
        self::assertSame(0, $this->fake->getRequestCount(self::WIKI));
    }

    public function testConnectionDeactivatedAfterQueueingIsSkipped(): void
    {
        $docs = $this->addConnection('docs', self::DOCS, active: false);
        $job = self::job(['apiConfiguration' => (string) $docs->getId()]);

        $this->processor()->process($job);

        self::assertSame(1, $job->getResultData()['skipped']);
        self::assertSame(0, $this->fake->getTransportCount());
    }

    public function testMissingConnectionFailsTheJob(): void
    {
        $job = self::job(['apiConfiguration' => '0199a1b2-0000-7000-8000-000000000000']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $this->processor()->process($job);
    }

    public function testPartialFailureCompletesWithErrorsInTheSummary(): void
    {
        $this->addConnection('docs', self::DOCS);
        $this->addConnection('wiki', self::WIKI);
        $this->fake->failWith(new \RuntimeException('Connection refused'), self::DOCS);
        $job = self::job([]);

        $this->processor()->process($job);

        self::assertSame(1, $job->getResultData()['failed']);
        self::assertSame(1, $job->getResultData()['scanned']);
        self::assertSame(McpToolScanner::STATUS_ERROR, $job->getResultData()['connections'][0]['status']);
    }

    public function testTotalFailureThrowsAndKeepsTheSummary(): void
    {
        $this->addConnection('docs', self::DOCS);
        $this->fake->failWith(new \RuntimeException('Connection refused'));
        $job = self::job([]);

        try {
            $this->processor()->process($job);
            self::fail('Expected the job to fail.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('docs: Connection refused', $e->getMessage());
        }

        self::assertSame(1, $job->getResultData()['failed']);
    }

    private function processor(): McpToolScanJobProcessor
    {
        $entityManager = $this->entityManager();

        return new McpToolScanJobProcessor(new McpToolScanner($entityManager, $this->fake, ConfigSecretsFixture::create()), $entityManager);
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function job(array $input): Job
    {
        return (new Job())->setType('mcp_tool_scan')->setInputData($input)->setStatus('running');
    }
}
