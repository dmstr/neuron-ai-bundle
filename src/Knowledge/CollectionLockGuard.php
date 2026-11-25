<?php

// file generated with AI assistance: Claude Code - 2026-10-13 18:45:18 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Knowledge;

use Dmstr\NeuronAiBundle\Entity\Collection;
use Doctrine\DBAL\Connection;

/**
 * The lock check for every write path of a collection's content (ingest,
 * upload, document removal, extraction, re-embedding, imports, reindex).
 *
 * It reads the lock state of the collection's row from the database, not
 * from the entity: a long-running messenger worker holds entities loaded
 * before the lock was set or cleared. The row decides; a collection without
 * a row (new, not yet flushed) is writable.
 *
 * The collection entity itself (PUT, PATCH, DELETE) is guarded by
 * {@see \Dmstr\NeuronAiBundle\Doctrine\CollectionLockListener}, which uses
 * this service as well.
 */
final class CollectionLockGuard
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @throws CollectionLockedException when the collection is locked
     */
    public function assertWritable(Collection $collection): void
    {
        if ($this->isLockedInDatabase($collection)) {
            throw new CollectionLockedException($collection);
        }
    }

    public function isWritable(Collection $collection): bool
    {
        return !$this->isLockedInDatabase($collection);
    }

    /**
     * Whether the stored row of the collection carries a lock; false for a
     * collection without a row.
     */
    public function isLockedInDatabase(Collection $collection): bool
    {
        $lockedAt = $this->connection->fetchOne(
            'SELECT locked_at FROM neuron_collection WHERE id = :id',
            ['id' => $collection->getId()->toRfc4122()],
        );

        // false: no row (new collection); null: unlocked.
        return false !== $lockedAt && null !== $lockedAt;
    }
}
