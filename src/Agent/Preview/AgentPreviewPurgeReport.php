<?php

// file generated with AI assistance: Claude Code - 2026-10-13 23:25:27 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent\Preview;

/**
 * Outcome of one run of {@see AgentPreviewPurger}:
 *
 * - candidates: previews older than the retention when the run started;
 * - deleted: previews removed (in a dry run: that would be removed);
 * - skipped: candidates kept, counted per reason (the `SKIP_*` constants);
 *   they are picked up by a later run once the reason is gone;
 * - evalRuns, conversations: rows removed with the deleted previews (in a
 *   dry run: that would be removed); results, evaluations, suggestions,
 *   messages, stream events and feedback go with them by database cascade
 *   and are not counted;
 * - retentionDays: the retention the run used (0: nothing is purged);
 * - ran: false when the application supplies no retention port and the run
 *   did nothing; dryRun: nothing was deleted.
 */
final readonly class AgentPreviewPurgeReport
{
    /** The preview is the current version of its agent (never expected, guarded by the entity). */
    public const string SKIP_CURRENT_VERSION = 'current_version';

    /** A conversation replay targets the preview (its foreign key is RESTRICT). */
    public const string SKIP_REPLAY_TARGET = 'replay_target';

    /** An evaluation run on the preview is `pending` or `running`. */
    public const string SKIP_ACTIVE_EVAL_RUN = 'active_eval_run';

    /** A turn of one of its conversations holds the turn lock. */
    public const string SKIP_RUNNING_TURN = 'running_turn';

    /**
     * @param array<string, int> $skipped reason => count, only reasons that occurred
     */
    public function __construct(
        public int $candidates = 0,
        public int $deleted = 0,
        public array $skipped = [],
        public int $evalRuns = 0,
        public int $conversations = 0,
        public int $retentionDays = 0,
        public bool $dryRun = false,
        public bool $ran = true,
    ) {
    }

    public static function notRun(bool $dryRun = false): self
    {
        return new self(dryRun: $dryRun, ran: false);
    }

    public function skippedTotal(): int
    {
        return array_sum($this->skipped);
    }

    public function isEmpty(): bool
    {
        return 0 === $this->candidates;
    }

    public function __toString(): string
    {
        $skipped = [];
        foreach ($this->skipped as $reason => $count) {
            $skipped[] = $reason.': '.$count;
        }

        return \sprintf(
            '%scandidates %d, deleted %d (eval runs %d, conversations %d), skipped %d%s',
            $this->dryRun ? 'dry run: ' : '',
            $this->candidates,
            $this->deleted,
            $this->evalRuns,
            $this->conversations,
            $this->skippedTotal(),
            [] === $skipped ? '' : ' ('.implode(', ', $skipped).')',
        );
    }
}
