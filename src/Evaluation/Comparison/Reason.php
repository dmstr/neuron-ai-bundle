<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:11:43 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation\Comparison;

/**
 * Why a compared question is a regression or an improvement;
 * the entries of `regressionReasons` and `improvementReasons` of a
 * {@see QuestionComparison}.
 */
final class Reason
{
    /** The candidate's completed verdict is worse than the baseline's. */
    public const string VERDICT_WORSE = 'verdict_worse';

    /** The configured human rating field is worse in the candidate. */
    public const string HUMAN_RATING_WORSE = 'human_rating_worse';

    /** The candidate's result failed, the baseline's did not. */
    public const string NEWLY_FAILED = 'newly_failed';

    /** The question has a result in the baseline but none in the candidate. */
    public const string MISSING_IN_CANDIDATE = 'missing_in_candidate';

    /** The candidate's completed verdict is better than the baseline's. */
    public const string VERDICT_BETTER = 'verdict_better';

    /** The configured human rating field is better in the candidate. */
    public const string HUMAN_RATING_BETTER = 'human_rating_better';

    /** The baseline's result failed, the candidate's did not. */
    public const string NO_LONGER_FAILED = 'no_longer_failed';

    /** Reasons that make a question a regression. */
    public const array REGRESSIONS = [self::VERDICT_WORSE, self::HUMAN_RATING_WORSE, self::NEWLY_FAILED, self::MISSING_IN_CANDIDATE];

    /** Reasons of an improvement (listed, never flagged). */
    public const array IMPROVEMENTS = [self::VERDICT_BETTER, self::HUMAN_RATING_BETTER, self::NO_LONGER_FAILED];
}
