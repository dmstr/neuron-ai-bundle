<?php

// file generated with AI assistance: Claude Code - 2026-10-01 17:14:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Document;

/**
 * One ordered part of an extracted document.
 *
 * Text formats produce a single section with the whole text and neither
 * `page` nor `sheet`. The PDF extractor produces one section per page with
 * text (`page`, 1-based), the spreadsheet extractor one per sheet with
 * content (`sheet`, the sheet name). The chunker records `page` on the
 * chunks it makes from a paged section; a sheet's name reaches the chunks as
 * its heading.
 */
final readonly class Section
{
    public function __construct(
        public string $text,
        public ?int $page = null,
        public ?string $sheet = null,
    ) {
    }
}
