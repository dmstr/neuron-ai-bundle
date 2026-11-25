<?php

// file generated with AI assistance: Claude Code - 2026-10-03 19:10:10 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

use Dmstr\NeuronAiBundle\Agent\AgentVersionPinner;
use Dmstr\NeuronAiBundle\Agent\RetrievalScope;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Anonymous\SystemActor;
use Dmstr\NeuronAiBundle\Conversation\ContextRetriever;
use Dmstr\NeuronAiBundle\Conversation\ConversationStatus;
use Dmstr\NeuronAiBundle\Conversation\ConversationTurnService;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Entity\EvalResult;
use Dmstr\NeuronAiBundle\Entity\EvalRun;
use Dmstr\NeuronAiBundle\Entity\Message;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Runs the questions of an {@see EvalRun}; driven by
 * {@see \Dmstr\NeuronAiBundle\JobProcessor\EvalRunJobProcessor}.
 *
 * 1. A pending run becomes `running` (`startedAt` is kept on a
 *    redelivery);
 * 2. the ACTIVE questions run in a stable order (`createdAt`, then `key`);
 *    a question that already has a result in this run is skipped, so a
 *    redelivered job resumes instead of duplicating results (the unique
 *    key run/question guards the rest). When the question list is taken,
 *    `questionsTotal` becomes |active questions ∪ questions already
 *    answered in this run|, so the progress stays truthful on a
 *    redelivery and when questions were archived or activated between
 *    the start and the job (the start only stores the active count of
 *    that moment); every answered question, active or not, is reported
 *    as skipped before the first checkpoint, so the progress of a
 *    redelivery starts at the answered share and reaches 100 even when no
 *    question is left to run;
 * 3. before each question the cancel flag is read from the database (not
 *    from the cached entity, the cancel request is another process): when
 *    it is set the run ends `cancelled` with `finishedAt`, and the
 *    remaining questions get no result;
 * 4. per question a {@see Conversation} is created for the run's
 *    `ownerSubject`, pinned to the run's version exactly as
 *    `POST /conversations` with `agent` and `variables` does
 *    ({@see AgentVersionPinner}: the version values, the question's
 *    `requestData` as variables ({@see QuestionVariables}), the default
 *    language), with `origin` `eval`; then ONE turn with the question text
 *    runs through {@see ConversationTurnService::turn()}, which takes
 *    instructions, schema, tools and limits from the pinned version. The
 *    result (`source` batch) is `completed` when the final record is
 *    `complete`, otherwise `failed` with the record's error; an exception
 *    of the creation or the turn (stale reference, invalid or missing
 *    variables, lock, gate) is a `failed` result with the exception
 *    message, e.g. `variables/date: "date" is a system value and cannot be
 *    supplied.`, without conversation when the pinning refused. A failing
 *    question never stops the run. `durationMs` covers creation and turn;
 *    tokens and interactions are read from the turn's records
 *    ({@see TurnMetrics});
 * 5. in mode {@see self::MODE_RETRIEVAL} the question's
 *    retrieval is measured after the turn, whether the turn succeeded or
 *    failed: the search the turn runs is repeated with the question text
 *    ({@see ContextRetriever::pool()}, uncapped, over the collections and
 *    at the retrieval depth and with the hybrid search of the run's
 *    version, resolved once per run
 *    through {@see VersionTurnMapper::retrievalScope()}) and classified by
 *    {@see RetrievalMeasurement::classify()} onto the result. That is one
 *    more query embedding per distinct embedding model of the collections
 *    and question, no chat call; a turn decided by a pre-answer rule
 *    searches nothing itself, but the measurement still searches, because
 *    the pool does not depend on the answer. A search that throws makes
 *    the measurement {@see RetrievalStatus::FAILED} (logged); the result
 *    keeps the turn's status. The conversation and its `sources` stay
 *    untouched. Mode `agent` (and runs without a mode) measure nothing,
 *    `retrievalStatus` stays null;
 * 5a. a retrieval-only run ({@see EvalRun::isRetrievalOnly()},
 *    always mode {@see self::MODE_RETRIEVAL}) takes another branch per
 *    question instead of steps 4 and 5: no conversation, no
 *    {@see SystemActor}, no turn and no chat or stream call (so no
 *    instructions are rendered and `requestData` is neither used nor
 *    checked), only the search of step 5 (one query embedding per
 *    distinct embedding model and question) and its classification.
 *    `durationMs` is the time of that search (loading the collections
 *    included, when it happens for this question); tokens, interactions
 *    and the conversation stay null, so the run's totals do not grow. The
 *    result is `completed` with its retrieval status (negative and
 *    unmeasured questions included); a search that throws (logged as in
 *    step 5) makes it `failed` with the exception message AND
 *    {@see RetrievalStatus::FAILED}. Order, cancel check, redelivery skip,
 *    `questionsTotal`, fingerprint, checkpoint and the final status are
 *    the same loop as for every other run;
 * 6. after each question the run's `tokensTotal` and `interactionsTotal`
 *    grow, everything is flushed and the checkpoint is called (the job
 *    writes `progress` and `resultData` there);
 * 7. at the end the run is `completed` with `finishedAt`.
 *
 * Audit: the conversation creation and every turn (whose flushes write
 * audited conversation changes) run under the system actor
 * {@see self::ACTOR}, because the worker has no user token.
 *
 * Entity manager: {@see ConversationTurnService} records provider
 * failures, failed retrieval and deadlines as an `error` record without
 * throwing, so the usual failure leaves the entity manager open. A
 * database error inside one of its transactions closes it; in that case
 * the manager is reset through the registry and the run and question are
 * fetched again, so the batch continues with the next question.
 */
final class BatchRunner
{
    /** Audit actor of evaluation conversations and their turns. */
    public const string ACTOR = 'system:neuron:eval';

    /** Run mode (`EvalRun.mode`): each question's retrieval is measured. */
    public const string MODE_RETRIEVAL = 'retrieval';

    /** Run mode (`EvalRun.mode`): no retrieval measurement. */
    public const string MODE_AGENT = 'agent';

    /** Every run mode. */
    public const array MODES = [self::MODE_RETRIEVAL, self::MODE_AGENT];

    /** Longest stored error message of a failed result. */
    public const int MAX_ERROR_LENGTH = 2000;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $registry,
        private readonly AgentVersionPinner $pinner,
        private readonly ConversationTurnService $turnService,
        private readonly SystemActor $systemActor,
        private readonly ClockInterface $clock,
        private readonly VersionTurnMapper $turnMapper,
        private readonly ContextRetriever $contextRetriever,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Runs the outstanding questions of $run.
     *
     * @param callable(EvalRun, BatchReport): void|null $checkpoint called after the start and after every question,
     *                                                  with everything flushed
     *
     * @return EvalRun the run in its final state (a fresh instance when the entity manager had to be reset)
     */
    public function run(EvalRun $run, BatchReport $report, ?callable $checkpoint = null): EvalRun
    {
        $runId = $run->getId();

        if (EvalRunStatus::PENDING === $run->getStatus()) {
            $run->setStatus(EvalRunStatus::RUNNING);
        }
        if (null === $run->getStartedAt()) {
            $run->setStartedAt($this->clock->now());
        }
        $answered = $this->answeredQuestionIds($run);
        $active = $this->activeQuestionIds();
        $run->setQuestionsTotal(\count($active + $answered));
        $this->entityManager->flush();

        // Every answered question, active or not, counts as skipped here,
        // before the first checkpoint: the loop below writes the progress
        // only after a question it runs, so a redelivery that finds every
        // question answered must already report 100 at this checkpoint.
        foreach (array_keys($answered) as $ignored) {
            $report->skipped();
        }

        if (null !== $checkpoint) {
            $checkpoint($run, $report);
        }

        // Resolved once per run; the collection entities are loaded on
        // first use and again after an entity manager reset.
        $retrievalOnly = $run->isRetrievalOnly();
        $scope = self::MODE_RETRIEVAL === $run->getMode() || $retrievalOnly
            ? $this->turnMapper->retrievalScope($run->getAgentVersion())
            : null;
        $collections = null;

        foreach (array_keys($active) as $questionId) {
            if (isset($answered[$questionId])) {
                // Counted as skipped before the loop.
                continue;
            }

            if ($this->cancelRequested($runId)) {
                $this->entityManager->refresh($run);
                $run->finish(EvalRunStatus::CANCELLED, $this->clock->now());
                $this->entityManager->flush();

                return $run;
            }

            $question = $this->entityManager->find(EvalQuestion::class, Uuid::fromString($questionId));
            if (!$question instanceof EvalQuestion) {
                // Removed meanwhile (questions with results are never deleted).
                continue;
            }

            // A retrieval-only question searches below, after the check of
            // the entity manager (which its search never closes).
            $outcome = $retrievalOnly ? null : $this->runQuestion($run, $question);

            if (!$this->entityManager->isOpen()) {
                $this->logger->error('The entity manager was closed by evaluation question {question}; resetting it.', ['question' => $question->getKey()]);
                $this->registry->resetManager();
                $run = $this->entityManager->find(EvalRun::class, $runId)
                    ?? throw new \RuntimeException(\sprintf('Evaluation run "%s" does not exist (anymore).', $runId));
                $question = $this->entityManager->find(EvalQuestion::class, Uuid::fromString($questionId))
                    ?? throw new \RuntimeException(\sprintf('Evaluation question "%s" does not exist (anymore).', $questionId));
                $collections = null;
            }

            $measurement = null;
            if (null === $outcome) {
                // Retrieval-only: $scope is always set for such a run.
                [$outcome, $measurement] = $this->searchQuestion($question, $scope ?? throw new \LogicException('A retrieval-only run has no retrieval scope.'), $collections);
            } elseif (null !== $scope) {
                // The result keeps the turn's status, whatever the search does.
                [$measurement] = $this->measure($question, $scope, $collections);
            }

            $result = $this->record($run, $question, $outcome, $measurement);
            if (EvalResultStatus::COMPLETED === $result->getStatus()) {
                $report->completed();
            } else {
                $report->failed($question->getKey(), (string) $result->getErrorMessage());
            }

            $this->entityManager->flush();
            if (null !== $checkpoint) {
                $checkpoint($run, $report);
            }
        }

        $run->finish(EvalRunStatus::COMPLETED, $this->clock->now());
        $this->entityManager->flush();

        return $run;
    }

    /**
     * Creates the conversation and runs the turn; never throws for a
     * failure of the question.
     *
     * @return array{conversation: ?string, error: ?string, durationMs: int}
     */
    private function runQuestion(EvalRun $run, EvalQuestion $question): array
    {
        $started = hrtime(true);
        $conversationId = null;
        $error = null;

        try {
            $conversation = $this->systemActor->run(self::ACTOR, fn (): Conversation => $this->createConversation($run, $question));
            $conversationId = (string) $conversation->getId();

            $final = $this->systemActor->run(self::ACTOR, fn (): Message => $this->turnService->turn($conversation, $question->getQuestion()));

            if (MessageStatus::COMPLETE !== $final->getStatus()) {
                $error = $final->getError() ?? \sprintf('The turn ended with status "%s".', $final->getStatus());
            }
        } catch (\Throwable $e) {
            $error = self::messageOf($e);
            $this->logger->warning('Evaluation question {question} failed: {message}', [
                'question' => $question->getKey(),
                'message' => $error,
                'exception' => $e,
            ]);
        }

        return [
            'conversation' => $conversationId,
            'error' => self::truncate($error),
            'durationMs' => self::elapsedMs($started),
        ];
    }

    /**
     * The retrieval-only branch of a question: the search of
     * {@see measure()} alone, timed, without conversation or turn; never
     * throws for a failing search.
     *
     * @param list<Collection>|null $collections see {@see measure()}
     *
     * @return array{0: array{conversation: null, error: ?string, durationMs: int}, 1: RetrievalMeasurement}
     */
    private function searchQuestion(EvalQuestion $question, RetrievalScope $scope, ?array &$collections): array
    {
        $started = hrtime(true);
        [$measurement, $error] = $this->measure($question, $scope, $collections);

        return [
            ['conversation' => null, 'error' => self::truncate($error), 'durationMs' => self::elapsedMs($started)],
            $measurement,
        ];
    }

    /**
     * Repeats the retrieval search of the turn for $question and classifies
     * the uncapped pool; never throws for a failing search, which is
     * {@see RetrievalMeasurement::failed()} with the exception message
     * (logged). The normal mode ignores the message (the result keeps the
     * turn's status), the retrieval-only branch stores it.
     *
     * @param list<Collection>|null $collections the loaded collections of $scope, null = not loaded yet (filled here)
     *
     * @return array{0: RetrievalMeasurement, 1: ?string} the measurement and the message of a failed search (null otherwise)
     */
    private function measure(EvalQuestion $question, RetrievalScope $scope, ?array &$collections): array
    {
        try {
            $collections ??= $this->contextRetriever->loadCollections($scope->collections);
            $pool = $this->contextRetriever->pool($question->getQuestion(), $collections, $scope->depth, $scope->hybrid, $scope->hybridWeight);
        } catch (\Throwable $e) {
            $message = self::messageOf($e);
            $this->logger->warning('Retrieval measurement of evaluation question {question} failed: {message}', [
                'question' => $question->getKey(),
                'message' => $message,
                'exception' => $e,
            ]);

            return [RetrievalMeasurement::failed(), $message];
        }

        return [RetrievalMeasurement::classify(true === $question->isNegative(), $question->getMarkers(), $pool), null];
    }

    /**
     * The message of $e, or its class when the message is empty.
     */
    private static function messageOf(\Throwable $e): string
    {
        return '' !== $e->getMessage() ? $e->getMessage() : $e::class;
    }

    /**
     * $error cut to {@see self::MAX_ERROR_LENGTH} characters.
     */
    private static function truncate(?string $error): ?string
    {
        return null !== $error ? mb_substr($error, 0, self::MAX_ERROR_LENGTH) : null;
    }

    /**
     * Milliseconds since $started (a {@see hrtime()} value in nanoseconds).
     */
    private static function elapsedMs(int|float $started): int
    {
        return intdiv((int) (hrtime(true) - $started), 1_000_000);
    }

    /**
     * A new evaluation conversation of the run's owner on the run's
     * version with the question's `requestData` as variables, stored (and
     * audited) before the turn.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException for invalid or missing variables
     *                                                                         (nothing is stored)
     */
    private function createConversation(EvalRun $run, EvalQuestion $question): Conversation
    {
        $conversation = new Conversation();
        $this->pinner->pin($conversation, $run->getAgentVersion(), QuestionVariables::of($question));
        $conversation
            ->setOwnerSubject($run->getOwnerSubject())
            ->setOrigin(Conversation::ORIGIN_EVAL)
            ->setStatus(ConversationStatus::ACTIVE)
            ->setNextSequence(1);

        $this->entityManager->persist($conversation);
        $this->entityManager->flush();

        return $conversation;
    }

    /**
     * Stores the result of a question (not flushed) with the fingerprint of
     * the question text and expected answer it ran with
     * ({@see EvalResult::fingerprintOf()}, for the run comparison) and its
     * retrieval measurement when there is one, and adds its tokens and tool
     * calls to the run.
     *
     * @param array{conversation: ?string, error: ?string, durationMs: int} $outcome
     */
    private function record(EvalRun $run, EvalQuestion $question, array $outcome, ?RetrievalMeasurement $measurement): EvalResult
    {
        $conversation = null !== $outcome['conversation']
            ? $this->entityManager->find(Conversation::class, Uuid::fromString($outcome['conversation']))
            : null;
        $metrics = $conversation instanceof Conversation
            ? TurnMetrics::fromRecords($this->entityManager->getRepository(Message::class)->findBy(['conversation' => $conversation]))
            : null;

        $result = new EvalResult()
            ->setEvalRun($run)
            ->setEvalQuestion($question)
            ->setConversation($conversation)
            ->setSource(EvalResultSource::BATCH)
            ->setQuestionFingerprint(EvalResult::fingerprintOf($question))
            ->setStatus(null === $outcome['error'] ? EvalResultStatus::COMPLETED : EvalResultStatus::FAILED)
            ->setErrorMessage($outcome['error'])
            ->setDurationMs($outcome['durationMs'])
            ->setTokensInput($metrics?->tokensInput)
            ->setTokensOutput($metrics?->tokensOutput)
            ->setInteractions($metrics?->interactions);
        if (null !== $measurement) {
            $result->applyRetrieval($measurement);
        }
        $this->entityManager->persist($result);

        if (null !== $metrics) {
            $run
                ->setTokensTotal($run->getTokensTotal() + $metrics->tokensTotal())
                ->setInteractionsTotal($run->getInteractionsTotal() + $metrics->interactions);
        }

        return $result;
    }

    /**
     * Ids of the active questions as keys, ordered by `createdAt`, then
     * `key`.
     *
     * @return array<string, true>
     */
    private function activeQuestionIds(): array
    {
        $questions = $this->entityManager->getRepository(EvalQuestion::class)->findBy(
            ['status' => EvalQuestionStatus::ACTIVE],
            ['createdAt' => 'ASC', 'key' => 'ASC'],
        );

        $ids = [];
        foreach ($questions as $question) {
            $ids[(string) $question->getId()] = true;
        }

        return $ids;
    }

    /**
     * Ids of the questions that already have a result in $run.
     *
     * @return array<string, true>
     */
    private function answeredQuestionIds(EvalRun $run): array
    {
        $ids = $this->entityManager->createQuery(
            'SELECT IDENTITY(r.evalQuestion) FROM '.EvalResult::class.' r WHERE r.evalRun = :run',
        )->setParameter('run', $run->getId(), 'uuid')->getSingleColumnResult();

        $answered = [];
        foreach ($ids as $id) {
            $answered[(string) $id] = true;
        }

        return $answered;
    }

    /**
     * The cancel flag as stored, bypassing the identity map.
     */
    private function cancelRequested(Uuid $runId): bool
    {
        $value = $this->entityManager->getConnection()->fetchOne(
            'SELECT cancel_requested_at FROM neuron_eval_run WHERE id = :id',
            ['id' => $runId->toRfc4122()],
        );

        return null !== $value && false !== $value;
    }
}
