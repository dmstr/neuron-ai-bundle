<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:23:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

/**
 * Processing status of a judge suggestion (`neuron_suggestion.status`);
 * the only field besides `assignee` an evaluation admin changes.
 *
 * - open: not handled yet (initial);
 * - fixed: the proposed action was carried out;
 * - wontfix: deliberately not carried out.
 */
final class SuggestionStatus
{
    public const string OPEN = 'open';
    public const string FIXED = 'fixed';
    public const string WONTFIX = 'wontfix';

    /** Every valid value, in lifecycle order. */
    public const array ALL = [self::OPEN, self::FIXED, self::WONTFIX];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::ALL;
    }
}
