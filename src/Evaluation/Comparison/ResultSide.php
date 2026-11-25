<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:11:43 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation\Comparison;

/**
 * One run's side of a compared question: the result and the
 * verdict that counts.
 */
final class ResultSide
{
    public function __construct(
        /** IRI of the evaluation result. */
        public readonly string $result,
        /** Status of the result (`pending`, `completed`, `failed`). */
        public readonly string $status,
        /** Status of the result's latest evaluation; null without one. */
        public readonly ?string $evaluationStatus,
        /** Verdict of the latest evaluation once it is `completed`; null: unrated. */
        public readonly ?string $verdict,
        /** Whether the result has a verdict that counts (`verdict` is set). */
        public readonly bool $rated,
    ) {
    }

    public static function of(ResultSnapshot $snapshot): self
    {
        return new self($snapshot->result, $snapshot->status, $snapshot->evaluationStatus, $snapshot->ratedVerdict(), $snapshot->isRated());
    }
}
