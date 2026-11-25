<?php

// file generated with AI assistance: Claude Code - 2026-10-13 18:45:18 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Doctrine;

use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Knowledge\CollectionLockedException;
use Dmstr\NeuronAiBundle\Knowledge\CollectionLockGuard;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;

/**
 * Refuses changes to a locked collection with 423 Locked
 * ({@see CollectionLockedException}).
 *
 * Doctrine entity listener on {@see Collection}, so it applies to the
 * default PUT, PATCH and DELETE processors of API Platform and to every
 * other path through the EntityManager (console, application code):
 *  - `preUpdate`: a collection that was locked before the change (the
 *    original `lockedAt` of the unit of work, or the stored row, which a
 *    stale entity of a long-running worker may not reflect) refuses every
 *    change except to `lockedAt` and `lockedBy` themselves, so unlocking
 *    stays possible. `updatedAt` is ignored: the lifecycle callback sets it.
 *    Clearing the reindex state ({@see REINDEX_FIELDS} set to null, and
 *    nothing else) passes as well: `POST .../reindex/cancel`
 *    ({@see \Dmstr\NeuronAiBundle\Reindex\CollectionReindexer::cancel()})
 *    stays allowed on a locked collection, because it only discards an
 *    unfinished reindex. Setting any of these fields stays refused.
 *  - `preRemove`: a locked collection cannot be deleted.
 *
 * The content of a collection (documents, chunks, vectors) is not an
 * association of the entity; its write paths call
 * {@see CollectionLockGuard::assertWritable()} themselves.
 */
final class CollectionLockListener
{
    /** Fields a locked collection may change: the lock itself. */
    public const array LOCK_FIELDS = ['lockedAt', 'lockedBy'];

    /** Fields maintained by the entity itself on every update. */
    private const array MAINTAINED_FIELDS = ['updatedAt'];

    /**
     * The reindex state a reindex cancel clears; a locked collection may
     * clear (never set) them.
     */
    public const array REINDEX_FIELDS = [
        'pendingEmbeddingModel',
        'pendingEmbeddingProviderModel',
        'pendingEmbeddingProviderConnection',
        'reindexJobId',
    ];

    public function __construct(
        private readonly CollectionLockGuard $guard,
    ) {
    }

    public function preUpdate(Collection $collection, PreUpdateEventArgs $args): void
    {
        $changeSet = $args->getEntityChangeSet();
        if ([] === self::refusedChanges($changeSet)) {
            return;
        }

        $wasLocked = $args->hasChangedField('lockedAt')
            ? null !== $args->getOldValue('lockedAt')
            : $collection->isLocked();
        if (!$wasLocked && !$this->guard->isLockedInDatabase($collection)) {
            return;
        }

        // Name the collection as it is stored, not as renamed.
        throw new CollectionLockedException(
            $args->hasChangedField('name') ? (string) $args->getOldValue('name') : $collection->getName(),
        );
    }

    public function preRemove(Collection $collection, PreRemoveEventArgs $args): void
    {
        if ($collection->isLocked() || $this->guard->isLockedInDatabase($collection)) {
            throw new CollectionLockedException($collection);
        }
    }

    /**
     * The changes of $changeSet a locked collection refuses: the guarded
     * changes ({@see guardedChanges()}) without the reindex fields that are
     * cleared (new value null).
     *
     * @param array<string, array{mixed, mixed}|mixed> $changeSet field => [old, new], as Doctrine reports it
     *
     * @return list<string>
     */
    public static function refusedChanges(array $changeSet): array
    {
        $refused = [];
        foreach (self::guardedChanges(array_map('strval', array_keys($changeSet))) as $field) {
            $change = $changeSet[$field];
            $cleared = \is_array($change) && \array_key_exists(1, $change) && null === $change[1];
            if ($cleared && \in_array($field, self::REINDEX_FIELDS, true)) {
                continue;
            }
            $refused[] = $field;
        }

        return $refused;
    }

    /**
     * The changed fields a locked collection refuses: all but the lock
     * fields and the fields the entity maintains itself.
     *
     * @param list<string> $fields
     *
     * @return list<string>
     */
    public static function guardedChanges(array $fields): array
    {
        return array_values(array_diff($fields, self::LOCK_FIELDS, self::MAINTAINED_FIELDS));
    }
}
// - revised 2026-10-13 20:10:00 UTC
