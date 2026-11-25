<?php

// file generated with AI assistance: Claude Code - 2026-10-13 20:10:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Knowledge;

use Dmstr\NeuronAiBundle\Entity\Collection;

/**
 * Removes the content of a collection before the collection itself is
 * deleted ({@see \Dmstr\NeuronAiBundle\State\CollectionDeleteProcessor}).
 *
 * The bundle does not know where an application keeps documents and
 * chunks, so the application implements this port and aliases the
 * interface to its implementation. Without an alias the bundle deletes
 * only empty collections and refuses a collection with content (409).
 *
 * Contract of {@see purge()}:
 *  - deletes every database row of the collection's content: documents,
 *    chunks and the vector rows of the collection for EVERY embedding model
 *    (current, pending and leftovers);
 *  - runs in one transaction, nested into the caller's when one is active
 *    (the delete processor runs the purge, the scope pruning and the removal
 *    of the collection in one transaction);
 *  - does NOT check the lock: the caller does that first
 *    ({@see CollectionLockGuard::assertWritable()});
 *  - leaves everything outside the database (stored files) to
 *    {@see CollectionPurgeReport::afterCommit()}, which the caller runs
 *    after the outermost commit. A leftover file is harmless, a row whose
 *    file is gone is not.
 */
interface CollectionContentPurgerInterface
{
    public function purge(Collection $collection): CollectionPurgeReport;
}
