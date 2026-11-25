<?php

// file generated with AI assistance: Claude Code - 2026-10-06 17:20:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider\Model;

/**
 * The capability vocabulary of model descriptions: Ollama's values from
 * `types/model/capability.go`. Anthropic models get `completion` and
 * `tools` always (Messages API contract), `vision` and `thinking` from the
 * Models API. Unknown values are dropped; a new Ollama value is added here
 * (no migration needed, the catalogue stores the list as JSON).
 */
final class ModelCapability
{
    public const string COMPLETION = 'completion';
    public const string TOOLS = 'tools';
    public const string INSERT = 'insert';
    public const string VISION = 'vision';
    public const string EMBEDDING = 'embedding';
    public const string THINKING = 'thinking';
    public const string IMAGE = 'image';
    public const string AUDIO = 'audio';
    public const string DECISION = 'decision';

    /** @var list<string> */
    public const array ALL = [
        self::COMPLETION,
        self::TOOLS,
        self::INSERT,
        self::VISION,
        self::EMBEDDING,
        self::THINKING,
        self::IMAGE,
        self::AUDIO,
        self::DECISION,
    ];

    private function __construct()
    {
    }

    /**
     * Guards a capability a caller names in code (a programming error, not
     * user input).
     *
     * @throws \InvalidArgumentException when $capability is not in {@see ALL}
     */
    public static function assertKnown(string $capability): void
    {
        if (!\in_array($capability, self::ALL, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown capability "%s" (expected one of: %s).', $capability, implode(', ', self::ALL)));
        }
    }

    /**
     * The known values of $values, unique and sorted; null when $values is
     * no list (capabilities unknown).
     *
     * @return list<string>|null
     */
    public static function normalize(mixed $values): ?array
    {
        if (!\is_array($values)) {
            return null;
        }

        $known = array_values(array_unique(array_filter($values, static fn (mixed $value): bool => \is_string($value) && \in_array($value, self::ALL, true))));
        sort($known);

        return $known;
    }
}
