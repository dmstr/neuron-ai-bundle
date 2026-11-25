<?php

// file generated with AI assistance: Claude Code - 2026-10-03 10:33:41 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Placeholder substitution in the instructions of an agent version.
 *
 * A placeholder is `{{name}}` with optional whitespace inside the braces
 * (`{{ name }}`); the name starts with a letter or an underscore. Every
 * occurrence is replaced in a single pass by the plain value, nothing is
 * evaluated and substituted values are not scanned again. Placeholders
 * inside JSON text of the instructions are ordinary text and are replaced
 * as well.
 */
final class InstructionTemplate
{
    public const string PLACEHOLDER = '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/';

    /**
     * The placeholder names of $text, unique, in order of first occurrence.
     *
     * @return list<string>
     */
    public static function placeholders(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param array<string, string> $values placeholder name => value
     *
     * @throws UnprocessableEntityHttpException naming every placeholder without a value
     *                                          (`variables/<name>: required by the instructions.`)
     */
    public static function render(string $text, array $values): string
    {
        $missing = array_values(array_filter(
            self::placeholders($text),
            static fn (string $name): bool => !\array_key_exists($name, $values),
        ));
        if ([] !== $missing) {
            throw new UnprocessableEntityHttpException(implode('; ', array_map(
                static fn (string $name): string => \sprintf('variables/%s: required by the instructions.', $name),
                $missing,
            )));
        }

        return (string) preg_replace_callback(
            self::PLACEHOLDER,
            static fn (array $match): string => $values[$match[1]],
            $text,
        );
    }
}
