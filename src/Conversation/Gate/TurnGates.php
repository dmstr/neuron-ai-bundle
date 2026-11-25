<?php

// file generated with AI assistance: Claude Code - 2026-10-04 12:12:07 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation\Gate;

use Dmstr\NeuronAiBundle\Agent\TurnConfiguration;
use Dmstr\NeuronAiBundle\Conversation\ContextChunk;
use Dmstr\NeuronAiBundle\Conversation\TurnCancelObserver;
use Dmstr\NeuronAiBundle\Conversation\TurnDeadlineObserver;

/**
 * The answer gates of an agent turn, the single entry point
 * every turn path calls (synchronous and streamed conversation turn,
 * agent chat), so a gate cannot end up wired into one path only:
 *
 *  1. pre-answer rules (not here), then {@see beforeRetrieval()}: the
 *     prompt-injection guard ({@see InjectionGuard}) with `injectionGuard`
 *     on;
 *  2. retrieval (not here), then {@see beforeAnswer()}: the answerability
 *     check ({@see AnswerabilityCheck}) with `answerabilityCheck` on and at
 *     least one collection;
 *  3. the answer (not here), then {@see afterAnswer()}: the follow-up
 *     suggestions ({@see FollowUpGenerator}) with `followUps` on.
 *
 * Each hook returns the {@see GateOutcome} of its gate (with the gate's
 * tokens) or null when the gate is off. An outcome with
 * {@see GateOutcome::isRefusal()} ends the turn with
 * {@see refusalText()} and the kind and close reason of
 * {@see GateClose::reason()}; a gate that failed passes with a failure
 * note. The caller keeps every outcome on its result
 * ({@see \Dmstr\NeuronAiBundle\Conversation\TurnResult::withGates()}).
 *
 * `$stop` is asked before each provider call of a gate; true skips the
 * call (the gate passes with a failure note), and an exception it throws
 * (deadline, cancellation) ends the turn as it would in the run.
 * {@see stop()} builds that closure for a turn; the turn paths drive the
 * hooks through a {@see GateRun}, which also builds the refusal result and
 * attaches every outcome to the turn's result.
 */
final class TurnGates
{
    /** The observer event the run's cancel and deadline checks react to. */
    private const string INFERENCE_START = 'inference-start';

    /**
     * @param AnswerabilityCheck|null  $answerabilityCheck null = no check service (a version with the check on is then a
     *                                                    configuration error, {@see beforeAnswer()})
     * @param int                      $deadlineSeconds    the deadline of the gate calls of one turn (the turn deadline,
     *                                                    `turn.deadline_seconds`; the run of the answer has its own)
     * @param (\Closure(): float)|null $clock              seconds as float for the deadline (default microtime(true)); a seam for tests
     * @param FollowUpGenerator|null $followUpGenerator the follow-up gate; required once a version has `followUps` on
     *                                                ({@see afterAnswer()} throws without it)
     */
    public function __construct(
        private readonly InjectionGuard $injectionGuard,
        private readonly ?AnswerabilityCheck $answerabilityCheck = null,
        private readonly int $deadlineSeconds = 120,
        private readonly ?\Closure $clock = null,
        private readonly ?FollowUpGenerator $followUpGenerator = null,
    ) {
    }

    /**
     * The `$stop` closure of one turn's gate calls, started now: before
     * each call it throws {@see \Dmstr\NeuronAiBundle\Conversation\TurnCancelledException}
     * when $isCancelled reports a cancel request (the cancel poll of the
     * streamed turn, {@see TurnCancelObserver}), then
     * {@see \Dmstr\NeuronAiBundle\Conversation\TurnDeadlineExceededException}
     * once the deadline has passed ({@see TurnDeadlineObserver}); it never
     * returns true. The checks are the run's own observers, asked as at an
     * inference start.
     *
     * @param (\Closure(): bool)|null $isCancelled null = the turn cannot be cancelled (synchronous turn, agent chat)
     *
     * @return \Closure(): bool
     */
    public function stop(?\Closure $isCancelled = null): \Closure
    {
        $deadline = new TurnDeadlineObserver($this->deadlineSeconds, $this->clock);
        $cancel = null === $isCancelled ? null : new TurnCancelObserver($isCancelled);

        return function () use ($deadline, $cancel): bool {
            $cancel?->onEvent(self::INFERENCE_START, $this);
            $deadline->onEvent(self::INFERENCE_START, $this);

            return false;
        };
    }

    /**
     * The prompt-injection guard on the current user message; null when
     * the version has it off.
     *
     * @param (\Closure(): bool)|null $stop
     */
    public function beforeRetrieval(TurnConfiguration $turn, string $userMessage, ?\Closure $stop = null): ?GateOutcome
    {
        if (!$turn->injectionGuard) {
            return null;
        }

        return $this->injectionGuard->check($turn, $userMessage, $stop);
    }

    /**
     * The answerability check on the current and the previous user
     * question and the retrieved context (all `answerabilityVotes` votes,
     * majority of {@see GateOutcome::fromVotes()}); null without a call
     * when the version has it off or has no collections. `$stop` is asked
     * before every vote.
     *
     * @param string|null             $previousUserMessage the previous user question (refused turns excluded), null in the first turn
     * @param list<ContextChunk>      $context             the retrieved chunks that would be sent
     * @param (\Closure(): bool)|null $stop
     *
     * @throws \LogicException when the check is on but no {@see AnswerabilityCheck} was configured
     */
    public function beforeAnswer(TurnConfiguration $turn, string $userMessage, ?string $previousUserMessage, array $context, ?\Closure $stop = null): ?GateOutcome
    {
        if (!$turn->answerabilityCheck || [] === $turn->collections) {
            return null;
        }
        if (null === $this->answerabilityCheck) {
            throw new \LogicException('An agent turn with the answerability check needs the AnswerabilityCheck service.');
        }

        return $this->answerabilityCheck->check($turn, $userMessage, $previousUserMessage, $context, $stop);
    }

    /**
     * The follow-up suggestions of a complete text answer
     * ({@see GateOutcome::$followUps}, {@see FollowUpGenerator::suggest()});
     * null when the version has them off. The outcome always passes; a
     * failed call gives `[]` with a failure note.
     *
     * @param string                  $answer  the complete text answer
     * @param list<ContextChunk>      $context the chunks that were sent
     * @param (\Closure(): bool)|null $stop
     *
     * @throws \LogicException when the version has `followUps` on but no generator was configured
     */
    public function afterAnswer(TurnConfiguration $turn, string $userMessage, string $answer, array $context, ?\Closure $stop = null): ?GateOutcome
    {
        if (!$turn->followUps) {
            return null;
        }
        if (null === $this->followUpGenerator) {
            throw new \LogicException('An agent turn with follow-ups needs the FollowUpGenerator service.');
        }

        return $this->followUpGenerator->suggest($turn, $userMessage, $answer, $context, $stop);
    }

    /**
     * The refusal text of a refusing $outcome: the gate's template slot
     * ({@see GateClose::slot()}) rendered in the effective language with a
     * fallback to the default language ({@see \Dmstr\NeuronAiBundle\Conversation\TurnTexts::slot()}),
     * else {@see GateClose::DEFAULT_TEXTS}.
     */
    public function refusalText(TurnConfiguration $turn, GateOutcome $outcome): string
    {
        return $turn->texts->slot(GateClose::slot($outcome->gate)) ?? GateClose::DEFAULT_TEXTS[$outcome->gate];
    }
}
