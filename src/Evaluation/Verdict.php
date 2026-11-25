<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:23:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

/**
 * Verdict of the judge on one evaluation result
 * (`neuron_evaluation.verdict`); the `verdict` enum of
 * `verdict.schema.json`.
 */
final class Verdict
{
    public const string PASS = 'pass';
    public const string MOSTLY_PASS = 'mostly_pass';
    public const string MOSTLY_FAIL = 'mostly_fail';
    public const string FAIL = 'fail';

    /** Every valid value, from best to worst. */
    public const array ALL = [self::PASS, self::MOSTLY_PASS, self::MOSTLY_FAIL, self::FAIL];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::ALL;
    }
}
