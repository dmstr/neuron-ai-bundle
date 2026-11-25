<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:26 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Anonymous;

/**
 * Triggers one run of {@see AnonymousConversationPurger}. Sent by the
 * `neuron` schedule ({@see NeuronScheduleProvider}) every
 * `dmstr_neuron_ai.anonymous.purge_interval` and by
 * `neuron:anonymous:purge --async`; handled by
 * {@see PurgeAnonymousConversationsHandler}. It carries no data: every run
 * reads the current policy and time itself.
 */
final readonly class PurgeAnonymousConversationsMessage
{
}
