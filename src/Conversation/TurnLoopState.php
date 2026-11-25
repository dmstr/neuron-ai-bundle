<?php

// file generated with AI assistance: Claude Code - 2026-10-14 12:30:38 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

/**
 * The per-turn counters and flags of {@see ToolCallBoundMiddleware} and
 * {@see UserTurnLimitMiddleware}, one instance per run, shared by
 * reference.
 *
 * neuron-ai 4.x copies every middleware registered by instance at the
 * start of each execution segment, and once per node class it is
 * registered for (neuron 4.x guide 18, case 6). Values a middleware wrote
 * on its own properties would stay on one of the copies: the user-turn
 * limit arms on the inference node's copy and checks the tool node's. The
 * copies are shallow, so they all share this object.
 */
final class TurnLoopState
{
    /** Tool calls executed in the turn, parallel ones included (the bound's count). */
    public int $executedCalls = 0;

    /** Whether the bound armed the forced step. */
    public bool $boundForced = false;

    /** Whether a batch of the bound's forced step ran (it contained a terminal call). */
    public bool $boundForcedBatchRan = false;

    /** Whether the user-turn limit armed the turn (from its first inference). */
    public bool $turnLimitArmed = false;

    /** Whether a batch of the forced last user turn ran (it contained a terminal call). */
    public bool $turnLimitBatchRan = false;
}
