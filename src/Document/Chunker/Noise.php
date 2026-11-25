<?php

// file generated with AI assistance: Claude Code - 2026-10-01 17:14:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Document\Chunker;

/**
 * Collapses noise runs: three or more of the same character among `.`,
 * `-`, `_`, `=` and `*` in sequence become exactly three, e.g. dot
 * leaders of a table of contents or ASCII rulers.
 *
 * Markdown stays valid: a table separator row `|:------:|` becomes
 * `|:---:|`, a thematic break stays a thematic break, and code fences
 * (backticks, tildes) are not affected.
 */
final class Noise
{
    public static function normalize(string $text): string
    {
        return (string) preg_replace('/([.\-_=*])\1{3,}/', '$1$1$1', $text);
    }
}
