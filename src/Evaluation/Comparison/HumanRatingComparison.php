<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:11:43 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation\Comparison;

/**
 * The human ratings of a question in both runs.
 *
 * With the setting `human_rating_comparison` ({@see HumanRatingRule}):
 * `field` and `order` are set, `baseline` and `candidate` are the values
 * of that field (null when there is no rating or the field is missing) and
 * `change` is worse, equal, better or unrated. Without it: `field` and
 * `order` are null, `baseline` and `candidate` are the whole rating
 * documents and `change` is changed or unchanged. `changed` compares the
 * two stated values for equality in both cases (objects key order
 * independent).
 */
final class HumanRatingComparison
{
    public function __construct(
        /** The compared field; null without the setting. */
        public readonly ?string $field,
        /** `higher_is_better` or `lower_is_better`; null without the setting. */
        public readonly ?string $order,
        /** The baseline's value (field value or whole rating). */
        public readonly mixed $baseline,
        /** The candidate's value (field value or whole rating). */
        public readonly mixed $candidate,
        /** Whether the two values differ. */
        public readonly bool $changed,
        /** One of worse, equal, better, unrated (with setting) or changed, unchanged (without). */
        public readonly string $change,
    ) {
    }

    /**
     * @param array<array-key, mixed>|null $baseline
     * @param array<array-key, mixed>|null $candidate
     */
    public static function of(?HumanRatingRule $rule, ?array $baseline, ?array $candidate): self
    {
        if (null === $rule) {
            $changed = !self::same($baseline, $candidate);

            return new self(null, null, $baseline, $candidate, $changed, $changed ? Change::CHANGED : Change::UNCHANGED);
        }

        $baselineValue = $rule->valueOf($baseline);
        $candidateValue = $rule->valueOf($candidate);

        return new self($rule->field, $rule->order, $baselineValue, $candidateValue, !self::same($baselineValue, $candidateValue), $rule->compare($baseline, $candidate));
    }

    /**
     * Equality of two decoded JSON values, independent of the key order of
     * objects.
     */
    public static function same(mixed $a, mixed $b): bool
    {
        return self::canonical($a) === self::canonical($b);
    }

    private static function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }
}
