<?php

// file generated with AI assistance: Claude Code - 2026-11-25 21:50:20 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support\Port;

use Dmstr\NeuronAiBundle\Agent\Preview\AgentPreviewRetentionInterface;

/**
 * Retention of agent previews ({@see AgentPreviewRetentionInterface}):
 * {@see self::DEFAULT_RETENTION_DAYS} unless a test calls {@see set()}
 * (0 keeps every preview). Cleared by {@see TestPorts::reset()}.
 */
final class InMemoryAgentPreviewRetention implements AgentPreviewRetentionInterface
{
    public const int DEFAULT_RETENTION_DAYS = 7;

    private static int $retentionDays = self::DEFAULT_RETENTION_DAYS;

    public static function set(int $retentionDays): void
    {
        self::$retentionDays = $retentionDays;
    }

    public static function reset(): void
    {
        self::$retentionDays = self::DEFAULT_RETENTION_DAYS;
    }

    public function retentionDays(): int
    {
        return self::$retentionDays;
    }
}
