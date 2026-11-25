<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:37:11 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Reindex;

/**
 * Running tally of one {@see CollectionReindexer::run()}: becomes the job's
 * `resultData` and the output of the console twin.
 *
 *  - total: documents of the collection;
 *  - done: documents embedded in this run;
 *  - skipped: documents that already had rows of the target model (from an
 *    earlier, interrupted run or from the dual write during the reindex);
 *  - chunks: chunks written in this run;
 *  - failed: documents whose embedding threw, with the error message;
 *  - cancelled: the pending model was cleared or replaced during the run.
 */
final class ReindexReport
{
    public int $total = 0;

    public int $done = 0;

    public int $skipped = 0;

    public int $chunks = 0;

    /** @var list<array{sourceType: string, sourceName: string, error: string}> */
    public array $failed = [];

    public bool $cancelled = false;

    public function addFailure(string $sourceType, string $sourceName, string $error): void
    {
        $this->failed[] = ['sourceType' => $sourceType, 'sourceName' => $sourceName, 'error' => $error];
    }

    public function hasFailures(): bool
    {
        return [] !== $this->failed;
    }

    /** Documents handled so far (embedded, skipped or failed). */
    public function processed(): int
    {
        return $this->done + $this->skipped + \count($this->failed);
    }

    /** Share of handled documents in percent (0..100); an empty collection counts as 100. */
    public function progress(): int
    {
        if (0 === $this->total) {
            return 100;
        }

        return min(100, intdiv($this->processed() * 100, $this->total));
    }

    /**
     * @return array{total: int, done: int, skipped: int, chunks: int, failed: list<array{sourceType: string, sourceName: string, error: string}>, cancelled: bool}
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'done' => $this->done,
            'skipped' => $this->skipped,
            'chunks' => $this->chunks,
            'failed' => $this->failed,
            'cancelled' => $this->cancelled,
        ];
    }
}
