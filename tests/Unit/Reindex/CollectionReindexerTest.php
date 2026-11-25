<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:37:11 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Reindex;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingConnectionException;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Reindex\CollectionReindexer;
use Dmstr\NeuronAiBundle\Reindex\ReindexReport;
use Dmstr\NeuronAiBundle\Reindex\ReindexRequestException;
use Dmstr\NeuronAiBundle\Testing\FakeEmbeddingsProvider;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\ReindexTestTrait;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the all-or-nothing reindex: probe, skip of
 * documents already present, failure without swap, cancel, swap and
 * cleanup of the old model's rows; the target as a catalogue entry, which
 * names the connection and whose registered name is the label (another
 * connection only with another label).
 */
final class CollectionReindexerTest extends TestCase
{
    use ReindexTestTrait;

    protected function setUp(): void
    {
        $this->setUpReindex();
    }

    public function testStartMarksThePendingModelAndClearsTheJobId(): void
    {
        $collection = $this->collection()->setReindexJobId('old-job');
        $target = $this->entry('mxbai-embed-large');

        $this->reindexer($this->source([]))->start($collection, $target);

        self::assertSame('mxbai-embed-large', $collection->getPendingEmbeddingModel(), 'The pending label is the registered name of the entry mxbai-embed-large:latest.');
        self::assertSame($target, $collection->getPendingEmbeddingProviderModel());
        self::assertSame($this->ollama, $collection->getPendingEmbeddingProviderConnection(), 'The entry names the target connection.');
        self::assertSame($target->iri(), $collection->getPendingEmbeddingModelReference());
        self::assertNull($collection->getReindexJobId());
        self::assertSame('nomic-embed-text', $collection->getEmbeddingModel());
        self::assertSame(1, $this->flushes);
    }

    public function testStartWithAModelOfAnotherConnectionMovesToThatConnection(): void
    {
        $collection = $this->collection();
        $target = $this->entry('mxbai-embed-large', $this->remote);

        $this->reindexer($this->source([]))->start($collection, $target);

        self::assertSame('mxbai-embed-large', $collection->getPendingEmbeddingModel());
        self::assertSame($this->remote, $collection->getPendingEmbeddingProviderConnection());
        self::assertSame($this->ollama, $collection->getEmbeddingProviderConnection());
        self::assertSame('fake-ollama-remote', $collection->getPendingEmbeddingConnection());
    }

    public function testStartWithTheCurrentLabelOnAnotherConnectionIsRejected(): void
    {
        $collection = $this->collection();

        try {
            $this->reindexer($this->source([]))->start($collection, $this->entry('nomic-embed-text', $this->remote));
            self::fail('Expected a ReindexRequestException.');
        } catch (ReindexRequestException $e) {
            self::assertSame(ReindexRequestException::FIELD_MODEL, $e->field);
            self::assertStringContainsString('already uses the embedding model "nomic-embed-text" on connection "fake-ollama"', $e->getMessage());
            self::assertStringContainsString('a different connection needs a different model as well', $e->getMessage());
        }

        self::assertNull($collection->getPendingEmbeddingModel());
        self::assertNull($collection->getPendingEmbeddingProviderModel());
        self::assertNull($collection->getPendingEmbeddingProviderConnection());
        self::assertSame(0, $this->flushes);
    }

    /**
     * The label decides, not the entry: an untagged entry of the current
     * label is the current model as well.
     */
    public function testStartWithTheCurrentLabelIsRejected(): void
    {
        $collection = $this->collection();

        foreach ([$this->entry('nomic-embed-text'), new ProviderModel($this->ollama, 'nomic-embed-text')] as $target) {
            try {
                $this->reindexer($this->source([]))->start($collection, $target);
                self::fail('Expected a ReindexRequestException.');
            } catch (ReindexRequestException $e) {
                self::assertSame(ReindexRequestException::FIELD_MODEL, $e->field);
                self::assertSame('Collection "docs-de" already uses the embedding model "nomic-embed-text".', $e->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{\Closure(self): array{\Dmstr\NeuronAiBundle\Entity\Collection, ProviderModel}, string}>
     */
    public static function rejectedTargets(): iterable
    {
        yield 'unregistered name' => [
            static fn (self $test): array => [$test->collection(), new ProviderModel($test->ollama, 'all-minilm')],
            'Model "all-minilm" of connection "fake-ollama" is no registered embedding model',
        ];
        yield 'inactive connection' => [
            static fn (self $test): array => [$test->collection(), $test->entry('mxbai-embed-large', ProviderConnectionFixtures::ollama('fake-ollama-off', active: false))],
            'is inactive',
        ];
        yield 'anthropic connection' => [
            static fn (self $test): array => [$test->collection(), $test->entry('mxbai-embed-large', ProviderConnectionFixtures::anthropic())],
            'cannot serve embeddings',
        ];
        yield 'same model pending on another connection' => [
            static fn (self $test): array => [$test->collection(pending: 'mxbai-embed-large', pendingConnection: $test->remote), $test->entry('mxbai-embed-large')],
            'a reindex to "mxbai-embed-large" on connection "fake-ollama-remote" is pending; cancel it first',
        ];
        yield 'other model pending' => [
            static fn (self $test): array => [$test->collection(pending: 'other-model'), $test->entry('mxbai-embed-large')],
            'a reindex to "other-model" on connection "fake-ollama" is pending; cancel it first',
        ];
    }

    /**
     * @param \Closure(self): array{\Dmstr\NeuronAiBundle\Entity\Collection, ProviderModel} $arrange
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedTargets')]
    public function testStartRejectsTheTargetAtModel(\Closure $arrange, string $message): void
    {
        [$collection, $target] = $arrange($this);
        $pending = $collection->getPendingEmbeddingModel();
        $pendingEntry = $collection->getPendingEmbeddingProviderModel();

        try {
            $this->reindexer($this->source([]))->start($collection, $target);
            self::fail('Expected a ReindexRequestException.');
        } catch (ReindexRequestException $e) {
            self::assertSame(ReindexRequestException::FIELD_MODEL, $e->field);
            self::assertStringContainsString($message, $e->getMessage());
        }

        self::assertSame($pending, $collection->getPendingEmbeddingModel());
        self::assertSame($pendingEntry, $collection->getPendingEmbeddingProviderModel());
        self::assertSame(0, $this->flushes);
    }

    public function testStartResumesTheSamePendingModel(): void
    {
        $collection = $this->collection(pending: 'mxbai-embed-large');

        // Also with the untagged entry of the same label on the same connection.
        $this->reindexer($this->source([]))->start($collection, new ProviderModel($this->ollama, 'mxbai-embed-large'));

        self::assertSame('mxbai-embed-large', $collection->getPendingEmbeddingModel());
        self::assertSame(1, $this->flushes);
    }

    public function testProbeWithAWrongDimensionThrowsAndCancels(): void
    {
        $collection = $this->collection(pending: 'mxbai-embed-large');
        $source = $this->source(['a']);

        try {
            $this->reindexer($source, new WrongDimensionProviderFactory($this->registry))
                ->run($collection, 'mxbai-embed-large', new ReindexReport());
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('returns vectors of 512 values, but 1024 are registered', $e->getMessage());
        }

        self::assertSame([], $source->embedded);
        self::assertNull($collection->getPendingEmbeddingModel());
        self::assertSame('nomic-embed-text', $collection->getEmbeddingModel());
        self::assertSame([['mxbai-embed-large', ['collection_id' => (string) $collection->getId()]]], $this->store->state()->deleteByModelCalls);
    }

    public function testHappyPathSwapsTheModelAndDeletesTheOldRows(): void
    {
        $collection = $this->collection(pending: 'mxbai-embed-large')->setReindexJobId('job-1');
        $other = $this->collection('docs-en');
        $state = $this->store->state();
        $state->addRows('nomic-embed-text', 'chunk', 'a', 2, ['collection_id' => (string) $collection->getId()]);
        $state->addRows('nomic-embed-text', 'chunk', 'x', 1, ['collection_id' => (string) $other->getId()]);
        $source = $this->source(['a', 'b', 'c'], chunksPerDocument: 3);
        $progress = [];

        $report = new ReindexReport();
        $this->reindexer($source)->run($collection, 'mxbai-embed-large', $report, static function (ReindexReport $report) use (&$progress): void {
            $progress[] = $report->progress();
        });

        self::assertSame('mxbai-embed-large', $collection->getEmbeddingModel());
        self::assertSame(1024, $collection->getEmbeddingDimension());
        self::assertNull($collection->getPendingEmbeddingModel());
        self::assertNull($collection->getPendingEmbeddingProviderConnection());
        self::assertSame($this->ollama, $collection->getEmbeddingProviderConnection());
        self::assertSame($this->entry('mxbai-embed-large'), $collection->getEmbeddingProviderModel(), 'The pending entry becomes the current one.');
        self::assertNull($collection->getPendingEmbeddingProviderModel());
        self::assertNull($collection->getReindexJobId());
        self::assertSame([['mxbai-embed-large', 1024]], $state->ensuredIndexes);
        self::assertSame([['a', 'mxbai-embed-large'], ['b', 'mxbai-embed-large'], ['c', 'mxbai-embed-large']], $source->embedded);
        self::assertSame([['nomic-embed-text', ['collection_id' => (string) $collection->getId()]]], $state->deleteByModelCalls);
        // The other collection's rows of the old model are untouched.
        self::assertCount(1, $state->rowsOf('nomic-embed-text'));
        self::assertCount(9, $state->rowsOf('mxbai-embed-large'));
        self::assertSame(
            ['total' => 3, 'done' => 3, 'skipped' => 0, 'chunks' => 9, 'failed' => [], 'cancelled' => false],
            $report->toArray(),
        );
        self::assertSame([100], $progress);
    }

    public function testRunEmbedsThroughThePendingConnectionAndSwapsToIt(): void
    {
        $collection = $this->collection(pending: 'mxbai-embed-large', pendingConnection: $this->remote);
        $source = $this->source(['a', 'b']);
        $factory = new FakeProviderFactory($this->registry);

        $this->reindexer($source, $factory)->run($collection, 'mxbai-embed-large', new ReindexReport());

        // The probe goes through the target connection, and so does every document.
        self::assertSame([['provider' => 'ollama', 'connection' => 'fake-ollama-remote', 'model' => 'mxbai-embed-large']], $factory->getRequestedEmbeddings());
        self::assertSame(['fake-ollama-remote', 'fake-ollama-remote'], $source->connections);
        self::assertSame('mxbai-embed-large', $collection->getEmbeddingModel());
        self::assertSame($this->remote, $collection->getEmbeddingProviderConnection());
        self::assertSame($this->entry('mxbai-embed-large', $this->remote), $collection->getEmbeddingProviderModel());
        self::assertSame('fake-ollama-remote', $collection->getEmbeddingConnection());
        self::assertNull($collection->getPendingEmbeddingProviderConnection());
    }

    public function testRunOnAnInactivePendingConnectionFailsAndKeepsThePendingPair(): void
    {
        $inactive = ProviderConnectionFixtures::ollama('fake-ollama-off', active: false);
        $collection = $this->collection(pending: 'mxbai-embed-large', pendingConnection: $inactive);
        $source = $this->source(['a']);

        try {
            $this->reindexer($source)->run($collection, 'mxbai-embed-large', new ReindexReport());
            self::fail('Expected an EmbeddingConnectionException.');
        } catch (EmbeddingConnectionException $e) {
            self::assertSame(EmbeddingConnectionException::INACTIVE, $e->reason);
            self::assertStringContainsString('Embedding connection "fake-ollama-off" is inactive', $e->getMessage());
        }

        self::assertSame([], $source->embedded);
        self::assertSame('mxbai-embed-large', $collection->getPendingEmbeddingModel());
        self::assertSame($inactive, $collection->getPendingEmbeddingProviderConnection());
        self::assertSame('nomic-embed-text', $collection->getEmbeddingModel());
        self::assertSame([], $this->store->state()->deleteByModelCalls);
    }

    public function testRunOfAPendingModelWithoutPendingConnectionFails(): void
    {
        $collection = $this->collection(pending: 'mxbai-embed-large', pendingConnection: false);
        $source = $this->source(['a']);

        try {
            $this->reindexer($source)->run($collection, 'mxbai-embed-large', new ReindexReport());
            self::fail('Expected an EmbeddingConnectionException.');
        } catch (EmbeddingConnectionException $e) {
            self::assertSame(EmbeddingConnectionException::NO_PENDING, $e->reason);
            self::assertStringContainsString('without a pending connection or catalogue entry', $e->getMessage());
        }

        self::assertSame([], $source->embedded);
        self::assertSame('mxbai-embed-large', $collection->getPendingEmbeddingModel());
    }

    /**
     * A pending label with a connection but no entry (queued before the
     * model reference, not migrated) is refused before anything is
     * embedded: the swap would otherwise leave the collection without an
     * entry, and `embedding_model_id` is NOT NULL.
     */
    public function testRunOfAPendingModelWithoutPendingEntryFails(): void
    {
        $collection = $this->collection(pending: 'mxbai-embed-large', pendingConnection: false)
            ->setPendingEmbeddingProviderConnection($this->ollama);
        $source = $this->source(['a']);

        try {
            $this->reindexer($source)->run($collection, 'mxbai-embed-large', new ReindexReport());
            self::fail('Expected an EmbeddingConnectionException.');
        } catch (EmbeddingConnectionException $e) {
            self::assertSame(EmbeddingConnectionException::NO_PENDING, $e->reason);
            self::assertStringContainsString('without a pending connection or catalogue entry', $e->getMessage());
        }

        self::assertSame([], $source->embedded);
        self::assertSame('mxbai-embed-large', $collection->getPendingEmbeddingModel());
        self::assertSame('nomic-embed-text', $collection->getEmbeddingModel());
        self::assertNotNull($collection->getEmbeddingProviderModel());
    }

    public function testRunOfAModelThatIsNotPendingEndsAsCancelled(): void
    {
        $collection = $this->collection();
        $source = $this->source(['a']);
        $factory = new FakeProviderFactory($this->registry);
        $report = new ReindexReport();

        $this->reindexer($source, $factory)->run($collection, 'mxbai-embed-large', $report);

        self::assertTrue($report->cancelled);
        self::assertSame([], $source->embedded);
        self::assertSame([], $factory->getRequestedEmbeddings());
        self::assertSame('nomic-embed-text', $collection->getEmbeddingModel());
    }

    public function testDocumentsAlreadyPresentForTheTargetModelAreSkipped(): void
    {
        $collection = $this->collection(pending: 'mxbai-embed-large');
        $this->store->state()->addRows('mxbai-embed-large', 'chunk', 'b', 2, ['collection_id' => (string) $collection->getId()]);
        $source = $this->source(['a', 'b', 'c']);

        $report = new ReindexReport();
        $this->reindexer($source)->run($collection, 'mxbai-embed-large', $report);

        self::assertSame([['a', 'mxbai-embed-large'], ['c', 'mxbai-embed-large']], $source->embedded);
        self::assertSame(2, $report->done);
        self::assertSame(1, $report->skipped);
        self::assertSame(4, $report->chunks);
        self::assertSame('mxbai-embed-large', $collection->getEmbeddingModel());
    }

    public function testFailingDocumentKeepsThePendingModelAndDoesNotSwap(): void
    {
        $collection = $this->collection(pending: 'mxbai-embed-large');
        $source = $this->source(['a', 'b', 'c'])->fail('b');
        $report = new ReindexReport();

        try {
            $this->reindexer($source)->run($collection, 'mxbai-embed-large', $report);
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('failed for 1 of 3 documents; pending model and connection kept, run again to resume', $e->getMessage());
        }

        // The loop went on after the failure.
        self::assertSame(['a', 'b', 'c'], array_column($source->embedded, 0));
        self::assertSame('nomic-embed-text', $collection->getEmbeddingModel());
        self::assertSame('mxbai-embed-large', $collection->getPendingEmbeddingModel());
        self::assertSame([], $this->store->state()->deleteByModelCalls);
        self::assertSame([['sourceType' => 'chunk', 'sourceName' => 'b', 'error' => 'Embedding of b failed.']], $report->failed);
        self::assertSame(2, $report->done);
        self::assertTrue($report->hasFailures());

        // A second run resumes: only the failed document is embedded.
        $source = $this->source(['a', 'b', 'c']);
        $report = new ReindexReport();
        $this->reindexer($source)->run($collection, 'mxbai-embed-large', $report);

        self::assertSame([['b', 'mxbai-embed-large']], $source->embedded);
        self::assertSame(2, $report->skipped);
        self::assertSame('mxbai-embed-large', $collection->getEmbeddingModel());
    }

    public function testCancelDuringTheRunStopsWithoutSwap(): void
    {
        $collection = $this->collection(pending: 'mxbai-embed-large');
        $reindexer = null;
        $source = $this->source(['a', 'b', 'c', 'd'])->before('b', function ($collection) use (&$reindexer): void {
            $reindexer->cancel($collection);
        });
        $reindexer = $this->reindexer($source);

        $report = new ReindexReport();
        $reindexer->run($collection, 'mxbai-embed-large', $report);

        self::assertTrue($report->cancelled);
        // b was being embedded when the cancel happened; c and d are not.
        self::assertSame(['a', 'b'], array_column($source->embedded, 0));
        self::assertSame('nomic-embed-text', $collection->getEmbeddingModel());
        self::assertSame(768, $collection->getEmbeddingDimension());
        self::assertNull($collection->getPendingEmbeddingModel());
        // Rows written after the cancel are discarded again.
        self::assertSame([], $this->store->state()->rowsOf('mxbai-embed-large'));
        self::assertNotContains(['nomic-embed-text', ['collection_id' => (string) $collection->getId()]], $this->store->state()->deleteByModelCalls);
    }

    public function testProgressIsReportedEveryFlushIntervalAndAtTheEnd(): void
    {
        $collection = $this->collection(pending: 'mxbai-embed-large');
        $documents = array_map(static fn (int $i): string => 'doc-'.$i, range(1, CollectionReindexer::FLUSH_EVERY * 2 + 3));
        $progress = [];

        $this->reindexer($this->source($documents, chunksPerDocument: 1))->run($collection, 'mxbai-embed-large', new ReindexReport(), static function (ReindexReport $report) use (&$progress): void {
            $progress[] = [$report->processed(), $report->progress()];
        });

        self::assertSame([[25, 47], [50, 94], [53, 100]], $progress);
    }

    public function testEmptyCollectionSwapsRightAway(): void
    {
        $collection = $this->collection(pending: 'mxbai-embed-large');
        $report = new ReindexReport();

        $this->reindexer($this->source([]))->run($collection, 'mxbai-embed-large', $report);

        self::assertSame(100, $report->progress());
        self::assertSame('mxbai-embed-large', $collection->getEmbeddingModel());
    }

    public function testCancelClearsThePendingModelAndDeletesItsRows(): void
    {
        $collection = $this->collection(pending: 'mxbai-embed-large')->setReindexJobId('job-1');
        $this->store->state()->addRows('mxbai-embed-large', 'chunk', 'a', 2, ['collection_id' => (string) $collection->getId()]);

        $this->reindexer($this->source([]))->cancel($collection);

        self::assertNull($collection->getPendingEmbeddingModel());
        self::assertNull($collection->getPendingEmbeddingProviderModel());
        self::assertNull($collection->getPendingEmbeddingProviderConnection());
        self::assertNull($collection->getReindexJobId());
        self::assertSame('nomic-embed-text', $collection->getEmbeddingModel());
        self::assertSame([['mxbai-embed-large', ['collection_id' => (string) $collection->getId()]]], $this->store->state()->deleteByModelCalls);
        self::assertSame([], $this->store->state()->rowsOf('mxbai-embed-large'));
        self::assertSame(1, $this->flushes);
    }

    public function testCancelWithoutPendingModelIsALogicError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has no pending reindex');

        $this->reindexer($this->source([]))->cancel($this->collection());
    }
}

/**
 * Provider factory whose fakes return 512 values whatever the registry
 * says: a model registered with the wrong dimension.
 */
final class WrongDimensionProviderFactory extends FakeProviderFactory
{
    public function embeddingsProvider(ApiConfiguration $connection, string $model): EmbeddingsProviderInterface
    {
        return new FakeEmbeddingsProvider($model, 512, $this->getEmbeddingsRecorder());
    }
}
