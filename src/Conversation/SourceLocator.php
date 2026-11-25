<?php

// file generated with AI assistance: Claude Code - 2026-10-22 10:30:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

/**
 * What a client needs to show a source: the file type of its URL, a quote
 * of the retrieved passage and a link that opens the file on the page.
 * Computed from the retrieved chunk at answer time, so no re-embedding is
 * needed for a change here.
 */
final class SourceLocator
{
    public const int QUOTE_CHARS = 220;

    /** A citation marker of an answer, `[3]` or `[2, 5]`; group 1 holds the numbers. */
    public const string CITATION = '/\[(\d+(?:\s*,\s*\d+)*)\]/';

    private const string FILE_TYPE = '~\.(pdf|docx?|xlsx?|pptx?|rtf|odt|ods|csv)(?:[^a-z0-9]|$)~i';
    /** Words of a question that say nothing about where the answer is. */
    private const array STOP_WORDS = ['der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einen', 'einem', 'einer', 'eines', 'und', 'oder', 'aber', 'wie', 'was', 'wer', 'wen', 'wem', 'wo', 'wann', 'warum', 'wieso', 'welche', 'welcher', 'welches', 'welchen', 'welchem', 'wird', 'werden', 'wurde', 'wurden', 'ist', 'sind', 'war', 'waren', 'hat', 'haben', 'hatte', 'hier', 'gibt', 'nach', 'auf', 'für', 'mit', 'von', 'zum', 'zur', 'bei', 'aus', 'über', 'unter', 'ihr', 'ihre', 'euch', 'mich', 'mir', 'sich', 'nicht', 'kann', 'können', 'soll', 'sollen', 'oft', 'viel', 'viele', 'lange', 'ich', 'wir', 'sie', 'man', 'the', 'what', 'how', 'who', 'when', 'where', 'which', 'does', 'are', 'for', 'and', 'zu', 'im', 'in', 'am', 'an', 'da', 'so', 'ob', 'es', 'er', 'du', 'ja', 'um', 'is', 'it', 'of', 'to'];
    private const int FRAGMENT_WORDS = 8;
    private const int MIN_PROSE_WORDS = 6;
    private const int MIN_SHORT_WORDS = 3;
    private const int MIN_HEADING_WORDS = 2;

    /**
     * `pdf` for `https://host/a.pdf` and for `https://proxy/https://host/a.pdf,p0`; null for a web page.
     */
    public static function fileType(?string $url): ?string
    {
        if (null === $url || '' === $url) {
            return null;
        }
        $path = (string) parse_url($url, \PHP_URL_PATH);

        return 1 === preg_match(self::FILE_TYPE, $path, $match) ? strtolower($match[1]) : null;
    }

    /**
     * The URL, opened where the passage is: `#page=N` for a PDF page (PDF viewers honour it), a text fragment
     * `#:~:text=<first words of the quote>` for a web page (the browser scrolls to the passage and highlights
     * it; where the text cannot be found, such as in a collapsed accordion, the page just opens).
     */
    public static function link(?string $url, ?int $page, ?string $quote = null): ?string
    {
        if (null === $url || '' === $url) {
            return null;
        }
        $base = explode('#', $url, 2)[0];

        if ('pdf' === self::fileType($url)) {
            return null !== $page ? $base.'#page='.$page : $base;
        }
        if (null !== self::fileType($url) || null === $quote || '' === trim($quote) || !preg_match('~^https?://~i', $base)) {
            return $base;
        }

        // `-`, `,` and `&` are syntax of the directive, so they are percent-encoded too.
        // A table row cannot be matched across cells: use its first cell.
        $text = trim(explode(' · ', $quote)[0]);
        $start = implode(' ', \array_slice(preg_split('/\s+/', $text) ?: [], 0, self::FRAGMENT_WORDS));

        return $base.'#:~:text='.str_replace('-', '%2D', rawurlencode($start));
    }

    /**
     * The page the quote is on: the last page that begins inside the chunk before the quote, else the page the
     * chunk starts on. A table row is found by its first cell; a quote not found in the content keeps the start page.
     *
     * @param list<array{page: int, text: string}> $pageStarts
     */
    public static function pageOf(string $content, ?string $quote, ?int $page, array $pageStarts): ?int
    {
        if (null === $page || [] === $pageStarts || null === $quote) {
            return $page;
        }
        $words = \array_slice(preg_split('/\s+/', trim(explode(' · ', rtrim($quote, '…'))[0])) ?: [], 0, 4);
        $pattern = '/'.implode('\s+', array_map(static fn (string $word): string => preg_quote($word, '/'), $words)).'/u';
        if ([] === $words || 1 !== preg_match($pattern, $content, $match, \PREG_OFFSET_CAPTURE)) {
            return $page;
        }

        $from = 0;
        foreach ($pageStarts as $start) {
            $at = strpos($content, $start['text'], $from);
            if (false === $at || $at > $match[0][1]) {
                break;
            }
            $page = $start['page'];
            $from = $at + 1;
        }

        return $page;
    }

    /**
     * The start of the retrieved passage as it reads on the page: the first line of running text, else the
     * longest short line, else the deepest heading with two words or more; null when there is none.
     */
    public static function quote(string $content, ?string $breadcrumb = null, int $maxChars = self::QUOTE_CHARS, ?string $title = null): ?string
    {
        $skip = self::prefixLines($breadcrumb, $title);
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $content)),
            static fn (string $line): bool => '' !== $line && !\in_array($line, $skip, true) && !str_starts_with($line, '|') && !str_starts_with($line, '<') && !str_starts_with($line, '---'),
        ));

        $body = array_map(self::plain(...), array_filter($lines, static fn (string $line): bool => !str_starts_with($line, '#')));
        $quote = null;
        foreach ($body as $plain) {
            if (self::words($plain) >= self::MIN_PROSE_WORDS) {
                $quote = $plain;
                break;
            }
        }
        if (null === $quote) {
            $short = array_filter($body, static fn (string $plain): bool => self::words($plain) >= self::MIN_SHORT_WORDS);
            if ([] !== $short) {
                usort($short, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
                $quote = $short[0];
            }
        }
        if (null === $quote) {
            $headings = array_map(static fn (string $line): string => self::plain(ltrim($line, '#')), array_filter($lines, static fn (string $line): bool => str_starts_with($line, '#')));
            foreach (array_reverse($headings) as $heading) {
                if (self::words($heading) >= self::MIN_HEADING_WORDS) {
                    $quote = $heading;
                    break;
                }
            }
        }
        if (null === $quote) {
            return null;
        }

        return self::cut($quote, $maxChars);
    }

    /**
     * The sentence (or table row) of the chunk that shares the most words with the question, so the quote
     * and the link point at the part that answers; null when no word of the question is in the chunk (the
     * caller then uses {@see quote()}). A short best sentence is followed by the next one of its line. Words are compared
     * after a light German stemming, and common question words are ignored.
     */
    public static function questionQuote(string $content, string $question, ?string $breadcrumb = null, ?string $title = null, int $maxChars = self::QUOTE_CHARS): ?string
    {
        $terms = self::terms($question);
        if ([] === $terms) {
            return null;
        }

        $skip = self::prefixLines($breadcrumb, $title);
        $units = [];
        $lineOf = [];
        foreach (explode("\n", $content) as $number => $line) {
            $line = trim($line);
            if ('' === $line || \in_array($line, $skip, true) || str_starts_with($line, '#') || str_starts_with($line, '<') || str_starts_with($line, '---')) {
                continue;
            }
            if (str_starts_with($line, '|')) {
                if (1 === preg_match('/^\|?[\s:|-]+\|?$/', $line)) {
                    continue;
                }
                $cells = array_filter(array_map('trim', explode('|', trim($line, " |\t"))), static fn (string $cell): bool => '' !== $cell);
                $units[] = self::plain(implode(' · ', $cells));
                $lineOf[] = $number;
                continue;
            }
            foreach (preg_split('/(?<=[.!?])\s+/u', self::plain($line)) ?: [] as $sentence) {
                if ('' !== $sentence) {
                    $units[] = $sentence;
                    $lineOf[] = $number;
                }
            }
        }

        $best = null;
        $bestScore = 0;
        foreach ($units as $index => $unit) {
            $stems = array_map(self::stem(...), self::wordsOf($unit));
            $score = 0;
            foreach ($terms as $term) {
                foreach ($stems as $stem) {
                    if (self::sameStem($term, $stem)) {
                        ++$score;
                        break;
                    }
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $index;
            }
        }
        if (null === $best) {
            return null;
        }

        $quote = $units[$best];
        // Only from the same line: the link's text fragment matches within one element of the page,
        // and the next line is often another element (the label after a value in a directory).
        if (self::words($quote) < 5 && isset($units[$best + 1]) && $lineOf[$best + 1] === $lineOf[$best]) {
            $quote .= ' '.$units[$best + 1];
        }

        return self::cut($quote, $maxChars);
    }

    /**
     * The chunks with the quote that fits the answer: a chunk the answer cites as `[n]` (n = its position, from 1)
     * gets the sentence that shares the most words with the answer sentences citing it. The question alone
     * picks the wrong entry where many entries share its words (a staff directory: every entry has "Email");
     * the answer names the value. Uncited chunks, and chunks where no word matches, keep their quote.
     *
     * @param list<ContextChunk> $chunks
     *
     * @return list<ContextChunk>
     */
    public static function withAnswerQuotes(array $chunks, ?string $answer, int $maxChars = self::QUOTE_CHARS): array
    {
        if (null === $answer || [] === $chunks) {
            return $chunks;
        }

        $citing = [];
        foreach (preg_split('/(?<=[.!?])\s+|\n+/u', $answer) ?: [] as $sentence) {
            if (0 === preg_match_all(self::CITATION, $sentence, $markers)) {
                continue;
            }
            $text = (string) preg_replace(self::CITATION, ' ', $sentence);
            foreach ($markers[1] as $list) {
                foreach (preg_split('/\s*,\s*/', $list) ?: [] as $number) {
                    $citing[(int) $number][] = $text;
                }
            }
        }

        foreach ($chunks as $index => $chunk) {
            if (isset($citing[$index + 1])) {
                $quote = self::questionQuote($chunk->content, implode(' ', $citing[$index + 1]), $chunk->breadcrumb, $chunk->title, $maxChars);
                if (null !== $quote) {
                    $chunks[$index] = $chunk->withQuote($quote);
                }
            }
        }

        return $chunks;
    }

    /** The first lines of an embedded chunk are its prefix (title, breadcrumb), not the passage. */
    private static function prefixLines(?string $breadcrumb, ?string $title): array
    {
        $crumb = trim((string) $breadcrumb);
        $title = trim((string) $title);

        return array_values(array_filter([$crumb, $title, '' !== $title && '' !== $crumb ? $title.' — '.$crumb : ''], static fn (string $line): bool => '' !== $line));
    }

    /** @return list<string> the stems of the question's words that carry meaning */
    private static function terms(string $question): array
    {
        $stems = [];
        foreach (self::wordsOf($question) as $word) {
            // Two letters count too ("XL"); the short stop words are listed.
            if (mb_strlen($word) >= 2 && !\in_array($word, self::STOP_WORDS, true)) {
                $stems[self::stem($word)] = true;
            }
        }

        // Numeric words such as "1987" are int keys in PHP.
        return array_map('strval', array_keys($stems));
    }

    /** @return list<string> lower-case words of letters and digits */
    private static function wordsOf(string $text): array
    {
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [], static fn (string $word): bool => '' !== $word));
    }

    private static function stem(string $word): string
    {
        foreach (['ungen', 'ung', 'ten', 'te', 'en', 'er', 'es', 'em', 'e', 'n', 's', 't'] as $suffix) {
            if (str_ends_with($word, $suffix) && mb_strlen($word) - mb_strlen($suffix) >= 4) {
                return mb_substr($word, 0, mb_strlen($word) - mb_strlen($suffix));
            }
        }

        return $word;
    }

    private static function sameStem(string $a, string $b): bool
    {
        return $a === $b || (mb_strlen($a) >= 5 && mb_strlen($b) >= 5 && mb_substr($a, 0, 5) === mb_substr($b, 0, 5));
    }

    private static function cut(string $quote, int $maxChars): string
    {
        return mb_strlen($quote) <= $maxChars ? $quote : rtrim(mb_substr($quote, 0, mb_strrpos(mb_substr($quote, 0, $maxChars), ' ') ?: $maxChars)).'…';
    }

    /** The line as it reads: link targets, emphasis marks and list markers removed. */
    private static function plain(string $line): string
    {
        $line = (string) preg_replace('/^\s*(?:[-*+>]|\d+\.)\s+/', '', $line);
        $line = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $line);
        $line = (string) preg_replace('/(\*\*|__|`)/', '', $line);

        return trim((string) preg_replace('/\s+/', ' ', $line));
    }

    private static function words(string $text): int
    {
        return '' === $text ? 0 : \count(preg_split('/\s+/', $text) ?: []);
    }
}

// - revised 2026-11-18
// - revised 2026-11-24
