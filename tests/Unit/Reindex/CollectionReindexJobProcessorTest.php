<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:37:11 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Reindex;

use Dmstr\NeuronAiBundle\Embeddings\EmbeddingConnectionException;
use Dmstr\NeuronAiBundle\JobProcessor\CollectionReindexJobProcessor;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\InMemoryReindexSource;
use Dmstr\NeuronAiBundle\Tests\Support\ReindexTestTrait;
use Dmstr\SymfonyJobQueue\Entity\Job;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the `collection_reindex` job processor: job type,
 * the no-op for a job that no longer matches the pending reindex, and the
 * delegation to a real reindexer (with in-memory fakes) including
 * progress and resultData.
 */
final class CollectionReindexJobProcessorTest extends TestCase
{
    use ReindexTestTrait;

    protected function setUp(): void
    {
        $this->setUpReindex();
    }

    public function testSupportsOnlyTheReindexJobType(): void
    {
        $processor = $this->processor($this->source([]));

        self::assertTrue($processor->supports('collection_reindex'));
        self::assertFalse($processor->supports('mcp_tool_scan'));
    }

    public function testRunsTheReindexAndWritesProgressAndResult(): void
    {
        $collection = $this->collection(pending: 'mxbai-embed-large');
        $job = self::job(['collection' => (string) $collection->getId(), 'model' => 'mxbai-embed-large']);
        $collection->setReindexJobId($job->getId());
        $source = $this->source(['a', 'b']);

        $this->processor($source)->process($job);

        self::assertSame('mxbai-embed-large', $collection->getEmbeddingModel());
        self::assertNull($collection->getPendingEmbeddingModel());
        self::assertSame(100, $job->getProgress());
        self::assertSame(
            ['total' => 2, 'done' => 2, 'skipped' => 0, 'chunks' => 4, 'failed' => [], 'cancelled' => false],
            $job->getResultData(),
        );
        // Progress written once at the end, then the swap's flush.
        self::assertSame(2, $this->flushes);
    }

    public function testFailureKeepsTheReportAndPropagates(): void
    {
        $collection = $this->collection(pending: 'mxbai-embed-large');
        $job = self::job(['collection' => (string) $collection->getId(), 'model' => 'mxbai-embed-large']);

        try {
            $this->processor($this->source(['a', 'b'])->fail('a'))->process($job);
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('failed for 1 of 2 documents', $e->getMessage());
        }

        self::assertSame(1, $job->getResultData()['done']);
        self::assertSame('a', $job->getResultData()['failed'][0]['sourceName']);
        self::assertSame('mxbai-embed-large', $collection->getPendingEmbeddingModel());
    }

    public function testInactiveTargetConnectionFailsTheJobAndKeepsThePendingPair(): void
    {
        $collection = $this->collection(pending: 'mxbai-embed-large', pendingConnection: ProviderConnectionFixtures::ollama('fake-ollama-off', active: false));
        $job = self::job(['collection' => (string) $collection->getId(), 'model' => 'mxbai-embed-large']);
        $source = $this->source(['a']);

        try {
            $this->processor($source)->process($job);
            self::fail('Expected an EmbeddingConnectionException.');
        } catch (EmbeddingConnectionException $e) {
            self::assertStringContainsString('"fake-ollama-off" is inactive', $e->getMessage());
        }

        self::assertSame([], $source->embedded);
        self::assertSame('mxbai-embed-large', $collection->getPendingEmbeddingModel());
        self::assertSame('nomic-embed-text', $collection->getEmbeddingModel());
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function staleJobs(): iterable
    {
        yield 'completed or cancelled' => [null, null];
        yield 'another model pending' => ['other-model', null];
        yield 'another job took over' => ['mxbai-embed-large', 'another-job-id'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('staleJobs')]
    public function testStaleJobIsANoOp(?string $pending, ?string $jobId): void
    {
        $collection = $this->collection(pending: $pending)->setReindexJobId($jobId);
        $job = self::job(['collection' => (string) $collection->getId(), 'model' => 'mxbai-embed-large']);
        $source = $this->source(['a']);

        $this->processor($source)->process($job);

        self::assertSame(['skipped' => 'no pending reindex to mxbai-embed-large'], $job->getResultData());
        self::assertSame([], $source->embedded);
        self::assertSame('nomic-embed-text', $collection->getEmbeddingModel());
    }

    public function testMissingCollectionFailsTheJob(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $this->processor($this->source([]))->process(self::job(['collection' => '0199a1b2-0000-7000-8000-000000000000', 'model' => 'mxbai-embed-large']));
    }

    private function processor(InMemoryReindexSource $source): CollectionReindexJobProcessor
    {
        return new CollectionReindexJobProcessor($this->reindexer($source), $this->entityManager());
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function job(array $input): Job
    {
        return new Job()->setType('collection_reindex')->setInputData($input)->setStatus('running');
    }
}
