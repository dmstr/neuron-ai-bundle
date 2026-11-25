<?php

// file generated with AI assistance: Claude Code - 2026-11-25 21:50:20 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support\Port;

use Dmstr\NeuronAiBundle\Anonymous\AnonymousAccessPolicy;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousAccessPolicyInterface;

/**
 * Release values and limits of anonymous conversations
 * ({@see AnonymousAccessPolicyInterface}) from static values, as a host
 * reads them from its settings. Keys and defaults:
 *
 * - `collections` (list of references, default none), `mcp_connections`
 *   (default none), `model` (catalogue reference, default null: not
 *   configured), `agent` (reference, default null);
 * - `retention_days` 30, `creations_per_address_and_hour` 20 (0 disables
 *   creation), `max_turns_per_conversation` 10,
 *   `max_tokens_per_conversation` 100000, `deletion_delay_days` 7.
 *
 * {@see configure()} merges values over the current ones (a key set to null
 * falls back to its default). Values are not validated beyond the range
 * checks of {@see AnonymousAccessPolicy}. Cleared by {@see TestPorts::reset()}.
 */
final class InMemoryAnonymousAccessPolicy implements AnonymousAccessPolicyInterface
{
    public const int DEFAULT_RETENTION_DAYS = 30;
    public const int DEFAULT_CREATIONS_PER_ADDRESS_AND_HOUR = 20;
    public const int DEFAULT_MAX_TURNS_PER_CONVERSATION = 10;
    public const int DEFAULT_MAX_TOKENS_PER_CONVERSATION = 100000;
    public const int DEFAULT_DELETION_DELAY_DAYS = 7;

    /** @var array<string, mixed> */
    private static array $values = [];

    /**
     * @param array<string, mixed> $values
     */
    public static function configure(array $values): void
    {
        self::$values = $values + self::$values;
    }

    /**
     * @return array<string, mixed> the configured values (without defaults)
     */
    public static function values(): array
    {
        return self::$values;
    }

    public static function reset(): void
    {
        self::$values = [];
    }

    public function current(): AnonymousAccessPolicy
    {
        $values = self::$values;

        return new AnonymousAccessPolicy(
            collections: self::strings($values['collections'] ?? []),
            mcpConnections: self::strings($values['mcp_connections'] ?? []),
            model: self::string($values['model'] ?? null),
            retentionDays: self::int($values['retention_days'] ?? null, self::DEFAULT_RETENTION_DAYS),
            creationsPerAddressAndHour: self::int($values['creations_per_address_and_hour'] ?? null, self::DEFAULT_CREATIONS_PER_ADDRESS_AND_HOUR),
            maxTurnsPerConversation: self::int($values['max_turns_per_conversation'] ?? null, self::DEFAULT_MAX_TURNS_PER_CONVERSATION),
            maxTokensPerConversation: self::int($values['max_tokens_per_conversation'] ?? null, self::DEFAULT_MAX_TOKENS_PER_CONVERSATION),
            deletionDelayDays: self::int($values['deletion_delay_days'] ?? null, self::DEFAULT_DELETION_DELAY_DAYS),
            agent: self::string($values['agent'] ?? null),
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $entry) {
            if (\is_scalar($entry) && '' !== trim((string) $entry)) {
                $strings[] = trim((string) $entry);
            }
        }

        return $strings;
    }

    private static function string(mixed $value): ?string
    {
        return \is_scalar($value) && '' !== trim((string) $value) ? trim((string) $value) : null;
    }

    private static function int(mixed $value, int $fallback): int
    {
        return is_numeric($value) ? (int) $value : $fallback;
    }
}
