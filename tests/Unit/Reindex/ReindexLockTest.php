<?php

// file generated with AI assistance: Claude Code - 2026-10-13 19:06:18 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Reindex;

use Dmstr\NeuronAiBundle\Tests\Support\CollectionLockGuardStubs;
use Dmstr\NeuronAiBundle\Tests\Support\InMemoryReindexSource;
use Dmstr\NeuronAiBundle\Tests\Support\ReindexTestTrait;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingIndexManager;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingsProviderLocator;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\JobProcessor\CollectionReindexJobProcessor;
use Dmstr\NeuronAiBundle\Knowledge\CollectionLockedException;
use Dmstr\NeuronAiBundle\Reindex\CollectionReindexer;
use Dmstr\NeuronAiBundle\Reindex\ReindexReport;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Kernel-free checks of the collection lock in {@see CollectionReindexer}
 * and {@see CollectionReindexJobProcessor}: start and run refuse a locked
 * collection before any work; a lock set during the run ends it at the
 * next checkpoint or at the swap, deletes the rows written for the pending
 * model and leaves the collection's current and pending model as they
 * were; the job ends unrecoverable with a clear message; cancel is not
 * refused by the reindexer.
 *
 * The guard is real over a stubbed DBAL connection (the stored row at the
 * start); a lock during the run is set on the entity, which the
 * checkpoint's and the swap's refresh re-read in production.
 */
final class ReindexLockTest extends TestCase
{
    use CollectionLockGuardStubs;
    use ReindexTestTrait;

    private const string TARGET = 'mxbai-embed-large';

    /** Whether refresh() with a pessimistic lock (the swap) locks the collection. */
    private bool $lockAtSwap = false;

    protected function setUp(): void
    {
        $this->setUpReindex();
    }

    public function testStartRefusesALockedCollection(): void
    {
        $collection = $this->collection();

        try {
            $this->lockingReindexer($this->source([]), true)->start($collection, $this->entry(self::TARGET));
            self::fail('The reindex of a locked collection was started.');
        } catch (CollectionLockedException $e) {
            self::assertSame(423, $e->getStatusCode());
            self::assertSame('Collection "docs-de" is locked', $e->getMessage());
        }

        self::assertNull($collection->getPendingEmbeddingModel());
        self::assertSame(0, $this->flushes);
    }

    public function testRunRefusesALockedCollectionBeforeAnyWork(): void
    {
        $collection = $this->collection(pending: self::TARGET);
        $source = $this->source(['a', 'b']);
        $factory = new FakeProviderFactory($this->registry);

        $this->expectException(CollectionLockedException::class);

        try {
            $this->lockingReindexer($source, true, $factory)->run($collection, self::TARGET, new ReindexReport());
        } finally {
            self::assertSame([], $factory->getRequestedEmbeddings(), 'No probe.');
            self::assertSame([], $source->embedded);
            self::assertSame([], $this->store->state()->deleteByModelCalls);
            self::assertSame(self::TARGET, $collection->getPendingEmbeddingModel());
        }
    }

    public function testALockDuringTheRunEndsItAtTheNextCheckpoint(): void
    {
        $collection = $this->collection(pending: self::TARGET);
        $documents = array_map(static fn (int $i): string => 'doc-'.$i, range(1, CollectionReindexer::FLUSH_EVERY + 5));
        $source = $this->source($documents)->before('doc-10', static function (Collection $collection): void {
            $collection->lock('admin', new \DateTimeImmutable());
        });
        $report = new ReindexReport();

        try {
            $this->lockingReindexer($source, false)->run($collection, self::TARGET, $report);
            self::fail('The run went on after the lock.');
        } catch (CollectionLockedException $e) {
            self::assertSame('Collection "docs-de" is locked', $e->getMessage());
        }

        // The documents up to the checkpoint, none after it.
        self::assertCount(CollectionReindexer::FLUSH_EVERY, $source->embedded);
        $this->assertAbortedCleanly($collection);
    }

    public function testALockBeforeTheSwapEndsTheRunWithoutSwap(): void
    {
        $collection = $this->collection(pending: self::TARGET)->setReindexJobId('job-1');
        $source = $this->source(['a', 'b', 'c']);
        $this->lockAtSwap = true;

        $this->expectException(CollectionLockedException::class);

        try {
            $this->lockingReindexer($source, false)->run($collection, self::TARGET, new ReindexReport());
        } finally {
            self::assertSame(['a', 'b', 'c'], array_column($source->embedded, 0));
            self::assertSame('job-1', $collection->getReindexJobId());
            $this->assertAbortedCleanly($collection);
        }
    }

    public function testTheJobOfALockedCollectionFailsWithoutRetry(): void
    {
        $collection = $this->collection(pending: self::TARGET);
        $processor = new CollectionReindexJobProcessor($this->lockingReindexer($this->source(['a']), true), $this->lockingEntityManager());
        $job = new Job()->setType('collection_reindex')->setInputData(['collection' => (string) $collection->getId(), 'model' => self::TARGET])->setStatus('running');

        try {
            $processor->process($job);
            self::fail('The job of a locked collection ran.');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertSame('Reindex of collection "docs-de" to "mxbai-embed-large" stopped: Collection "docs-de" is locked', $e->getMessage());
            self::assertInstanceOf(CollectionLockedException::class, $e->getPrevious());
        }

        self::assertSame(['total' => 0, 'done' => 0, 'skipped' => 0, 'chunks' => 0, 'failed' => [], 'cancelled' => false], $job->getResultData());
    }

    /**
     * Discarding an unfinished reindex stays possible; the
     * reindexer does not check the lock for it.
     */
    public function testCancelIsNotRefusedByTheReindexer(): void
    {
        $collection = $this->collection(pending: self::TARGET);
        $collection->lock('admin', new \DateTimeImmutable());

        $this->lockingReindexer($this->source([]), true)->cancel($collection);

        self::assertNull($collection->getPendingEmbeddingModel());
        self::assertSame([[self::TARGET, ['collection_id' => (string) $collection->getId()]]], $this->store->state()->deleteByModelCalls);
    }

    /**
     * The rows written for the target are gone, the collection keeps its
     * current model and its pending pair (a locked collection refuses every
     * other change), and the old model's rows are untouched.
     */
    private function assertAbortedCleanly(Collection $collection): void
    {
        self::assertSame([], $this->store->state()->rowsOf(self::TARGET));
        self::assertSame([[self::TARGET, ['collection_id' => (string) $collection->getId()]]], $this->store->state()->deleteByModelCalls);
        self::assertSame('nomic-embed-text', $collection->getEmbeddingModel());
        self::assertSame(768, $collection->getEmbeddingDimension());
        self::assertSame(self::TARGET, $collection->getPendingEmbeddingModel());
        self::assertSame($this->entry(self::TARGET), $collection->getPendingEmbeddingProviderModel());
    }

    private function lockingReindexer(InMemoryReindexSource $source, bool $storedLock, ?FakeProviderFactory $factory = null): CollectionReindexer
    {
        return new CollectionReindexer(
            $source,
            new EmbeddingsProviderLocator($factory ?? new FakeProviderFactory($this->registry), $this->registry),
            $this->registry,
            $this->store,
            new EmbeddingIndexManager($this->store, $this->registry),
            $this->lockingEntityManager(),
            null,
            $this->lockGuard($storedLock),
        );
    }

    /**
     * The trait's entity manager, plus a refresh() that locks the
     * collection at the swap when {@see $lockAtSwap} is set.
     */
    private function lockingEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->entityManager();
        \assert($entityManager instanceof Stub);
        $entityManager->method('refresh')->willReturnCallback(function (object $entity, mixed $lockMode = null): void {
            if ($this->lockAtSwap && LockMode::PESSIMISTIC_WRITE === $lockMode && $entity instanceof Collection) {
                $entity->lock('admin', new \DateTimeImmutable());
            }
        });

        return $entityManager;
    }
}
