<?php

// file generated with AI assistance: Claude Code - 2026-10-13 23:10:57 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent;

/**
 * Kind of an {@see \Dmstr\NeuronAiBundle\Entity\AgentVersion}, stored in
 * `neuron_agent_version.kind`.
 *
 *  - `published`: frozen by {@see AgentPublisher}, numbered from 1, can
 *    become the agent's current version;
 *  - `preview`: the frozen candidate for a test chat or an evaluation,
 *    created by {@see AgentPreviewFactory}, number 0, identified per agent
 *    by the hash of its configuration; never current, never a rollback or
 *    replay target, removed by the purge after the retention.
 */
enum AgentVersionKind: string
{
    case PUBLISHED = 'published';
    case PREVIEW = 'preview';
}
