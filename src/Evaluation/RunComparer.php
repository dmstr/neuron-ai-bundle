<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:11:43 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

use ApiPlatform\Metadata\IriConverterInterface;
use Dmstr\NeuronAiBundle\Entity\EvalResult;
use Dmstr\NeuronAiBundle\Entity\EvalRun;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\Change;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\ComparisonSummary;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\HumanRatingComparison;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\HumanRatingRule;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\NotComparableException;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\Presence;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\QuestionComparison;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\Reason;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\ResultSide;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\ResultSnapshot;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\RunComparison;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\RunReference;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Compares two completed evaluation runs per question key (operation
 * `eval_run_compare`): the caller names a BASELINE and a
 * CANDIDATE, and every change is read as "the candidate relative to the
 * baseline". Nothing is stored.
 *
 * Per key ({@see self::classify()}, no database needed):
 *
 * - presence: in both runs, only in the baseline (a regression,
 *   {@see Reason::MISSING_IN_CANDIDATE}) or only in the candidate (listed);
 * - verdict: the verdict that counts is the one of the result's LATEST
 *   evaluation once it is `completed`, as {@see RunAggregates} counts it;
 *   no evaluation, or a pending or failed latest one, is unrated, which is
 *   never a regression. With both sides rated the change is worse, equal
 *   or better by the position in {@see Verdict::ALL} (best first);
 * - newly failed: the candidate's result is `failed`, the baseline's is
 *   not (a regression); the opposite is the improvement
 *   {@see Reason::NO_LONGER_FAILED};
 * - newly unrated: the baseline's result is rated, the candidate's is not;
 * - question changed: the question fingerprints of the two results
 *   ({@see EvalResult::fingerprintOf()}) differ; when one of them has none
 *   (recorded before fingerprints existed), the first user messages of the two result
 *   conversations are compared: different means changed, equal or not
 *   available (a deleted conversation) means unknown (null), because the
 *   expected answer may still differ. A changed question does not suppress
 *   other findings; it says the key no longer means the same question;
 * - human rating: ranked by the setting `human_rating_comparison`
 *   ({@see HumanRatingRule}), a worse value is a regression; without the
 *   setting only "changed"/"unchanged" with both ratings.
 *
 * Improvements (better verdict, better human rating, no longer failed) are
 * listed in `improvementReasons` but never flag the question.
 */
final class RunComparer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?EvaluationSettingsInterface $settings = null,
        private readonly ?IriConverterInterface $iriConverter = null,
    ) {
    }

    /**
     * Compares $candidate with $baseline.
     *
     * @throws NotComparableException when both are the same run or one of them is not completed
     */
    public function compare(EvalRun $candidate, EvalRun $baseline): RunComparison
    {
        self::assertComparable($candidate, $baseline);

        $rule = HumanRatingRule::fromSetting($this->settings?->humanRatingComparison());
        $baselineSnapshots = $this->snapshots($baseline);
        $candidateSnapshots = $this->snapshots($candidate);

        if (self::needsFirstUserMessages($baselineSnapshots, $candidateSnapshots)) {
            $messages = $this->firstUserMessages($baseline, $candidate);
            $baselineSnapshots = self::withFirstUserMessages($baselineSnapshots, $messages);
            $candidateSnapshots = self::withFirstUserMessages($candidateSnapshots, $messages);
        }

        $questions = self::classifyAll($baselineSnapshots, $candidateSnapshots, $rule);

        return new RunComparison(
            $this->reference($baseline),
            $this->reference($candidate),
            $rule?->toArray(),
            ComparisonSummary::of($questions),
            $questions,
        );
    }

    /**
     * @throws NotComparableException when both are the same run or one of them is not completed
     */
    public static function assertComparable(EvalRun $candidate, EvalRun $baseline): void
    {
        if ($candidate->getId()->equals($baseline->getId())) {
            throw new NotComparableException('A run cannot be compared with itself; name two different runs as candidate and baseline.');
        }
        foreach (['candidate' => $candidate, 'baseline' => $baseline] as $role => $run) {
            if (EvalRunStatus::COMPLETED !== $run->getStatus()) {
                throw new NotComparableException(\sprintf('The %s run %s is not completed (status "%s"); only completed runs can be compared.', $role, $run->getId()->toRfc4122(), $run->getStatus()));
            }
        }
    }

    /**
     * Classifies every key of either run, in key order.
     *
     * @param array<string, ResultSnapshot> $baseline  by question key
     * @param array<string, ResultSnapshot> $candidate by question key
     *
     * @return list<QuestionComparison>
     */
    public static function classifyAll(array $baseline, array $candidate, ?HumanRatingRule $rule): array
    {
        $keys = array_values(array_unique(array_merge(array_map('strval', array_keys($baseline)), array_map('strval', array_keys($candidate)))));
        sort($keys, \SORT_STRING);

        return array_map(static fn (string $key): QuestionComparison => self::classify($baseline[$key] ?? null, $candidate[$key] ?? null, $rule), $keys);
    }

    /**
     * Classifies one question key; at least one side must be given.
     */
    public static function classify(?ResultSnapshot $baseline, ?ResultSnapshot $candidate, ?HumanRatingRule $rule): QuestionComparison
    {
        $any = $candidate ?? $baseline ?? throw new \InvalidArgumentException('At least one result is needed to classify a question.');

        if (null === $candidate || null === $baseline) {
            $onlyInBaseline = null === $candidate;

            return new QuestionComparison(
                key: $any->questionKey,
                question: $any->question,
                presence: $onlyInBaseline ? Presence::ONLY_IN_BASELINE : Presence::ONLY_IN_CANDIDATE,
                baseline: null === $baseline ? null : ResultSide::of($baseline),
                candidate: null === $candidate ? null : ResultSide::of($candidate),
                verdictChange: null,
                newlyFailed: false,
                newlyUnrated: false,
                questionChanged: null,
                humanRating: null,
                regression: $onlyInBaseline,
                regressionReasons: $onlyInBaseline ? [Reason::MISSING_IN_CANDIDATE] : [],
            );
        }

        $regressions = [];
        $improvements = [];

        $verdictChange = self::verdictChange($baseline->ratedVerdict(), $candidate->ratedVerdict());
        if (Change::WORSE === $verdictChange) {
            $regressions[] = Reason::VERDICT_WORSE;
        } elseif (Change::BETTER === $verdictChange) {
            $improvements[] = Reason::VERDICT_BETTER;
        }

        $humanRating = HumanRatingComparison::of($rule, $baseline->humanRating, $candidate->humanRating);
        if (Change::WORSE === $humanRating->change) {
            $regressions[] = Reason::HUMAN_RATING_WORSE;
        } elseif (Change::BETTER === $humanRating->change) {
            $improvements[] = Reason::HUMAN_RATING_BETTER;
        }

        $newlyFailed = $candidate->isFailed() && !$baseline->isFailed();
        if ($newlyFailed) {
            $regressions[] = Reason::NEWLY_FAILED;
        } elseif ($baseline->isFailed() && !$candidate->isFailed()) {
            $improvements[] = Reason::NO_LONGER_FAILED;
        }

        return new QuestionComparison(
            key: $candidate->questionKey,
            question: $candidate->question,
            presence: Presence::BOTH,
            baseline: ResultSide::of($baseline),
            candidate: ResultSide::of($candidate),
            verdictChange: $verdictChange,
            newlyFailed: $newlyFailed,
            newlyUnrated: $baseline->isRated() && !$candidate->isRated(),
            questionChanged: self::questionChanged($baseline, $candidate),
            humanRating: $humanRating,
            regression: [] !== $regressions,
            regressionReasons: $regressions,
            improvementReasons: $improvements,
        );
    }

    /**
     * worse, equal or better by the position in {@see Verdict::ALL} (best
     * first); null when either side is unrated.
     */
    public static function verdictChange(?string $baseline, ?string $candidate): ?string
    {
        $baselineIndex = null === $baseline ? false : array_search($baseline, Verdict::ALL, true);
        $candidateIndex = null === $candidate ? false : array_search($candidate, Verdict::ALL, true);
        if (false === $baselineIndex || false === $candidateIndex) {
            return null;
        }

        // A lower index is better, so the negated index is the rank.
        return Change::ofRanks(-$baselineIndex, -$candidateIndex);
    }

    /**
     * true or false from the fingerprints; without both fingerprints true
     * when the first user messages differ, else null (unknown).
     */
    public static function questionChanged(ResultSnapshot $baseline, ResultSnapshot $candidate): ?bool
    {
        if (null !== $baseline->questionFingerprint && null !== $candidate->questionFingerprint) {
            return !hash_equals($baseline->questionFingerprint, $candidate->questionFingerprint);
        }

        if (!$baseline->hasConversation || !$candidate->hasConversation || null === $baseline->firstUserMessage || null === $candidate->firstUserMessage) {
            return null;
        }

        // Equal question texts: the expected answer may still differ.
        return $baseline->firstUserMessage !== $candidate->firstUserMessage ? true : null;
    }

    /**
     * The run's results by question key, with the latest evaluation and the
     * question in one query.
     *
     * @return array<string, ResultSnapshot>
     */
    private function snapshots(EvalRun $run): array
    {
        /** @var list<EvalResult> $results */
        $results = $this->entityManager->createQueryBuilder()
            ->select('r', 'q', 'e')
            ->from(EvalResult::class, 'r')
            ->innerJoin('r.evalQuestion', 'q')
            ->leftJoin('r.evaluation', 'e')
            ->where('r.evalRun = :run')
            ->setParameter('run', $run->getId(), 'uuid')
            ->getQuery()
            ->getResult();

        $snapshots = [];
        foreach ($results as $result) {
            $question = $result->getEvalQuestion();
            $evaluation = $result->getEvaluation();
            $snapshots[$question->getKey()] = new ResultSnapshot(
                questionKey: $question->getKey(),
                question: $this->iri($question, '/api/eval_questions/'.$question->getId()->toRfc4122()),
                result: $this->iri($result, '/api/eval_results/'.$result->getId()->toRfc4122()),
                status: $result->getStatus(),
                evaluationStatus: $evaluation?->getStatus(),
                evaluationVerdict: $evaluation?->getVerdict(),
                humanRating: $result->getHumanRating(),
                questionFingerprint: $result->getQuestionFingerprint(),
                hasConversation: null !== $result->getConversation(),
                firstUserMessage: null,
            );
        }

        return $snapshots;
    }

    /**
     * Whether a key in both runs lacks a fingerprint on a side, so the
     * first user messages are needed.
     *
     * @param array<string, ResultSnapshot> $baseline
     * @param array<string, ResultSnapshot> $candidate
     */
    private static function needsFirstUserMessages(array $baseline, array $candidate): bool
    {
        foreach ($baseline as $key => $snapshot) {
            $other = $candidate[$key] ?? null;
            if (null !== $other && (null === $snapshot->questionFingerprint || null === $other->questionFingerprint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Content of the first user message (lowest sequence) of every result
     * conversation of both runs, keyed by result IRI.
     *
     * @return array<string, string>
     */
    private function firstUserMessages(EvalRun $baseline, EvalRun $candidate): array
    {
        /** @var list<array{result: EvalResult, content: string|null}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r AS result', 'm.content AS content')
            ->from(EvalResult::class, 'r')
            ->innerJoin(Message::class, 'm', 'WITH', 'm.conversation = r.conversation AND m.role = :user')
            ->where('r.evalRun = :baseline OR r.evalRun = :candidate')
            ->andWhere('m.sequence = (SELECT MIN(m2.sequence) FROM '.Message::class.' m2 WHERE m2.conversation = r.conversation AND m2.role = :user)')
            ->setParameter('baseline', $baseline->getId(), 'uuid')
            ->setParameter('candidate', $candidate->getId(), 'uuid')
            ->setParameter('user', Message::SPEAKER_USER)
            ->getQuery()
            ->getResult();

        $messages = [];
        foreach ($rows as $row) {
            $result = $row['result'];
            if (null !== $row['content']) {
                $messages[$this->iri($result, '/api/eval_results/'.$result->getId()->toRfc4122())] = $row['content'];
            }
        }

        return $messages;
    }

    /**
     * @param array<string, ResultSnapshot> $snapshots
     * @param array<string, string>         $messages  by result IRI
     *
     * @return array<string, ResultSnapshot>
     */
    private static function withFirstUserMessages(array $snapshots, array $messages): array
    {
        foreach ($snapshots as $key => $snapshot) {
            $snapshots[$key] = new ResultSnapshot(
                questionKey: $snapshot->questionKey,
                question: $snapshot->question,
                result: $snapshot->result,
                status: $snapshot->status,
                evaluationStatus: $snapshot->evaluationStatus,
                evaluationVerdict: $snapshot->evaluationVerdict,
                humanRating: $snapshot->humanRating,
                questionFingerprint: $snapshot->questionFingerprint,
                hasConversation: $snapshot->hasConversation,
                firstUserMessage: $messages[$snapshot->result] ?? null,
            );
        }

        return $snapshots;
    }

    private function reference(EvalRun $run): RunReference
    {
        $version = $run->getAgentVersion();
        $agent = $version->getAgent();

        return new RunReference(
            run: $this->iri($run, '/api/eval_runs/'.$run->getId()->toRfc4122()),
            status: $run->getStatus(),
            agentVersion: $this->iri($version, '/api/agent_versions/'.$version->getId()->toRfc4122()),
            agent: $this->iri($agent, '/api/agents/'.$agent->getId()->toRfc4122()),
            agentSlug: $agent->getSlug(),
            versionNumber: $version->getVersionNumber(),
            versionLabel: $version->label(),
            versionKind: $version->getKind()->value,
            startedAt: $run->getStartedAt(),
            finishedAt: $run->getFinishedAt(),
            questionsTotal: $run->getQuestionsTotal(),
            verdictCounts: $run->getVerdictCounts(),
        );
    }

    private function iri(object $resource, string $fallback): string
    {
        return $this->iriConverter?->getIriFromResource($resource) ?? $fallback;
    }
}

// - revised 2026-10-13
