<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:11:43 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation\Comparison;

use Dmstr\NeuronAiBundle\Evaluation\EvalResultStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvaluationStatus;
use Dmstr\NeuronAiBundle\Evaluation\Verdict;

/**
 * What the run comparison needs of one evaluation result,
 * loaded by {@see \Dmstr\NeuronAiBundle\Evaluation\RunComparer} and
 * classified without the database.
 */
final class ResultSnapshot
{
    /**
     * @param array<array-key, mixed>|null $humanRating
     */
    public function __construct(
        /** Key of the question, the matching key of the comparison. */
        public readonly string $questionKey,
        /** IRI of the question. */
        public readonly string $question,
        /** IRI of the result. */
        public readonly string $result,
        /** One of {@see EvalResultStatus::ALL}. */
        public readonly string $status,
        /** Status of the LATEST evaluation ({@see EvaluationStatus::ALL}); null without one. */
        public readonly ?string $evaluationStatus = null,
        /** Verdict of the latest evaluation, whatever its status. */
        public readonly ?string $evaluationVerdict = null,
        public readonly ?array $humanRating = null,
        /** {@see \Dmstr\NeuronAiBundle\Entity\EvalResult::fingerprintOf()}; null for results recorded before fingerprints existed. */
        public readonly ?string $questionFingerprint = null,
        /** Whether the result's conversation still exists. */
        public readonly bool $hasConversation = true,
        /** Content of the first user message of the conversation; null when unknown or not loaded. */
        public readonly ?string $firstUserMessage = null,
    ) {
    }

    /**
     * The verdict that counts: the one of the latest evaluation, and only
     * once that evaluation is `completed` (as
     * {@see \Dmstr\NeuronAiBundle\Evaluation\RunAggregates} counts it).
     * Null means unrated: no evaluation, or the latest one is pending or
     * failed.
     */
    public function ratedVerdict(): ?string
    {
        if (EvaluationStatus::COMPLETED !== $this->evaluationStatus || null === $this->evaluationVerdict) {
            return null;
        }

        return \in_array($this->evaluationVerdict, Verdict::ALL, true) ? $this->evaluationVerdict : null;
    }

    public function isRated(): bool
    {
        return null !== $this->ratedVerdict();
    }

    public function isFailed(): bool
    {
        return EvalResultStatus::FAILED === $this->status;
    }
}
