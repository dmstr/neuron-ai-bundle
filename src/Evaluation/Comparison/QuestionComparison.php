<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:11:43 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation\Comparison;

/**
 * One question key of a run comparison, classified by
 * {@see \Dmstr\NeuronAiBundle\Evaluation\RunComparer::classify()}; the
 * candidate is always read relative to the baseline.
 */
final class QuestionComparison
{
    /**
     * @param list<string> $regressionReasons
     * @param list<string> $improvementReasons
     */
    public function __construct(
        public readonly string $key,
        /** IRI of the question. */
        public readonly string $question,
        /** One of {@see Presence::ALL}. */
        public readonly string $presence,
        /** The baseline's result; null when the key is only in the candidate. */
        public readonly ?ResultSide $baseline,
        /** The candidate's result; null when the key is only in the baseline. */
        public readonly ?ResultSide $candidate,
        /** worse, equal or better when both results have a verdict that counts; null otherwise. */
        public readonly ?string $verdictChange,
        /** The candidate's result failed, the baseline's did not (both present). */
        public readonly bool $newlyFailed,
        /** The baseline's result has a verdict that counts, the candidate's has none (both present). */
        public readonly bool $newlyUnrated,
        /**
         * Whether question text or expected answer differ between the two
         * results: true or false from the question fingerprints; for
         * results without a fingerprint true when the first user messages
         * of the two result conversations differ, else null (unknown: a
         * conversation was deleted, or only the expected answer could
         * differ). Null as well when the key is in one run only.
         */
        public readonly ?bool $questionChanged,
        /** The human ratings of both results; null when the key is in one run only. */
        public readonly ?HumanRatingComparison $humanRating,
        /** Whether the question is a regression (`regressionReasons` is not empty). */
        public readonly bool $regression,
        /** Entries of {@see Reason::REGRESSIONS}. */
        public readonly array $regressionReasons = [],
        /** Entries of {@see Reason::IMPROVEMENTS}; listed, never flagged. */
        public readonly array $improvementReasons = [],
    ) {
    }
}
