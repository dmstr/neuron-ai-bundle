<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:11:43 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation\Comparison;

/**
 * Response of `GET /eval_runs/{id}/compare/{baselineId}`
 * (`eval_run_compare`): the candidate run `{id}` compared with
 * the baseline run `{baselineId}`, computed on request and not stored.
 * Questions are matched by key and listed in key order.
 */
final class RunComparison
{
    /**
     * @param array{field: string, order: string, values: list<string>|null}|null $humanRatingComparison
     * @param list<QuestionComparison>                                         $questions
     */
    public function __construct(
        public readonly RunReference $baseline,
        public readonly RunReference $candidate,
        /** The effective setting `evaluation.human_rating_comparison`; null without a usable one. */
        public readonly ?array $humanRatingComparison,
        public readonly ComparisonSummary $summary,
        public readonly array $questions,
    ) {
    }
}
