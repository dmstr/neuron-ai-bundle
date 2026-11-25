<?php

// file generated with AI assistance: Claude Code - 2026-10-13 20:10:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Knowledge;

/**
 * What {@see CollectionContentPurgerInterface::purge()} deleted, plus the
 * work it leaves for after the commit (deleting stored files).
 *
 * {@see afterCommit()} runs that work once; the caller calls it after the
 * outermost commit and never inside the transaction, so a rollback cannot
 * leave rows whose files are gone. It never throws: a failing file
 * deletion is the implementation's to log.
 */
final class CollectionPurgeReport
{
    private ?\Closure $afterCommit;

    /**
     * @param list<string>            $models      embedding models whose vector rows of the collection were deleted
     * @param list<string>            $files       storage keys of the files deleted after the commit
     * @param (\Closure(): void)|null $afterCommit deletes the stored files
     */
    public function __construct(
        public readonly int $documents = 0,
        public readonly int $chunks = 0,
        public readonly int $vectors = 0,
        public readonly array $models = [],
        public readonly array $files = [],
        ?\Closure $afterCommit = null,
    ) {
        $this->afterCommit = $afterCommit;
    }

    /** Whether anything was deleted (rows of any kind). */
    public function isEmpty(): bool
    {
        return 0 === $this->documents && 0 === $this->chunks && 0 === $this->vectors;
    }

    /**
     * Runs the work left for after the commit, at most once.
     */
    public function afterCommit(): void
    {
        $afterCommit = $this->afterCommit;
        $this->afterCommit = null;
        if (null !== $afterCommit) {
            $afterCommit();
        }
    }
}
