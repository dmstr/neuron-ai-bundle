<?php

// file generated with AI assistance: Claude Code - 2026-10-03 18:38:08 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

/**
 * How an evaluation result came about (`neuron_eval_result.source`).
 *
 * - batch: executed as part of an {@see \Dmstr\NeuronAiBundle\Entity\EvalRun};
 * - single: a single execution of one question outside a run (`evalRun`
 *   null).
 */
final class EvalResultSource
{
    public const string BATCH = 'batch';
    public const string SINGLE = 'single';

    /** Every valid value. */
    public const array ALL = [self::BATCH, self::SINGLE];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::ALL;
    }
}
