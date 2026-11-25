<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:11:43 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation\Comparison;

/**
 * In which of the two compared runs a question key has a result. A key only in the baseline counts as a regression (the question
 * is missing in the candidate); a key only in the candidate is listed and
 * nothing more.
 */
final class Presence
{
    public const string BOTH = 'both';
    public const string ONLY_IN_BASELINE = 'only_in_baseline';
    public const string ONLY_IN_CANDIDATE = 'only_in_candidate';

    /** Every valid value. */
    public const array ALL = [self::BOTH, self::ONLY_IN_BASELINE, self::ONLY_IN_CANDIDATE];
}
