<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:23:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

/**
 * Status of a judge evaluation (`neuron_evaluation.status`).
 *
 * - pending: the evaluation job is queued or running;
 * - completed: the judge answered with a valid verdict (`verdict`,
 *   `reasoning` and the suggestions are stored);
 * - failed: the judge could not run or its answer did not match the verdict
 *   schema (`errorMessage`).
 */
final class EvaluationStatus
{
    public const string PENDING = 'pending';
    public const string COMPLETED = 'completed';
    public const string FAILED = 'failed';

    /** Every valid value, in lifecycle order. */
    public const array ALL = [self::PENDING, self::COMPLETED, self::FAILED];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::ALL;
    }
}
