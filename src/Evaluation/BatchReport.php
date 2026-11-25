<?php

// file generated with AI assistance: Claude Code - 2026-10-03 19:10:10 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

/**
 * Progress of one delivery of an evaluation batch job, written as the
 * job's `resultData` ({@see toArray()}):
 *
 * - completed: questions answered in this delivery (result `completed`);
 * - failed: questions whose turn failed in this delivery (result `failed`);
 * - skipped: questions that already had a result in the run (an earlier,
 *   redelivered attempt of the same job);
 * - failures: `{question: <key>, error: <message>}` per failed question,
 *   at most {@see MAX_FAILURES} entries (the results keep every message).
 *
 * {@see progress()} is the share of the run's questions that have a
 * result, in percent.
 */
final class BatchReport
{
    public const int MAX_FAILURES = 50;

    private int $completed = 0;

    private int $failed = 0;

    private int $skipped = 0;

    /** @var list<array{question: string, error: string}> */
    private array $failures = [];

    public function completed(): void
    {
        ++$this->completed;
    }

    public function failed(string $questionKey, string $error): void
    {
        ++$this->failed;
        if (\count($this->failures) < self::MAX_FAILURES) {
            $this->failures[] = ['question' => $questionKey, 'error' => $error];
        }
    }

    public function skipped(): void
    {
        ++$this->skipped;
    }

    /**
     * Questions with a result, this delivery and earlier ones.
     */
    public function done(): int
    {
        return $this->completed + $this->failed + $this->skipped;
    }

    /**
     * Percentage of $total questions with a result, 0..100; 100 for an
     * empty run.
     */
    public function progress(int $total): int
    {
        if ($total <= 0) {
            return 100;
        }

        return max(0, min(100, intdiv($this->done() * 100, $total)));
    }

    /**
     * @return array{completed: int, failed: int, skipped: int, failures: list<array{question: string, error: string}>}
     */
    public function toArray(): array
    {
        return [
            'completed' => $this->completed,
            'failed' => $this->failed,
            'skipped' => $this->skipped,
            'failures' => $this->failures,
        ];
    }
}
