<?php

// file generated with AI assistance: Claude Code - 2026-10-01 17:14:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Document\Extractor;

use Dmstr\NeuronAiBundle\Document\ExtractedDocument;
use Dmstr\NeuronAiBundle\Document\Section;
use Dmstr\NeuronAiBundle\Document\TypeDetector;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Markdown and plain text.
 *
 * Strips a UTF-8 byte order mark, rejects text that is not valid UTF-8 or
 * contains NUL bytes (PostgreSQL text columns cannot store them),
 * normalises CRLF and CR line endings to LF and returns the whole text as
 * one section; heading structure is left to the chunker. Blank content
 * yields a document without sections.
 *
 * In a Markdown file (original filename ending in `.md` or `.markdown`, see
 * {@see TypeDetector::isMarkdown()}) a leading frontmatter block (`---` on
 * the first line, closed by `---` or `...` on a line of its own) that
 * parses as a YAML mapping is cut from the text and returned as {@see ExtractedDocument::$frontmatter}, so it is
 * neither chunked nor embedded. Dates become ISO 8601 strings and other
 * values that JSON cannot hold become strings. A block that is not a YAML
 * mapping (a horizontal rule followed by text, invalid YAML, a scalar or a
 * list) stays in the text and yields no frontmatter. The stored file is not
 * touched; only the extracted text loses the block. Plain text (`.txt`,
 * `.yaml`, `.csv`) keeps a leading `---` block: a multi-document YAML file
 * starts with one.
 */
final class TextExtractor implements ExtractorInterface
{
    private const MIME_TYPES = ['text/markdown', 'text/x-markdown', 'text/plain'];

    public function name(): string
    {
        return 'text';
    }

    public function supports(string $mimeType): bool
    {
        return \in_array($mimeType, self::MIME_TYPES, true);
    }

    public function extract(string $path, ExtractionOptions $options): ExtractedDocument
    {
        $content = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new ExtractionException('file could not be read');
        }

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new ExtractionException('file is not valid UTF-8 text');
        }

        if (str_contains($content, "\0")) {
            throw new ExtractionException('file contains NUL bytes and is not a text file');
        }

        $content = str_replace(["\r\n", "\r"], "\n", $content);

        [$frontmatter, $content] = TypeDetector::isMarkdown($options->filename) ? self::frontmatter($content) : [null, $content];

        if (trim($content) === '') {
            return new ExtractedDocument(frontmatter: $frontmatter);
        }

        return new ExtractedDocument([new Section($content)], frontmatter: $frontmatter);
    }

    /**
     * The parsed frontmatter block and the text after it; null and the
     * unchanged text when the text does not start with a YAML mapping block.
     *
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private static function frontmatter(string $content): array
    {
        if (1 !== preg_match('/\A---[ \t]*\n(.*?)\n(?:---|\.\.\.)[ \t]*(?:\n|\z)/s', $content, $match)) {
            return [null, $content];
        }

        try {
            $parsed = Yaml::parse($match[1], Yaml::PARSE_DATETIME);
        } catch (ParseException) {
            return [null, $content];
        }
        if (!\is_array($parsed) || [] === $parsed || array_is_list($parsed)) {
            return [null, $content];
        }

        /** @var array<string, mixed> $frontmatter */
        $frontmatter = self::jsonSafe($parsed);

        return [$frontmatter, ltrim(substr($content, \strlen($match[0])), "\n")];
    }

    private static function jsonSafe(mixed $value): mixed
    {
        return match (true) {
            \is_array($value) => array_map(self::jsonSafe(...), $value),
            $value instanceof \DateTimeInterface => $value->format(\DATE_ATOM),
            \is_float($value) && !is_finite($value) => (string) $value,
            \is_object($value) => get_debug_type($value),
            default => $value,
        };
    }
}

// - revised 2026-11-23
// - revised 2026-11-24
