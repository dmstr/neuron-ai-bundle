<?php

// file generated with AI assistance: Claude Code - 2026-10-01 17:14:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Document\Chunker;

/**
 * One chunk of a document: the heading path it belongs to, its body and
 * its position (0-based, contiguous within a document).
 */
final readonly class Chunk
{
    /**
     * @param string $breadcrumb heading path such as `H1 > H2 > H3`, empty when none
     * @param int|null $page     page of the source file the chunk starts on (1-based), null without page information
     * @param list<array{page: int, text: string}> $pageStarts pages that begin inside the body, each with the first characters of its text
     */
    public function __construct(
        public string $breadcrumb,
        public string $body,
        public int $index,
        public ?int $page = null,
        public array $pageStarts = [],
    ) {
    }

    /**
     * Breadcrumb, a blank line and the body; just the body without a breadcrumb.
     */
    public function text(): string
    {
        return $this->breadcrumb === '' ? $this->body : $this->breadcrumb."\n\n".$this->body;
    }

    /**
     * Characters of text().
     */
    public function size(): int
    {
        return mb_strlen($this->text());
    }
}
