<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:11:43 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation\Comparison;

use Dmstr\NeuronAiBundle\Evaluation\EvaluationSettingsInterface;

/**
 * How the run comparison ranks a human rating, from the
 * evaluation setting `human_rating_comparison`
 * ({@see EvaluationSettingsInterface::humanRatingComparison()}):
 *
 * - `field`: top-level property of the rating document that is compared;
 * - `order`: {@see self::HIGHER_IS_BETTER} or {@see self::LOWER_IS_BETTER};
 * - `values` (optional): the scale of the field from the LOWEST to the
 *   HIGHEST value, a list of strings; the rank of a value is its position.
 *   Without `values` the field must hold a number (integer or float; a
 *   boolean or a numeric string is no number).
 *
 * A value that cannot be ranked (no rating, field missing, value outside
 * `values`, not a number) makes the side unrated: the change is
 * {@see Change::UNRATED}, never a regression.
 */
final class HumanRatingRule
{
    public const string HIGHER_IS_BETTER = 'higher_is_better';
    public const string LOWER_IS_BETTER = 'lower_is_better';

    /** Every valid `order`. */
    public const array ORDERS = [self::HIGHER_IS_BETTER, self::LOWER_IS_BETTER];

    /**
     * @param list<string>|null $values
     */
    public function __construct(
        public readonly string $field,
        public readonly string $order,
        public readonly ?array $values = null,
    ) {
        if ('' === $field) {
            throw new \InvalidArgumentException('The human rating field must not be empty.');
        }
        if (!\in_array($order, self::ORDERS, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is no valid human rating order.', $order));
        }
    }

    /**
     * The rule of a setting value; null without one or when the value is
     * unusable (no non-empty string `field`, an unknown `order`, `values`
     * that is not a non-empty list of strings). An unusable entry counts
     * as none, so the comparison falls back to "changed"/"unchanged".
     *
     * @param array<array-key, mixed>|null $setting
     */
    public static function fromSetting(?array $setting): ?self
    {
        if (null === $setting) {
            return null;
        }

        $field = $setting['field'] ?? null;
        $order = $setting['order'] ?? null;
        $values = $setting['values'] ?? null;
        if (!\is_string($field) || '' === trim($field) || !\is_string($order) || !\in_array($order, self::ORDERS, true)) {
            return null;
        }
        if (null !== $values) {
            if (!\is_array($values) || [] === $values || !array_is_list($values)) {
                return null;
            }
            foreach ($values as $value) {
                if (!\is_string($value)) {
                    return null;
                }
            }
            /** @var list<string> $values */
        }

        return new self(trim($field), $order, $values);
    }

    /**
     * The value of the field in $rating; null when there is no rating or
     * the field is missing.
     *
     * @param array<array-key, mixed>|null $rating
     */
    public function valueOf(?array $rating): mixed
    {
        return null === $rating ? null : ($rating[$this->field] ?? null);
    }

    /**
     * Rank of $value where a higher rank is better; null when it cannot be
     * ranked.
     */
    public function rank(mixed $value): int|float|null
    {
        if (null !== $this->values) {
            $position = \is_string($value) ? array_search($value, $this->values, true) : false;
            if (false === $position) {
                return null;
            }
            $rank = (int) $position;
        } elseif (\is_int($value) || (\is_float($value) && is_finite($value))) {
            $rank = $value;
        } else {
            return null;
        }

        return self::HIGHER_IS_BETTER === $this->order ? $rank : -$rank;
    }

    /**
     * The change of the field from $baseline to $candidate (the whole
     * rating documents): worse, equal or better, or unrated when either
     * side cannot be ranked.
     *
     * @param array<array-key, mixed>|null $baseline
     * @param array<array-key, mixed>|null $candidate
     */
    public function compare(?array $baseline, ?array $candidate): string
    {
        $baselineRank = $this->rank($this->valueOf($baseline));
        $candidateRank = $this->rank($this->valueOf($candidate));
        if (null === $baselineRank || null === $candidateRank) {
            return Change::UNRATED;
        }

        return Change::ofRanks($baselineRank, $candidateRank);
    }

    /**
     * The setting as the comparison response states it.
     *
     * @return array{field: string, order: string, values: list<string>|null}
     */
    public function toArray(): array
    {
        return ['field' => $this->field, 'order' => $this->order, 'values' => $this->values];
    }
}
