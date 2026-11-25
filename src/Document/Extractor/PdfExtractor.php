<?php

// file generated with AI assistance: Claude Code - 2026-10-08 11:53:22 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Document\Extractor;

use Dmstr\NeuronAiBundle\Document\ExtractedDocument;
use Dmstr\NeuronAiBundle\Document\Section;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;

/**
 * Text PDFs through `smalot/pdfparser` (pure PHP, no OCR).
 *
 * One section per page with text, carrying its 1-based page number;
 * pages without text are dropped. The result's page count is the number
 * of pages of the file, including those without text.
 *
 * Memory guards (a PHP memory fatal cannot be caught and kills the
 * worker): image streams are not kept (`setRetainImageContent(false)`),
 * every decompressed stream is capped at `$decodeMemoryLimit` bytes and a
 * file with more than `$maxPages` pages is rejected before any page text
 * is extracted. The whole file is still parsed into memory, so very large
 * PDFs should be split before upload (see the bundle README).
 *
 * Failures are {@see ExtractionException}s with a readable message: an
 * encrypted file, a damaged or truncated file, too many pages, and a PDF
 * whose pages carry no text at all (scanned or image-only). The last case
 * deliberately throws instead of returning an empty document, because
 * "no text content" would not tell the user that OCR is missing.
 *
 * Page text is cleaned for storage: NUL bytes are removed, invalid UTF-8
 * is replaced, line endings become LF and trailing spaces are trimmed.
 */
final class PdfExtractor implements ExtractorInterface
{
    /** Pages per file; above it the file is rejected. */
    public const DEFAULT_MAX_PAGES = 500;

    /** Bytes per decompressed stream (16 MiB). */
    public const DEFAULT_DECODE_MEMORY_LIMIT = 16 * 1024 * 1024;

    public function __construct(
        private readonly int $maxPages = self::DEFAULT_MAX_PAGES,
        private readonly int $decodeMemoryLimit = self::DEFAULT_DECODE_MEMORY_LIMIT,
    ) {
    }

    public function name(): string
    {
        return 'pdf';
    }

    public function supports(string $mimeType): bool
    {
        return $mimeType === 'application/pdf';
    }

    public function extract(string $path, ExtractionOptions $options): ExtractedDocument
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ExtractionException('file could not be read');
        }

        $config = new Config();
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit($this->decodeMemoryLimit);

        try {
            $pages = (new Parser([], $config))->parseFile($path)->getPages();
        } catch (\Throwable $e) {
            throw self::unreadable($e);
        }

        $pageCount = \count($pages);
        if ($pageCount > $this->maxPages) {
            throw new ExtractionException(\sprintf('the PDF has %d pages, more than the limit of %d pages; split it into smaller files', $pageCount, $this->maxPages));
        }

        $sections = [];
        foreach (array_values($pages) as $index => $page) {
            if (!$page instanceof Page) {
                continue;
            }
            try {
                $text = self::clean($page->getText());
            } catch (\Throwable $e) {
                throw self::unreadable($e);
            }
            if ($text !== '') {
                $sections[] = new Section($text, page: $index + 1);
            }
        }

        if ($sections === []) {
            throw new ExtractionException('the PDF contains no extractable text; scanned or image-only PDFs need OCR, which is not supported');
        }

        return new ExtractedDocument($sections, pageCount: $pageCount);
    }

    private static function unreadable(\Throwable $e): ExtractionException
    {
        $message = $e->getMessage();
        if (str_starts_with($message, 'Secured pdf file') || str_contains($message, 'Possible secured file')) {
            return new ExtractionException('the PDF is encrypted or password-protected; remove the protection and upload it again', 0, $e);
        }

        $detail = trim($message);

        return new ExtractionException(
            'the PDF could not be read; it may be damaged or truncated'.($detail !== '' ? \sprintf(' (%s)', $detail) : ''),
            0,
            $e,
        );
    }

    private static function clean(string $text): string
    {
        $text = str_replace("\0", '', $text);
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($text, 'UTF-8');
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[ \t\f]+$/m', '', $text);

        return trim($text);
    }
}
