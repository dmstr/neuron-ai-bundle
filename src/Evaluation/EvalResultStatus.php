<?php

// file generated with AI assistance: Claude Code - 2026-10-03 18:38:08 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

/**
 * Status of an evaluation result (`neuron_eval_result.status`).
 *
 * - pending: the question is being executed;
 * - completed: the turn ran and its answer is stored in the conversation;
 * - failed: the turn could not run or ended with an error (`errorMessage`).
 */
final class EvalResultStatus
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
