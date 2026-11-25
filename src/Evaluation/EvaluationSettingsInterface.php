<?php

// file generated with AI assistance: Claude Code - 2026-10-03 19:07:47 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

/**
 * Settings of the evaluation (judge, feedback and run comparison), supplied by the
 * application (e.g. from a settings section). Implementations read the
 * current values on every call, so a changed setting applies to the next
 * request without a deployment.
 */
interface EvaluationSettingsInterface
{
    /**
     * Reference (slug, UUID or IRI) of the agent whose current published
     * version acts as the AI judge; trimmed, never empty. Null: no judge.
     * Whether it resolves is checked where it is used.
     */
    public function judgeAgent(): ?string;

    /**
     * JSON Schema a human rating of an evaluation result must match, decoded
     * as an associative array. Null: no rating schema.
     *
     * @return array<string, mixed>|null
     */
    public function humanRatingSchema(): ?array;

    /**
     * Feedback submissions one user or one capability token may send per
     * hour; 0 disables feedback.
     */
    public function feedbackPerSubjectAndHour(): int;

    /**
     * How the run comparison compares the human ratings of two
     * results, decoded as an associative array:
     *
     * - `field`: name of the top-level property of the rating document to
     *   compare (non-empty string);
     * - `order`: `higher_is_better` or `lower_is_better`;
     * - `values` (optional): the scale of `field` from the LOWEST to the
     *   HIGHEST value, as a list of strings; a rating value is ranked by
     *   its position and `order` names the better end. Without `values`
     *   the field is compared as a number.
     *
     * Null: no comparison entry; the comparison then only reports whether
     * the rating changed. The value is checked again where it is used
     * ({@see Comparison\HumanRatingRule::fromSetting()}), an unusable
     * entry counts as none.
     *
     * @return array<string, mixed>|null
     */
    public function humanRatingComparison(): ?array;
}
