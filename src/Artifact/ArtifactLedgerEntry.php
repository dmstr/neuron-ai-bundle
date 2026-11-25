<?php

// file generated with AI assistance: Claude Code - 2026-10-16 08:40:52 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Artifact;

/**
 * One ledger row as the tool wrapper needs it: the stored spelling of the
 * path (relative to the user root) and the size the last write left.
 */
final readonly class ArtifactLedgerEntry
{
    public function __construct(
        public string $path,
        public int $sizeBytes,
    ) {
    }
}
