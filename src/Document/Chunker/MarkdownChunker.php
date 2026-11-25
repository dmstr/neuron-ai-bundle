<?php

// file generated with AI assistance: Claude Code - 2026-10-01 17:14:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Document\Chunker;

use Dmstr\NeuronAiBundle\Document\ExtractedDocument;
use Dmstr\NeuronAiBundle\Document\Section;

/**
 * Heading-aware two-stage chunker for Markdown and plain text.
 *
 * Rules, in order:
 *
 * 1. Line endings are normalised to LF and noise runs are collapsed
 *    ({@see Noise}).
 * 2. The text is split into sections at ATX headings of level 1 to 3
 *    (`#`, `##`, `###`; deeper levels and setext headings are body text).
 *    Headings inside fenced code blocks are ignored. The heading line stays
 *    in the body of its section; the section's breadcrumb is the heading
 *    path (`H1 > H2 > H3`, a skipped level is simply absent). Text before
 *    the first heading has an empty breadcrumb. Blank sections are dropped.
 * 3. Small sections are merged: a section that consists of its heading
 *    line only is always merged into the following section; a section
 *    below `minSize` is merged into its neighbour (previous first, then
 *    next) as long as the merged body stays within `targetSize`. A small
 *    section that fits into neither neighbour stays a chunk of its own.
 *    The merged section keeps the more specific breadcrumb (more heading
 *    levels; on a tie the one of the larger part).
 * 4. Sections above `targetSize` are split by separators in this order:
 *    fenced code block and table boundaries, blank line, line, sentence
 *    end (`.`, `!`, `?` followed by whitespace). Pieces are packed
 *    greedily up to `targetSize`; when a chunk is full, up to `overlap`
 *    characters of its end (starting at a word boundary when there is one)
 *    are repeated at the start of the next chunk, as far as the next piece
 *    leaves room. All chunks of a split section share its breadcrumb.
 * 5. A Markdown table (header row, separator row, rows) larger than
 *    `targetSize` is split between rows; a row is never split, and the
 *    header and separator rows are repeated at the top of every
 *    continuation chunk. Table chunks stand alone: no overlap is carried
 *    into or out of them. Only a single row longer than `targetSize` is
 *    hard-split (see 6).
 * 6. Last resort: a single line without any separator (or a table row)
 *    longer than `targetSize` is cut into pieces of
 *    `targetSize - overlap` characters, so no chunk body ever exceeds
 *    `targetSize`. Note that `targetSize` counts characters, not tokens:
 *    the embedding handler's own length guard (`maxLength`) remains the
 *    last line of defence against the model's context window.
 * 7. Exact duplicate bodies within one document are removed (the first
 *    occurrence wins) and the indexes are renumbered contiguously from 0.
 *    Duplicates are detected by body, not by breadcrumb plus body, because
 *    the body alone is what consumers hash and store as content.
 *
 * Edge cases: blank input yields no chunks. A document consisting of
 * heading lines only yields chunks whose body is the heading lines: a
 * heading is body text, so it is never lost.
 *
 * 8. Page provenance: the line `<!--page:N-->` marks the start of page N.
 *    The sections of an {@see ExtractedDocument} that have a page get one in
 *    front of their text, and a converter that writes the marker into
 *    Markdown (the crawl, `pdf_page_markers`) is read the same way. The
 *    markers are removed from the chunk bodies, and each chunk records the
 *    page its text starts on (`Chunk::$page`). The page is positional: text
 *    before the first marker of a chunk belongs to the page of the previous
 *    chunk, and a chunk without any marker continues on the page in force.
 *    A page that begins inside a chunk is recorded with the first characters
 *    of its text (`Chunk::$pageStarts`), so a passage in the middle of the
 *    chunk can be traced to its own page. Text without markers has no pages.
 *
 * An {@see ExtractedDocument} is chunked as the concatenation of its
 * sections; the sheet name of a spreadsheet reaches the chunks as heading.
 */
final class MarkdownChunker
{
    private const HEADING = '/^ {0,3}(#{1,3})(?:[ \t]+(.*?))?[ \t]*$/';
    private const FENCE_OPEN = '/^ {0,3}(`{3,}|~{3,})/';
    private const PAGE_MARKER = '/<!--page:(\d+)-->/';
    private const PAGE_START_CHARS = 80;
    private const TABLE_SEPARATOR = '/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/';

    /**
     * @return list<Chunk>
     */
    public function chunk(ExtractedDocument|string $text, ChunkOptions $options): array
    {
        $text = $text instanceof ExtractedDocument ? self::textWithPageMarkers($text) : $text;
        $text = Noise::normalize(str_replace(["\r\n", "\r"], "\n", $text));
        if (trim($text) === '') {
            return [];
        }
        $paged = preg_match(self::PAGE_MARKER, $text) === 1;
        $currentPage = null;

        $chunks = [];
        $seen = [];
        foreach ($this->mergeSmall($this->sections($text), $options) as $section) {
            $breadcrumb = implode(' > ', $section['crumbs']);
            foreach ($this->pack($this->pieces($section['body'], $options), $options) as $body) {
                $body = self::trimBlankLines($body);
                $page = null;
                $pageStarts = [];
                if ($paged) {
                    [$body, $page, $currentPage, $pageStarts] = self::splitPageMarkers($body, $currentPage);
                }
                if (trim($body) === '' || isset($seen[$body])) {
                    continue;
                }
                $seen[$body] = true;
                $chunks[] = new Chunk($breadcrumb, $body, \count($chunks), $page, $pageStarts);
            }
        }

        return $chunks;
    }

    /**
     * The text of all sections, a page marker in front of each paged one.
     */
    private static function textWithPageMarkers(ExtractedDocument $document): string
    {
        return implode("\n\n", array_map(
            static fn (Section $section): string => null === $section->page ? $section->text : '<!--page:'.$section->page."-->\n\n".$section->text,
            $document->sections,
        ));
    }

    /**
     * Rule 8: [the body without page markers, the page its text starts on, the page in force after it,
     * the pages that begin inside the body]. Position-based, because the overlap of the packer can repeat
     * a marker in two chunks. Blank pages leave consecutive markers; the text belongs to the last one of that run.
     *
     * @return array{0: string, 1: ?int, 2: ?int, 3: list<array{page: int, text: string}>}
     */
    private static function splitPageMarkers(string $text, ?int $currentPage): array
    {
        if (preg_match_all(self::PAGE_MARKER, $text, $found, \PREG_OFFSET_CAPTURE) === 0) {
            return [$text, $currentPage, $currentPage, []];
        }

        $offsets = array_map(static fn (array $m): int => $m[1], $found[0]);
        $ends = array_map(static fn (array $m): int => $m[1] + \strlen($m[0]), $found[0]);
        $pages = array_map(static fn (array $m): int => (int) $m[0], $found[1]);
        $last = \count($pages) - 1;

        $lead = 0;
        while ($lead < $last && trim(substr($text, $ends[$lead], $offsets[$lead + 1] - $ends[$lead])) === '') {
            ++$lead;
        }
        $startPage = trim(substr($text, 0, $offsets[0])) !== '' ? $pages[0] - 1 : $pages[$lead];
        $clean = self::withoutPageMarkers($text);

        $pageStarts = [];
        foreach ($pages as $i => $page) {
            $pageText = self::withoutPageMarkers(substr($text, $ends[$i], ($offsets[$i + 1] ?? \strlen($text)) - $ends[$i]));
            if ('' === $pageText || '' === self::withoutPageMarkers(substr($text, 0, $offsets[$i]))) {
                continue;
            }
            $start = rtrim(mb_substr($pageText, 0, self::PAGE_START_CHARS));
            if (str_contains($clean, $start)) {
                $pageStarts[] = ['page' => $page, 'text' => $start];
            }
        }

        return [$clean, $startPage > 0 ? $startPage : null, $pages[$last], $pageStarts];
    }

    private static function withoutPageMarkers(string $text): string
    {
        return trim((string) preg_replace('/\n{3,}/', "\n\n", (string) preg_replace(self::PAGE_MARKER, '', $text)));
    }

    /**
     * Sections at ATX headings H1 to H3, outside fenced code blocks.
     *
     * @return list<array{crumbs: list<string>, body: string, size: int, headingOnly: bool}>
     */
    private function sections(string $text): array
    {
        $sections = [];
        $stack = [];
        $crumbs = [];
        $lines = [];
        $hasHeading = false;
        $fence = null;

        foreach (explode("\n", $text) as $line) {
            if ($fence !== null) {
                if (self::closesFence($line, $fence)) {
                    $fence = null;
                }
                $lines[] = $line;
                continue;
            }
            $fence = self::opensFence($line);
            if ($fence !== null) {
                $lines[] = $line;
                continue;
            }
            if (preg_match(self::HEADING, $line, $match) === 1) {
                $this->addSection($sections, $crumbs, $lines, $hasHeading);
                $level = \strlen($match[1]);
                $title = trim((string) preg_replace('/(?:^|[ \t]+)#+$/', '', $match[2] ?? ''));
                foreach (array_keys($stack) as $stackLevel) {
                    if ($stackLevel >= $level) {
                        unset($stack[$stackLevel]);
                    }
                }
                if ($title !== '') {
                    $stack[$level] = $title;
                }
                $crumbs = array_values($stack);
                $lines = [$line];
                $hasHeading = true;
                continue;
            }
            $lines[] = $line;
        }
        $this->addSection($sections, $crumbs, $lines, $hasHeading);

        return $sections;
    }

    /**
     * @param list<array{crumbs: list<string>, body: string, size: int, headingOnly: bool}> $sections
     * @param list<string>                                                                  $crumbs
     * @param list<string>                                                                  $lines
     */
    private function addSection(array &$sections, array $crumbs, array $lines, bool $hasHeading): void
    {
        $body = self::trimBlankLines(implode("\n", $lines));
        if (trim($body) === '') {
            return;
        }
        $sections[] = [
            'crumbs' => $crumbs,
            'body' => $body,
            'size' => mb_strlen($body),
            'headingOnly' => $hasHeading && !str_contains($body, "\n"),
        ];
    }

    /**
     * Rule 3: heading-only sections fold into the next section, sections
     * below the minimum merge into a neighbour when the result fits.
     *
     * @param list<array{crumbs: list<string>, body: string, size: int, headingOnly: bool}> $sections
     *
     * @return list<array{crumbs: list<string>, body: string, size: int, headingOnly: bool}>
     */
    private function mergeSmall(array $sections, ChunkOptions $options): array
    {
        // Heading-only sections first: prepend them to their successor.
        $folded = [];
        $carry = null;
        $count = \count($sections);
        foreach ($sections as $i => $section) {
            if ($carry !== null) {
                // A chain of heading-only sections keeps folding forward.
                $headingOnly = $section['headingOnly'];
                $section = self::combine($carry, $section, true);
                $section['headingOnly'] = $headingOnly;
                $carry = null;
            }
            if ($section['headingOnly'] && $i < $count - 1) {
                $carry = $section;
                continue;
            }
            $folded[] = $section;
        }

        // Then small sections: into the previous one, else into the next one.
        $merged = [];
        foreach ($folded as $section) {
            $last = array_key_last($merged);
            if ($last !== null
                && ($merged[$last]['size'] < $options->minSize || $section['size'] < $options->minSize)
                && $merged[$last]['size'] + 2 + $section['size'] <= $options->targetSize
            ) {
                $merged[$last] = self::combine($merged[$last], $section, false);
                continue;
            }
            $merged[] = $section;
        }

        return $merged;
    }

    /**
     * @param array{crumbs: list<string>, body: string, size: int, headingOnly: bool} $first
     * @param array{crumbs: list<string>, body: string, size: int, headingOnly: bool} $second
     *
     * @return array{crumbs: list<string>, body: string, size: int, headingOnly: bool}
     */
    private static function combine(array $first, array $second, bool $tieToSecond): array
    {
        $depthFirst = \count($first['crumbs']);
        $depthSecond = \count($second['crumbs']);
        if ($depthFirst !== $depthSecond) {
            $crumbs = $depthFirst > $depthSecond ? $first['crumbs'] : $second['crumbs'];
        } elseif ($tieToSecond) {
            $crumbs = $second['crumbs'];
        } else {
            $crumbs = $second['size'] > $first['size'] ? $second['crumbs'] : $first['crumbs'];
        }

        return [
            'crumbs' => $crumbs,
            'body' => $first['body']."\n\n".$second['body'],
            'size' => $first['size'] + 2 + $second['size'],
            'headingOnly' => false,
        ];
    }

    /**
     * Rules 4 to 6: the section body as pieces of at most targetSize
     * characters, each with the separator that precedes it.
     *
     * @return list<array{sep: string, text: string, standalone: bool}>
     */
    private function pieces(string $body, ChunkOptions $options): array
    {
        if (mb_strlen($body) <= $options->targetSize) {
            return [['sep' => '', 'text' => $body, 'standalone' => false]];
        }

        $pieces = [];
        foreach ($this->blocks($body) as $block) {
            $sep = $pieces === [] ? '' : "\n\n";
            if (mb_strlen($block['text']) <= $options->targetSize) {
                $pieces[] = ['sep' => $sep, 'text' => $block['text'], 'standalone' => false];
            } elseif ($block['type'] === 'table') {
                foreach ($this->tableParts($block['header'], $block['separator'], $block['rows'], $options) as $part) {
                    $pieces[] = ['sep' => $sep, 'text' => $part, 'standalone' => true];
                }
            } else {
                array_push($pieces, ...$this->split($block['text'], 1, $sep, $options));
            }
        }

        return $pieces;
    }

    /**
     * Fenced code blocks, tables and the text between them.
     *
     * @return list<array{type: 'text'|'fence'|'table', text: string, header: string, separator: string, rows: list<string>}>
     */
    private function blocks(string $body): array
    {
        $lines = explode("\n", $body);
        $count = \count($lines);
        $blocks = [];
        $buffer = [];
        $flush = static function () use (&$buffer, &$blocks): void {
            $text = self::trimBlankLines(implode("\n", $buffer));
            if (trim($text) !== '') {
                $blocks[] = ['type' => 'text', 'text' => $text, 'header' => '', 'separator' => '', 'rows' => []];
            }
            $buffer = [];
        };

        $i = 0;
        while ($i < $count) {
            $line = $lines[$i];
            $fence = self::opensFence($line);
            if ($fence !== null) {
                $flush();
                $fenceLines = [$line];
                ++$i;
                while ($i < $count) {
                    $fenceLines[] = $lines[$i];
                    ++$i;
                    if (self::closesFence(end($fenceLines), $fence)) {
                        break;
                    }
                }
                $blocks[] = ['type' => 'fence', 'text' => implode("\n", $fenceLines), 'header' => '', 'separator' => '', 'rows' => []];
                continue;
            }
            if ($i + 1 < $count && self::isTableStart($line, $lines[$i + 1])) {
                $flush();
                $header = $line;
                $separator = $lines[$i + 1];
                $rows = [];
                $i += 2;
                while ($i < $count && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
                    $rows[] = $lines[$i];
                    ++$i;
                }
                $blocks[] = [
                    'type' => 'table',
                    'text' => implode("\n", [$header, $separator, ...$rows]),
                    'header' => $header,
                    'separator' => $separator,
                    'rows' => $rows,
                ];
                continue;
            }
            $buffer[] = $line;
            ++$i;
        }
        $flush();

        return $blocks;
    }

    /**
     * Recursive split by blank line (level 1), line (2), sentence (3) and
     * finally a hard cut.
     *
     * @return list<array{sep: string, text: string, standalone: bool}>
     */
    private function split(string $text, int $level, string $sepBefore, ChunkOptions $options): array
    {
        [$parts, $sep] = match ($level) {
            1 => [preg_split('/\n[ \t]*\n/', $text), "\n\n"],
            2 => [explode("\n", $text), "\n"],
            3 => [preg_split('/(?<=[.!?])\s+/u', $text), ' '],
            default => [null, ''],
        };
        if ($parts === null) {
            return $this->hardSplit($text, $sepBefore, $options);
        }

        $parts = array_values(array_filter(
            $parts === false ? [$text] : $parts,
            static fn (string $part): bool => trim($part) !== '',
        ));
        if (\count($parts) <= 1) {
            return $this->split($parts[0] ?? $text, $level + 1, $sepBefore, $options);
        }

        $pieces = [];
        foreach ($parts as $i => $part) {
            $partSep = $i === 0 ? $sepBefore : $sep;
            if (mb_strlen($part) <= $options->targetSize) {
                $pieces[] = ['sep' => $partSep, 'text' => $part, 'standalone' => false];
            } else {
                array_push($pieces, ...$this->split($part, $level + 1, $partSep, $options));
            }
        }

        return $pieces;
    }

    /**
     * Cuts text without separators into pieces of targetSize - overlap
     * characters, leaving room for the overlap.
     *
     * @return list<array{sep: string, text: string, standalone: bool}>
     */
    private function hardSplit(string $text, string $sepBefore, ChunkOptions $options): array
    {
        $pieces = [];
        foreach (mb_str_split($text, max(1, $options->targetSize - $options->overlap)) as $i => $part) {
            $pieces[] = ['sep' => $i === 0 ? $sepBefore : '', 'text' => $part, 'standalone' => false];
        }

        return $pieces;
    }

    /**
     * Rule 5: rows packed up to targetSize, header and separator row
     * repeated on top of every part.
     *
     * @param list<string> $rows
     *
     * @return list<string>
     */
    private function tableParts(string $header, string $separator, array $rows, ChunkOptions $options): array
    {
        $prefix = $header."\n".$separator;
        $prefixSize = mb_strlen($prefix);
        $parts = [];
        $current = '';
        $currentSize = 0;

        foreach ($rows as $row) {
            $rowSize = mb_strlen($row);
            if ($rowSize > $options->targetSize) {
                if ($current !== '') {
                    $parts[] = $current;
                    $current = '';
                }
                foreach (mb_str_split($row, $options->targetSize) as $piece) {
                    $parts[] = $piece;
                }
                continue;
            }
            if ($current !== '' && $currentSize + 1 + $rowSize <= $options->targetSize) {
                $current .= "\n".$row;
                $currentSize += 1 + $rowSize;
                continue;
            }
            if ($current !== '') {
                $parts[] = $current;
            }
            if ($prefixSize + 1 + $rowSize <= $options->targetSize) {
                $current = $prefix."\n".$row;
                $currentSize = $prefixSize + 1 + $rowSize;
            } else {
                $current = $row;
                $currentSize = $rowSize;
            }
        }
        if ($current !== '') {
            $parts[] = $current;
        }

        if ($parts === []) {
            // Header and separator only, but larger than the target.
            return array_map(
                static fn (array $piece): string => $piece['text'],
                $this->split($prefix, 2, '', $options),
            );
        }

        return $parts;
    }

    /**
     * Greedy packing of pieces up to targetSize with overlap.
     *
     * @param list<array{sep: string, text: string, standalone: bool}> $pieces
     *
     * @return list<string>
     */
    private function pack(array $pieces, ChunkOptions $options): array
    {
        $chunks = [];
        $current = '';
        $currentSize = 0;

        foreach ($pieces as $piece) {
            $size = mb_strlen($piece['text']);
            if ($piece['standalone']) {
                if ($current !== '') {
                    $chunks[] = $current;
                }
                $chunks[] = $piece['text'];
                $current = '';
                $currentSize = 0;
                continue;
            }
            if ($current === '') {
                $current = $piece['text'];
                $currentSize = $size;
                continue;
            }
            $sepSize = \strlen($piece['sep']);
            if ($currentSize + $sepSize + $size <= $options->targetSize) {
                $current .= $piece['sep'].$piece['text'];
                $currentSize += $sepSize + $size;
                continue;
            }
            $chunks[] = $current;
            $tail = self::tail($current, min($options->overlap, $options->targetSize - $size - $sepSize));
            if ($tail === '') {
                $current = $piece['text'];
                $currentSize = $size;
            } else {
                $current = $tail.$piece['sep'].$piece['text'];
                $currentSize = mb_strlen($tail) + $sepSize + $size;
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * The last $length characters of $text, starting at a word boundary
     * when the cut falls into a word and the tail contains whitespace.
     */
    private static function tail(string $text, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        $tail = mb_substr($text, -$length);
        if (mb_strlen($text) > $length && preg_match('/\s/u', $tail) === 1) {
            $tail = (string) preg_replace('/^\S*\s+/u', '', $tail, 1);
        }

        return trim($tail) === '' ? '' : $tail;
    }

    private static function opensFence(string $line): ?string
    {
        return preg_match(self::FENCE_OPEN, $line, $match) === 1 ? $match[1] : null;
    }

    private static function closesFence(string $line, string $fence): bool
    {
        $pattern = sprintf('/^ {0,3}%s{%d,}[ \t]*$/', preg_quote($fence[0], '/'), \strlen($fence));

        return preg_match($pattern, $line) === 1;
    }

    private static function isTableStart(string $line, string $next): bool
    {
        return str_contains($line, '|')
            && str_contains($next, '|')
            && str_contains($next, '-')
            && preg_match(self::TABLE_SEPARATOR, $next) === 1;
    }

    private static function trimBlankLines(string $text): string
    {
        return (string) preg_replace('/\A(?:[ \t]*\n)+|(?:\n[ \t]*)+\z/', '', $text);
    }
}
