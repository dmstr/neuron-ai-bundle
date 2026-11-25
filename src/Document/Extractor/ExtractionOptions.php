<?php

// file generated with AI assistance: Claude Code - 2026-10-01 17:14:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Document\Extractor;

/**
 * Per-call input of an extractor besides the file itself.
 *
 * The file handed to an extractor is usually a temporary copy without the
 * original name, so the original filename travels here.
 */
final readonly class ExtractionOptions
{
    public function __construct(
        public ?string $filename = null,
    ) {
    }
}
