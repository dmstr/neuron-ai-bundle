<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:34:44 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

/**
 * One suggestion of a judge verdict, as validated by {@see VerdictSchema};
 * becomes one {@see \Dmstr\NeuronAiBundle\Entity\Suggestion}.
 */
final readonly class JudgeSuggestion
{
    /**
     * @param string $type     one of {@see SuggestionType::ALL}
     * @param string $priority one of {@see SuggestionPriority::ALL}
     */
    public function __construct(
        public string $type,
        public string $category,
        public string $description,
        public string $action,
        public string $priority,
    ) {
    }
}
