<?php

// file generated with AI assistance: Claude Code - 2026-10-04 10:15:18 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

/**
 * The context limits of one turn on an agent version, resolved by
 * {@see \Dmstr\NeuronAiBundle\Agent\VersionTurnMapper}: the cap of the hits
 * ({@see ContextRetriever::cap()}) and the separate character budget of the
 * neighbour passages added afterwards ({@see ContextExpander::expand()}).
 * The context sent is therefore never longer than
 * `$maxChars + $expansionMaxChars` characters.
 *
 * A version stored before these keys existed has none of the keys and maps to the
 * bundle cap (`turn.context_max_chunks`, `turn.context_max_chars`) without
 * expansion, so its context stays as it was.
 */
final readonly class ContextLimits
{
    /**
     * @param int $maxChunks         hits in the context, at least 1
     * @param int $maxChars          characters of the hits in total, applied before expansion, at least 1
     * @param int $expansionMaxChars characters of neighbour passages (and their separators) in total; 0 = no expansion
     */
    public function __construct(
        public int $maxChunks,
        public int $maxChars,
        public int $expansionMaxChars = 0,
    ) {
    }
}
