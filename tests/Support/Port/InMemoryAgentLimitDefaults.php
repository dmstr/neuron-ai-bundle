<?php

// file generated with AI assistance: Claude Code - 2026-11-25 21:50:20 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support\Port;

use Dmstr\NeuronAiBundle\Agent\AgentLimitDefaultsInterface;

/**
 * Host-wide limit defaults of agent turns ({@see AgentLimitDefaultsInterface}),
 * between the agent version and the bundle default; both unset (null) unless
 * a test calls {@see set()}. Cleared by {@see TestPorts::reset()}.
 */
final class InMemoryAgentLimitDefaults implements AgentLimitDefaultsInterface
{
    private static ?int $maxToolCalls = null;
    private static ?int $maxUserTurns = null;

    public static function set(?int $maxToolCalls, ?int $maxUserTurns = null): void
    {
        self::$maxToolCalls = $maxToolCalls;
        self::$maxUserTurns = $maxUserTurns;
    }

    public static function reset(): void
    {
        self::$maxToolCalls = null;
        self::$maxUserTurns = null;
    }

    public function maxToolCalls(): ?int
    {
        return self::$maxToolCalls;
    }

    public function maxUserTurns(): ?int
    {
        return self::$maxUserTurns;
    }
}
