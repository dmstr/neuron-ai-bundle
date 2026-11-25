<?php

// file generated with AI assistance: Claude Code - 2026-10-03 18:59:07 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent\Template;

use Dmstr\NeuronAiBundle\Agent\InstructionTemplate;

/**
 * Lenient placeholder substitution in output templates.
 *
 * Same placeholder syntax and single-pass replacement as
 * {@see InstructionTemplate::render()}, but a placeholder without a value
 * renders as the empty string instead of answering 422: a template is shown
 * to the user, and a declared but unsupplied caller variable must not turn
 * a fixed answer or a refusal into an error. Which names a template may use
 * is checked when publishing
 * ({@see \Dmstr\NeuronAiBundle\Agent\AgentConfigurationValidator}).
 */
final class TemplateRenderer
{
    /**
     * Every placeholder ({@see InstructionTemplate::PLACEHOLDER}) without a
     * value renders as ''. Never throws.
     *
     * @param array<string, string> $values placeholder name => value
     */
    public static function render(string $text, array $values): string
    {
        $rendered = preg_replace_callback(
            InstructionTemplate::PLACEHOLDER,
            static fn (array $match): string => (string) ($values[$match[1]] ?? ''),
            $text,
        );

        // Only fails on invalid input of a non-`u` pattern, i.e. never; keep the text then.
        return $rendered ?? $text;
    }
}
