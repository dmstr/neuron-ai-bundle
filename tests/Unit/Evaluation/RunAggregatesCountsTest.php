<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:46:34 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Evaluation;

use Dmstr\NeuronAiBundle\Evaluation\RunAggregates;
use Dmstr\NeuronAiBundle\Evaluation\Verdict;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of {@see RunAggregates::counts()}, the fold of the
 * aggregate rows into `verdictCounts`: always the four verdict keys in the
 * order of {@see Verdict::ALL}, totals cast from the driver's strings,
 * unknown and empty verdicts ignored. The query itself runs in
 * RunAggregatesTest (CI).
 */
final class RunAggregatesCountsTest extends TestCase
{
    public function testNoRowsGivesEveryVerdictAtZero(): void
    {
        $counts = RunAggregates::counts([]);

        self::assertSame([Verdict::PASS => 0, Verdict::MOSTLY_PASS => 0, Verdict::MOSTLY_FAIL => 0, Verdict::FAIL => 0], $counts);
        self::assertSame(Verdict::ALL, array_keys($counts));
    }

    public function testRowsAreFoldedInVerdictOrder(): void
    {
        $counts = RunAggregates::counts([
            ['verdict' => Verdict::FAIL, 'total' => '2'],
            ['verdict' => Verdict::PASS, 'total' => 5],
            ['verdict' => 'unknown', 'total' => 7],
            ['verdict' => null, 'total' => 3],
        ]);

        self::assertSame([Verdict::PASS => 5, Verdict::MOSTLY_PASS => 0, Verdict::MOSTLY_FAIL => 0, Verdict::FAIL => 2], $counts);
    }
}
