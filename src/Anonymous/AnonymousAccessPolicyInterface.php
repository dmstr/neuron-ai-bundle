<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:54:19 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Anonymous;

/**
 * Source of the current {@see AnonymousAccessPolicy}: what anonymous
 * visitors may reach (released collections, MCP connections, provider and
 * model) and how much they may use. Implemented by the application (for
 * example from runtime settings), consumed by the anonymous creation, the
 * turn and the purge.
 *
 * Callers call {@see current()} once per request or run and keep the value
 * object. Implementations must not memoize across calls: the worker is
 * long-lived, and a changed value has to take effect without a deployment.
 */
interface AnonymousAccessPolicyInterface
{
    public function current(): AnonymousAccessPolicy;
}
