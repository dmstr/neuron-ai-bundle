<?php

// file generated with AI assistance: Claude Code - 2026-10-03 21:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Feedback;

/**
 * Rating of a {@see \Dmstr\NeuronAiBundle\Entity\Feedback}:
 * thumbs up or down. Stored as its value in `neuron_feedback.rating`.
 */
enum FeedbackRating: string
{
    case UP = 'up';
    case DOWN = 'down';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
