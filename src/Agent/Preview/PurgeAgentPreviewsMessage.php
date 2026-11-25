<?php

// file generated with AI assistance: Claude Code - 2026-10-13 23:25:27 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent\Preview;

/**
 * Triggers one run of {@see AgentPreviewPurger}. Sent by the `neuron`
 * schedule ({@see \Dmstr\NeuronAiBundle\Anonymous\NeuronScheduleProvider})
 * every `dmstr_neuron_ai.agent_previews.purge_interval` and by
 * `neuron:agent:previews:purge --async`; handled by
 * {@see PurgeAgentPreviewsHandler}. It carries no data: every run reads the
 * current retention and time itself, and a scheduled run is never a dry run.
 */
final readonly class PurgeAgentPreviewsMessage
{
}
