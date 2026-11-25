<?php

// file generated with AI assistance: Claude Code - 2026-10-06 17:28:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

/**
 * The answer of {@see ModelCapabilityCheck::verdict()}: the model has every
 * required capability (`supported`), the catalogue knows it lacks some
 * (`missing`, with the missing and the known capabilities and the id of
 * the catalogue row), or the catalogue cannot tell (`unknown`: no row,
 * capabilities never described, or the row describes another endpoint).
 */
final readonly class CapabilityVerdict
{
    public const string SUPPORTED = 'supported';
    public const string MISSING = 'missing';
    public const string UNKNOWN = 'unknown';

    /**
     * @param list<string> $missing required capabilities the model lacks
     * @param list<string> $known   the model's capabilities from the catalogue
     * @param string|null  $modelId the UUID of the catalogue row, for `neuron:provider-models:sync`
     */
    private function __construct(
        public string $status,
        public array $missing = [],
        public array $known = [],
        public ?string $modelId = null,
    ) {
    }

    public static function supported(): self
    {
        return new self(self::SUPPORTED);
    }

    /**
     * @param list<string> $missing
     * @param list<string> $known
     */
    public static function missing(array $missing, array $known, string $modelId): self
    {
        return new self(self::MISSING, $missing, $known, $modelId);
    }

    public static function unknown(): self
    {
        return new self(self::UNKNOWN);
    }

    public function isMissing(): bool
    {
        return self::MISSING === $this->status;
    }
}
