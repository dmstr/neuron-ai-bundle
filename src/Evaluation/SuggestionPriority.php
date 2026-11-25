<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:23:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

/**
 * Priority the judge assigns to a suggestion
 * (`neuron_suggestion.priority`).
 */
final class SuggestionPriority
{
    public const string LOW = 'low';
    public const string MEDIUM = 'medium';
    public const string HIGH = 'high';

    /** Every valid value, from lowest to highest. */
    public const array ALL = [self::LOW, self::MEDIUM, self::HIGH];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::ALL;
    }
}
