<?php

// file generated with AI assistance: Claude Code - 2026-10-16 08:40:52 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Artifact;

/**
 * Bookkeeping of the artifacts of each user: one row per file, keyed by
 * owner and lower-cased path, so the limits are checked without walking the
 * storage and two names differing only in case are caught before a
 * case-insensitive share (CIFS) merges them.
 *
 * Paths are relative to the user root, with `/` as separator. Callers
 * serialise writes of one owner (lock `artifact:<ownerSubject>`, see
 * {@see ArtifactTool}); implementations need no locking of their own.
 */
interface ArtifactLedgerInterface
{
    /**
     * @param string $pathLower the path lower-cased by {@see ArtifactPath::lower()}
     */
    public function find(string $ownerSubject, string $pathLower): ?ArtifactLedgerEntry;

    /**
     * Files of the owner below `<folder>/` (any depth, case-insensitive).
     */
    public function countInFolder(string $ownerSubject, string $folder): int;

    /** Sum of the recorded sizes of the owner's files, in bytes. */
    public function totalSize(string $ownerSubject): int;

    /**
     * Inserts or updates the row of a written file. A new row remembers
     * `$conversationId` as its conversation and creator; an existing row
     * keeps both and only takes the new size, spelling and updater.
     */
    public function record(string $ownerSubject, string $path, int $sizeBytes, string $conversationId): void;
}
