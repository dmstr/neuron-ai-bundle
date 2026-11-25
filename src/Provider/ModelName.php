<?php

// file generated with AI assistance: Claude Code - 2026-10-06 17:20:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

/**
 * Equivalence of model names: exact, or with Ollama's default tag
 * (`llama3.2` matches `llama3.2:latest` and the other way round). There is
 * no wildcard; {@see ModelListerInterface::ANY_MODEL} is handled by
 * {@see ModelAvailabilityCheck::offers()} alone.
 */
final class ModelName
{
    public const string DEFAULT_TAG = ':latest';

    private function __construct()
    {
    }

    public static function equivalent(string $a, string $b): bool
    {
        if ('' === $a || '' === $b) {
            return false;
        }

        return $a === $b || $a === $b.self::DEFAULT_TAG || $a.self::DEFAULT_TAG === $b;
    }
}
