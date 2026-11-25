<?php

// file generated with AI assistance: Claude Code - 2026-10-03 18:38:08 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

/**
 * Status of an evaluation question (`neuron_eval_question.status`).
 *
 * - active: part of every new evaluation run;
 * - archived: kept for the results that reference it, skipped by new runs
 *   (questions are never deleted while results point to them).
 */
final class EvalQuestionStatus
{
    public const string ACTIVE = 'active';
    public const string ARCHIVED = 'archived';

    /** Every valid value. */
    public const array ALL = [self::ACTIVE, self::ARCHIVED];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::ALL;
    }
}
