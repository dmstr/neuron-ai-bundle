<?php

// file generated with AI assistance: Claude Code - 2026-10-03 21:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Feedback;

/**
 * Who gave a {@see \Dmstr\NeuronAiBundle\Entity\Feedback},
 * derived on submission and never taken from the request body:
 *  - `reviewer`: the submitter holds `neuronai:feedback:submit_reviewer` and may read the conversation;
 *  - `enduser`: the owner of the conversation or the holder of its
 *    capability token.
 */
enum FeedbackSource: string
{
    case ENDUSER = 'enduser';
    case REVIEWER = 'reviewer';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
