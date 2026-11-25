<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:12 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use Dmstr\NeuronAiBundle\Agent\Rule\RuleOutcome;
use Dmstr\NeuronAiBundle\Agent\TurnConfiguration;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousAccessPolicy;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateRun;
use Dmstr\NeuronAiBundle\Conversation\Gate\TurnGates;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Stream\StoringSink;
use Dmstr\NeuronAiBundle\Stream\StreamEvent;
use Dmstr\NeuronAiBundle\Stream\StreamEventSinkInterface;
use Dmstr\NeuronAiBundle\Stream\StreamEventStore;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A streamed turn after its preflight ({@see StreamingTurnService::begin()}):
 * holds the conversation's turn lock and the `streaming` placeholder of the
 * final record until {@see run()} ends.
 *
 * run() sends, through a {@see StoringSink} (every event is stored before
 * it is sent, with its id):
 *
 * 1. `sources` {messageId, sources} after the retrieval (`[]` when nothing
 *    was retrieved or the retrieval failed);
 * 2. the run's events ({@see TurnRunner::stream()}), with a cancel check
 *    that reads `cancel_requested_at` over DBAL at most once per poll
 *    interval; the placeholder's id is passed on as the `messageId` of the
 *    `turn_limit`, `limit` and `terminal_tool` events, so `sources`, those
 *    events and the terminal event name the same record;
 * 3. `frame` {messageId, prefix, suffix} when the turn is framed: the
 *    agent version has a `frame` template and the final record is kind
 *    `text`, status `complete`, close reason not `terminal_tool`
 *    ({@see ConversationTurnService::applyFrame()}; normal, forced and
 *    fallback answers). The record carries the same `frame` object, its
 *    `content` stays unframed. Sent once the record is stored, so after
 *    the run's last event (`limit`/`turn_limit`, the fallback `token`) and
 *    before `done`; never on a turn ending with `terminal_tool`, a schema,
 *    rule, error or aborted record; without a `frame` template no `frame`
 *    event at all;
 * 4. `follow_ups` {messageId, followUps} after `frame` (if any) and before
 *    `done`, only when the stored final record is `complete` and carries a
 *    non-empty `followUps` list (the follow-up gate ran on a complete text
 *    answer, {@see GateRun::finish()}, before the records were built);
 * 5. after the records are final: `done` {messageId, status, provider,
 *    model, connection, usage, closeReason} for a complete or cancelled
 *    turn (`provider` the connection's type, `connection` its name), `error`
 *    {messageId, status, message} for a failed one. `closeReason` is
 *    always present: the final record's close reason (`terminal_tool` for
 *    a terminal answer, `user_turn_limit` for the answer of the last
 *    allowed user turn, `forced_bound` for the answer of a step forced by
 *    the bound, `rule_answer` or `rule_refusal` for a turn a pre-answer
 *    rule decided, `injection_refusal` or `unanswerable_refusal` for a turn
 *    an answer gate refused, else null; an aborted record carries none).
 *    `usage` is {@see TurnResult::usageWithGates()}: the totals include
 *    the tokens of the answer gates, listed per gate under `usage.gates`
 *    when a gate ran.
 *
 * A turn a pre-answer rule decided (`$ruleOutcome`, evaluated by
 * {@see StreamingTurnService::begin()}) runs no retrieval and no model
 * call: it sends exactly `sources` {messageId, sources: []}, `rule`
 * {messageId, index, rule, match, outcome, template, language, message}
 * (all keys always present) and `done` (status `complete`, the version's
 * provider and model, `usage` null, `closeReason` `rule_answer` or
 * `rule_refusal`); the placeholder becomes the rule record.
 *
 * The answer gates of an agent version ({@see GateRun}, built with the
 * turn's own cancel poll and the turn deadline, {@see TurnGates::stop()})
 * run here, after the placeholder exists: the
 * prompt-injection guard before the retrieval, the answerability hook
 * between retrieval and run, the follow-up hook after a complete text
 * answer. A gate call is skipped with an aborted turn on a cancel request
 * and an error turn once the deadline passed, as in the run; the
 * follow-up gate, which runs after a complete answer, passes with `[]` and
 * a failure note instead, so the answer stays. A turn a gate
 * refused runs no answer call and sends exactly `sources` (always `[]`: a
 * refusal shows no sources, the retrieved chunks did not answer the question),
 * `gate` {messageId, gate, language, message} (all keys always present,
 * never the reason) and `done` (status `complete`, `closeReason` the
 * refusal kind, `usage` with `gates`); the placeholder becomes the refusal
 * record ({@see TurnRecorder}).
 *
 * The records are built by {@see TurnRecorder::assistantRecords()} (the
 * same layout as the non-streaming turn, including the `limit` record of a
 * bounded run and the close reason on the placeholder) and stored by
 * {@see TurnPersistence::finalise()} (step records below the placeholder,
 * one transaction that also counts the turn and closes an anonymous
 * conversation at its limits; a cancelled or failed turn counts too). When
 * that fails (e.g. a closed entity manager), the placeholder gets its
 * status, error and content with a DBAL update instead
 * ({@see TurnPersistence::markFinal()}, which also clears the close reason
 * on row and entity), so no record stays `streaming`, and the turn is
 * counted there. The lock is released at the end in every case; a failing
 * release is logged (a dead connection has released the session lock
 * already). A turn that is dropped without run() releases the lock when it
 * is destroyed.
 */
final class StreamingTurn
{
    private const string CANCEL_SQL = 'SELECT cancel_requested_at FROM neuron_conversation WHERE id = :id';

    private bool $started = false;

    private readonly GateRun $gates;

    /**
     * Built by {@see StreamingTurnService::begin()} only.
     *
     * @internal
     *
     * @param list<Collection>           $collections
     * @param AnonymousAccessPolicy|null $policy      the policy the gate checked an anonymous conversation against
     * @param RuleOutcome|null           $ruleOutcome the decision of the pre-answer rule that answers or refuses the turn; null runs the turn
     * @param array{prefix: string, suffix: string}|null $frame the rendered `frame` template of the agent version; null = not framed
     * @param bool                       $hybrid       hybrid search of the agent version ({@see ContextRetriever::retrieve()}); false without an agent
     * @param float                      $hybridWeight keyword share of the fusion, 0..1
     * @param ContextLimits|null         $contextLimits cap and expansion budget of the agent version ({@see ContextRetriever::retrieve()}); null = the bundle cap, no expansion
     * @param TurnConfiguration|null     $configuration the agent version's configuration whose answer gates run; null = no gates
     * @param TurnGates|null             $turnGates     the answer gates service; required when $configuration has a gate on
     *
     * @throws \LogicException when $configuration has a gate on but no $turnGates were given
     */
    public function __construct(
        private readonly Conversation $conversation,
        private readonly Message $placeholder,
        private readonly TurnRequest $request,
        private readonly array $collections,
        private readonly int $retrievalDepth,
        private readonly TurnPersistence $persistence,
        private readonly ?AnonymousAccessPolicy $policy,
        private readonly TurnLock $lock,
        private readonly TurnRunner $runner,
        private readonly ContextRetriever $contextRetriever,
        private readonly StreamEventStore $eventStore,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly int $cancelPollIntervalMs = 250,
        private readonly ?RuleOutcome $ruleOutcome = null,
        private readonly ?array $frame = null,
        private readonly bool $hybrid = false,
        private readonly float $hybridWeight = 0.5,
        private readonly ?ContextLimits $contextLimits = null,
        private readonly ?TurnConfiguration $configuration = null,
        ?TurnGates $turnGates = null,
    ) {
        $this->gates = GateRun::of($turnGates, $configuration, $this->cancelPoll());
    }

    /**
     * Safety net for a turn that was begun but never run (e.g. the response
     * could not be created): releases the lock; the next turn's sweep
     * marks the placeholder `aborted`.
     */
    public function __destruct()
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        try {
            $this->lock->release($this->conversation->getId());
        } catch (\Throwable $e) {
            $this->logger->error('Could not release the turn lock: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * The placeholder's id: the final record's id, announced in every
     * `sources`, `turn_limit`, `limit`, `terminal_tool`, `rule`, `gate`, `frame`,
     * `follow_ups`, `done` and `error` event of the turn.
     */
    public function getMessageId(): Uuid
    {
        return $this->placeholder->getId();
    }

    /**
     * Retrieval → `sources`; the run; finalisation; the `frame`, the
     * `follow_ups` and the terminal event after the record is final; the lock is released at
     * the end. Runs once.
     *
     * @return Message the final record (status `complete`, `aborted` or `error`)
     */
    public function run(StreamEventSinkInterface $sink): Message
    {
        if ($this->started) {
            throw new \LogicException('A streaming turn runs only once.');
        }
        $this->started = true;

        try {
            $storing = new StoringSink($sink, $this->eventStore, $this->conversation->getId(), $this->placeholder->getId(), $this->logger);
            $result = $this->execute($storing);
            $final = $this->finalise($result);
            $this->sendFrame($storing, $final);
            $this->sendFollowUps($storing, $final);
            $this->sendTerminal($storing, $final, $result);

            return $final;
        } finally {
            try {
                $this->lock->release($this->conversation->getId());
            } catch (\Throwable $e) {
                $this->logger->error('Could not release the turn lock: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            }
        }
    }

    private function execute(StreamEventSinkInterface $sink): TurnResult
    {
        $context = [];
        $refusal = null;
        try {
            if (null !== $this->ruleOutcome) {
                $this->sendSources($sink, []);
                $this->sendRule($sink, $this->ruleOutcome);

                return TurnResult::ruled($this->request, $this->ruleOutcome);
            }

            try {
                // A gate's cancel or deadline exception ends the turn here
                // (aborted or error), as a failed retrieval does.
                $refusal = $this->gates->beforeRetrieval($this->request);
                if (null === $refusal) {
                    $context = $this->contextRetriever->retrieve($this->contextRetriever->query($this->request->content, $this->request->history), $this->collections, $this->retrievalDepth, $this->hybrid, $this->hybridWeight, $this->contextLimits);
                    $refusal = $this->gates->beforeAnswer($this->request, $context);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Conversation turn failed before the provider call: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
                $this->sendSources($sink, $context);

                return $this->gates->finish(TurnResult::failedBeforeRun($this->request, $e, $context));
            }

            $this->sendSources($sink, $refusal?->context ?? $context);
            if (null !== $refusal) {
                $this->sendGate($sink, $refusal);

                return $refusal;
            }

            return $this->gates->finish($this->runner->stream($this->request->withContext($context), $sink, $this->cancelPoll(), $this->placeholder->getId()->toRfc4122()));
        } catch (\Throwable $e) {
            // The runner returns its failures; this is a sink failure. A
            // refusal stays a refusal.
            $this->logger->error('Streaming turn failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return $refusal ?? $this->gates->finish(TurnResult::failedBeforeRun($this->request, $e, $context));
        }
    }

    /**
     * @param list<ContextChunk> $context
     */
    private function sendSources(StreamEventSinkInterface $sink, array $context): void
    {
        $sink->send(new StreamEvent(StreamEvent::SOURCES, [
            'messageId' => $this->placeholder->getId()->toRfc4122(),
            'sources' => array_map(static fn (ContextChunk $chunk): array => $chunk->toArray(), $context),
        ]));
    }

    /**
     * The `rule` event of a turn a pre-answer rule decided; all keys always
     * present.
     */
    private function sendRule(StreamEventSinkInterface $sink, RuleOutcome $outcome): void
    {
        $sink->send(new StreamEvent(StreamEvent::RULE, [
            'messageId' => $this->placeholder->getId()->toRfc4122(),
            'index' => $outcome->index,
            'rule' => $outcome->name,
            'match' => $outcome->match,
            'outcome' => $outcome->outcome,
            'template' => $outcome->template,
            'language' => $outcome->language,
            'message' => $outcome->text,
        ]));
    }

    /**
     * The `gate` event of a turn an answer gate refused; all keys always
     * present, the gate's reason never.
     */
    private function sendGate(StreamEventSinkInterface $sink, TurnResult $refusal): void
    {
        $sink->send(StreamEvent::gate(
            $this->placeholder->getId()->toRfc4122(),
            $refusal->gateRefusal()?->gate ?? '',
            $this->configuration?->language ?? '',
            (string) $refusal->answer,
        ));
    }

    /**
     * Stores the records, the final one (the placeholder) with the frame
     * when the turn is framed ({@see ConversationTurnService::applyFrame()});
     * on failure the DBAL fallback, which leaves the record unframed
     * and without follow-ups.
     */
    private function finalise(TurnResult $result): Message
    {
        try {
            $records = TurnRecorder::assistantRecords($this->conversation, $result, $this->placeholder);
            ConversationTurnService::applyFrame($records[array_key_last($records)], $this->frame);
            $this->persistence->finalise($this->conversation, $records, $result, $this->policy);

            return $this->placeholder;
        } catch (\Throwable $e) {
            $this->logger->error('Could not store the records of the streaming turn: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
        // The transaction was rolled back; the error record is not framed
        // and has no follow-ups (neither was stored).
        $this->placeholder->setFrame(null)->setFollowUps(null);

        if ($result->isAborted()) {
            [$status, $error, $content] = [MessageStatus::ABORTED, null, $result->partialText];
        } elseif (null !== $result->failure) {
            [$status, $error, $content] = [MessageStatus::ERROR, self::errorText($result->failure), $result->partialText];
        } else {
            [$status, $error, $content] = [MessageStatus::ERROR, 'The answer could not be stored.', $result->answer];
        }

        try {
            $this->persistence->markFinal($this->placeholder, $status, $error, $content, $result);
        } catch (\Throwable $e) {
            // The next turn's sweep marks the record `aborted`.
            $this->logger->error('Could not finalise the placeholder record: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            $this->placeholder->setStatus($status)->setError($error)->setContent($content);
        }

        return $this->placeholder;
    }

    /**
     * The `frame` event of a framed turn: only when the stored final record
     * is `complete` and carries the frame; same object as on the record.
     */
    private function sendFrame(StreamEventSinkInterface $sink, Message $final): void
    {
        $frame = $final->getFrame();
        if (null === $frame || MessageStatus::COMPLETE !== $final->getStatus()) {
            return;
        }

        try {
            $sink->send(new StreamEvent(StreamEvent::FRAME, [
                'messageId' => $final->getId()->toRfc4122(),
                'prefix' => $frame['prefix'],
                'suffix' => $frame['suffix'],
            ]));
        } catch (\Throwable $e) {
            $this->logger->error('Could not send the frame stream event: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * The `follow_ups` event: only when the stored final record is
     * `complete` and carries a non-empty `followUps` list; the same list as
     * on the record.
     */
    private function sendFollowUps(StreamEventSinkInterface $sink, Message $final): void
    {
        $followUps = $final->getFollowUps();
        if (null === $followUps || [] === $followUps || MessageStatus::COMPLETE !== $final->getStatus()) {
            return;
        }

        try {
            $sink->send(StreamEvent::followUps($final->getId()->toRfc4122(), $followUps));
        } catch (\Throwable $e) {
            $this->logger->error('Could not send the follow_ups stream event: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * `error` for a failed final record, else `done`; the record is final
     * here, so `done.closeReason` is the stored close reason (null after
     * the DBAL fallback, which clears it).
     */
    private function sendTerminal(StreamEventSinkInterface $sink, Message $final, TurnResult $result): void
    {
        $messageId = $final->getId()->toRfc4122();
        $event = MessageStatus::ERROR === $final->getStatus()
            ? new StreamEvent(StreamEvent::ERROR, [
                'messageId' => $messageId,
                'status' => MessageStatus::ERROR,
                'message' => $final->getError() ?? 'The turn failed.',
            ])
            : new StreamEvent(StreamEvent::DONE, [
                'messageId' => $messageId,
                'status' => $final->getStatus(),
                'provider' => $final->getProvider() ?? $this->request->provider,
                'model' => $final->getModel() ?? $this->request->model,
                'connection' => $this->request->connection?->getName(),
                'usage' => $result->usageWithGates(),
                'closeReason' => $final->getCloseReason(),
            ]);

        try {
            $sink->send($event);
        } catch (\Throwable $e) {
            $this->logger->error('Could not send the terminal stream event: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * The cancel check of the run: reads `cancel_requested_at` at most
     * once per poll interval (the first call reads); once set, it stays
     * cancelled. A failing read counts as "not cancelled".
     *
     * @return \Closure(): bool
     */
    private function cancelPoll(): \Closure
    {
        $lastPoll = null;
        $cancelled = false;
        $interval = $this->cancelPollIntervalMs * 1_000_000;
        $id = $this->conversation->getId()->toRfc4122();

        return function () use (&$lastPoll, &$cancelled, $interval, $id): bool {
            if ($cancelled) {
                return true;
            }
            $now = hrtime(true);
            if (null !== $lastPoll && $now - $lastPoll < $interval) {
                return false;
            }
            $lastPoll = $now;

            try {
                $value = $this->connection->fetchOne(self::CANCEL_SQL, ['id' => $id]);
                $cancelled = null !== $value && false !== $value;
            } catch (\Throwable $e) {
                $this->logger->warning('Could not read the cancel flag: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            }

            return $cancelled;
        };
    }

    private static function errorText(\Throwable $failure): string
    {
        return '' !== $failure->getMessage() ? $failure->getMessage() : $failure::class;
    }
}
// - revised 2026-11-09
