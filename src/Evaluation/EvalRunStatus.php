<?php

// file generated with AI assistance: Claude Code - 2026-10-03 18:38:08 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

/**
 * Status of an evaluation run (`neuron_eval_run.status`).
 *
 * - pending: created, the batch job is queued;
 * - running: the batch job executes the questions;
 * - completed: every question has a result (completed or failed);
 * - cancelled: stopped after `cancelRequestedAt` was set;
 * - failed: the batch job itself failed (not a single question).
 */
final class EvalRunStatus
{
    public const string PENDING = 'pending';
    public const string RUNNING = 'running';
    public const string COMPLETED = 'completed';
    public const string CANCELLED = 'cancelled';
    public const string FAILED = 'failed';

    /** Every valid value, in lifecycle order. */
    public const array ALL = [self::PENDING, self::RUNNING, self::COMPLETED, self::CANCELLED, self::FAILED];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::ALL;
    }
}
