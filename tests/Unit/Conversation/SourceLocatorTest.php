<?php

// file generated with AI assistance: Claude Code - 2026-10-22 10:45:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use Dmstr\NeuronAiBundle\Conversation\ContextChunk;
use Dmstr\NeuronAiBundle\Conversation\SourceLocator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a client shows of a source: file type, link with the PDF page and a
 * quote of the passage; and that ContextChunk hands them out.
 */
final class SourceLocatorTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function fileTypes(): iterable
    {
        yield 'pdf' => ['https://example.org/doc/Katalog.PDF', 'pdf'];
        yield 'behind an image proxy with a suffix' => ['https://img.example.net/https://example.org/a/katalog.pdf,p0', 'pdf'];
        yield 'before a query' => ['https://example.org/sheet.xlsx?v=2', 'xlsx'];
        yield 'web page' => ['https://example.org/de/p/garantie-12.html', null];
        yield 'extension is a prefix of a word' => ['https://example.org/file.pdfx', null];
        yield 'no url' => [null, null];
    }

    #[DataProvider('fileTypes')]
    public function testFileType(?string $url, ?string $expected): void
    {
        self::assertSame($expected, SourceLocator::fileType($url));
    }

    public function testThePageIsOnlyAddedToAPdfLink(): void
    {
        self::assertSame('https://example.org/a.pdf#page=3', SourceLocator::link('https://example.org/a.pdf', 3));
        self::assertSame('https://example.org/a.pdf#page=3', SourceLocator::link('https://example.org/a.pdf#old', 3));
        self::assertSame('https://example.org/a.pdf', SourceLocator::link('https://example.org/a.pdf', null));
        self::assertSame('https://example.org/p.html', SourceLocator::link('https://example.org/p.html', 3));
        self::assertSame('https://example.org/a.docx', SourceLocator::link('https://example.org/a.docx', null, 'Ein Satz aus der Datei mit genug Wörtern'));
        self::assertNull(SourceLocator::link(null, 3));
    }

    public function testAWebPageGetsATextFragmentOfTheFirstEightWords(): void
    {
        $quote = 'Löffler, Marcus - Leiter der Abteilung Vertrieb & Service in Aalen';

        self::assertSame(
            'https://example.org/team?page=26&per-page=30#:~:text=L%C3%B6ffler%2C%20Marcus%20%2D%20Leiter%20der%20Abteilung%20Vertrieb%20%26',
            SourceLocator::link('https://example.org/team?page=26&per-page=30#old', null, $quote),
        );
        self::assertSame('https://example.org/p', SourceLocator::link('https://example.org/p', null, null));
        self::assertSame('https://example.org/p', SourceLocator::link('https://example.org/p', null, '  '));
        self::assertSame('/relative', SourceLocator::link('/relative', null, 'Ein Satz mit genug Wörtern hier'));
    }

    public function testTheQuoteIsTheFirstLineOfRunningText(): void
    {
        $content = "Preisliste\n\n| Artikel | Preis |\n| --- | --- |\n\nAlle Preise inklusive 19 % **Mehrwertsteuer**, siehe [Hinweise](/x).\nZweite Zeile.";

        self::assertSame('Alle Preise inklusive 19 % Mehrwertsteuer, siehe Hinweise.', SourceLocator::quote($content));
    }

    public function testTheQuoteSkipsTheBreadcrumbAndFallsBackToTheHeading(): void
    {
        self::assertSame('Lagerware 5 Werktage.', SourceLocator::quote("Lieferung\n\nLagerware 5 Werktage.", 'Lieferung'));
        self::assertSame('Pflege und Kontakt', SourceLocator::quote("# Pflege und Kontakt\n"));
        self::assertNull(SourceLocator::quote("| a | b |\n| --- | --- |"));
    }

    private const CHUNK = "Produktkatalog 2026 — Überblick und Garantie\n\nDie Nordlicht Möbel GmbH wurde 1987 in Flensburg gegründet und fertigt Möbel aus Eiche und Buche.\n\nAuf alle Möbel gewähren wir eine Garantie von 10 Jahren. Die Garantie deckt Material- und Verarbeitungsfehler ab.\n\n| Artikel | Material | Preis (EUR) |\n| --- | --- | --- |\n| Esstisch Fjord | Eiche | 1.290,00 |\n| Esstisch Fjord XL | Eiche | 1.590,00 |\n| Stuhl Möwe | Buche | 189,00 |";

    public function testTheQuoteIsTheSentenceThatAnswersTheQuestion(): void
    {
        $crumb = 'Überblick und Garantie';
        $title = 'Produktkatalog 2026';

        self::assertSame('Auf alle Möbel gewähren wir eine Garantie von 10 Jahren.', SourceLocator::questionQuote(self::CHUNK, 'Wie lange ist die Garantie auf Möbel?', $crumb, $title));
        self::assertSame('Die Nordlicht Möbel GmbH wurde 1987 in Flensburg gegründet und fertigt Möbel aus Eiche und Buche.', SourceLocator::questionQuote(self::CHUNK, 'Wann wurde die Firma 1987 gegründet?', $crumb, $title));
    }

    public function testATableRowCanBeTheQuoteAndItsLinkUsesTheFirstCell(): void
    {
        $quote = SourceLocator::questionQuote(self::CHUNK, 'Was kostet der Esstisch Fjord XL?', 'Überblick und Garantie', 'Produktkatalog 2026');

        self::assertSame('Esstisch Fjord XL · Eiche · 1.590,00', $quote);
        self::assertSame('https://example.org/p#:~:text=Esstisch%20Fjord%20XL', SourceLocator::link('https://example.org/p', null, $quote));
    }

    public function testTheTitleLineIsNotTheQuoteAndNoMatchGivesNull(): void
    {
        // "Produktkatalog" is in the prefix line only; it must not win.
        self::assertNull(SourceLocator::questionQuote("Produktkatalog 2026\n\nNichts zum Thema hier.", 'Welcher Produktkatalog?', null, 'Produktkatalog 2026'));
        self::assertNull(SourceLocator::questionQuote(self::CHUNK, 'Wie ist das Wetter?', null, null));
        self::assertNull(SourceLocator::questionQuote(self::CHUNK, 'Wie lange ist die?', null, null), 'only stop words');
    }

    public function testAStemMatchesInflectedWords(): void
    {
        self::assertSame('Wir liefern Maßanfertigungen innerhalb von sechs Wochen.', SourceLocator::questionQuote("Allgemeines.\n\nWir liefern Maßanfertigungen innerhalb von sechs Wochen.", 'Wie lange dauert die Maßanfertigung?'));
    }

    public function testTheRetrievalSetsTheQuoteForTheQuestion(): void
    {
        $chunk = (new ContextChunk(self::CHUNK, 0.9, breadcrumb: 'Überblick und Garantie', title: 'Produktkatalog 2026'))->withQuote(SourceLocator::questionQuote(self::CHUNK, 'Garantie?', 'Überblick und Garantie', 'Produktkatalog 2026'));

        self::assertSame('Auf alle Möbel gewähren wir eine Garantie von 10 Jahren.', $chunk->toArray()['quote']);
        self::assertSame('Auf alle Möbel gewähren wir eine Garantie von 10 Jahren.', $chunk->withContent('x')->quote);
        self::assertSame('Die Nordlicht Möbel GmbH wurde 1987 in Flensburg gegründet und fertigt Möbel aus Eiche und Buche.', (new ContextChunk(self::CHUNK, 0.9, breadcrumb: 'Überblick und Garantie', title: 'Produktkatalog 2026'))->toArray()['quote'], 'without a question: the first prose line, not the title line');
    }

    public function testALongQuoteIsCutAtAWord(): void
    {
        $quote = (string) SourceLocator::quote(str_repeat('wort ', 100), null, 50);

        self::assertLessThanOrEqual(51, mb_strlen($quote));
        self::assertStringEndsWith('…', $quote);
        self::assertStringNotContainsString('wor…', $quote);
    }

    public function testAContextChunkHandsOutTheSourceFields(): void
    {
        $chunk = new ContextChunk(
            content: "Preisliste\n\nDer Esstisch Fjord XL kostet 1.590 Euro im Katalog.",
            score: 0.9,
            sourceUrl: 'https://img.example.net/https://example.org/katalog.pdf,p0',
            breadcrumb: 'Preisliste',
            page: 3,
            title: 'Produktkatalog 2026',
        );

        $array = $chunk->toArray();

        self::assertSame('Produktkatalog 2026', $array['title']);
        self::assertSame('pdf', $array['ext']);
        self::assertSame(3, $array['page']);
        self::assertSame('Der Esstisch Fjord XL kostet 1.590 Euro im Katalog.', $array['quote']);
        self::assertSame('https://img.example.net/https://example.org/katalog.pdf,p0#page=3', $array['link']);

        $web = (new ContextChunk("Garantie\n\nAuf alle Möbel gewähren wir zehn Jahre Garantie.", 0.5, sourceUrl: 'https://example.org/garantie', breadcrumb: 'Garantie'))->toArray();
        self::assertSame('https://example.org/garantie#:~:text=Auf%20alle%20M%C3%B6bel%20gew%C3%A4hren%20wir%20zehn%20Jahre%20Garantie.', $web['link']);
        self::assertSame(3, $chunk->withContent('x')->page);
        self::assertSame($array, ContextChunk::fromArray($array)->toArray());
        self::assertNull(ContextChunk::fromArray(['content' => 'old stored source', 'score' => 0.5])->page);
    }

    public function testASourceLinksToThePageOfItsQuoteNotThePageTheChunkStartsOn(): void
    {
        // The chunk starts with the overlap of page 2 and runs into the price list on page 3.
        $chunk = new ContextChunk(
            content: "89 Euro. Eine Lieferung auf die Nordseeinseln ist nicht möglich.\n\nPreisliste\n\n| Artikel | Material | Preis |\n| --- | --- | --- |\n| Stuhl Möwe | Buche | 189,00 |",
            score: 0.9,
            sourceUrl: 'https://example.org/katalog.pdf',
            page: 2,
            quote: 'Stuhl Möwe · Buche · 189,00',
            pageStarts: [['page' => 3, 'text' => 'Preisliste']],
        );

        self::assertSame(3, $chunk->toArray()['page']);
        self::assertSame('https://example.org/katalog.pdf#page=3', $chunk->toArray()['link']);
        self::assertSame(2, $chunk->withQuote('89 Euro. Eine Lieferung auf die Nordseeinseln ist nicht möglich.')->toArray()['page']);
        self::assertSame(2, $chunk->withQuote('Nicht im Text')->toArray()['page']);
        self::assertSame(3, ContextChunk::fromArray($chunk->toArray())->toArray()['page']);
    }

    public function testThePageStartsComeFromTheMetadata(): void
    {
        $document = (new \NeuronAI\RAG\Document('Text'))->addMetadata('page', 1)->addMetadata('page_starts', [['page' => 2, 'text' => 'Seite zwei'], ['page' => 0, 'text' => 'x'], 'kaputt']);

        self::assertSame([['page' => 2, 'text' => 'Seite zwei']], ContextChunk::fromDocument($document)->pageStarts);
    }

    public function testACitedSourceQuotesTheSentenceTheAnswerNames(): void
    {
        $directory = "### Löffler, Frank\n\nEmail\n\n[frank.loeffler@example.org](mailto:frank.loeffler@example.org)\n\n### Löffler, Viktor\n\nEmail\n\n[viktor.loeffler@example.net](mailto:viktor.loeffler@example.net)\n\nFunktion\n\nFörderung, Portal";
        $chunks = [
            (new ContextChunk($directory, 0.9))->withQuote(SourceLocator::questionQuote($directory, 'viktor löffler email?')),
            new ContextChunk('Ein anderer Text ohne Bezug.', 0.5),
        ];
        // The question alone matches every "Email" label: the first one wins.
        self::assertSame('Email', $chunks[0]->quote);

        $quoted = SourceLocator::withAnswerQuotes($chunks, 'Die E-Mail-Adresse von Viktor Löffler lautet [viktor.loeffler@example.net](mailto:viktor.loeffler@example.net) [1].');

        self::assertSame('viktor.loeffler@example.net', $quoted[0]->quote);
        self::assertSame($chunks[1], $quoted[1]);
        self::assertSame($chunks, SourceLocator::withAnswerQuotes($chunks, 'Für Viktor ist kein Eintrag vorhanden.'));
    }
}
