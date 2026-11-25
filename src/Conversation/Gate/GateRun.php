<?php

// file generated with AI assistance: Claude Code - 2026-10-04 13:05:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation\Gate;

use Dmstr\NeuronAiBundle\Agent\TurnConfiguration;
use Dmstr\NeuronAiBundle\Conversation\ContextChunk;
use Dmstr\NeuronAiBundle\Conversation\TurnRequest;
use Dmstr\NeuronAiBundle\Conversation\TurnResult;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * The answer gates of one agent turn, shared by every turn path
 * (synchronous and streamed conversation turn, agent chat) so the refusal
 * logic exists once and the paths cannot drift apart:
 *
 *     $gates = GateRun::of($turnGates, $configuration, $isCancelled);
 *     $result = $gates->beforeRetrieval($request)          // refusal or null
 *         ?? ...retrieval...
 *         ?? $gates->beforeAnswer($request, $context)      // refusal or null
 *         ?? ...the run...;
 *     $result = $gates->finish($result);
 *
 * Each hook calls its {@see TurnGates} hook and keeps the outcome; a
 * refusing outcome is returned as the turn's result
 * ({@see TurnResult::gateRefused()} with every outcome so far, the refusal
 * text of {@see TurnGates::refusalText()} and the context retrieved before
 * the refusal). {@see finish()} runs the follow-up hook on a complete text
 * answer and attaches the outcomes to a result that does not carry them yet
 * (a run, a failure before or during the run), so the gate tokens are
 * counted on every path ({@see TurnResult::totalUsage()}).
 *
 * The stop closure ({@see TurnGates::stop()}) is built once per turn: the
 * gate calls share the turn deadline and, on the streamed path, the cancel
 * poll. Its exceptions propagate from the hooks; the caller treats them as
 * a failure before the run.
 *
 * A turn without an agent configuration has no gates ({@see none()}).
 */
final class GateRun
{
    /** @var list<GateOutcome> */
    private array $outcomes = [];

    /**
     * @param (\Closure(): bool)|null $stop
     */
    private function __construct(
        private readonly ?TurnGates $gates,
        private readonly ?TurnConfiguration $turn,
        private readonly ?\Closure $stop,
    ) {
    }

    /**
     * No gates: every hook is a no-op and {@see finish()} returns the
     * result unchanged (paths without an agent, the judge call).
     */
    public static function none(): self
    {
        return new self(null, null, null);
    }

    /**
     * The gates of a turn on $turn; {@see none()} without a configuration.
     *
     * @param (\Closure(): bool)|null $isCancelled the cancel poll of a streamed turn; null for a turn that cannot be cancelled
     *
     * @throws \LogicException when $turn has a gate on but no {@see TurnGates} was configured
     */
    public static function of(?TurnGates $gates, ?TurnConfiguration $turn, ?\Closure $isCancelled = null): self
    {
        if (null === $turn) {
            return self::none();
        }
        if (null === $gates) {
            if ($turn->injectionGuard || $turn->answerabilityCheck || $turn->followUps) {
                throw new \LogicException('An agent turn with answer gates needs the TurnGates service.');
            }

            return self::none();
        }

        return new self($gates, $turn, $gates->stop($isCancelled));
    }

    /**
     * The prompt-injection guard on the user message of $request; the
     * refusal result when it refuses, else null (also when it is off or
     * failed open).
     */
    public function beforeRetrieval(TurnRequest $request): ?TurnResult
    {
        if (null === $this->gates || null === $this->turn) {
            return null;
        }

        return $this->keep($request, $this->gates->beforeRetrieval($this->turn, $request->content, $this->stop), []);
    }

    /**
     * The answerability check on the user message, the previous user
     * question ({@see previousUserMessage()} of the request's history) and
     * the retrieved $context ({@see AnswerabilityCheck}, with
     * `answerabilityCheck` on and at least one collection); the refusal
     * result (with $context as its sources) when the majority of the votes
     * refuses, else null (also when it is off or failed open).
     *
     * @param list<ContextChunk> $context
     */
    public function beforeAnswer(TurnRequest $request, array $context): ?TurnResult
    {
        if (null === $this->gates || null === $this->turn) {
            return null;
        }

        $outcome = $this->gates->beforeAnswer($this->turn, $request->content, self::previousUserMessage($request->history), $context, $this->stop);

        return $this->keep($request, $outcome, $context);
    }

    /**
     * The final result of the turn: a refusal result as it is; otherwise
     * the follow-up hook runs on a complete text answer
     * ({@see isCompleteTextAnswer()}) and every outcome is attached. A
     * follow-up hook stopped by the deadline or a cancel request passes
     * with a failure note: the answer is complete and stays.
     */
    public function finish(TurnResult $result): TurnResult
    {
        if (null === $this->gates || null === $this->turn || $result->isGateRefused()) {
            return $result;
        }

        if (self::isCompleteTextAnswer($result)) {
            try {
                $outcome = $this->gates->afterAnswer($this->turn, $result->request->content, (string) $result->answer, $result->context, $this->stop);
            } catch (\Throwable $e) {
                $outcome = GateOutcome::pass(GateOutcome::FOLLOW_UPS, null, null, \sprintf('%s: %s', GateReply::FAILURE_STOPPED, '' !== $e->getMessage() ? $e->getMessage() : $e::class));
            }
            if (null !== $outcome) {
                $this->outcomes[] = $outcome;
            }
        }

        return $result->withGates(...$this->outcomes);
    }

    /**
     * The outcomes kept so far, in run order.
     *
     * @return list<GateOutcome>
     */
    public function outcomes(): array
    {
        return $this->outcomes;
    }

    /**
     * Whether $result is a complete text answer, the only answer that gets
     * follow-up suggestions: no failure, no rule, no gate refusal, no
     * terminal tool, no response schema, no forced-close fallback and a
     * non-empty answer.
     */
    public static function isCompleteTextAnswer(TurnResult $result): bool
    {
        return !$result->isFailed()
            && !$result->isRuled()
            && !$result->isGateRefused()
            && null === $result->terminalCall
            && null === $result->request->responseSchema
            && !$result->fallbackAnswer
            && null !== $result->answer
            && '' !== trim($result->answer);
    }

    /**
     * The previous user question of a turn: the content of the
     * last user message of its history, tool results excluded. The history
     * of a conversation turn leaves out refused turns
     * ({@see \Dmstr\NeuronAiBundle\Conversation\HistoryReconstructor}); in
     * the agent chat it is the user message before the last one. Null in a
     * first turn.
     *
     * @param list<Message> $history
     */
    public static function previousUserMessage(array $history): ?string
    {
        foreach (array_reverse($history) as $message) {
            if ($message instanceof UserMessage && !$message instanceof ToolResultMessage) {
                $content = $message->getContent();

                return \is_string($content) ? $content : null;
            }
        }

        return null;
    }

    /**
     * Keeps $outcome; the refusal result when it refuses.
     *
     * @param list<ContextChunk> $context
     */
    private function keep(TurnRequest $request, ?GateOutcome $outcome, array $context): ?TurnResult
    {
        if (null === $outcome || null === $this->gates || null === $this->turn) {
            return null;
        }
        $this->outcomes[] = $outcome;
        if (!$outcome->isRefusal()) {
            return null;
        }

        // A refusal says nothing, so it shows no sources: the retrieved chunks did not answer the question.
        return TurnResult::gateRefused($request, $this->gates->refusalText($this->turn, $outcome), $this->outcomes, []);
    }
}
