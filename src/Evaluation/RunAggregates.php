<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:46:34 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

use Dmstr\NeuronAiBundle\Entity\EvalResult;
use Dmstr\NeuronAiBundle\Entity\EvalRun;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The judge aggregates of an {@see EvalRun}: `verdictCounts`
 * maps every {@see Verdict} (`pass`, `mostly_pass`, `mostly_fail`,
 * `fail`; all four keys always present) to the number of the run's results
 * whose LATEST evaluation (`EvalResult.evaluation`) is `completed` with
 * that verdict. Earlier evaluations of a result do not count, so a
 * re-evaluation moves the result from one verdict to another instead of
 * adding to the total; results without a completed latest evaluation
 * (none yet, pending, failed) are not counted.
 *
 * Refreshed by the evaluate job after it stored a verdict and when the
 * batch job completes a run (all four keys at 0 then, unless results were
 * evaluated while the run was still going). `tokensTotal` and
 * `interactionsTotal` stay the sums of the question turns maintained by
 * {@see BatchRunner}; the tokens of the judge are on each
 * {@see \Dmstr\NeuronAiBundle\Entity\Evaluation}.
 *
 * The retrieval aggregates are `recall`, `mrr` and the
 * denominators `questionsMeasured`, `questionsNegative`,
 * `questionsUnmeasured` and `questionsRetrievalFailed`, computed from the
 * stored `retrieval_status`, `recall_hit` and `first_relevant_rank` of the
 * run's results ({@see self::retrieval()} for the arithmetic). Results
 * without a retrieval status (mode `agent`, rows written before retrieval measurement existed) are
 * ignored. The denominators come from the stored results only, never from
 * `questionsTotal`, so a cancelled, redelivered or failed run reports
 * exactly what was measured.
 *
 * Both refreshes recompute from stored rows (never increment), set the
 * values on the entity and do not flush.
 */
final class RunAggregates
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Recomputes `verdictCounts` of $run with one aggregate query; not
     * flushed.
     */
    public function refresh(EvalRun $run): void
    {
        /** @var list<array{verdict: string|null, total: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('e.verdict AS verdict', 'COUNT(r.id) AS total')
            ->from(EvalResult::class, 'r')
            ->innerJoin('r.evaluation', 'e')
            ->where('r.evalRun = :run')
            ->andWhere('e.status = :completed')
            ->groupBy('e.verdict')
            ->setParameter('run', $run->getId(), 'uuid')
            ->setParameter('completed', EvaluationStatus::COMPLETED)
            ->getQuery()
            ->getArrayResult();

        $run->setVerdictCounts(self::counts($rows));
    }

    /**
     * Recomputes the retrieval aggregates of $run (`recall`, `mrr` and the
     * four denominators) with one aggregate query over its stored results;
     * not flushed. Results the caller has not flushed yet are not counted.
     */
    public function refreshRetrieval(EvalRun $run): void
    {
        /** @var list<array{retrieval_status: string|null, total: int|string, hits: int|string|null, reciprocal_rank_sum: float|int|string|null}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT retrieval_status, COUNT(*) AS total,'
            .' SUM(CASE WHEN recall_hit THEN 1 ELSE 0 END) AS hits,'
            .' SUM(CASE WHEN first_relevant_rank > 0 THEN 1.0 / first_relevant_rank ELSE 0 END) AS reciprocal_rank_sum'
            .' FROM neuron_eval_result WHERE eval_run_id = :run AND retrieval_status IS NOT NULL'
            .' GROUP BY retrieval_status',
            ['run' => $run->getId()->toRfc4122()],
        );

        $aggregates = self::retrieval($rows);

        $run->setRecall($aggregates['recall'])
            ->setMrr($aggregates['mrr'])
            ->setQuestionsMeasured($aggregates['measured'])
            ->setQuestionsNegative($aggregates['negative'])
            ->setQuestionsUnmeasured($aggregates['unmeasured'])
            ->setQuestionsRetrievalFailed($aggregates['failed']);
    }

    /**
     * Folds the rows of the retrieval GROUP BY into the run aggregates.
     *
     * One row per `retrieval_status` with the keys
     * - `retrieval_status`: a {@see RetrievalStatus} value (unknown or null
     *   rows are ignored);
     * - `total`: number of results with that status;
     * - `hits`: number of those results with `recall_hit` true;
     * - `reciprocal_rank_sum`: Σ 1 / `first_relevant_rank` over those
     *   results (a miss has no rank and adds 0).
     * Numbers may arrive as driver strings.
     *
     * Only `measured` rows contribute hits and rank sum: recall = hits /
     * measured, MRR = Σ(1/rank) / measured; both null when nothing was
     * measured. `negative`, `unmeasured` and `failed` are counted but are
     * not part of either denominator.
     *
     * @param iterable<array{retrieval_status: string|null, total: int|string, hits?: int|string|null, reciprocal_rank_sum?: float|int|string|null}> $rows
     *
     * @return array{recall: ?float, mrr: ?float, measured: int, negative: int, unmeasured: int, failed: int}
     */
    public static function retrieval(iterable $rows): array
    {
        $counts = array_fill_keys(RetrievalStatus::ALL, 0);
        $hits = 0;
        $reciprocalRankSum = 0.0;

        foreach ($rows as $row) {
            $status = $row['retrieval_status'];
            if (null === $status || !\array_key_exists($status, $counts)) {
                continue;
            }
            $counts[$status] += (int) $row['total'];
            if (RetrievalStatus::MEASURED === $status) {
                $hits += (int) ($row['hits'] ?? 0);
                $reciprocalRankSum += (float) ($row['reciprocal_rank_sum'] ?? 0);
            }
        }

        $measured = $counts[RetrievalStatus::MEASURED];

        return [
            'recall' => $measured > 0 ? (float) $hits / $measured : null,
            'mrr' => $measured > 0 ? $reciprocalRankSum / $measured : null,
            'measured' => $measured,
            'negative' => $counts[RetrievalStatus::NEGATIVE],
            'unmeasured' => $counts[RetrievalStatus::UNMEASURED],
            'failed' => $counts[RetrievalStatus::FAILED],
        ];
    }

    /**
     * Folds aggregate rows into the four verdict keys, in the order of
     * {@see Verdict::ALL}; unknown or empty verdicts are ignored.
     *
     * @param iterable<array{verdict: string|null, total: int|string}> $rows
     *
     * @return array<string, int>
     */
    public static function counts(iterable $rows): array
    {
        $counts = array_fill_keys(Verdict::ALL, 0);
        foreach ($rows as $row) {
            $verdict = $row['verdict'];
            if (null !== $verdict && \array_key_exists($verdict, $counts)) {
                $counts[$verdict] += (int) $row['total'];
            }
        }

        return $counts;
    }
}
