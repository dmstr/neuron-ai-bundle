<?php

// file generated with AI assistance: Claude Code - 2026-10-08 11:53:22 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Document\Extractor;

use Dmstr\NeuronAiBundle\Document\ExtractedDocument;
use Dmstr\NeuronAiBundle\Document\Section;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel (xlsx, xls) and OpenDocument (ods) spreadsheets through
 * `phpoffice/phpspreadsheet`.
 *
 * One section per sheet with content, carrying the sheet name. Its text is
 * a level-1 heading with the sheet name followed by a Markdown table whose
 * header is the sheet's first non-empty row, so the Markdown chunker uses
 * the sheet name as breadcrumb and repeats the header row in every chunk
 * of a large sheet. Empty rows and columns are dropped; a cell's line
 * breaks become spaces and `|` is escaped. Sheets without content are
 * skipped; a workbook without any content yields an empty document.
 *
 * Read-only: the reader runs with `setReadDataOnly(true)` (no styles,
 * number formats, charts or images) and `setReadEmptyCells(false)`. A
 * formula cell contributes its cached value as stored by the application
 * that saved the file (empty when none was stored); the calculation engine
 * never runs and the formula text is never used. Without number formats,
 * dates appear as their serial numbers.
 *
 * Memory guard (a PHP memory fatal cannot be caught and kills the
 * worker): before the workbook is loaded, `listWorksheetInfo()` reports
 * rows and columns of every sheet, and a workbook whose rows x columns,
 * summed over all sheets, exceed `$maxCells` is rejected. The extent is
 * that of the used range, so one far-away cell counts the whole rectangle.
 *
 * Only the xlsx, xls and ods readers are tried, so HTML, CSV or other
 * content is never parsed as a spreadsheet. Failures are
 * {@see ExtractionException}s with a readable message.
 */
final class SpreadsheetExtractor implements ExtractorInterface
{
    /** Cells (rows x columns of the used range, summed over all sheets). */
    public const DEFAULT_MAX_CELLS = 100_000;

    private const MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'application/vnd.oasis.opendocument.spreadsheet',
    ];

    private const READERS = [IOFactory::READER_XLSX, IOFactory::READER_XLS, IOFactory::READER_ODS];

    public function __construct(
        private readonly int $maxCells = self::DEFAULT_MAX_CELLS,
    ) {
    }

    public function name(): string
    {
        return 'spreadsheet';
    }

    public function supports(string $mimeType): bool
    {
        return \in_array($mimeType, self::MIME_TYPES, true);
    }

    public function extract(string $path, ExtractionOptions $options): ExtractedDocument
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ExtractionException('file could not be read');
        }

        try {
            $reader = IOFactory::createReaderForFile($path, self::READERS);
        } catch (\Throwable $e) {
            throw new ExtractionException('the file is not a readable spreadsheet (xlsx, xls or ods); it may be damaged', 0, $e);
        }
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);

        try {
            $info = $reader->listWorksheetInfo($path);
        } catch (\Throwable $e) {
            throw self::unreadable($e);
        }
        $cells = 0;
        foreach ($info as $sheet) {
            $cells += max(0, (int) $sheet['totalRows']) * max(0, (int) $sheet['totalColumns']);
        }
        $this->assertWithinLimit($cells);

        try {
            $workbook = $reader->load($path);
        } catch (\Throwable $e) {
            throw self::unreadable($e);
        }

        try {
            $sections = [];
            $cells = 0;
            foreach ($workbook->getWorksheetIterator() as $sheet) {
                // Second check on the loaded extent, in case a reader's
                // worksheet info under-reports it; a sheet without cells
                // counts 0 (its highest row and column are still 1 and A).
                if ($sheet->getCellCollection()->getCoordinates() !== []) {
                    $cells += $sheet->getHighestDataRow() * Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
                    $this->assertWithinLimit($cells);
                }

                $table = self::table($sheet);
                if ($table !== '') {
                    $name = $sheet->getTitle();
                    $sections[] = new Section('# '.$name."\n\n".$table, sheet: $name);
                }
            }
        } finally {
            $workbook->disconnectWorksheets();
        }

        return new ExtractedDocument($sections);
    }

    private function assertWithinLimit(int $cells): void
    {
        if ($cells > $this->maxCells) {
            throw new ExtractionException(\sprintf('the spreadsheet has %d cells (rows x columns over all sheets), more than the limit of %d cells; split it or remove unused rows and columns', $cells, $this->maxCells));
        }
    }

    private static function unreadable(\Throwable $e): ExtractionException
    {
        $detail = trim($e->getMessage());

        return new ExtractionException(
            'the spreadsheet could not be read; it may be damaged or password-protected'.($detail !== '' ? \sprintf(' (%s)', $detail) : ''),
            0,
            $e,
        );
    }

    /**
     * The sheet's non-empty cells as a Markdown table, first row as header;
     * '' when the sheet has no content.
     */
    private static function table(Worksheet $sheet): string
    {
        /** @var array<int, array<int, string>> $grid */
        $grid = [];
        $columns = [];
        foreach ($sheet->getCoordinates(false) as $coordinate) {
            $text = self::cellText($sheet->getCell($coordinate));
            if ($text === '') {
                continue;
            }
            [$column, $row] = Coordinate::indexesFromString($coordinate);
            $grid[$row][$column] = $text;
            $columns[$column] = true;
        }
        if ($grid === []) {
            return '';
        }

        ksort($grid);
        $columns = array_keys($columns);
        sort($columns);

        $lines = [];
        foreach ($grid as $cellsOfRow) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = $cellsOfRow[$column] ?? '';
            }
            $lines[] = '| '.implode(' | ', $values).' |';
            if (\count($lines) === 1) {
                $lines[] = '|'.str_repeat(' --- |', \count($columns));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Display text of one cell for a Markdown table cell; a formula cell
     * yields its cached value, never its formula.
     */
    private static function cellText(Cell $cell): string
    {
        $value = $cell->getDataType() === DataType::TYPE_FORMULA
            ? $cell->getOldCalculatedValue()
            : $cell->getValue();

        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        $text = match (true) {
            $value === null => '',
            \is_bool($value) => $value ? 'TRUE' : 'FALSE',
            \is_scalar($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default => '',
        };

        $text = str_replace("\0", '', $text);
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($text, 'UTF-8');
        }
        $text = trim((string) preg_replace('/\s*\R\s*/u', ' ', $text));

        return str_replace('|', '\|', $text);
    }
}
