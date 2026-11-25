<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:11:43 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation\Comparison;

/**
 * One of the two compared runs, with what is needed to
 * attribute the comparison: its agent version and when it ran. A run on a
 * preview has `versionNumber` 0, so `versionLabel` and `versionKind` name
 * the version for display.
 */
final class RunReference
{
    /**
     * @param array<string, int>|null $verdictCounts
     */
    public function __construct(
        /** IRI of the evaluation run. */
        public readonly string $run,
        public readonly string $status,
        /** IRI of the agent version the run executed. */
        public readonly string $agentVersion,
        /** IRI of the version's agent. */
        public readonly string $agent,
        /** Slug of the version's agent. */
        public readonly string $agentSlug,
        /** Number of the version; 0 for a preview. */
        public readonly int $versionNumber,
        /** `v<n>` or `preview <hash prefix>` ({@see \Dmstr\NeuronAiBundle\Entity\AgentVersion::label()}). */
        public readonly string $versionLabel,
        /** `published` or `preview` ({@see \Dmstr\NeuronAiBundle\Agent\AgentVersionKind}). */
        public readonly string $versionKind,
        public readonly ?\DateTimeImmutable $startedAt,
        public readonly ?\DateTimeImmutable $finishedAt,
        public readonly int $questionsTotal,
        /** The run's `verdictCounts`. */
        public readonly ?array $verdictCounts,
    ) {
    }
}

// - revised 2026-10-13
