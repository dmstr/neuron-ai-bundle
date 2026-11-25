<?php

// file generated with AI assistance: Claude Code - 2026-10-03 19:10:10 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Evaluation;

use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Evaluation\BatchReport;
use Dmstr\NeuronAiBundle\Evaluation\TurnMetrics;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the batch report (the job's `resultData` and
 * `progress`) and of the turn metrics read from a turn's records (tokens
 * of every step, tool calls, user records ignored, null without usage).
 */
final class BatchReportTest extends TestCase
{
    public function testReportCountsAndProgress(): void
    {
        $report = new BatchReport();
        self::assertSame(['completed' => 0, 'failed' => 0, 'skipped' => 0, 'failures' => []], $report->toArray());
        self::assertSame(0, $report->progress(4));
        self::assertSame(100, $report->progress(0));

        $report->skipped();
        $report->completed();
        $report->failed('warranty', 'Upstream provider overloaded (529).');

        self::assertSame([
            'completed' => 1,
            'failed' => 1,
            'skipped' => 1,
            'failures' => [['question' => 'warranty', 'error' => 'Upstream provider overloaded (529).']],
        ], $report->toArray());
        self::assertSame(3, $report->done());
        self::assertSame(75, $report->progress(4));

        // Questions added after the start never push progress past 100.
        $report->completed();
        $report->completed();
        self::assertSame(100, $report->progress(4));
    }

    public function testFailureListIsCapped(): void
    {
        $report = new BatchReport();
        for ($i = 0; $i < BatchReport::MAX_FAILURES + 5; ++$i) {
            $report->failed('q'.$i, 'error');
        }

        self::assertSame(BatchReport::MAX_FAILURES + 5, $report->toArray()['failed']);
        self::assertCount(BatchReport::MAX_FAILURES, $report->toArray()['failures']);
    }

    public function testTurnMetricsSumUsageAndCountToolCalls(): void
    {
        $records = [
            self::record(Message::SPEAKER_USER, MessageKind::TEXT, ['input_tokens' => 999, 'output_tokens' => 999]),
            self::record(Message::SPEAKER_ASSISTANT, MessageKind::TOOL_CALL, ['input_tokens' => 100, 'output_tokens' => 20, 'cached_input_tokens' => 50, 'reasoning_tokens' => 5]),
            self::record(Message::SPEAKER_ASSISTANT, MessageKind::TOOL_CALL, null),
            self::record(Message::SPEAKER_ASSISTANT, MessageKind::TEXT, ['input_tokens' => 150, 'output_tokens' => 40]),
        ];

        $metrics = TurnMetrics::fromRecords($records);

        self::assertSame(250, $metrics->tokensInput);
        self::assertSame(60, $metrics->tokensOutput);
        self::assertSame(2, $metrics->interactions);
        self::assertSame(310, $metrics->tokensTotal());
    }

    public function testTurnMetricsWithoutUsage(): void
    {
        $metrics = TurnMetrics::fromRecords([self::record(Message::SPEAKER_ASSISTANT, MessageKind::TEXT, null)]);

        self::assertNull($metrics->tokensInput);
        self::assertNull($metrics->tokensOutput);
        self::assertSame(0, $metrics->interactions);
        self::assertSame(0, $metrics->tokensTotal());
    }

    /**
     * @param array<string, int>|null $usage
     */
    private static function record(string $role, string $kind, ?array $usage): Message
    {
        return new Message()->setRole($role)->setKind($kind)->setUsage($usage);
    }
}
