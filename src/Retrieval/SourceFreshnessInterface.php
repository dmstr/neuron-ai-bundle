<?php

// file generated with AI assistance: Claude Code - 2026-11-19 10:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Retrieval;

/**
 * Port to the application that owns the documents behind the chunks: how
 * fresh a source is, so a client can show "checked 3 days ago, changed on …".
 * Asked when the answer is returned, not stored with the chunk, because an
 * unchanged source is not re-embedded and a stored date would go stale.
 * Without an alias the sources carry no dates (null).
 */
interface SourceFreshnessInterface
{
    /**
     * Freshness per document id: `checkedAt`, when an import last found the
     * source (null for an upload), and `changedAt`, when its content last
     * changed. Unknown ids are missing from the result; must not throw for
     * an id that is not a valid identifier.
     *
     * @param list<string> $documentIds
     *
     * @return array<string, array{checkedAt: ?\DateTimeImmutable, changedAt: ?\DateTimeImmutable}>
     */
    public function freshness(array $documentIds): array;
}
