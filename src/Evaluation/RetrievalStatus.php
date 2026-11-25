<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:21:35 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

/**
 * Retrieval measurement status of an evaluation result
 * (`neuron_eval_result.retrieval_status`), as classified by
 * {@see RetrievalMeasurement}.
 *
 * - measured: the question has markers; `recallHit` and
 *   `firstRelevantRank` hold the outcome;
 * - negative: the question has no answer in the knowledge base on purpose
 *   and is not part of recall or MRR;
 * - unmeasured: the question has no markers;
 * - failed: the repeated search threw; the result keeps the turn's status.
 *
 * The column stays null for runs in mode `agent`, for single executions and
 * for rows written before retrieval measurement existed.
 */
final class RetrievalStatus
{
    public const string MEASURED = 'measured';
    public const string NEGATIVE = 'negative';
    public const string UNMEASURED = 'unmeasured';
    public const string FAILED = 'failed';

    /** Every valid value. */
    public const array ALL = [self::MEASURED, self::NEGATIVE, self::UNMEASURED, self::FAILED];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::ALL;
    }
}
