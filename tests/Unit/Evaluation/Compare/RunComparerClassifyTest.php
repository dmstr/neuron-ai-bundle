<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:11:43 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Evaluation\Compare;

use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Entity\EvalResult;
use Dmstr\NeuronAiBundle\Entity\EvalRun;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\Change;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\ComparisonSummary;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\HumanRatingRule;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\NotComparableException;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\Presence;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\QuestionComparison;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\Reason;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\ResultSnapshot;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvalRunStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvaluationStatus;
use Dmstr\NeuronAiBundle\Evaluation\RunComparer;
use Dmstr\NeuronAiBundle\Evaluation\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free classification matrix of the run comparison:
 * {@see RunComparer::classify()} on result snapshots, always the candidate
 * relative to the baseline.
 *
 * Covered: worse, equal and better verdicts by the order of
 * {@see Verdict::ALL}; a pending, failed or missing latest evaluation is
 * unrated (no verdict change, never a regression) and newly unrated when
 * the baseline was rated; a re-evaluation (another latest evaluation) moves
 * the verdict; newly failed and no longer failed; only in the baseline (a
 * regression) and only in the candidate (none); a changed question by
 * fingerprint and, for results without one, by the first user message,
 * including a deleted conversation (unknown); the human rating without the
 * setting (changed/unchanged with both ratings), with a number field and
 * with an ordered list, with values that cannot be ranked (unrated, never a
 * regression); the key order of {@see RunComparer::classifyAll()}, the
 * summary and the 422 preconditions of {@see RunComparer::assertComparable()}.
 */
final class RunComparerClassifyTest extends TestCase
{
    private const string FINGERPRINT = 'aaaa';

    // --- verdict --------------------------------------------------------

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function verdictPairs(): iterable
    {
        yield 'pass to mostly_fail is worse' => [Verdict::PASS, Verdict::MOSTLY_FAIL, Change::WORSE];
        yield 'mostly_pass to fail is worse' => [Verdict::MOSTLY_PASS, Verdict::FAIL, Change::WORSE];
        yield 'pass to pass is equal' => [Verdict::PASS, Verdict::PASS, Change::EQUAL];
        yield 'fail to fail is equal' => [Verdict::FAIL, Verdict::FAIL, Change::EQUAL];
        yield 'fail to mostly_pass is better' => [Verdict::FAIL, Verdict::MOSTLY_PASS, Change::BETTER];
        yield 'mostly_pass to pass is better' => [Verdict::MOSTLY_PASS, Verdict::PASS, Change::BETTER];
    }

    #[DataProvider('verdictPairs')]
    public function testVerdictChangeFollowsTheVerdictOrder(string $baseline, string $candidate, string $change): void
    {
        $question = RunComparer::classify(self::rated('q', $baseline), self::rated('q', $candidate), null);

        self::assertSame(Presence::BOTH, $question->presence);
        self::assertSame($change, $question->verdictChange);
        self::assertSame(Change::WORSE === $change, $question->regression);
        self::assertSame(Change::WORSE === $change ? [Reason::VERDICT_WORSE] : [], $question->regressionReasons);
        self::assertSame(Change::BETTER === $change ? [Reason::VERDICT_BETTER] : [], $question->improvementReasons);
        self::assertSame($baseline, $question->baseline?->verdict);
        self::assertSame($candidate, $question->candidate?->verdict);
        self::assertFalse($question->newlyUnrated);
        self::assertFalse($question->newlyFailed);
    }

    /**
     * @return iterable<string, array{0: string|null, 1: string|null}>
     */
    public static function unratedEvaluations(): iterable
    {
        yield 'latest evaluation pending' => [EvaluationStatus::PENDING, null];
        yield 'latest evaluation pending after an earlier verdict' => [EvaluationStatus::PENDING, Verdict::PASS];
        yield 'latest evaluation failed' => [EvaluationStatus::FAILED, null];
        yield 'no evaluation' => [null, null];
        yield 'unknown verdict' => [EvaluationStatus::COMPLETED, 'excellent'];
    }

    #[DataProvider('unratedEvaluations')]
    public function testUnratedCandidateIsNewlyUnratedAndNoRegression(?string $evaluationStatus, ?string $verdict): void
    {
        $candidate = self::snapshot('q', evaluationStatus: $evaluationStatus, verdict: $verdict);

        $question = RunComparer::classify(self::rated('q', Verdict::PASS), $candidate, null);

        self::assertNull($question->verdictChange);
        self::assertTrue($question->newlyUnrated);
        self::assertFalse($question->regression);
        self::assertSame([], $question->regressionReasons);
        self::assertFalse($question->candidate?->rated);
        self::assertNull($question->candidate?->verdict);
        self::assertSame($evaluationStatus, $question->candidate?->evaluationStatus);
    }

    public function testUnratedBaselineGivesNoVerdictChangeAndIsNotNewlyUnrated(): void
    {
        $question = RunComparer::classify(self::snapshot('q', evaluationStatus: EvaluationStatus::PENDING), self::rated('q', Verdict::FAIL), null);

        self::assertNull($question->verdictChange);
        self::assertFalse($question->newlyUnrated);
        self::assertFalse($question->regression);
    }

    /**
     * The verdict that counts is the latest evaluation's: replacing it
     * (a re-evaluation) moves the comparison.
     */
    public function testReEvaluationMovesTheVerdict(): void
    {
        $baseline = self::rated('q', Verdict::MOSTLY_PASS);

        self::assertSame(Change::EQUAL, RunComparer::classify($baseline, self::rated('q', Verdict::MOSTLY_PASS), null)->verdictChange);
        self::assertSame(Change::WORSE, RunComparer::classify($baseline, self::rated('q', Verdict::FAIL), null)->verdictChange);
        self::assertSame(Change::BETTER, RunComparer::classify($baseline, self::rated('q', Verdict::PASS), null)->verdictChange);
        self::assertNull(RunComparer::classify($baseline, self::snapshot('q', evaluationStatus: EvaluationStatus::PENDING, verdict: Verdict::PASS), null)->verdictChange);
    }

    // --- result status ---------------------------------------------------

    public function testNewlyFailedIsARegression(): void
    {
        $candidate = self::snapshot('q', status: EvalResultStatus::FAILED);

        $question = RunComparer::classify(self::rated('q', Verdict::PASS), $candidate, null);

        self::assertTrue($question->newlyFailed);
        self::assertTrue($question->newlyUnrated);
        self::assertTrue($question->regression);
        self::assertSame([Reason::NEWLY_FAILED], $question->regressionReasons);
        self::assertSame(EvalResultStatus::FAILED, $question->candidate?->status);
    }

    public function testFailedInBothRunsIsNotNewlyFailed(): void
    {
        $question = RunComparer::classify(self::snapshot('q', status: EvalResultStatus::FAILED), self::snapshot('q', status: EvalResultStatus::FAILED), null);

        self::assertFalse($question->newlyFailed);
        self::assertFalse($question->regression);
        self::assertSame([], $question->improvementReasons);
    }

    public function testNoLongerFailedIsAnImprovementOnly(): void
    {
        $question = RunComparer::classify(self::snapshot('q', status: EvalResultStatus::FAILED), self::rated('q', Verdict::PASS), null);

        self::assertFalse($question->newlyFailed);
        self::assertFalse($question->regression);
        self::assertSame([Reason::NO_LONGER_FAILED], $question->improvementReasons);
    }

    // --- presence --------------------------------------------------------

    public function testOnlyInBaselineIsARegression(): void
    {
        $question = RunComparer::classify(self::rated('q', Verdict::PASS), null, null);

        self::assertSame(Presence::ONLY_IN_BASELINE, $question->presence);
        self::assertTrue($question->regression);
        self::assertSame([Reason::MISSING_IN_CANDIDATE], $question->regressionReasons);
        self::assertNull($question->candidate);
        self::assertNotNull($question->baseline);
        self::assertNull($question->verdictChange);
        self::assertNull($question->questionChanged);
        self::assertNull($question->humanRating);
    }

    public function testOnlyInCandidateIsListedWithoutRegression(): void
    {
        $question = RunComparer::classify(null, self::snapshot('q', status: EvalResultStatus::FAILED), null);

        self::assertSame(Presence::ONLY_IN_CANDIDATE, $question->presence);
        self::assertFalse($question->regression);
        self::assertFalse($question->newlyFailed);
        self::assertSame([], $question->regressionReasons);
        self::assertNull($question->baseline);
        self::assertNotNull($question->candidate);
    }

    // --- changed question ------------------------------------------------

    /**
     * @return iterable<string, array{0: ResultSnapshot, 1: ResultSnapshot, 2: bool|null}>
     */
    public static function questionChanges(): iterable
    {
        yield 'same fingerprint' => [self::snapshot('q', fingerprint: 'f1'), self::snapshot('q', fingerprint: 'f1'), false];
        yield 'other fingerprint' => [self::snapshot('q', fingerprint: 'f1'), self::snapshot('q', fingerprint: 'f2'), true];
        yield 'fingerprint wins over equal messages' => [self::snapshot('q', fingerprint: 'f1', firstUserMessage: 'Same?'), self::snapshot('q', fingerprint: 'f2', firstUserMessage: 'Same?'), true];
        yield 'legacy, other first message' => [self::snapshot('q', fingerprint: null, firstUserMessage: 'Old text?'), self::snapshot('q', fingerprint: null, firstUserMessage: 'New text?'), true];
        yield 'legacy baseline, other first message' => [self::snapshot('q', fingerprint: null, firstUserMessage: 'Old text?'), self::snapshot('q', fingerprint: 'f1', firstUserMessage: 'New text?'), true];
        yield 'legacy, same first message: expected answer unknown' => [self::snapshot('q', fingerprint: null, firstUserMessage: 'Same?'), self::snapshot('q', fingerprint: null, firstUserMessage: 'Same?'), null];
        yield 'legacy, deleted baseline conversation' => [self::snapshot('q', fingerprint: null, hasConversation: false), self::snapshot('q', fingerprint: null, firstUserMessage: 'Text?'), null];
        yield 'legacy, deleted candidate conversation' => [self::snapshot('q', fingerprint: null, firstUserMessage: 'Text?'), self::snapshot('q', fingerprint: 'f1', hasConversation: false), null];
        yield 'legacy, conversation without user message' => [self::snapshot('q', fingerprint: null, firstUserMessage: null), self::snapshot('q', fingerprint: null, firstUserMessage: 'Text?'), null];
    }

    #[DataProvider('questionChanges')]
    public function testQuestionChanged(ResultSnapshot $baseline, ResultSnapshot $candidate, ?bool $changed): void
    {
        $question = RunComparer::classify($baseline, $candidate, null);

        self::assertSame($changed, $question->questionChanged);
        self::assertFalse($question->regression, 'A changed question alone is no regression.');
    }

    public function testFingerprintIsSha256OfQuestionNulAndExpectedAnswer(): void
    {
        $question = new EvalQuestion()->setQuestion('What is the maintenance interval?')->setExpectedAnswer('Every 500 hours.');

        self::assertSame(hash('sha256', "What is the maintenance interval?\0Every 500 hours."), EvalResult::fingerprintOf($question));
        self::assertNotSame(EvalResult::fingerprintOf($question), EvalResult::fingerprintOf(new EvalQuestion()->setQuestion('What is the maintenance interval?')->setExpectedAnswer('Every 400 hours.')));
        // The separator keeps "ab" + "c" apart from "a" + "bc".
        self::assertNotSame(
            EvalResult::fingerprintOf(new EvalQuestion()->setQuestion('ab')->setExpectedAnswer('c')),
            EvalResult::fingerprintOf(new EvalQuestion()->setQuestion('a')->setExpectedAnswer('bc')),
        );
    }

    // --- human rating ------------------------------------------------------

    public function testHumanRatingWithoutSettingReportsChangedWithBothRatings(): void
    {
        $baseline = self::snapshot('q', humanRating: ['score' => 4, 'comment' => 'ok']);

        $changed = RunComparer::classify($baseline, self::snapshot('q', humanRating: ['score' => 2]), null)->humanRating;
        self::assertNotNull($changed);
        self::assertNull($changed->field);
        self::assertNull($changed->order);
        self::assertSame(['score' => 4, 'comment' => 'ok'], $changed->baseline);
        self::assertSame(['score' => 2], $changed->candidate);
        self::assertTrue($changed->changed);
        self::assertSame(Change::CHANGED, $changed->change);

        // Key order does not matter.
        $same = RunComparer::classify($baseline, self::snapshot('q', humanRating: ['comment' => 'ok', 'score' => 4]), null);
        self::assertSame(Change::UNCHANGED, $same->humanRating?->change);
        self::assertFalse($same->humanRating->changed);

        // A worse score is no regression without the setting.
        self::assertFalse(RunComparer::classify($baseline, self::snapshot('q', humanRating: ['score' => 1]), null)->regression);

        // Rated in one run only.
        self::assertSame(Change::CHANGED, RunComparer::classify($baseline, self::snapshot('q'), null)->humanRating?->change);
        self::assertSame(Change::UNCHANGED, RunComparer::classify(self::snapshot('q'), self::snapshot('q'), null)->humanRating?->change);
    }

    /**
     * @return iterable<string, array{0: string, 1: mixed, 2: mixed, 3: string}>
     */
    public static function numberRatings(): iterable
    {
        yield 'higher is better, lower score' => [HumanRatingRule::HIGHER_IS_BETTER, 4, 3, Change::WORSE];
        yield 'higher is better, same score' => [HumanRatingRule::HIGHER_IS_BETTER, 4, 4, Change::EQUAL];
        yield 'higher is better, higher score' => [HumanRatingRule::HIGHER_IS_BETTER, 3, 5, Change::BETTER];
        yield 'higher is better, float' => [HumanRatingRule::HIGHER_IS_BETTER, 3.5, 3.25, Change::WORSE];
        yield 'lower is better, higher count' => [HumanRatingRule::LOWER_IS_BETTER, 1, 3, Change::WORSE];
        yield 'lower is better, lower count' => [HumanRatingRule::LOWER_IS_BETTER, 3, 0, Change::BETTER];
        yield 'candidate field missing' => [HumanRatingRule::HIGHER_IS_BETTER, 4, null, Change::UNRATED];
        yield 'baseline field missing' => [HumanRatingRule::HIGHER_IS_BETTER, null, 1, Change::UNRATED];
        yield 'numeric string is no number' => [HumanRatingRule::HIGHER_IS_BETTER, 4, '1', Change::UNRATED];
        yield 'boolean is no number' => [HumanRatingRule::HIGHER_IS_BETTER, 4, false, Change::UNRATED];
    }

    #[DataProvider('numberRatings')]
    public function testHumanRatingNumberField(string $order, mixed $baseline, mixed $candidate, string $change): void
    {
        $rule = new HumanRatingRule('score', $order);

        $question = RunComparer::classify(
            self::snapshot('q', humanRating: self::rating('score', $baseline)),
            self::snapshot('q', humanRating: self::rating('score', $candidate)),
            $rule,
        );

        self::assertNotNull($question->humanRating);
        self::assertSame('score', $question->humanRating->field);
        self::assertSame($order, $question->humanRating->order);
        self::assertSame($baseline, $question->humanRating->baseline);
        self::assertSame($candidate, $question->humanRating->candidate);
        self::assertSame($change, $question->humanRating->change);
        self::assertSame(Change::WORSE === $change, $question->regression);
        self::assertSame(Change::WORSE === $change ? [Reason::HUMAN_RATING_WORSE] : [], $question->regressionReasons);
        self::assertSame(Change::BETTER === $change ? [Reason::HUMAN_RATING_BETTER] : [], $question->improvementReasons);
    }

    public function testHumanRatingWithoutRatingIsUnrated(): void
    {
        $question = RunComparer::classify(self::snapshot('q', humanRating: ['score' => 4]), self::snapshot('q'), new HumanRatingRule('score', HumanRatingRule::HIGHER_IS_BETTER));

        self::assertSame(Change::UNRATED, $question->humanRating?->change);
        self::assertTrue($question->humanRating->changed);
        self::assertFalse($question->regression);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function listRatings(): iterable
    {
        yield 'higher is better, good to fair' => [HumanRatingRule::HIGHER_IS_BETTER, 'good', 'fair', Change::WORSE];
        yield 'higher is better, poor to good' => [HumanRatingRule::HIGHER_IS_BETTER, 'poor', 'good', Change::BETTER];
        yield 'higher is better, fair to fair' => [HumanRatingRule::HIGHER_IS_BETTER, 'fair', 'fair', Change::EQUAL];
        yield 'lower is better, good to fair' => [HumanRatingRule::LOWER_IS_BETTER, 'good', 'fair', Change::BETTER];
        yield 'value outside the scale' => [HumanRatingRule::HIGHER_IS_BETTER, 'good', 'excellent', Change::UNRATED];
        yield 'case matters' => [HumanRatingRule::HIGHER_IS_BETTER, 'good', 'Poor', Change::UNRATED];
    }

    #[DataProvider('listRatings')]
    public function testHumanRatingOrderedList(string $order, string $baseline, string $candidate, string $change): void
    {
        $rule = new HumanRatingRule('grade', $order, ['poor', 'fair', 'good']);

        $question = RunComparer::classify(
            self::snapshot('q', humanRating: ['grade' => $baseline]),
            self::snapshot('q', humanRating: ['grade' => $candidate]),
            $rule,
        );

        self::assertSame($change, $question->humanRating?->change);
        self::assertSame($baseline !== $candidate, $question->humanRating->changed);
        self::assertSame(Change::WORSE === $change, $question->regression);
    }

    public function testNumberOnAListScaleIsUnrated(): void
    {
        $rule = new HumanRatingRule('grade', HumanRatingRule::HIGHER_IS_BETTER, ['1', '2', '3']);

        self::assertSame(Change::UNRATED, $rule->compare(['grade' => '3'], ['grade' => 1]));
        self::assertSame(Change::WORSE, $rule->compare(['grade' => '3'], ['grade' => '1']));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>|null, 1: array{field: string, order: string, values: list<string>|null}|null}>
     */
    public static function settings(): iterable
    {
        yield 'none' => [null, null];
        yield 'number field' => [['field' => 'score', 'order' => 'higher_is_better'], ['field' => 'score', 'order' => 'higher_is_better', 'values' => null]];
        yield 'ordered list' => [['field' => ' grade ', 'order' => 'lower_is_better', 'values' => ['a', 'b']], ['field' => 'grade', 'order' => 'lower_is_better', 'values' => ['a', 'b']]];
        yield 'values null' => [['field' => 'score', 'order' => 'higher_is_better', 'values' => null], ['field' => 'score', 'order' => 'higher_is_better', 'values' => null]];
        yield 'field missing' => [['order' => 'higher_is_better'], null];
        yield 'empty field' => [['field' => ' ', 'order' => 'higher_is_better'], null];
        yield 'unknown order' => [['field' => 'score', 'order' => 'up'], null];
        yield 'values empty' => [['field' => 'score', 'order' => 'higher_is_better', 'values' => []], null];
        yield 'values not strings' => [['field' => 'score', 'order' => 'higher_is_better', 'values' => [1, 2]], null];
        yield 'values an object' => [['field' => 'score', 'order' => 'higher_is_better', 'values' => ['a' => 'x']], null];
    }

    /**
     * An unusable setting counts as none.
     *
     * @param array<string, mixed>|null                                                    $setting
     * @param array{field: string, order: string, values: list<string>|null}|null $expected
     */
    #[DataProvider('settings')]
    public function testRuleFromSetting(?array $setting, ?array $expected): void
    {
        self::assertSame($expected, HumanRatingRule::fromSetting($setting)?->toArray());
    }

    // --- whole comparison --------------------------------------------------

    public function testClassifyAllListsEveryKeyInKeyOrderAndSummarises(): void
    {
        $baseline = [
            'c-worse' => self::rated('c-worse', Verdict::PASS),
            'a-equal' => self::rated('a-equal', Verdict::PASS),
            'd-only-baseline' => self::rated('d-only-baseline', Verdict::PASS),
            'e-newly-failed' => self::rated('e-newly-failed', Verdict::MOSTLY_PASS),
            'f-unrated' => self::rated('f-unrated', Verdict::PASS),
            'g-changed' => self::snapshot('g-changed', fingerprint: 'f1'),
            'h-better' => self::rated('h-better', Verdict::FAIL),
        ];
        $candidate = [
            'a-equal' => self::rated('a-equal', Verdict::PASS),
            'b-only-candidate' => self::rated('b-only-candidate', Verdict::PASS),
            'c-worse' => self::rated('c-worse', Verdict::FAIL),
            'e-newly-failed' => self::snapshot('e-newly-failed', status: EvalResultStatus::FAILED),
            'f-unrated' => self::snapshot('f-unrated', evaluationStatus: EvaluationStatus::FAILED),
            'g-changed' => self::snapshot('g-changed', fingerprint: 'f2'),
            'h-better' => self::rated('h-better', Verdict::PASS),
        ];

        $questions = RunComparer::classifyAll($baseline, $candidate, null);

        self::assertSame(['a-equal', 'b-only-candidate', 'c-worse', 'd-only-baseline', 'e-newly-failed', 'f-unrated', 'g-changed', 'h-better'], array_map(static fn (QuestionComparison $q): string => $q->key, $questions));
        self::assertSame(['c-worse', 'd-only-baseline', 'e-newly-failed'], array_values(array_map(static fn (QuestionComparison $q): string => $q->key, array_filter($questions, static fn (QuestionComparison $q): bool => $q->regression))));

        $summary = ComparisonSummary::of($questions);
        self::assertSame(8, $summary->questions);
        self::assertSame(6, $summary->inBoth);
        self::assertSame(1, $summary->onlyInBaseline);
        self::assertSame(1, $summary->onlyInCandidate);
        self::assertSame(3, $summary->regressions);
        self::assertSame(1, $summary->improvements);
        self::assertSame(1, $summary->verdictWorse);
        self::assertSame(1, $summary->verdictEqual);
        self::assertSame(1, $summary->verdictBetter);
        self::assertSame(1, $summary->newlyFailed);
        self::assertSame(2, $summary->newlyUnrated);
        self::assertSame(1, $summary->questionChanged);
        self::assertSame(0, $summary->questionChangeUnknown);
        self::assertSame(0, $summary->humanRatingChanged);
    }

    public function testNumericKeysStayStrings(): void
    {
        $questions = RunComparer::classifyAll(['10' => self::rated('10', Verdict::PASS)], ['9' => self::rated('9', Verdict::PASS)], null);

        self::assertSame(['10', '9'], array_map(static fn (QuestionComparison $q): string => $q->key, $questions));
    }

    // --- preconditions -----------------------------------------------------

    public function testCompletedRunsAreComparable(): void
    {
        $this->expectNotToPerformAssertions();

        RunComparer::assertComparable(self::evalRun(EvalRunStatus::COMPLETED), self::evalRun(EvalRunStatus::COMPLETED));
    }

    public function testSameRunTwiceIsNotComparable(): void
    {
        $run = self::evalRun(EvalRunStatus::COMPLETED);

        $this->expectException(NotComparableException::class);
        $this->expectExceptionMessage('itself');

        RunComparer::assertComparable($run, $run);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function unfinishedRuns(): iterable
    {
        foreach ([EvalRunStatus::PENDING, EvalRunStatus::RUNNING, EvalRunStatus::CANCELLED, EvalRunStatus::FAILED] as $status) {
            yield 'candidate '.$status => [$status, EvalRunStatus::COMPLETED, 'candidate'];
            yield 'baseline '.$status => [EvalRunStatus::COMPLETED, $status, 'baseline'];
        }
    }

    #[DataProvider('unfinishedRuns')]
    public function testRunThatIsNotCompletedIsNotComparable(string $candidate, string $baseline, string $role): void
    {
        $this->expectException(NotComparableException::class);
        $this->expectExceptionMessageMatches('/^The '.$role.' run .* is not completed/');

        RunComparer::assertComparable(self::evalRun($candidate), self::evalRun($baseline));
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @param array<array-key, mixed>|null $humanRating
     */
    private static function snapshot(
        string $key,
        string $status = EvalResultStatus::COMPLETED,
        ?string $evaluationStatus = null,
        ?string $verdict = null,
        ?array $humanRating = null,
        ?string $fingerprint = self::FINGERPRINT,
        bool $hasConversation = true,
        ?string $firstUserMessage = null,
    ): ResultSnapshot {
        return new ResultSnapshot(
            questionKey: $key,
            question: '/api/eval_questions/'.$key,
            result: '/api/eval_results/'.$key.'-'.bin2hex(random_bytes(2)),
            status: $status,
            evaluationStatus: $evaluationStatus,
            evaluationVerdict: $verdict,
            humanRating: $humanRating,
            questionFingerprint: $fingerprint,
            hasConversation: $hasConversation,
            firstUserMessage: $hasConversation ? $firstUserMessage : null,
        );
    }

    private static function rated(string $key, string $verdict): ResultSnapshot
    {
        return self::snapshot($key, evaluationStatus: EvaluationStatus::COMPLETED, verdict: $verdict);
    }

    /**
     * @return array<string, mixed>
     */
    private static function rating(string $field, mixed $value): array
    {
        return null === $value ? ['comment' => 'no '.$field] : [$field => $value];
    }

    private static function evalRun(string $status): EvalRun
    {
        return new EvalRun()->setStatus($status);
    }
}
