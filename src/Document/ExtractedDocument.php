<?php

// file generated with AI assistance: Claude Code - 2026-10-01 17:14:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Document;

/**
 * Result of an extractor: the document's text as an ordered list of
 * sections, the name of the extractor that produced it (set by
 * {@see Extractor\ExtractorRegistry}) and, for paged formats (PDF), the
 * number of pages of the file, including pages without text (null for
 * other formats) and, for Markdown and plain text that start with a YAML
 * frontmatter block, the parsed block (null without one).
 */
final readonly class ExtractedDocument
{
    /** @var list<Section> */
    public array $sections;

    /**
     * @param iterable<Section> $sections
     */
    public function __construct(
        iterable $sections = [],
        public ?string $extractorName = null,
        public ?int $pageCount = null,
        /** @var array<string, mixed>|null JSON-safe values, dates as ISO 8601 strings */
        public ?array $frontmatter = null,
    ) {
        $list = [];
        foreach ($sections as $section) {
            $list[] = $section;
        }
        $this->sections = $list;
    }

    public function withExtractorName(string $name): self
    {
        return new self($this->sections, $name, $this->pageCount, $this->frontmatter);
    }

    /**
     * True when there is no section or every section is blank.
     */
    public function isEmpty(): bool
    {
        foreach ($this->sections as $section) {
            if (trim($section->text) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * The text of all sections, separated by a blank line.
     */
    public function text(): string
    {
        return implode("\n\n", array_map(static fn (Section $section): string => $section->text, $this->sections));
    }
}

// - revised 2026-11-23
