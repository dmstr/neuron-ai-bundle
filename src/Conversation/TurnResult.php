<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:35:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use Dmstr\NeuronAiBundle\Agent\Rule\RuleOutcome;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateOutcome;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;

/**
 * Outcome of one {@see TurnRunner::run()} or {@see TurnRunner::stream()}.
 *
 * On success the last step is the final (non-tool) response and `answer`
 * its text, or a tool-call step with a terminal call ({@see TerminalClose}):
 * then `terminalCall` is that call and `answer` its answer text. With a failure (provider error, passed deadline, failed
 * retrieval, cancellation) the completed steps are kept and `failure` holds
 * the cause; a streamed run also keeps the text of the step that was in
 * flight (`partialText`). A cancelled run is aborted, not failed in the
 * record sense ({@see isAborted()}).
 *
 * A turn with a tool-call bound ({@see ToolCallBoundMiddleware}) that
 * reached it has `limitReached`, the reason, the mode of the forced step,
 * its step index (`limitStep`), the executed calls (`limitCalls`) and the
 * bound (`limitBound`); `forced` is true only when the forced step
 * produced the final answer (no failure), whether as text or through a
 * terminal tool.
 *
 * The last allowed user turn ({@see TurnRequest::$userTurnLimit},
 * {@see UserTurnLimitMiddleware}) has `turnLimit` once its first inference
 * was armed, with the reason, the mode and the step index of the forced
 * inference (`turnLimitStep`, 1). It was forced by the turn limit when
 * `turnLimit !== null && failure === null`. `limitReached` and `forced`
 * stay about the tool-call bound only: they drive the `limit` record and
 * event, which the turn limit does not send.
 *
 * A forced close that failed ({@see ForcedCloseFailedException}) or
 * answered with an empty text, on a request whose texts have a fallback
 * ({@see TurnTexts::fallback()}), is answered with that fallback instead:
 * `fallbackAnswer` is true, `answer` the rendered fallback, `failure` null
 * (so `forced` follows the bound as for any forced answer), `structured`
 * and `schemaErrors` null.
 *
 * A turn a pre-answer rule decided ({@see ruled()}) has `ruleOutcome`, no
 * steps, no transcript, no context and the rendered rule text as `answer`;
 * no model was called.
 *
 * The answer gates that ran in the turn are kept in `gates`
 * in run order ({@see withGates()}). A turn a gate refused
 * ({@see gateRefused()}) has no steps, the refusal text as `answer` and
 * the context retrieved before the refusal (none for the injection
 * guard). The gates' tokens count in {@see totalUsage()}, so every
 * consumer of the total (conversation budget, anonymous token budget,
 * `done.usage`, agent-chat `usage`) includes them; {@see gateUsage()} is
 * their share per gate and {@see usageWithGates()} the total with that
 * share under `gates`. {@see followUps()} are the suggestions of the
 * follow-up gate (null when it did not run).
 */
final readonly class TurnResult
{
    /**
     * @param list<TurnStep>             $steps        provider responses in order (index 1..n)
     * @param list<Message>              $transcript   the turn's messages as the agent committed them to its history (user message first)
     * @param list<ContextChunk>         $context      chunks that were sent
     * @param array<string, mixed>|null  $schemaErrors
     * @param string|null                $partialText  streamed text of the step in flight when the run ended early (null when nothing arrived or not streamed)
     * @param bool                       $limitReached whether the tool-call bound was reached
     * @param bool                       $forced       whether the forced step produced the final answer
     * @param string|null                $limitReason  {@see ForcedClose::limitReason()} when the bound was reached
     * @param string|null                $forcedMode   {@see ForcedClose::MODE_TERMINAL_TOOL}, {@see ForcedClose::MODE_TOOL_CHOICE} or {@see ForcedClose::MODE_FALLBACK} when the bound was reached
     * @param int|null                   $limitStep    step index of the forced inference when the bound was reached
     * @param int|null                   $limitCalls   executed tool calls when the bound was reached (parallel calls counted)
     * @param int|null                   $limitBound   the bound when it was reached
     * @param TurnToolCall|null          $terminalCall the terminal call that answered the turn (null for a text answer or a failure)
     * @param UserTurnLimit|null         $turnLimit       the user-turn limit when this last allowed turn was forced from its first inference (null otherwise, also when the run failed before it)
     * @param string|null                $turnLimitReason {@see ForcedClose::turnLimitReason()} when the turn limit was armed
     * @param string|null                $turnLimitMode   {@see ForcedClose::MODE_TERMINAL_TOOL}, {@see ForcedClose::MODE_TOOL_CHOICE} or {@see ForcedClose::MODE_FALLBACK} when the turn limit was armed
     * @param int|null                   $turnLimitStep   step index of the forced inference when the turn limit was armed
     * @param RuleOutcome|null           $ruleOutcome     the decision of the pre-answer rule that answered or refused the turn (null when no rule decided)
     * @param bool                       $fallbackAnswer  whether `answer` is the rendered `forcedCloseFallback` ({@see TurnTexts::fallback()}) of a failed or empty forced close
     * @param list<GateOutcome>          $gates           the answer gates that ran in the turn, in run order
     */
    public function __construct(
        public TurnRequest $request,
        public string $instructions,
        public array $steps,
        public array $transcript,
        public array $context,
        public ?\Throwable $failure = null,
        public ?string $answer = null,
        public mixed $structured = null,
        public ?array $schemaErrors = null,
        public ?string $partialText = null,
        public bool $limitReached = false,
        public bool $forced = false,
        public ?string $limitReason = null,
        public ?string $forcedMode = null,
        public ?int $limitStep = null,
        public ?int $limitCalls = null,
        public ?int $limitBound = null,
        public ?TurnToolCall $terminalCall = null,
        public ?UserTurnLimit $turnLimit = null,
        public ?string $turnLimitReason = null,
        public ?string $turnLimitMode = null,
        public ?int $turnLimitStep = null,
        public ?RuleOutcome $ruleOutcome = null,
        public bool $fallbackAnswer = false,
        public array $gates = [],
    ) {
    }

    /**
     * A turn that failed before the provider was called.
     *
     * @param list<ContextChunk> $context
     */
    public static function failedBeforeRun(TurnRequest $request, \Throwable $failure, array $context = []): self
    {
        return new self($request, '', [], [], $context, $failure);
    }

    /**
     * A turn a pre-answer rule answered or refused before retrieval and
     * before any model call.
     */
    public static function ruled(TurnRequest $request, RuleOutcome $outcome): self
    {
        return new self($request, '', [], [], [], answer: $outcome->text, ruleOutcome: $outcome);
    }

    /**
     * A turn an answer gate refused: no answer call, $answer is the
     * refusal text ({@see Gate\TurnGates::refusalText()}). $gates are all
     * gates that ran, the refusing one last; $context the chunks retrieved
     * before the refusal (empty for the injection guard).
     *
     * @param list<GateOutcome>  $gates
     * @param list<ContextChunk> $context
     *
     * @throws \InvalidArgumentException when the last gate did not refuse
     */
    public static function gateRefused(TurnRequest $request, string $answer, array $gates, array $context = []): self
    {
        $last = [] === $gates ? null : $gates[array_key_last($gates)];
        if (!$last instanceof GateOutcome || !$last->isRefusal()) {
            throw new \InvalidArgumentException('A gate-refused turn needs the refusing gate as its last gate.');
        }

        return new self($request, '', [], [], $context, answer: $answer, gates: array_values($gates));
    }

    /**
     * The same result with $gates appended to its gates.
     */
    public function withGates(GateOutcome ...$gates): self
    {
        if ([] === $gates) {
            return $this;
        }

        return new self(...['gates' => [...$this->gates, ...array_values($gates)]] + get_object_vars($this));
    }

    /**
     * The gate that refused the turn, or null.
     */
    public function gateRefusal(): ?GateOutcome
    {
        foreach ($this->gates as $gate) {
            if ($gate->isRefusal()) {
                return $gate;
            }
        }

        return null;
    }

    public function isGateRefused(): bool
    {
        return null !== $this->gateRefusal();
    }

    /**
     * The tokens per gate that ran (zero when a gate reported none); a
     * gate that ran twice is summed. Empty when no gate ran.
     *
     * @return array<string, array{input_tokens: int, output_tokens: int}>
     */
    public function gateUsage(): array
    {
        $usage = [];
        foreach ($this->gates as $gate) {
            $tokens = $gate->tokens();
            $usage[$gate->gate] = [
                'input_tokens' => ($usage[$gate->gate]['input_tokens'] ?? 0) + $tokens['input_tokens'],
                'output_tokens' => ($usage[$gate->gate]['output_tokens'] ?? 0) + $tokens['output_tokens'],
            ];
        }

        return $usage;
    }

    /**
     * The entries stored under `providerPayload.gates` of the final record:
     * {@see GateOutcome::toPayload()} by gate name. Empty when no gate ran.
     *
     * @return array<string, array<string, mixed>>
     */
    public function gatesPayload(): array
    {
        $payload = [];
        foreach ($this->gates as $gate) {
            $payload[$gate->gate] = $gate->toPayload();
        }

        return $payload;
    }

    /**
     * The suggestions of the follow-up gate ({@see GateOutcome::FOLLOW_UPS}):
     * null when it did not run (off, or no complete text answer), `[]` when
     * it failed or produced nothing, else the list. The value of the final
     * record's `followUps`, the stream's `follow_ups` event and the
     * agent-chat `followUps`.
     *
     * @return list<string>|null
     */
    public function followUps(): ?array
    {
        foreach ($this->gates as $gate) {
            if (GateOutcome::FOLLOW_UPS === $gate->gate) {
                return $gate->followUps;
            }
        }

        return null;
    }

    public function isRuled(): bool
    {
        return null !== $this->ruleOutcome;
    }

    public function isFailed(): bool
    {
        return null !== $this->failure;
    }

    /**
     * Whether the run was cancelled on request ({@see TurnCancelledException}).
     */
    public function isAborted(): bool
    {
        return $this->failure instanceof TurnCancelledException;
    }

    public function finalStep(): ?TurnStep
    {
        return [] === $this->steps ? null : $this->steps[array_key_last($this->steps)];
    }

    /**
     * Token usage summed over all steps and the answer gates; null when
     * neither a step nor a gate reported usage, so a turn without gates
     * keeps its total.
     *
     * @return array{input_tokens: int, output_tokens: int, cached_input_tokens: int, reasoning_tokens: int}|null
     */
    public function totalUsage(): ?array
    {
        $total = null;
        foreach ($this->steps as $step) {
            $usage = $step->response->getUsage();
            if (!$usage instanceof Usage) {
                continue;
            }
            $total ??= ['input_tokens' => 0, 'output_tokens' => 0, 'cached_input_tokens' => 0, 'reasoning_tokens' => 0];
            $total['input_tokens'] += $usage->inputTokens;
            $total['output_tokens'] += $usage->outputTokens;
            $total['cached_input_tokens'] += $usage->cachedInputTokens;
            $total['reasoning_tokens'] += $usage->reasoningTokens;
        }
        foreach ($this->gates as $gate) {
            if (null === $gate->usage) {
                continue;
            }
            $total ??= ['input_tokens' => 0, 'output_tokens' => 0, 'cached_input_tokens' => 0, 'reasoning_tokens' => 0];
            $total['input_tokens'] += $gate->usage['input_tokens'];
            $total['output_tokens'] += $gate->usage['output_tokens'];
        }

        return $total;
    }

    /**
     * {@see totalUsage()} with the gate share under `gates`
     * ({@see gateUsage()}): the `usage` of the final record, of
     * `done` and of the agent-chat response. Exactly {@see totalUsage()}
     * when no gate ran; with gates, the totals are zero rather than null
     * when nothing reported usage.
     *
     * @return array{input_tokens: int, output_tokens: int, cached_input_tokens: int, reasoning_tokens: int, gates?: array<string, array{input_tokens: int, output_tokens: int}>}|null
     */
    public function usageWithGates(): ?array
    {
        $total = $this->totalUsage();
        if ([] === $this->gates) {
            return $total;
        }

        return ($total ?? ['input_tokens' => 0, 'output_tokens' => 0, 'cached_input_tokens' => 0, 'reasoning_tokens' => 0])
            + ['gates' => $this->gateUsage()];
    }
}
