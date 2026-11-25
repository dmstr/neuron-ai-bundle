<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:23:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

/**
 * Kind of improvement a judge suggestion proposes
 * (`neuron_suggestion.type`).
 *
 * - data: the knowledge base lacks or misstates something (documents,
 *   collections);
 * - prompt: the agent version should behave differently (instructions,
 *   tools, settings).
 */
final class SuggestionType
{
    public const string DATA = 'data';
    public const string PROMPT = 'prompt';

    /** Every valid value. */
    public const array ALL = [self::DATA, self::PROMPT];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::ALL;
    }
}
