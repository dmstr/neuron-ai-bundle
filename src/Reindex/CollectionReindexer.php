<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:37:11 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Reindex;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingConnectionException;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingIndexManager;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingsProviderLocator;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Knowledge\CollectionLockedException;
use Dmstr\NeuronAiBundle\Knowledge\CollectionLockGuard;
use Dmstr\NeuronPgvectorStore\PgVectorStore;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Moves a collection to another embedding model, which may be on another
 * embedding connection, all or nothing.
 *
 * The target is an entry of the model catalogue ({@see ProviderModel}); it
 * names the target connection, and its registered name
 * ({@see EmbeddingModelRegistry::forEntry()}) is the target label the
 * vectors are stored under. {@see start()} marks entry, label and
 * connection as the collection's pending model; from then on new and
 * changed documents are embedded for both labels (dual write, done by the
 * application's embedding handler). {@see run()} embeds every document for
 * the pending label through the pending connection and, only when all
 * documents succeeded, swaps the collection over in one transaction and
 * deletes the old label's rows of the collection. Until the swap, searches
 * keep using the current model.
 *
 * The connection changes only together with the label: store rows and chunk ids are labelled by the registered name only,
 * so a reindex to the same label would skip every document or overwrite
 * the live vectors, and the same model may yield other vectors on another
 * server. The pending connection is checked like any embedding connection
 * (active `ollama`), so a run on a connection deactivated meanwhile fails
 * with an {@see EmbeddingConnectionException} and keeps the pending model.
 *
 * Resumable: a document that already has rows of the target model is
 * skipped, so running again after a failure or an interruption only embeds
 * what is missing. {@see cancel()} drops the pending model and its rows.
 *
 * Callers: the job processor of `POST /api/admin/collections/{id}/reindex`
 * ({@see \Dmstr\NeuronAiBundle\JobProcessor\CollectionReindexJobProcessor})
 * and the application's console twin. The documents come from the
 * application through {@see ReindexSourceInterface}.
 *
 * Lock: a locked collection is not reindexed. {@see start()} refuses it
 * (423, {@see CollectionLockedException}); {@see run()} refuses it before
 * any work, and a lock set during a run ends the run at the next
 * checkpoint or at the swap: the rows written for the pending model are
 * deleted, the collection keeps its current model and its pending pair
 * (a locked collection refuses every change but the lock itself), and
 * run() throws the {@see CollectionLockedException}. {@see cancel()} stays
 * allowed: it only discards an unfinished reindex.
 *
 * The swap writes the model fields directly and flushes; it does not go
 * through the validator, whose
 * {@see \Dmstr\NeuronAiBundle\Validator\ValidEmbeddingModel} constraint
 * rejects a direct model change on purpose.
 */
final class CollectionReindexer
{
    /** Documents between two progress reports and cancellation checks. */
    public const int FLUSH_EVERY = 25;

    /** Text embedded once before the run to check the model's dimension. */
    private const string PROBE_TEXT = 'probe';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ReindexSourceInterface $source,
        private readonly EmbeddingsProviderLocator $locator,
        private readonly EmbeddingModelRegistry $registry,
        private readonly PgVectorStore $store,
        private readonly EmbeddingIndexManager $indexManager,
        private readonly EntityManagerInterface $entityManager,
        ?LoggerInterface $logger = null,
        // Optional only for kernel-free tests; the container injects it.
        private readonly ?CollectionLockGuard $lockGuard = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Checks the request and marks the catalogue entry $target, its
     * registered name (the label) and its connection as the collection's
     * pending model. The entry was resolved by the caller
     * ({@see \Dmstr\NeuronAiBundle\Provider\ProviderModelResolver} with
     * the capability `embedding`). Starting again with the model that is
     * already pending (the same label on the same connection) is allowed
     * and resumes the reindex. Clears the job id of an earlier reindex; the
     * API processor sets the new one once the job exists.
     *
     * @throws ReindexRequestException at `model` when the entry's name is not registered, its label is the current
     *                                 one (on the same or another connection), its connection is not usable, or
     *                                 another model is pending
     * @throws CollectionLockedException  when the collection is locked
     */
    public function start(Collection $collection, ProviderModel $target): void
    {
        $this->lockGuard?->assertWritable($collection);

        try {
            $model = $this->registry->forEntry($target)->name;
        } catch (\InvalidArgumentException $e) {
            throw new ReindexRequestException($e->getMessage(), ReindexRequestException::FIELD_MODEL, $e);
        }
        $connection = $target->getApiConfiguration();

        $current = $collection->getEmbeddingProviderConnection();
        if ($collection->getEmbeddingModel() === $model) {
            if (!self::sameConnection($connection, $current)) {
                throw new ReindexRequestException(\sprintf(
                    'Collection "%s" already uses the embedding model "%s"%s; a different connection needs a different model as well, because the stored vectors are labelled by the model only. Reindex to another model of "%s".',
                    $collection->getName(),
                    $model,
                    null !== $current ? \sprintf(' on connection "%s"', $current->getName()) : ' without a connection',
                    $connection->getName(),
                ), ReindexRequestException::FIELD_MODEL);
            }

            throw new ReindexRequestException(\sprintf('Collection "%s" already uses the embedding model "%s".', $collection->getName(), $model), ReindexRequestException::FIELD_MODEL);
        }

        try {
            EmbeddingsProviderLocator::assertUsable($connection);
        } catch (EmbeddingConnectionException $e) {
            throw new ReindexRequestException($e->getMessage(), ReindexRequestException::FIELD_MODEL, $e);
        }

        $pending = $collection->getPendingEmbeddingModel();
        $pendingConnection = $collection->getPendingEmbeddingProviderConnection();
        if (null !== $pending && ($pending !== $model || !self::sameConnection($connection, $pendingConnection))) {
            throw new ReindexRequestException(\sprintf(
                'Collection "%s": a reindex to "%s"%s is pending; cancel it first.',
                $collection->getName(),
                $pending,
                null !== $pendingConnection ? \sprintf(' on connection "%s"', $pendingConnection->getName()) : '',
            ), ReindexRequestException::FIELD_MODEL);
        }

        $collection->setPendingEmbeddingModel($model);
        $collection->setPendingEmbeddingProviderModel($target);
        $collection->setReindexJobId(null);
        $this->entityManager->flush();
    }

    /**
     * Embeds every document of the collection for the label $model through
     * the pending connection and swaps the collection over (entry, label,
     * dimension and connection) when all of them succeeded.
     *
     * $onProgress receives the report every {@see FLUSH_EVERY} documents
     * and once at the end; persisting progress (and flushing) is the
     * caller's business.
     *
     * Ends without a swap when the pending pair is cleared or replaced
     * during the run (report `cancelled`); the rows written for $model by
     * this run are deleted again in that case. A run for a model that is
     * not pending (anymore) ends the same way before embedding anything.
     *
     * @param (callable(ReindexReport): void)|null $onProgress
     *
     * @throws \InvalidArgumentException    when the model is not registered
     * @throws EmbeddingConnectionException when the pending connection or the pending catalogue entry is
     *                                      missing (queued before provider connections or the model
     *                                      reference), or the connection is inactive or no `ollama`
     *                                      connection (pending pair kept)
     * @throws \RuntimeException            when the model's vectors do not have the registered
     *                                      dimension (the pending pair is cancelled), or when
     *                                      documents failed (the pending pair is kept, run
     *                                      again to resume)
     * @throws CollectionLockedException    when the collection is locked before the run (nothing written) or
     *                                      during it (the rows written for $model are deleted, the collection
     *                                      keeps its current and its pending model)
     */
    public function run(Collection $collection, string $model, ReindexReport $report, ?callable $onProgress = null): void
    {
        $dimension = $this->registry->get($model)->dimension;
        if ($collection->getPendingEmbeddingModel() !== $model) {
            $report->cancelled = true;
            if (null !== $onProgress) {
                $onProgress($report);
            }
            $this->discardCancelledRows($collection, $model);

            return;
        }
        $connection = $collection->getPendingEmbeddingProviderConnection() ?? throw EmbeddingConnectionException::noPending($collection);
        // The swap moves the pending entry into the current one, which is
        // NOT NULL: a target without an entry is refused before any work.
        if (null === $collection->getPendingEmbeddingProviderModel()) {
            throw EmbeddingConnectionException::noPending($collection);
        }
        // A waiting job checks the lock when it starts, before any work.
        $this->lockGuard?->assertWritable($collection);
        $this->probe($collection, $connection, $model, $dimension);
        $this->indexManager->ensure($model);

        $store = $this->store->forModel($model, $dimension);
        $documents = [];
        foreach ($this->source->enumerate($collection) as [$sourceType, $sourceName]) {
            $documents[] = [$sourceType, $sourceName];
        }
        $report->total = \count($documents);

        $this->logger->info('Reindex of collection {collection} to {model} on {connection}: {total} document(s).', [
            'collection' => $collection->getName(),
            'model' => $model,
            'connection' => $connection->getName(),
            'total' => $report->total,
        ]);

        foreach ($documents as $index => [$sourceType, $sourceName]) {
            if ($index > 0 && 0 === $index % self::FLUSH_EVERY) {
                if ($this->checkpoint($collection, $connection, $model, $report, $onProgress)) {
                    $this->abortLocked($collection, $model);
                }
            }
            // Cheap in-memory check per document; the checkpoint re-reads
            // the row to see a cancel from another process.
            if (!self::isPending($collection, $connection, $model)) {
                $report->cancelled = true;
                break;
            }

            if ($store->countBy($sourceType, $sourceName) > 0) {
                ++$report->skipped;
                continue;
            }

            try {
                $report->chunks += $this->source->embed($collection, $sourceType, $sourceName, $connection, $model);
                ++$report->done;
            } catch (\Throwable $e) {
                $report->addFailure($sourceType, $sourceName, $e->getMessage());
                $this->logger->warning('Reindex of collection {collection} to {model}: document {source_type}/{source_name} failed: {error}', [
                    'collection' => $collection->getName(),
                    'model' => $model,
                    'source_type' => $sourceType,
                    'source_name' => $sourceName,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        if (!$report->cancelled) {
            if ($this->checkpoint($collection, $connection, $model, $report, $onProgress)) {
                $this->abortLocked($collection, $model);
            }
        } elseif (null !== $onProgress) {
            $onProgress($report);
        }

        if ($report->cancelled) {
            $this->discardCancelledRows($collection, $model);

            return;
        }

        if ($report->hasFailures()) {
            throw new \RuntimeException(\sprintf(
                'Reindex of collection "%s" to "%s" failed for %d of %d documents; pending model and connection kept, run again to resume.',
                $collection->getName(),
                $model,
                \count($report->failed),
                $report->total,
            ));
        }

        $oldModel = $this->swap($collection, $connection, $model, $dimension);
        if (false === $oldModel) {
            // Locked between the last checkpoint and the swap.
            $this->abortLocked($collection, $model);
        }
        if (null === $oldModel) {
            // Cancelled between the last checkpoint and the swap.
            $report->cancelled = true;
            $this->discardCancelledRows($collection, $model);

            return;
        }

        $this->store->deleteByModel($oldModel, ['collection_id' => (string) $collection->getId()]);

        $this->logger->info('Reindex of collection {collection} from {old} to {model} on {connection} completed: {done} embedded, {skipped} skipped, {chunks} chunk(s).', [
            'collection' => $collection->getName(),
            'old' => $oldModel,
            'model' => $model,
            'connection' => $connection->getName(),
            'done' => $report->done,
            'skipped' => $report->skipped,
            'chunks' => $report->chunks,
        ]);
    }

    /**
     * Drops the pending model: clears the pending entry, label and
     * connection and the job id, then deletes the pending label's rows of
     * the collection.
     *
     * @throws \LogicException when no reindex is pending
     */
    public function cancel(Collection $collection): void
    {
        $pending = $collection->getPendingEmbeddingModel();
        if (null === $pending) {
            throw new \LogicException(\sprintf('Collection "%s" has no pending reindex.', $collection->getName()));
        }

        $collection->setPendingEmbeddingModel(null);
        $collection->setPendingEmbeddingProviderModel(null);
        $collection->setPendingEmbeddingProviderConnection(null);
        $collection->setReindexJobId(null);
        $this->entityManager->flush();

        $this->store->deleteByModel($pending, ['collection_id' => (string) $collection->getId()]);
    }

    /**
     * Embeds a probe text and compares the vector length with the
     * registered dimension. A mismatch is a configuration error that would
     * also break the dual write of every new document, so the pending model
     * is cancelled before throwing. Provider errors (e.g. the model server
     * is down) and an unusable connection
     * ({@see EmbeddingConnectionException}) propagate unchanged and keep the
     * pending pair for a retry.
     */
    private function probe(Collection $collection, ApiConfiguration $connection, string $model, int $dimension): void
    {
        $actual = \count($this->locator->for($connection, $model)->embedText(self::PROBE_TEXT));
        if ($actual === $dimension) {
            return;
        }

        if ($collection->getPendingEmbeddingModel() === $model) {
            $this->cancel($collection);
        }

        throw new \RuntimeException(\sprintf(
            'Embedding model "%s" returns vectors of %d values, but %d are registered; fix the model registry (dmstr_neuron_ai.embeddings.models). The reindex of collection "%s" was cancelled.',
            $model,
            $actual,
            $dimension,
            $collection->getName(),
        ));
    }

    /**
     * Re-reads the collection (cancel or lock from another process) and
     * reports progress; returns whether the collection is locked now.
     *
     * @param (callable(ReindexReport): void)|null $onProgress
     */
    private function checkpoint(Collection $collection, ApiConfiguration $connection, string $model, ReindexReport $report, ?callable $onProgress): bool
    {
        $this->entityManager->refresh($collection);
        if (!self::isPending($collection, $connection, $model)) {
            $report->cancelled = true;
        }

        if (null !== $onProgress) {
            $onProgress($report);
        }

        // The refresh re-read the row, lock fields included.
        return !$report->cancelled && $collection->isLocked();
    }

    /**
     * Ends a run on a collection that was locked during it: deletes the
     * rows written for $model (the shadow index of the reindex) and throws.
     * The collection keeps its current model and its pending pair; after an
     * unlock the reindex can be run again (it starts over) or cancelled.
     *
     * @throws CollectionLockedException always
     */
    private function abortLocked(Collection $collection, string $model): never
    {
        $this->store->deleteByModel($model, ['collection_id' => (string) $collection->getId()]);
        $this->logger->warning('Reindex of collection {collection} to {model} stopped: the collection was locked; the rows written for {model} were deleted.', [
            'collection' => $collection->getName(),
            'model' => $model,
        ]);

        throw new CollectionLockedException($collection);
    }

    /**
     * Switches the collection to $model on $connection in one transaction,
     * after locking and re-reading its row; returns the previous model,
     * null when the pending pair is no longer ($model, $connection)
     * (cancelled meanwhile), or false when the collection is locked now.
     * Nothing is thrown inside the transaction: wrapInTransaction() closes
     * the entity manager on an exception.
     */
    private function swap(Collection $collection, ApiConfiguration $connection, string $model, int $dimension): string|false|null
    {
        /** @var string|false|null $oldModel */
        $oldModel = $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($collection, $connection, $model, $dimension): string|false|null {
            $entityManager->refresh($collection, LockMode::PESSIMISTIC_WRITE);
            if (!self::isPending($collection, $connection, $model)) {
                return null;
            }
            if ($collection->isLocked()) {
                return false;
            }

            $oldModel = $collection->getEmbeddingModel();
            $collection->setEmbeddingModel($model);
            $collection->setEmbeddingDimension($dimension);
            // run() refused a pending model without an entry; the refreshed
            // row may have lost it only through a cancel, which isPending()
            // caught above.
            $target = $collection->getPendingEmbeddingProviderModel()
                ?? throw new \LogicException(\sprintf('The pending reindex of collection "%s" has no catalogue entry.', $collection->getName()));
            $collection->setEmbeddingProviderModel($target);
            $collection->setPendingEmbeddingModel(null);
            $collection->setPendingEmbeddingProviderModel(null);
            $collection->setPendingEmbeddingProviderConnection(null);
            $collection->setReindexJobId(null);
            $entityManager->flush();

            return $oldModel;
        });

        return $oldModel;
    }

    /**
     * After a cancel, removes the rows this run may have written for $model
     * after the cancel had already deleted them, unless $model is pending
     * again (a new reindex to the same model).
     */
    private function discardCancelledRows(Collection $collection, string $model): void
    {
        if ($collection->getPendingEmbeddingModel() === $model || $collection->getEmbeddingModel() === $model) {
            return;
        }

        $this->store->deleteByModel($model, ['collection_id' => (string) $collection->getId()]);
        $this->logger->info('Reindex of collection {collection} to {model} was cancelled.', [
            'collection' => $collection->getName(),
            'model' => $model,
        ]);
    }

    /**
     * Whether ($model, $connection) is still the collection's pending pair.
     */
    private static function isPending(Collection $collection, ApiConfiguration $connection, string $model): bool
    {
        return $collection->getPendingEmbeddingModel() === $model
            && self::sameConnection($connection, $collection->getPendingEmbeddingProviderConnection());
    }

    private static function sameConnection(ApiConfiguration $connection, ?ApiConfiguration $other): bool
    {
        return null !== $other && $connection->getId()->equals($other->getId());
    }
}
// - revised 2026-10-13 19:06:18 UTC
