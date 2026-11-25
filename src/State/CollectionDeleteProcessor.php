<?php

// file generated with AI assistance: Claude Code - 2026-10-13 20:10:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\McpEndpoint\Scope\ScopePruner;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Knowledge\CollectionContentPurgerInterface;
use Dmstr\NeuronAiBundle\Knowledge\CollectionLockGuard;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `DELETE /admin/collections/{id}`: deletes a collection together with its
 * content.
 *
 *  1. 423 Locked for a locked collection ({@see CollectionLockGuard}, the
 *     stored lock; {@see \Dmstr\NeuronAiBundle\Knowledge\CollectionLockedException}),
 *     before anything is deleted.
 *  2. 409 Conflict while a reindex is pending or running (pending model
 *     set): its job would write rows for a collection that is gone. Cancel
 *     it first (`POST .../reindex/cancel`).
 *  3. In ONE transaction: the content purge of the application
 *     ({@see CollectionContentPurgerInterface}: documents, chunks, vector
 *     rows of every model), the removal of the collection's id from the
 *     scope of every MCP endpoint ({@see ScopePruner}, only with
 *     dmstr/mcp-endpoint-bundle, audited through Gedmo) and the removal of
 *     the collection itself (audited, guarded once more by the lock
 *     listener).
 *  4. After the commit: the purge's remaining work, deleting the stored
 *     files ({@see \Dmstr\NeuronAiBundle\Knowledge\CollectionPurgeReport::afterCommit()}).
 *     Files go last on purpose: a rollback must not leave rows whose files
 *     are gone, while a file left behind by a failed deletion is harmless.
 *
 * Without an application purger (the bundle used standalone) only an
 * empty collection is deleted, as before; one with vector rows, or one the
 * database refuses because rows still reference it (foreign keys RESTRICT),
 * is a 409 that says so. A content write racing the deletion ends the same
 * way (409, nothing deleted).
 *
 * The pruner is called here and not from a Doctrine listener: a flush
 * inside a flush is not allowed, and the processor is the one deletion path
 * of a collection (REST, admin UI, MCP). An endpoint that still holds a
 * dead id (a collection deleted by SQL) is pruned by the endpoint
 * validator on its next save and skipped by the knowledge tools meanwhile.
 *
 * @implements ProcessorInterface<Collection, null>
 */
final class CollectionDeleteProcessor implements ProcessorInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CollectionLockGuard $lockGuard,
        // The vector store table (dmstr_neuron_ai.store.table), for the
        // content check without an application purger.
        private readonly string $storeTable,
        private readonly ?CollectionContentPurgerInterface $purger = null,
        private readonly ?ScopePruner $scopePruner = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        if (!$data instanceof Collection) {
            throw new NotFoundHttpException('Not Found');
        }

        $this->lockGuard->assertWritable($data);

        $pending = $data->getPendingEmbeddingModel();
        if (null !== $pending) {
            throw new ConflictHttpException(\sprintf('Collection "%s" has a pending reindex to "%s"; cancel it first (POST /api/admin/collections/%s/reindex/cancel), then delete the collection.', $data->getName(), $pending, $data->getId()->toRfc4122()));
        }

        if (null === $this->purger && $this->hasVectors($data)) {
            throw self::notEmpty($data);
        }

        $id = $data->getId()->toRfc4122();
        $name = $data->getName();
        $report = null;
        $pruned = 0;

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $report = $this->purger?->purge($data);
            $pruned = $this->scopePruner?->removeCollection($id) ?? 0;
            $this->entityManager->remove($data);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            if ($e instanceof ForeignKeyConstraintViolationException) {
                throw null === $this->purger
                    ? self::notEmpty($data, $e)
                    : new ConflictHttpException(\sprintf('Collection "%s" received new content while it was being deleted; nothing was deleted, try again.', $data->getName()), $e);
            }

            throw $e;
        }

        // After the commit only: see the class comment.
        $report?->afterCommit();

        $this->logger->info('Collection {collection} ({id}) deleted: {documents} document(s), {chunks} chunk(s), {vectors} vector row(s), {endpoints} MCP endpoint scope(s) pruned.', [
            'collection' => $name,
            'id' => $id,
            'documents' => $report?->documents ?? 0,
            'chunks' => $report?->chunks ?? 0,
            'vectors' => $report?->vectors ?? 0,
            'endpoints' => $pruned,
        ]);

        return null;
    }

    /** Whether the store table holds vector rows of the collection (any model). */
    private function hasVectors(Collection $collection): bool
    {
        $found = $this->entityManager->getConnection()->fetchOne(
            \sprintf('SELECT 1 FROM %s WHERE metadata @> CAST(:filter AS jsonb) LIMIT 1', $this->storeTable),
            ['filter' => json_encode(['collection_id' => $collection->getId()->toRfc4122()], \JSON_THROW_ON_ERROR)],
        );

        return false !== $found && null !== $found;
    }

    private static function notEmpty(Collection $collection, ?\Throwable $previous = null): ConflictHttpException
    {
        return new ConflictHttpException(\sprintf('Collection "%s" still has content (documents, chunks or vectors) and no content purger is configured to remove it; delete its content first.', $collection->getName()), $previous);
    }
}
