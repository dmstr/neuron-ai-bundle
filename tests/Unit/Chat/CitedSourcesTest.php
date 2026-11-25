<?php

// file generated with AI assistance: Claude Code - 2026-11-20 14:20:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Chat;

use Dmstr\NeuronAiBundle\Chat\CitedSources;
use PHPUnit\Framework\TestCase;

/**
 * {@see CitedSources}: only the passages the answer cites as `[n]`, one
 * source per page in the order of the first citation and in the shape of
 * the sources in the chat API of RAG services, the markers renumbered to
 * that order.
 */
final class CitedSourcesTest extends TestCase
{
    public function testGroupsCitedPassagesByPageInCitationOrder(): void
    {
        [$answer, $sources] = CitedSources::of('B steht hier [3], A dort [1] und [2].', [
            self::passage('https://example.org/a', 'A1', '2026-11-18T08:00:00+00:00', '2026-11-01T08:00:00+00:00'),
            self::passage('https://example.org/a', 'A2', '2026-11-18T08:00:00+00:00', '2026-11-01T08:00:00+00:00'),
            self::passage('https://example.org/b', 'B'),
            self::passage('https://example.org/uncited', 'U'),
        ]);

        self::assertSame('B steht hier [1], A dort [2] und [2].', $answer);
        self::assertSame(['https://example.org/b', 'https://example.org/a'], array_column($sources, 'url'));
        self::assertSame(['A1', 'A2'], array_column($sources[1]['locators'], 'quote'));
        self::assertSame('https://example.org/a#:~:text=A1', $sources[1]['locators'][0]['link']);
        self::assertSame('2026-11-18T08:00:00+00:00', $sources[1]['last_verified_at']);
        self::assertSame('2026-11-01T08:00:00+00:00', $sources[1]['last_changed_at']);
        self::assertNull($sources[0]['last_verified_at']);
        self::assertSame('Title of https://example.org/b', $sources[0]['title']);
    }

    public function testCollapsesRepeatedMarkersAndDropsUnknownOnes(): void
    {
        [$answer, $sources] = CitedSources::of('Text [1][2] und [9].', [
            self::passage('https://example.org/a', 'A1'),
            self::passage('https://example.org/a', 'A2'),
        ]);

        self::assertSame('Text [1] und .', $answer);
        self::assertCount(1, $sources);
    }

    public function testNumbersASourceWithANumericNameOnItsOwn(): void
    {
        [$answer, $sources] = CitedSources::of('X [1] Y [2].', [
            self::passage('https://example.org/a', 'A'),
            ['sourceUrl' => null, 'filename' => '2024', 'title' => null, 'quote' => 'Z', 'page' => null],
        ]);

        self::assertSame('X [1] Y [2].', $answer);
        self::assertSame(['https://example.org/a', '2024'], array_column($sources, 'url'));
    }

    public function testMarksASourceWithoutWebAddressSynthetic(): void
    {
        [, $sources] = CitedSources::of('Web [1], Datei [2].', [
            self::passage('https://example.org/a', 'A'),
            ['sourceUrl' => null, 'filename' => 'merkblatt.pdf', 'title' => null, 'quote' => 'M', 'page' => 2],
        ]);

        self::assertFalse($sources[0]['synthetic']);
        self::assertTrue($sources[1]['synthetic']);
        self::assertSame('merkblatt.pdf', $sources[1]['url']);
        self::assertSame([['link' => 'merkblatt.pdf', 'quote' => 'M', 'page' => 2]], $sources[1]['locators']);
    }

    public function testRenumbersGroupedMarkers(): void
    {
        [$answer, $sources] = CitedSources::of('Beides [3, 1], doppelt [1,2], unbekannt [8, 9].', [
            self::passage('https://example.org/a', 'A1'),
            self::passage('https://example.org/a', 'A2'),
            self::passage('https://example.org/b', 'B'),
        ]);

        self::assertSame('Beides [1, 2], doppelt [2], unbekannt .', $answer);
        self::assertSame(['https://example.org/b', 'https://example.org/a'], array_column($sources, 'url'));
        self::assertSame(['A1', 'A2'], array_column($sources[1]['locators'], 'quote'));
    }

    public function testStructuredAnswerRenumbersOnlyItsStrings(): void
    {
        [$structured, $sources] = CitedSources::ofStructured([
            'message' => 'Siehe [2].',
            'pages' => [7],
            'items' => [['text' => 'Und [1]', 'count' => 3]],
        ], [
            self::passage('https://example.org/a', 'A'),
            self::passage('https://example.org/b', 'B'),
        ]);

        self::assertSame(['message' => 'Siehe [1].', 'pages' => [7], 'items' => [['text' => 'Und [2]', 'count' => 3]]], $structured);
        self::assertSame(['https://example.org/b', 'https://example.org/a'], array_column($sources, 'url'));
    }

    public function testAnswerWithoutMarkersHasNoSources(): void
    {
        [$answer, $sources] = CitedSources::of('Dazu habe ich nichts gefunden.', [
            self::passage('https://example.org/a', 'A1'),
        ]);

        self::assertSame('Dazu habe ich nichts gefunden.', $answer);
        self::assertSame([], $sources);
    }

    /**
     * @return array<string, mixed>
     */
    private static function passage(string $url, string $quote, ?string $checkedAt = null, ?string $changedAt = null): array
    {
        return [
            'sourceUrl' => $url,
            'title' => 'Title of '.$url,
            'quote' => $quote,
            'link' => $url.'#:~:text='.rawurlencode($quote),
            'page' => null,
            'checkedAt' => $checkedAt,
            'changedAt' => $changedAt,
        ];
    }
}

// - revised 2026-11-24
