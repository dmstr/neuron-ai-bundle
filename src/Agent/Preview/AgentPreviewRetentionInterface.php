<?php

// file generated with AI assistance: Claude Code - 2026-10-13 23:25:27 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent\Preview;

/**
 * Port: how long preview versions of agents
 * ({@see \Dmstr\NeuronAiBundle\Agent\AgentVersionKind::PREVIEW}) are kept
 * before {@see AgentPreviewPurger} removes them. Supplied by the
 * application (a runtime setting), read once per purge run, so a changed
 * value applies to the next run without a deployment.
 *
 * Optional: without an implementation the purge logs a warning and removes
 * nothing, so previews are kept.
 */
interface AgentPreviewRetentionInterface
{
    /**
     * Days after its creation (`publishedAt`) a preview is kept; 0 means
     * previews are never purged. Never negative.
     */
    public function retentionDays(): int;
}
