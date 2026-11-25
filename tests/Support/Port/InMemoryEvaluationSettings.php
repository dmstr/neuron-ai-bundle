<?php

// file generated with AI assistance: Claude Code - 2026-11-25 21:50:20 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support\Port;

use Dmstr\NeuronAiBundle\Evaluation\EvaluationSettingsInterface;

/**
 * Evaluation settings ({@see EvaluationSettingsInterface}) from static
 * values, as a host reads them from its settings. Keys and defaults:
 * `judge_agent` (reference, default null: no AI judge),
 * `human_rating_schema` (JSON Schema object, default null: rating answers
 * 422), `feedback_per_subject_and_hour`
 * ({@see self::DEFAULT_FEEDBACK_PER_SUBJECT_AND_HOUR}),
 * `human_rating_comparison` (object, default null).
 *
 * {@see configure()} merges values over the current ones. Cleared by
 * {@see TestPorts::reset()}.
 */
final class InMemoryEvaluationSettings implements EvaluationSettingsInterface
{
    public const int DEFAULT_FEEDBACK_PER_SUBJECT_AND_HOUR = 30;

    /** @var array<string, mixed> */
    private static array $values = [];

    /**
     * @param array<string, mixed> $values
     */
    public static function configure(array $values): void
    {
        self::$values = $values + self::$values;
    }

    public static function reset(): void
    {
        self::$values = [];
    }

    public function judgeAgent(): ?string
    {
        $agent = self::$values['judge_agent'] ?? null;

        return \is_string($agent) && '' !== trim($agent) ? trim($agent) : null;
    }

    public function humanRatingSchema(): ?array
    {
        return self::object(self::$values['human_rating_schema'] ?? null);
    }

    public function feedbackPerSubjectAndHour(): int
    {
        $limit = self::$values['feedback_per_subject_and_hour'] ?? null;

        return is_numeric($limit) && (int) $limit >= 0 ? (int) $limit : self::DEFAULT_FEEDBACK_PER_SUBJECT_AND_HOUR;
    }

    public function humanRatingComparison(): ?array
    {
        return self::object(self::$values['human_rating_comparison'] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function object(mixed $value): ?array
    {
        if (!\is_array($value) || [] === $value || array_is_list($value)) {
            return null;
        }

        return $value;
    }
}
