<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:11:43 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation\Comparison;

/**
 * Change of one measure between the baseline and the candidate run of a
 * comparison, always read as "the candidate is … than the
 * baseline".
 *
 * - worse, equal, better: both sides have a comparable value (a completed
 *   verdict; a human rating value on the configured scale);
 * - unrated: a configured human rating cannot be ranked on at least one
 *   side (no rating, field missing, value outside `values` or not a
 *   number); never a regression;
 * - changed, unchanged: the human rating without a comparison setting,
 *   compared for equality only.
 */
final class Change
{
    public const string WORSE = 'worse';
    public const string EQUAL = 'equal';
    public const string BETTER = 'better';
    public const string UNRATED = 'unrated';
    public const string CHANGED = 'changed';
    public const string UNCHANGED = 'unchanged';

    /** Every valid value. */
    public const array ALL = [self::WORSE, self::EQUAL, self::BETTER, self::UNRATED, self::CHANGED, self::UNCHANGED];

    /**
     * The change from rank $baseline to rank $candidate where a HIGHER
     * rank is better.
     */
    public static function ofRanks(int|float $baseline, int|float $candidate): string
    {
        return match (true) {
            $candidate > $baseline => self::BETTER,
            $candidate < $baseline => self::WORSE,
            default => self::EQUAL,
        };
    }
}
