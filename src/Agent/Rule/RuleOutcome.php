<?php

// file generated with AI assistance: Claude Code - 2026-10-03 18:59:07 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent\Rule;

/**
 * The decision of the rule that matched a user input
 * ({@see RuleSet::evaluate()}): a fixed answer or a refusal with its
 * rendered text. A `continue` rule never yields an outcome.
 */
final readonly class RuleOutcome
{
    public const string ANSWER = 'answer';
    public const string REFUSE = 'refuse';

    public function __construct(
        /** 0-based position in the version's `rules`. */
        public int $index,
        /** Rule name or null. */
        public ?string $name,
        /** Match type: `keywords`, `pattern` or `language`. */
        public string $match,
        /** {@see ANSWER} or {@see REFUSE}, never `continue`. */
        public string $outcome,
        /** Template name. */
        public string $template,
        /** Rendered text in the effective language (default-language text as fallback). */
        public string $text,
        /** The effective language passed to {@see RuleSet::evaluate()}. */
        public string $language,
    ) {
    }

    public function isRefusal(): bool
    {
        return self::REFUSE === $this->outcome;
    }
}
