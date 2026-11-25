<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:21:35 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Evaluation;

use Dmstr\NeuronAiBundle\Evaluation\RetrievalStatus;
use Dmstr\NeuronAiBundle\Evaluation\RunAggregates;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of {@see RunAggregates::retrieval()}, the arithmetic
 * of the retrieval aggregates: recall = hits / measured, MRR =
 * Σ(1/rank) / measured with a miss counting 0, both null without measured
 * results; negative, unmeasured and failed results are counted but are not
 * part of either denominator. Every number is exact. The query itself runs
 * against the database in CI.
 */
final class RunAggregatesRetrievalTest extends TestCase
{
    public function testRanksOneTwoMissOneGiveExactRecallAndMrr(): void
    {
        // Four measured results with ranks 1, 2, miss, 1: three hits,
        // Σ 1/rank = 1 + 0.5 + 0 + 1 = 2.5, as the GROUP BY reports them
        // (numbers as driver strings).
        $aggregates = RunAggregates::retrieval([
            ['retrieval_status' => RetrievalStatus::MEASURED, 'total' => '4', 'hits' => '3', 'reciprocal_rank_sum' => '2.5000000000000000'],
        ]);

        self::assertSame([
            'recall' => 0.75,
            'mrr' => 0.625,
            'measured' => 4,
            'negative' => 0,
            'unmeasured' => 0,
            'failed' => 0,
        ], $aggregates);
    }

    public function testOtherStatusesAreCountedButNotInTheDenominators(): void
    {
        $aggregates = RunAggregates::retrieval([
            ['retrieval_status' => RetrievalStatus::NEGATIVE, 'total' => 2, 'hits' => 0, 'reciprocal_rank_sum' => 0],
            ['retrieval_status' => RetrievalStatus::MEASURED, 'total' => 4, 'hits' => 3, 'reciprocal_rank_sum' => 2.5],
            ['retrieval_status' => RetrievalStatus::UNMEASURED, 'total' => 5, 'hits' => 0, 'reciprocal_rank_sum' => 0],
            // Hits or ranks on a non-measured row must not leak into recall or MRR.
            ['retrieval_status' => RetrievalStatus::FAILED, 'total' => 1, 'hits' => 1, 'reciprocal_rank_sum' => 1],
            ['retrieval_status' => 'unknown', 'total' => 9, 'hits' => 9, 'reciprocal_rank_sum' => 9],
            ['retrieval_status' => null, 'total' => 7, 'hits' => 7, 'reciprocal_rank_sum' => 7],
        ]);

        self::assertSame([
            'recall' => 0.75,
            'mrr' => 0.625,
            'measured' => 4,
            'negative' => 2,
            'unmeasured' => 5,
            'failed' => 1,
        ], $aggregates);
    }

    public function testAllHitsAtRankOneGiveFloatOne(): void
    {
        $aggregates = RunAggregates::retrieval([
            ['retrieval_status' => RetrievalStatus::MEASURED, 'total' => 2, 'hits' => 2, 'reciprocal_rank_sum' => '2.0'],
        ]);

        self::assertSame(1.0, $aggregates['recall']);
        self::assertSame(1.0, $aggregates['mrr']);
    }

    public function testAllMissesGiveZero(): void
    {
        $aggregates = RunAggregates::retrieval([
            ['retrieval_status' => RetrievalStatus::MEASURED, 'total' => 3, 'hits' => 0, 'reciprocal_rank_sum' => null],
        ]);

        self::assertSame(0.0, $aggregates['recall']);
        self::assertSame(0.0, $aggregates['mrr']);
        self::assertSame(3, $aggregates['measured']);
    }

    public function testNothingMeasuredGivesNullRecallAndMrr(): void
    {
        self::assertSame(
            ['recall' => null, 'mrr' => null, 'measured' => 0, 'negative' => 0, 'unmeasured' => 0, 'failed' => 0],
            RunAggregates::retrieval([]),
        );

        $aggregates = RunAggregates::retrieval([
            ['retrieval_status' => RetrievalStatus::NEGATIVE, 'total' => 1, 'hits' => 0, 'reciprocal_rank_sum' => 0],
            ['retrieval_status' => RetrievalStatus::UNMEASURED, 'total' => 2, 'hits' => 0, 'reciprocal_rank_sum' => 0],
            ['retrieval_status' => RetrievalStatus::FAILED, 'total' => 3, 'hits' => 0, 'reciprocal_rank_sum' => 0],
        ]);

        self::assertSame(
            ['recall' => null, 'mrr' => null, 'measured' => 0, 'negative' => 1, 'unmeasured' => 2, 'failed' => 3],
            $aggregates,
        );
    }
}
