<?php

// file generated with AI assistance: Claude Code - 2026-10-02 21:49:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent;

/**
 * Resolves a turn limit (`maxToolCalls`, `maxUserTurns`) of an agent
 * conversation, stateless:
 *
 *  - the base is the first non-null of the agent version's value, the
 *    settings default ({@see AgentLimitDefaultsInterface}) and the bundle
 *    default; null when all three are null (unbounded);
 *  - a request value may only lower the base: the result is
 *    `min(base, request)`; without a base the request applies, without a
 *    request the base.
 *
 * Applied to conversations created with an agent:
 * `maxUserTurns` once at the creation of the conversation (no bundle
 * default; the result is stored on the conversation), `maxToolCalls` at
 * each turn (bundle default `turn.max_tool_calls_default`). Conversations
 * without an agent do not use it: their request values apply as given.
 */
final class LimitResolver
{
    public function resolve(?int $version, ?int $settingsDefault, ?int $bundleDefault, ?int $request): ?int
    {
        $base = $version ?? $settingsDefault ?? $bundleDefault;

        if (null === $request) {
            return $base;
        }
        if (null === $base) {
            return $request;
        }

        return min($base, $request);
    }
}
