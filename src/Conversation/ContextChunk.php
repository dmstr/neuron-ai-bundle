<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:35:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use NeuronAI\RAG\Document;
use Symfony\Component\Uid\Uuid;

/**
 * One retrieved chunk as it was sent to the model, with the metadata written
 * at ingest. toArray() has the field shape of a search hit and is
 * what a turn stores in `Message::$sources` and the stateless chat returns
 * as `sources`.
 */
final readonly class ContextChunk
{
    public function __construct(
        public string $content,
        public float $score,
        /** UUID of the collection the chunk belongs to (metadata "collection_id"). */
        public ?string $collectionId = null,
        /** Current name of that collection; null if unknown. */
        public ?string $collectionName = null,
        public ?string $topic = null,
        public ?string $sourceUrl = null,
        public ?string $chunkId = null,
        public ?int $chunkNumber = null,
        /** UUID of the uploaded document the chunk comes from (metadata "document_id"); null for crawler chunks. */
        public ?string $documentId = null,
        /** Original filename of that document (metadata "filename"). */
        public ?string $filename = null,
        /** Heading path of the chunk within the document (metadata "breadcrumb"). */
        public ?string $breadcrumb = null,
        /** Page of the source file the chunk starts on (metadata "page"); null without page information. */
        public ?int $page = null,
        /** Title of the document (metadata "title"); null when none was given. */
        public ?string $title = null,
        /** The part of the chunk that answers the question (set by the retrieval); null derives the start of the chunk. */
        public ?string $quote = null,
        /** @var list<array{page: int, text: string}> pages that begin inside the chunk (metadata "page_starts"), to find the page of the quote */
        public array $pageStarts = [],
    ) {
    }

    /**
     * @param array<string, string> $collectionNames collection id => name
     */
    public static function fromDocument(Document $document, array $collectionNames = []): self
    {
        $meta = $document->getMetadata();
        $collectionId = self::normalizeId($meta['collection_id'] ?? null);

        return new self(
            content: $document->getContent(),
            score: $document->getScore() ?? 0.0,
            collectionId: $collectionId,
            collectionName: null !== $collectionId ? ($collectionNames[$collectionId] ?? null) : null,
            topic: self::stringOrNull($meta['topic'] ?? null),
            sourceUrl: self::stringOrNull($meta['source_url'] ?? null),
            chunkId: self::stringOrNull($meta['chunk_id'] ?? null),
            chunkNumber: isset($meta['chunk_number']) ? (int) $meta['chunk_number'] : null,
            documentId: self::stringOrNull($meta['document_id'] ?? null),
            filename: self::stringOrNull($meta['filename'] ?? null),
            breadcrumb: self::stringOrNull($meta['breadcrumb'] ?? null),
            page: isset($meta['page']) && (int) $meta['page'] > 0 ? (int) $meta['page'] : null,
            title: self::stringOrNull($meta['title'] ?? null),
            pageStarts: self::pageStarts($meta['page_starts'] ?? null),
        );
    }

    /**
     * The same chunk with the quote that fits the question: the sentence of the chunk that answers it.
     */
    public function withQuote(?string $quote): self
    {
        return new self($this->content, $this->score, $this->collectionId, $this->collectionName, $this->topic, $this->sourceUrl, $this->chunkId, $this->chunkNumber, $this->documentId, $this->filename, $this->breadcrumb, $this->page, $this->title, $quote, $this->pageStarts);
    }

    /**
     * A chunk from its {@see toArray()} form, as a turn stores it in `Message::$sources`. The keys derived
     * there (`ext`, `link`) are recomputed, a stored `quote` is kept, and keys a stored source does not have are null.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            content: (string) ($data['content'] ?? ''),
            score: (float) ($data['score'] ?? 0.0),
            collectionId: self::stringOrNull($data['collectionId'] ?? null),
            collectionName: self::stringOrNull($data['collectionName'] ?? null),
            topic: self::stringOrNull($data['topic'] ?? null),
            sourceUrl: self::stringOrNull($data['sourceUrl'] ?? null),
            chunkId: self::stringOrNull($data['chunkId'] ?? null),
            chunkNumber: isset($data['chunkNumber']) ? (int) $data['chunkNumber'] : null,
            documentId: self::stringOrNull($data['documentId'] ?? null),
            filename: self::stringOrNull($data['filename'] ?? null),
            breadcrumb: self::stringOrNull($data['breadcrumb'] ?? null),
            page: isset($data['page']) ? (int) $data['page'] : null,
            title: self::stringOrNull($data['title'] ?? null),
            quote: self::stringOrNull($data['quote'] ?? null),
        );
    }

    /**
     * The same chunk with other content (used when the context cap shortens it).
     */
    public function withContent(string $content): self
    {
        return new self($content, $this->score, $this->collectionId, $this->collectionName, $this->topic, $this->sourceUrl, $this->chunkId, $this->chunkNumber, $this->documentId, $this->filename, $this->breadcrumb, $this->page, $this->title, $this->quote, $this->pageStarts);
    }

    /**
     * @return array{content: string, score: float, collectionId: ?string, collectionName: ?string, topic: ?string, sourceUrl: ?string, chunkId: ?string, chunkNumber: ?int, documentId: ?string, filename: ?string, breadcrumb: ?string, title: ?string, ext: ?string, page: ?int, quote: ?string, link: ?string}
     */
    public function toArray(): array
    {
        $quote = $this->quote ?? SourceLocator::quote($this->content, $this->breadcrumb, title: $this->title);
        $page = SourceLocator::pageOf($this->content, $quote, $this->page, $this->pageStarts);

        return [
            'content' => $this->content,
            'score' => $this->score,
            'collectionId' => $this->collectionId,
            'collectionName' => $this->collectionName,
            'topic' => $this->topic,
            'sourceUrl' => $this->sourceUrl,
            'chunkId' => $this->chunkId,
            'chunkNumber' => $this->chunkNumber,
            'documentId' => $this->documentId,
            'filename' => $this->filename,
            'breadcrumb' => $this->breadcrumb,
            // For a client to show the source: the document title, the file type of the URL (`pdf`), the page of
            // the quote, a quote of the passage and a link that opens the page at it (`#page=N` for a PDF, a text fragment for a web page).
            // Derived here, not stored.
            'title' => $this->title,
            'ext' => SourceLocator::fileType($this->sourceUrl),
            'page' => $page,
            'quote' => $quote,
            'link' => SourceLocator::link($this->sourceUrl, $page, $quote),
        ];
    }

    private static function normalizeId(mixed $value): ?string
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return Uuid::isValid($value) ? (string) Uuid::fromString($value) : $value;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return null === $value ? null : (string) $value;
    }

    /** @return list<array{page: int, text: string}> */
    private static function pageStarts(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $start): ?array => \is_array($start) && isset($start['page'], $start['text']) ? ['page' => (int) $start['page'], 'text' => (string) $start['text']] : null, $value),
            static fn (?array $start): bool => null !== $start && $start['page'] > 0 && '' !== $start['text'],
        ));
    }
}

// - revised 2026-10-29
// - revised 2026-10-22
// - revised 2026-10-14
