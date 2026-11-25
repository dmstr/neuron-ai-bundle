<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:21:35 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Evaluation;

use Dmstr\NeuronAiBundle\Conversation\ContextChunk;
use Dmstr\NeuronAiBundle\Evaluation\RetrievalMeasurement;
use Dmstr\NeuronAiBundle\Evaluation\RetrievalStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free classification table of {@see RetrievalMeasurement}:
 * negative and unmeasured questions, case-insensitive marker matching on
 * chunk content only (never file name, source URL or heading path), the
 * first matching rank, misses, and the documented `ß`/`SS` limit.
 */
final class RetrievalMeasurementTest extends TestCase
{
    public function testVocabulary(): void
    {
        self::assertSame(['measured', 'negative', 'unmeasured', 'failed'], RetrievalStatus::ALL);
        self::assertSame(RetrievalStatus::ALL, RetrievalStatus::all());
    }

    /**
     * @param list<string>|null  $markers
     * @param list<ContextChunk> $pool
     */
    #[DataProvider('classifications')]
    public function testClassify(bool $negative, ?array $markers, array $pool, string $status, ?bool $recallHit, ?int $firstRelevantRank): void
    {
        $measurement = RetrievalMeasurement::classify($negative, $markers, $pool);

        self::assertSame($status, $measurement->status);
        self::assertSame($recallHit, $measurement->recallHit);
        self::assertSame($firstRelevantRank, $measurement->firstRelevantRank);
    }

    /**
     * @return iterable<string, array{bool, list<string>|null, list<ContextChunk>, string, ?bool, ?int}>
     */
    public static function classifications(): iterable
    {
        $pool = [
            self::chunk('Allgemeine Hinweise zur Bedienung.'),
            self::chunk('Die Wartung erfolgt alle 500 Stunden.'),
            self::chunk('Ölwechsel nach 1000 Stunden.'),
            self::chunk('Die WARTUNG der Pumpe ist separat geregelt.'),
        ];

        yield 'negative question' => [true, null, $pool, RetrievalStatus::NEGATIVE, null, null];
        yield 'negative wins over markers' => [true, ['Wartung'], $pool, RetrievalStatus::NEGATIVE, null, null];
        yield 'no markers' => [false, null, $pool, RetrievalStatus::UNMEASURED, null, null];
        yield 'empty markers' => [false, [], $pool, RetrievalStatus::UNMEASURED, null, null];
        yield 'hit at rank 2' => [false, ['Wartung'], $pool, RetrievalStatus::MEASURED, true, 2];
        yield 'case-insensitive' => [false, ['wARTUNG'], $pool, RetrievalStatus::MEASURED, true, 2];
        yield 'umlaut case-insensitive' => [false, ['ÖLWECHSEL'], $pool, RetrievalStatus::MEASURED, true, 3];
        yield 'first match wins across markers' => [false, ['Pumpe', 'Ölwechsel', 'Wartung'], $pool, RetrievalStatus::MEASURED, true, 2];
        yield 'first match at rank 1' => [false, ['Hinweise', 'Pumpe'], $pool, RetrievalStatus::MEASURED, true, 1];
        yield 'later marker matches earlier chunk' => [false, ['Pumpe', 'Bedienung'], $pool, RetrievalStatus::MEASURED, true, 1];
        yield 'miss' => [false, ['Garantie'], $pool, RetrievalStatus::MEASURED, false, null];
        yield 'empty pool is a miss' => [false, ['Wartung'], [], RetrievalStatus::MEASURED, false, null];
    }

    public function testUmlautsMatchAcrossCase(): void
    {
        $pool = [self::chunk('ÄNDERUNGEN AM GERÄT')];

        $measurement = RetrievalMeasurement::classify(false, ['änderungen'], $pool);

        self::assertSame(RetrievalStatus::MEASURED, $measurement->status);
        self::assertTrue($measurement->recallHit);
        self::assertSame(1, $measurement->firstRelevantRank);
    }

    public function testSharpSDoesNotMatchDoubleSAsDocumentedLimit(): void
    {
        $pool = [self::chunk('Die GRÖSSE des Tanks beträgt 40 Liter.')];

        $measurement = RetrievalMeasurement::classify(false, ['Größe'], $pool);

        self::assertSame(RetrievalStatus::MEASURED, $measurement->status);
        self::assertFalse($measurement->recallHit);
        self::assertNull($measurement->firstRelevantRank);
    }

    public function testOnlyChunkContentCounts(): void
    {
        $pool = [
            new ContextChunk(
                content: 'Allgemeine Hinweise.',
                score: 0.9,
                collectionName: 'Wartungshandbuch',
                topic: 'wartung',
                sourceUrl: 'https://example.org/wartung',
                filename: 'wartung.pdf',
                breadcrumb: 'Kapitel 3 > Wartung',
            ),
            self::chunk('Die Wartung erfolgt jährlich.'),
        ];

        $measurement = RetrievalMeasurement::classify(false, ['Wartung'], $pool);

        self::assertSame(RetrievalStatus::MEASURED, $measurement->status);
        self::assertTrue($measurement->recallHit);
        self::assertSame(2, $measurement->firstRelevantRank);

        $metadataOnly = RetrievalMeasurement::classify(false, ['Wartung'], [$pool[0]]);
        self::assertFalse($metadataOnly->recallHit);
        self::assertNull($metadataOnly->firstRelevantRank);
    }

    public function testFailed(): void
    {
        $measurement = RetrievalMeasurement::failed();

        self::assertSame(RetrievalStatus::FAILED, $measurement->status);
        self::assertNull($measurement->recallHit);
        self::assertNull($measurement->firstRelevantRank);
    }

    private static function chunk(string $content): ContextChunk
    {
        return new ContextChunk(content: $content, score: 0.5);
    }
}
