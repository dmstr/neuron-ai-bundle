<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:11:43 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation\Comparison;

/**
 * Counts over the questions of a run comparison.
 */
final class ComparisonSummary
{
    public function __construct(
        /** Question keys in either run. */
        public readonly int $questions = 0,
        public readonly int $inBoth = 0,
        public readonly int $onlyInBaseline = 0,
        public readonly int $onlyInCandidate = 0,
        /** Questions flagged as regression. */
        public readonly int $regressions = 0,
        /** Questions with at least one improvement reason. */
        public readonly int $improvements = 0,
        public readonly int $verdictWorse = 0,
        public readonly int $verdictEqual = 0,
        public readonly int $verdictBetter = 0,
        public readonly int $newlyFailed = 0,
        public readonly int $newlyUnrated = 0,
        /** Questions with `questionChanged` true. */
        public readonly int $questionChanged = 0,
        /** Questions in both runs with `questionChanged` null. */
        public readonly int $questionChangeUnknown = 0,
        public readonly int $humanRatingWorse = 0,
        public readonly int $humanRatingBetter = 0,
        /** Questions in both runs whose stated human rating values differ. */
        public readonly int $humanRatingChanged = 0,
    ) {
    }

    /**
     * @param iterable<QuestionComparison> $questions
     */
    public static function of(iterable $questions): self
    {
        $counts = array_fill_keys(['questions', 'inBoth', 'onlyInBaseline', 'onlyInCandidate', 'regressions', 'improvements', 'verdictWorse', 'verdictEqual', 'verdictBetter', 'newlyFailed', 'newlyUnrated', 'questionChanged', 'questionChangeUnknown', 'humanRatingWorse', 'humanRatingBetter', 'humanRatingChanged'], 0);
        foreach ($questions as $question) {
            ++$counts['questions'];
            match ($question->presence) {
                Presence::BOTH => ++$counts['inBoth'],
                Presence::ONLY_IN_BASELINE => ++$counts['onlyInBaseline'],
                default => ++$counts['onlyInCandidate'],
            };
            $counts['regressions'] += (int) $question->regression;
            $counts['improvements'] += (int) ([] !== $question->improvementReasons);
            $counts['verdictWorse'] += (int) (Change::WORSE === $question->verdictChange);
            $counts['verdictEqual'] += (int) (Change::EQUAL === $question->verdictChange);
            $counts['verdictBetter'] += (int) (Change::BETTER === $question->verdictChange);
            $counts['newlyFailed'] += (int) $question->newlyFailed;
            $counts['newlyUnrated'] += (int) $question->newlyUnrated;
            $counts['questionChanged'] += (int) (true === $question->questionChanged);
            $counts['questionChangeUnknown'] += (int) (Presence::BOTH === $question->presence && null === $question->questionChanged);
            $counts['humanRatingWorse'] += (int) (Change::WORSE === $question->humanRating?->change);
            $counts['humanRatingBetter'] += (int) (Change::BETTER === $question->humanRating?->change);
            $counts['humanRatingChanged'] += (int) (true === $question->humanRating?->changed);
        }

        return new self(...$counts);
    }
}
