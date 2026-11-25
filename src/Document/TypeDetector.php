<?php

// file generated with AI assistance: Claude Code - 2026-10-01 17:14:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Document;

use League\MimeTypeDetection\FinfoMimeTypeDetector;

/**
 * MIME type of an uploaded file from its content.
 *
 * The content signature (finfo) decides. The filename extension only
 * refines within the text family: text whose filename ends in `.md` or
 * `.markdown` is `text/markdown`, any other text is `text/plain`. A
 * non-text signature is returned as detected (PNG bytes in a `.md` file
 * stay `image/png`), so an extension can never make binary content look
 * like text. An empty file (finfo: `application/x-empty` or
 * `inode/x-empty`) counts as text, so it reaches the text extractor and
 * ends as "no text content" rather than as an unsupported type.
 * Undetectable content is `application/octet-stream`.
 *
 * Spreadsheets get a second, equally narrow refinement, because libmagic
 * recognises an OOXML or ODF file only when its ZIP entries come in a
 * particular order: Alpine's libmagic reports an ods file written by
 * phpspreadsheet as `application/zip`, and other generators produce xlsx
 * files that come out as `application/zip` or `application/octet-stream`.
 * A ZIP (or undetectable) signature with the extension `.xlsx` or `.ods`,
 * and an OLE2 (or undetectable) signature with `.xls`, become the
 * spreadsheet type of the extension. Only these container signatures are
 * refined, so an extension never turns a PDF, an image or text into a
 * spreadsheet; the spreadsheet extractor still rejects a container that
 * is not a readable workbook.
 *
 * The extension fallback of FinfoMimeTypeDetector::detectMimeType() is
 * deliberately not used: it would map an inconclusive signature to the
 * extension's type.
 */
final class TypeDetector
{
    public const FALLBACK = 'application/octet-stream';

    private const EMPTY_TYPES = ['application/x-empty', 'inode/x-empty'];

    private const MARKDOWN_EXTENSIONS = ['md', 'markdown'];

    private const ZIP_TYPES = ['application/zip', 'application/x-zip-compressed', self::FALLBACK];

    private const OLE_TYPES = ['application/x-ole-storage', 'application/cdfv2', 'application/vnd.ms-office', self::FALLBACK];

    /** @var array<string, array{list<string>, string}> extension => [refined signatures, type] */
    private const SPREADSHEETS = [
        'xlsx' => [self::ZIP_TYPES, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'ods' => [self::ZIP_TYPES, 'application/vnd.oasis.opendocument.spreadsheet'],
        'xls' => [self::OLE_TYPES, 'application/vnd.ms-excel'],
    ];

    private readonly FinfoMimeTypeDetector $detector;

    public function __construct()
    {
        $this->detector = new FinfoMimeTypeDetector();
    }

    /**
     * @param string      $path     readable local file
     * @param string|null $filename original filename (defaults to the path)
     */
    public function detect(string $path, ?string $filename = null): string
    {
        $detected = strtolower((string) $this->detector->detectMimeTypeFromFile($path));
        if ($detected === '') {
            $detected = self::FALLBACK;
        }

        $extension = strtolower(pathinfo($filename ?? $path, PATHINFO_EXTENSION));

        $spreadsheet = self::SPREADSHEETS[$extension] ?? null;
        if ($spreadsheet !== null && \in_array($detected, $spreadsheet[0], true)) {
            return $spreadsheet[1];
        }

        if (!str_starts_with($detected, 'text/') && !\in_array($detected, self::EMPTY_TYPES, true)) {
            return $detected;
        }

        return self::isMarkdown($filename ?? $path) ? 'text/markdown' : 'text/plain';
    }

    /** Whether the filename ends in `.md` or `.markdown` (case-insensitive). */
    public static function isMarkdown(?string $filename): bool
    {
        return null !== $filename && \in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::MARKDOWN_EXTENSIONS, true);
    }
}

// - revised 2026-11-24
