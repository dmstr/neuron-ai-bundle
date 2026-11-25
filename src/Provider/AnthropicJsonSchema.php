<?php

// file generated with AI assistance: Claude Code - 2026-11-09 14:30:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

/**
 * The copy of a response schema that Anthropic's structured outputs
 * (`output_config.format`) accept, or null when the schema cannot be sent.
 *
 * The API requires `additionalProperties: false` on every object and
 * rejects some keywords. Keywords it does not support are dropped from the
 * copy (as the official SDKs do; the full schema is still validated on the
 * answer), and so are the `x-*` extension keywords. A schema with an object
 * that allows additional properties (missing, true or a schema) cannot be
 * made compatible without changing its meaning, so it gives null.
 */
final class AnthropicJsonSchema
{
    /** Keywords the API does not support; dropped from the copy. */
    public const array UNSUPPORTED = [
        'minLength', 'maxLength', 'pattern',
        'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
        'minProperties', 'maxProperties',
        'minItems', 'maxItems', 'uniqueItems',
    ];

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>|null
     */
    public static function of(array $schema): ?array
    {
        try {
            return self::node($schema);
        } catch (\DomainException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private static function node(array $node): array
    {
        $types = (array) ($node['type'] ?? []);
        if ((\in_array('object', $types, true) || isset($node['properties'])) && false !== ($node['additionalProperties'] ?? null)) {
            throw new \DomainException('An object without additionalProperties: false.');
        }

        $copy = [];
        foreach ($node as $key => $value) {
            if (\in_array($key, self::UNSUPPORTED, true) || str_starts_with((string) $key, 'x-')) {
                continue;
            }
            $copy[$key] = match ($key) {
                'properties', '$defs', 'definitions' => array_map(self::node(...), (array) $value),
                'items' => \is_array($value) ? self::node($value) : $value,
                'anyOf', 'allOf' => array_map(self::node(...), (array) $value),
                default => $value,
            };
        }

        return $copy;
    }
}
