<?php

// file generated with AI assistance: Claude Code - 2026-10-02 21:49:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent;

/**
 * Operator defaults of the agent turn limits, the middle step of
 * {@see LimitResolver} between the agent version and the bundle default.
 * The application implements it (settings section `agent_limits`) and
 * aliases this interface; the bundle injects it `nullOnInvalid`, so
 * without an implementation the settings step is skipped.
 */
interface AgentLimitDefaultsInterface
{
    /** Default tool-call bound per turn; null: no settings default. */
    public function maxToolCalls(): ?int;

    /** Default user-turn bound per conversation; null: no settings default. */
    public function maxUserTurns(): ?int;
}
