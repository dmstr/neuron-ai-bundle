<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\ProviderConnection\Catalogue;

use Dmstr\NeuronAiBundle\JobProcessor\ProviderModelScanJobProcessor;
use Dmstr\NeuronAiBundle\Provider\ProviderModelScanner;
use Dmstr\NeuronAiBundle\Testing\FakeModelDescriber;
use Dmstr\NeuronAiBundle\Tests\Support\InMemoryProviderCatalogueTrait;
use Dmstr\SymfonyJobQueue\Entity\Job;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Kernel-free checks of the `provider_model_scan` job: all connections or
 * one, the summary as `resultData`, and the failure contract (throw only
 * when the connection is gone or every scanned connection failed).
 */
final class ProviderModelScanJobProcessorTest extends TestCase
{
    use InMemoryProviderCatalogueTrait;

    private FakeModelDescriber $describer;

    protected function setUp(): void
    {
        $this->describer = new FakeModelDescriber();
    }

    public function testSupportsOnlyTheScanJobType(): void
    {
        self::assertTrue($this->processor()->supports('provider_model_scan'));
        self::assertFalse($this->processor()->supports('mcp_tool_scan'));
    }

    public function testEmptyInputScansAllConnectionsAndStoresTheSummary(): void
    {
        $remote = $this->addOllama('remote');
        $claude = $this->addAnthropic('claude');
        $this->describer->describe('remote', ['name' => 'llama3.1:8b'])->describe('claude', ['name' => 'claude-sonnet-5-5']);
        $job = self::job([]);

        $this->processor()->process($job);

        self::assertSame(2, $job->getResultData()['scanned']);
        self::assertSame(2, $job->getResultData()['created']);
        self::assertSame(['llama3.1:8b'], array_keys($this->modelsByName($remote)));
        self::assertSame(['claude-sonnet-5-5'], array_keys($this->modelsByName($claude)));
    }

    public function testConnectionInputScansOnlyThatConnection(): void
    {
        $remote = $this->addOllama('remote');
        $this->addAnthropic('claude');
        $job = self::job(['apiConfiguration' => (string) $remote->getId()]);

        $this->processor()->process($job);

        self::assertSame(['remote'], $this->describer->getDescribedConnections());
        self::assertSame(['remote'], array_column($job->getResultData()['connections'], 'name'));
    }

    public function testMissingConnectionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist (anymore)');

        $this->processor()->process(self::job(['apiConfiguration' => '00000000-0000-4000-8000-000000000000']));
    }

    public function testAllConnectionsFailingThrowsAndKeepsTheSummary(): void
    {
        $this->addOllama('remote');
        $this->describer->unreachable();
        $job = self::job([]);

        try {
            $this->processor()->process($job);
            self::fail('Expected the job to fail.');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('Provider model scan failed. remote: ', $e->getMessage());
        }

        self::assertSame(1, $job->getResultData()['failed']);
    }

    public function testPartialFailureCompletes(): void
    {
        $this->addOllama('remote');
        $this->addAnthropic('claude');
        $this->describer->unreachable('remote');
        $job = self::job([]);

        $this->processor()->process($job);

        self::assertSame(['failed' => 1, 'scanned' => 1], ['failed' => $job->getResultData()['failed'], 'scanned' => $job->getResultData()['scanned']]);
    }

    /**
     * @param array<string, string> $input
     */
    private static function job(array $input): Job
    {
        return (new Job())->setType('provider_model_scan')->setInputData($input);
    }

    private function processor(): ProviderModelScanJobProcessor
    {
        $entityManager = $this->entityManager();

        return new ProviderModelScanJobProcessor(
            new ProviderModelScanner($entityManager, $this->describer, new LockFactory(new InMemoryStore())),
            $entityManager,
        );
    }
}
