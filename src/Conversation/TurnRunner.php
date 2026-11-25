<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:35:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use Dmstr\NeuronAiBundle\Provider\ResponseFormatAwareProviderInterface;
use Dmstr\NeuronAiBundle\Provider\ToolChoiceAwareProviderInterface;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\Stream\DeltaBatcher;
use Dmstr\NeuronAiBundle\Stream\StreamEvent;
use Dmstr\NeuronAiBundle\Stream\StreamEventSinkInterface;
use NeuronAI\Agent\Nodes\InferenceNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolCall;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Runs one turn on a Neuron agent; shared by the persisted conversation
 * turn ({@see ConversationTurnService}, {@see StreamingTurnService}) and
 * the stateless chat ({@see \Dmstr\NeuronAiBundle\Chat\ChatService}).
 * Persists nothing.
 *
 * The agent gets the provider of the request's connection
 * ({@see ProviderFactory::chatProvider()} with the model and model options of
 * the request; a request without a connection fails the run), the system prompt
 * (caller instructions, retrieved context, JSON-schema instruction), the
 * tools, the earlier messages in an in-memory message store with the
 * configured context window, a {@see TurnDeadlineObserver} and a
 * {@see TurnRecorder} whose tool error handler turns a failing tool into a
 * tool result. A provider failure or a passed deadline does not throw: the
 * result carries the completed steps and the failure.
 *
 * Thread (neuron-ai 4.x): every run builds a new agent ({@see TurnAgent},
 * or {@see TerminalToolAgent}) bound to {@see TurnRequest::$threadId}, the
 * conversation's UUID, or to a fresh UUID for a request without one; a
 * bound agent is never rebound. Its message store holds only this turn's
 * history ({@see TurnRequest::$history}), checked and trimmed when the
 * store is filled: a history that breaks the user/assistant alternation
 * throws a ChatHistoryException from run() and stream(), before the run.
 * Over the context window, neuron-ai 4.x cuts the history down to half the
 * window.
 *
 * {@see run()} waits for the complete answer; {@see stream()} consumes the
 * agent's stream and sends the protocol events of the run (`turn_limit`
 * on the last allowed user turn, `reasoning`, `token`, `tool_call`,
 * `tool_result`, `limit` once the tool-call bound was reached, and
 * `terminal_tool`) to a sink as they arrive, and can be cancelled.
 *
 * Tool-call bound: with `TurnRequest::$maxToolCalls`, else the default
 * (`turn.max_tool_calls_default`), a {@see ToolCallBoundMiddleware} on the
 * tool node forces the inference after the batch that reaches the bound to
 * answer (both run() and stream()); the result reports it
 * ({@see TurnResult::$limitReached}, {@see TurnResult::$forced}). A bound
 * below 1 fails the run with an \InvalidArgumentException (the HTTP layers
 * reject such values before). Without a bound no middleware is registered.
 *
 * The `limit` event of {@see stream()} relies on the public behaviour of
 * neuron-ai's workflow executor (`Segment::runNode()` in 4.x): it yields
 * every chunk of a node as the node produces it and runs the node's
 * after() middleware only once the node generator has returned. So
 * {@see ToolCallBoundMiddleware::after()} reports the limit to the
 * {@see TurnRecorder} after the last `tool_result` of the batch was
 * consumed and before the forced inference starts (`inference-start`, the
 * point where the deadline and cancel observers throw); the consuming loop
 * sees the pending limit at its next chunk or on its exit. Should a
 * neuron-ai update change that order, the event would move.
 *
 * Terminal tools: with `TurnRequest::$terminalTools` the agent is a
 * {@see TerminalToolAgent} (else a {@see TurnAgent}), whose tool
 * node ends the run after a batch with a terminal call that succeeded
 * ({@see TerminalClose}). Such a final tool-call step is a success: the
 * answer is {@see TerminalClose::answerText()} of the call's result,
 * validated against the response schema when there is one, else decoded
 * to `structured` when it is a JSON object or list
 * ({@see TurnResult::$terminalCall}). At the bound, the forced step targets
 * the first terminal tool where the provider supports it
 * ({@see ForcedClose::arm()}).
 *
 * User-turn limit: a request with `TurnRequest::$userTurnLimit` is the last
 * allowed user turn of its conversation. A {@see UserTurnLimitMiddleware}
 * on the inference node forces it from its first inference (no tool loop,
 * with or without a bound; the tool choice `tool:<first terminal>` or
 * `none` where supported, else no tools and
 * {@see ForcedClose::TURN_LIMIT_INSTRUCTION}); its guard on the tool node
 * is registered before the bound's middleware, so it fails a non-terminal
 * batch first. The result reports it ({@see TurnResult::$turnLimit}), the
 * stream announces it with one `turn_limit` event before the first model
 * event. Without the field no middleware is registered.
 *
 * Texts and fallback: the `limit` reasons come from `TurnRequest::$texts`
 * ({@see TurnTexts}, English by default). When a forced close fails
 * ({@see ForcedCloseFailedException}) or answers with an empty text and the
 * texts have a fallback ({@see TurnTexts::fallback()}), the turn succeeds
 * with that fallback as its answer ({@see TurnResult::$fallbackAnswer});
 * without a fallback both stay as before (failed turn, empty answer).
 */
final class TurnRunner
{
    private const string CANCELLED = 'The turn was cancelled.';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ProviderFactory $providerFactory,
        private readonly StructuredAnswerValidator $validator,
        private readonly int $deadlineSeconds = 120,
        private readonly int $historyContextWindow = 200000,
        private readonly ?int $defaultMaxToolCalls = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function run(TurnRequest $request): TurnResult
    {
        $instructions = $this->instructions($request);
        $threadId = self::threadId($request);
        $history = $this->history($request, $threadId);
        $recorder = new TurnRecorder($request->texts);
        $agent = self::agentFor($request, $threadId);
        $failure = null;

        try {
            $this->configure($agent, $request, $instructions, $history, $recorder);
            $agent->chat(new UserMessage($request->content));
        } catch (\Throwable $e) {
            $failure = $e;
            $this->logFailure($request, $e);
        } finally {
            // The observers belong to this agent instance (neuron-ai 4.x has
            // no global event bus), so nothing is left to drop.
            self::resetToolChoice($agent, $recorder);
        }

        return $this->result($request, $instructions, $recorder, $failure);
    }

    /**
     * Runs the turn on the agent's stream and sends its events to $sink:
     *
     * - `turn_limit` {messageId, step, reason, maxUserTurns, turn, mode}
     *   exactly once on the last allowed user turn
     *   ({@see TurnRequest::$userTurnLimit}): before the first model event
     *   (`reasoning`, `token`, `tool_call`, `tool_result`), and on every
     *   exit path when the run ends before one (empty forced answer,
     *   failure, deadline, cancellation); before `limit` when both are
     *   pending. All keys always present; `step` is the forced inference
     *   (1). Not a delta: sent on cancellation too, never counts towards
     *   `partialText`;
     * - `reasoning` and `token` deltas, batched by a {@see DeltaBatcher}
     *   (one open buffer; flushed when 100 ms passed since the last flush or
     *   200 characters are buffered, before any event of another type, at
     *   the end of a step and before returning); reasoning is never merged
     *   into a token;
     * - `tool_call` {name, callId, input} before and `tool_result` {name,
     *   callId, result, error, durationMs} after each tool execution (the
     *   recorder has seen the call by then);
     * - `limit` {messageId, step, reason, maxToolCalls, toolCalls, mode}
     *   exactly once when the tool-call bound was reached: after the
     *   flushed deltas and the tool events of the batch that reached it,
     *   before the first delta of the forced step, and on every exit path
     *   when the run ends before such a delta (empty forced answer, failed
     *   forced close, deadline, cancellation, any other failure). It is not
     *   a delta: it is sent on cancellation too and never counts towards
     *   `partialText`. A forced step that answers with another tool call
     *   ({@see ForcedCloseFailedException}) sends no `tool_call` for it;
     *   text it streamed before the call is sent as tokens after `limit`;
     * - `terminal_tool` {messageId, step, name, callId, kind, answer,
     *   structured, schemaErrors} exactly once when a terminal tool ended
     *   the run: after the `tool_result` events of its batch, the flushed
     *   deltas and the `limit` event, before returning (all keys always
     *   present; `kind` is `schema` for a schema turn, else `text`). Not
     *   sent when the run failed or was cancelled;
     * - one `token` {text} with the whole fallback answer when the forced
     *   close was answered by the fallback ({@see TurnResult::$fallbackAnswer}):
     *   after the `turn_limit`/`limit` event (and after any tokens the
     *   forced step streamed before its tool call), as the last event of
     *   the run, so before the caller's `done`.
     *
     * Events carry no id; the caller wraps the sink to store them. Sources,
     * done and error events are the caller's; $messageId (the id of the
     * final record) only goes into the `turn_limit`, `limit` and
     * `terminal_tool` payloads.
     *
     * $isCancelled is asked before every batch flush and, through a
     * {@see TurnCancelObserver} registered after the recorder, at
     * `inference-start`, `tool-calling` and `tool-called`. A cancellation
     * ends the run with a {@see TurnCancelledException} as failure
     * ({@see TurnResult::isAborted()}); `partialText` is the token text of
     * the step in flight that was sent (null when none). Any other
     * throwable from the stream is the failure, as in {@see run()}; the
     * deltas received before it are sent first.
     *
     * The agent's stream generator lives only inside the consuming loop:
     * leaving it destroys it, which closes the provider's HTTP stream.
     *
     * @param (\Closure(): bool)|null $isCancelled
     * @param string|null             $messageId   id of the final record, sent as `messageId` of the `turn_limit`, `limit` and `terminal_tool` events
     */
    public function stream(TurnRequest $request, StreamEventSinkInterface $sink, ?\Closure $isCancelled = null, ?string $messageId = null): TurnResult
    {
        $instructions = $this->instructions($request);
        $threadId = self::threadId($request);
        $history = $this->history($request, $threadId);
        $recorder = new TurnRecorder($request->texts);
        $agent = self::agentFor($request, $threadId);
        $failure = null;
        $partialText = null;

        try {
            $this->configure($agent, $request, $instructions, $history, $recorder);
            if (null !== $isCancelled) {
                $agent->observe(new TurnCancelObserver($isCancelled));
            }
            ['failure' => $failure, 'partialText' => $partialText] = $this->consume($agent, $request, $recorder, $sink, $isCancelled, $messageId);
        } catch (\Throwable $e) {
            $failure = $e;
        } finally {
            self::resetToolChoice($agent, $recorder);
        }

        if (null !== $failure) {
            $this->logFailure($request, $failure);
        }

        $result = $this->result($request, $instructions, $recorder, $failure, $partialText);
        if ($result->fallbackAnswer && '' !== (string) $result->answer) {
            $sink->send(new StreamEvent(StreamEvent::TOKEN, ['text' => (string) $result->answer]));
        }

        return $result;
    }

    /**
     * The consuming loop of {@see stream()}. Never throws for a failure of
     * the run: it returns it.
     *
     * @param (\Closure(): bool)|null $isCancelled
     * @param string|null             $messageId   `messageId` of the `turn_limit`, `limit` and `terminal_tool` events
     *
     * @return array{failure: ?\Throwable, partialText: ?string}
     */
    private function consume(TurnAgent $agent, TurnRequest $request, TurnRecorder $recorder, StreamEventSinkInterface $sink, ?\Closure $isCancelled, ?string $messageId): array
    {
        $batcher = new DeltaBatcher();
        $failure = null;
        // Number of completed steps when the step in flight started, and
        // the token text of that step that was sent.
        $stepMark = 0;
        $sentText = '';

        $cancelled = static fn (): bool => null !== $isCancelled && true === $isCancelled();
        $flush = static function () use ($batcher, $sink, &$sentText): void {
            $event = $batcher->flush();
            if (null !== $event) {
                if (StreamEvent::TOKEN === $event->type) {
                    $sentText .= $event->payload['text'];
                }
                $sink->send($event);
            }
        };

        // The `turn_limit` and `limit` events are sent at most once per
        // run each, `turn_limit` first when both are pending.
        $turnLimitSent = false;
        $limitSent = false;
        $sendLimit = static function () use ($recorder, $sink, $messageId, &$turnLimitSent, &$limitSent): void {
            if (!$turnLimitSent && null !== $recorder->getTurnLimit()) {
                $turnLimitSent = true;
                $sink->send(self::turnLimitEvent($recorder, $messageId));
            }
            if ($limitSent || null === $recorder->getLimitReason()) {
                return;
            }
            $limitSent = true;
            $sink->send(self::limitEvent($recorder, $messageId));
        };

        // A lazy generator of the run's chunks (neuron-ai 4.x has no
        // handler); its return value, the final AgentState, is not needed.
        $events = $agent->stream(new UserMessage($request->content));

        try {
            foreach ($events as $chunk) {
                // A step ended (inference-stop) since the last chunk: flush
                // its buffer, the next text belongs to a new step.
                $completed = \count($recorder->getSteps());
                if ($completed !== $stepMark) {
                    if (null !== $batcher->openType()) {
                        if ($cancelled()) {
                            $failure = new TurnCancelledException(self::CANCELLED);
                            break;
                        }
                        $flush();
                    }
                    $stepMark = $completed;
                    $sentText = '';
                }

                // The inference node's before() middleware reported the
                // turn limit before the first chunk, or the tool node's
                // after() middleware reported the bound since the last
                // chunk: this chunk belongs to the forced step (see the
                // class doc).
                $sendLimit();

                if ($chunk instanceof TextChunk || $chunk instanceof ReasoningChunk) {
                    $type = $chunk instanceof TextChunk ? StreamEvent::TOKEN : StreamEvent::REASONING;
                    if ('' === $chunk->content) {
                        continue;
                    }
                    $open = $batcher->openType();
                    if (null !== $open && $open !== $type) {
                        if ($cancelled()) {
                            $failure = new TurnCancelledException(self::CANCELLED);
                            break;
                        }
                        $flush();
                    }
                    $batcher->add($type, $chunk->content);
                    if ($batcher->isDue()) {
                        if ($cancelled()) {
                            $failure = new TurnCancelledException(self::CANCELLED);
                            break;
                        }
                        $flush();
                    }
                    continue;
                }

                if ($chunk instanceof ToolCallChunk || $chunk instanceof ToolResultChunk) {
                    if (null !== $batcher->openType()) {
                        if ($cancelled()) {
                            $failure = new TurnCancelledException(self::CANCELLED);
                            break;
                        }
                        $flush();
                    }
                    $sink->send($chunk instanceof ToolCallChunk ? self::toolCallEvent($chunk->tool) : self::toolResultEvent($chunk->tool, $recorder));
                }
                // Other chunk types (tool arguments, audio, image) are not part
                // of the protocol.
            }

            if (null === $failure) {
                $flush();
            }
            // The loop ended before a chunk of the forced step arrived
            // (empty forced answer) or broke on a cancellation.
            $sendLimit();
            if (null === $failure) {
                $terminal = $this->terminalEvent($request, $recorder, $messageId);
                if (null !== $terminal) {
                    $sink->send($terminal);
                }
            }
        } catch (\Throwable $e) {
            $failure = $e;
            // Deltas received before a failure are sent, so the client has
            // exactly the text the record will keep; a cancellation sends
            // no more deltas.
            if (!$e instanceof TurnCancelledException) {
                try {
                    $flush();
                } catch (\Throwable $flushFailure) {
                    $this->logger->warning('Could not send the buffered deltas: {message}', ['message' => $flushFailure->getMessage(), 'exception' => $flushFailure]);
                }
            }
            // The limit records precede the error or aborted record, so the
            // stream announces them on this path too (failed forced close,
            // deadline or cancellation at the forced step's start).
            try {
                $sendLimit();
            } catch (\Throwable $limitFailure) {
                $this->logger->warning('Could not send the limit event: {message}', ['message' => $limitFailure->getMessage(), 'exception' => $limitFailure]);
            }
        } finally {
            // Destroys the suspended generator (closing the provider's HTTP
            // stream) while the run's observers are still registered.
            unset($events);
        }

        $partialText = null;
        if (null !== $failure && \count($recorder->getSteps()) === $stepMark && '' !== $sentText) {
            $partialText = $sentText;
        }

        return ['failure' => $failure, 'partialText' => $partialText];
    }

    /**
     * The `limit` event of a run whose recorder reports the bound.
     */
    private static function limitEvent(TurnRecorder $recorder, ?string $messageId): StreamEvent
    {
        return new StreamEvent(StreamEvent::LIMIT, [
            'messageId' => $messageId,
            'step' => $recorder->getLimitStep(),
            'reason' => $recorder->getLimitReason(),
            'maxToolCalls' => $recorder->getLimitBound(),
            'toolCalls' => $recorder->getLimitCalls(),
            'mode' => $recorder->getForcedMode(),
        ]);
    }

    /**
     * The `turn_limit` event of a run whose recorder reports the user-turn
     * limit.
     */
    private static function turnLimitEvent(TurnRecorder $recorder, ?string $messageId): StreamEvent
    {
        $limit = $recorder->getTurnLimit();

        return new StreamEvent(StreamEvent::TURN_LIMIT, [
            'messageId' => $messageId,
            'step' => $recorder->getTurnLimitStep(),
            'reason' => $recorder->getTurnLimitReason(),
            'maxUserTurns' => $limit?->max,
            'turn' => $limit?->turn,
            'mode' => $recorder->getTurnLimitMode(),
        ]);
    }

    /**
     * The `terminal_tool` event of a run that a terminal tool ended (its last
     * step is a tool call with a terminal call); null otherwise.
     */
    private function terminalEvent(TurnRequest $request, TurnRecorder $recorder, ?string $messageId): ?StreamEvent
    {
        $steps = $recorder->getSteps();
        $final = [] !== $steps ? $steps[array_key_last($steps)] : null;
        if (null === $final || !$final->isToolCall()) {
            return null;
        }
        $call = TerminalClose::firstTerminalCall($final, $request->terminalTools);
        if (null === $call) {
            return null;
        }
        ['answer' => $answer, 'structured' => $structured, 'schemaErrors' => $schemaErrors] = $this->terminalAnswer($request, $call);

        return new StreamEvent(StreamEvent::TERMINAL_TOOL, [
            'messageId' => $messageId,
            'step' => $final->index,
            'name' => $call->name,
            'callId' => $call->callId,
            'kind' => null !== $request->responseSchema ? MessageKind::SCHEMA : MessageKind::TEXT,
            'answer' => $answer,
            'structured' => $structured,
            'schemaErrors' => $schemaErrors,
        ]);
    }

    /**
     * The answer of a terminal call: {@see TerminalClose::answerText()} of
     * its raw result; with a response schema validated like a text answer,
     * without one `structured` is the decoded answer when it is a JSON
     * object or list, else null.
     *
     * @return array{answer: string, structured: mixed, schemaErrors: array<string, mixed>|null}
     */
    private function terminalAnswer(TurnRequest $request, TurnToolCall $call): array
    {
        $answer = TerminalClose::answerText($call->result ?? '');
        if (null !== $request->responseSchema) {
            ['structured' => $structured, 'errors' => $schemaErrors] = $this->validator->validate($answer, $request->responseSchema);

            return ['answer' => $answer, 'structured' => $structured, 'schemaErrors' => $schemaErrors];
        }

        $decoded = json_decode($answer, true);

        return ['answer' => $answer, 'structured' => \is_array($decoded) ? $decoded : null, 'schemaErrors' => null];
    }

    private static function toolCallEvent(ToolCall $tool): StreamEvent
    {
        $inputs = $tool->getInputs();

        return new StreamEvent(StreamEvent::TOOL_CALL, [
            'name' => $tool->getName(),
            'callId' => $tool->getCallId(),
            // An empty input is a JSON object, not a list.
            'input' => [] === $inputs ? new \stdClass() : $inputs,
        ]);
    }

    private static function toolResultEvent(ToolCall $tool, TurnRecorder $recorder): StreamEvent
    {
        $steps = $recorder->getSteps();
        $calls = [] !== $steps ? $steps[array_key_last($steps)]->getToolCalls() : [];
        $call = null;
        foreach (array_reverse($calls) as $candidate) {
            if ($candidate->callId === $tool->getCallId() && $candidate->name === $tool->getName()) {
                $call = $candidate;
                break;
            }
        }

        return new StreamEvent(StreamEvent::TOOL_RESULT, [
            'name' => $tool->getName(),
            'callId' => $tool->getCallId(),
            'result' => $call?->result,
            'error' => $call?->error ?? false,
            'durationMs' => $call?->durationMs,
        ]);
    }

    private function configure(TurnAgent $agent, TurnRequest $request, string $instructions, InMemoryMessageStore $history, TurnRecorder $recorder): void
    {
        $connection = $request->getConnection() ?? throw new \LogicException('The turn request has no provider connection.');
        $provider = $this->providerFactory->chatProvider($connection, $request->model, $request->modelOptions);
        if (null !== $request->responseSchema && $provider instanceof ResponseFormatAwareProviderInterface) {
            // Enforced by the API where the provider can (structured
            // outputs); the prompt instruction and the validation stay. The
            // provider is new per run, so nothing is cleared afterwards.
            $provider->setResponseFormat($request->responseSchema);
        }
        $agent->setAiProvider($provider);
        if ('' !== $instructions) {
            $agent->setInstructions($instructions);
        }
        if ([] !== $request->tools) {
            $agent->addTool($request->tools);
        }
        $agent->setMessageStore($history)->setContextWindow($this->historyContextWindow);
        $agent->observe(new TurnDeadlineObserver($this->deadlineSeconds));
        $agent->observe($recorder);
        $agent->toolErrorHandler($recorder->toolErrorHandler());

        // The middleware's counters and flags (neuron-ai 4.x runs a copy of
        // each middleware per node class and execution segment).
        $loop = new TurnLoopState();
        if (null !== $request->userTurnLimit) {
            // Before the bound's middleware: its tool-node guard must run
            // first (per-class registration order is kept).
            $agent->addMiddleware([InferenceNode::class, ToolNode::class], new UserTurnLimitMiddleware($request->userTurnLimit, $provider, $recorder, $request->terminalTools, $loop));
        }

        $bound = $request->maxToolCalls ?? $this->defaultMaxToolCalls;
        if (null !== $bound) {
            // The bound governs the loop: neuron-ai's own runs per tool
            // (10 by default) must not refuse calls below it.
            $agent->toolMaxRuns(\PHP_INT_MAX);
            // Subclass-aware matching: covers the parallel tool node too.
            $agent->addMiddleware(ToolNode::class, new ToolCallBoundMiddleware($bound, $provider, $recorder, $request->terminalTools, $loop));
        }
    }

    /**
     * The agent of a run, bound to $threadId: a {@see TerminalToolAgent}
     * when the request names terminal tools, else a {@see TurnAgent}.
     */
    private static function agentFor(TurnRequest $request, string $threadId): TurnAgent
    {
        return [] !== $request->terminalTools
            ? TerminalToolAgent::make(workflowId: $threadId)->setTerminalTools($request->terminalTools)
            : TurnAgent::make(workflowId: $threadId);
    }

    /**
     * The thread of the run: the request's (the conversation's UUID), else
     * a fresh UUID (neuron-ai 4.x never makes one up).
     */
    private static function threadId(TurnRequest $request): string
    {
        return $request->threadId ?? Uuid::v4()->toRfc4122();
    }

    /**
     * Clears the tool choice a forced close (of the bound or the user-turn
     * limit) set (`none` or the terminal tool), so a provider instance that
     * outlives the run (a shared test fake) does not force the next one.
     */
    private static function resetToolChoice(TurnAgent $agent, TurnRecorder $recorder): void
    {
        $choiceModes = [ForcedClose::MODE_TOOL_CHOICE, ForcedClose::MODE_TERMINAL_TOOL];
        if (!\in_array($recorder->getForcedMode(), $choiceModes, true) && !\in_array($recorder->getTurnLimitMode(), $choiceModes, true)) {
            return;
        }
        $provider = $agent->getProvider();
        if ($provider instanceof ToolChoiceAwareProviderInterface) {
            $provider->setToolChoice(null);
        }
    }

    /**
     * The message store of the run's agent with the request's history on
     * $threadId, added through a history with the agent's context window
     * and trimmer ({@see TurnAgent::historyTrimmer()}), so the sequence is
     * checked (a ChatHistoryException for a broken alternation) and trimmed
     * before the run, as the agent's own history does with every message.
     */
    private function history(TurnRequest $request, string $threadId): InMemoryMessageStore
    {
        $store = new InMemoryMessageStore();
        $history = new ChatHistory($store, $threadId, $this->historyContextWindow, TurnAgent::historyTrimmer());
        foreach ($request->history as $message) {
            $history->addMessage($message);
        }

        return $store;
    }

    /**
     * The result of a run from the recorder: a run without a final
     * (non-tool) response fails unless its last step holds a terminal call
     * ({@see TerminalClose::firstTerminalCall()}); the answer of a schema
     * turn is validated. A failed forced close or an empty forced text
     * answer becomes the fallback answer when the request's texts have one
     * ({@see fallbackApplies()}).
     */
    private function result(TurnRequest $request, string $instructions, TurnRecorder $recorder, ?\Throwable $failure, ?string $partialText = null): TurnResult
    {
        $steps = $recorder->getSteps();
        $final = [] !== $steps ? $steps[array_key_last($steps)] : null;
        $terminalCall = null;
        if (null === $failure && null !== $final && $final->isToolCall()) {
            $terminalCall = TerminalClose::firstTerminalCall($final, $request->terminalTools);
        }
        if (null === $failure && (null === $final || ($final->isToolCall() && null === $terminalCall))) {
            $failure = new \LogicException('The run ended without a final answer.');
        }

        $answer = null;
        $structured = null;
        $schemaErrors = null;
        if (null === $failure && null !== $terminalCall) {
            ['answer' => $answer, 'structured' => $structured, 'schemaErrors' => $schemaErrors] = $this->terminalAnswer($request, $terminalCall);
        } elseif (null === $failure && null !== $final) {
            $answer = $final->response->getContent() ?? '';
            // A model that thinks first (Claude Haiku 5.5) can spend the whole
            // output budget on thinking; an empty answer would look like success.
            if ('' === $answer && $final->response instanceof AssistantMessage && 'max_tokens' === $final->response->stopReason()) {
                $failure = new \RuntimeException('The model reached maxTokens before it wrote an answer (thinking counts toward the limit); raise maxTokens of the agent version.');
                $answer = null;
            } elseif (null !== $request->responseSchema) {
                ['structured' => $structured, 'errors' => $schemaErrors] = $this->validator->validate($answer, $request->responseSchema);
            }
        }

        $fallbackAnswer = false;
        $fallback = $request->texts->fallback();
        if (null !== $fallback && self::fallbackApplies($recorder, $failure, $terminalCall, $answer)) {
            [$failure, $answer, $structured, $schemaErrors, $partialText, $fallbackAnswer] = [null, $fallback, null, null, null, true];
        }

        $limitReached = null !== $recorder->getLimitReason();

        return new TurnResult(
            request: $request,
            instructions: $instructions,
            steps: $steps,
            transcript: $recorder->getTranscript(),
            context: SourceLocator::withAnswerQuotes($request->context, $answer),
            failure: $failure,
            answer: $answer,
            structured: $structured,
            schemaErrors: $schemaErrors,
            partialText: $partialText,
            limitReached: $limitReached,
            forced: $limitReached && null === $failure,
            limitReason: $recorder->getLimitReason(),
            forcedMode: $recorder->getForcedMode(),
            limitStep: $recorder->getLimitStep(),
            limitCalls: $recorder->getLimitCalls(),
            limitBound: $recorder->getLimitBound(),
            terminalCall: $terminalCall,
            turnLimit: $recorder->getTurnLimit(),
            turnLimitReason: $recorder->getTurnLimitReason(),
            turnLimitMode: $recorder->getTurnLimitMode(),
            turnLimitStep: $recorder->getTurnLimitStep(),
            fallbackAnswer: $fallbackAnswer,
        );
    }

    /**
     * Whether the forced close of the run did not produce an answer: it
     * failed ({@see ForcedCloseFailedException}), or a forced step (of the
     * bound or the user-turn limit) answered with an empty text. Any other
     * failure (deadline, cancellation, provider error) keeps its path.
     */
    private static function fallbackApplies(TurnRecorder $recorder, ?\Throwable $failure, ?TurnToolCall $terminalCall, ?string $answer): bool
    {
        if ($failure instanceof ForcedCloseFailedException) {
            return true;
        }
        $forced = null !== $recorder->getLimitReason() || null !== $recorder->getTurnLimit();

        return null === $failure && $forced && null === $terminalCall && '' === $answer;
    }

    /**
     * Logs a failed or cancelled run with provider type, connection name and
     * model; never the connection's configuration or credentials.
     */
    private function logFailure(TurnRequest $request, \Throwable $e): void
    {
        $context = ['provider' => $request->provider, 'connection' => $request->connection?->getName(), 'model' => $request->model];
        if ($e instanceof TurnCancelledException) {
            $this->logger->info('Turn cancelled', $context);

            return;
        }

        $this->logger->warning('Turn failed: {message}', ['message' => $e->getMessage(), 'exception' => $e] + $context);
    }

    private function instructions(TurnRequest $request): string
    {
        $parts = [
            trim((string) $request->instructions),
            ContextRetriever::instructionsFor($request->context, $request->contextInstruction),
            null !== $request->responseSchema ? $this->validator->instructionsFor($request->responseSchema) : '',
        ];

        return implode("\n\n", array_filter($parts, static fn (string $part): bool => '' !== $part));
    }
}

// - revised 2026-10-14
// - revised 2026-11-09
// - revised 2026-11-09
// - revised 2026-11-18
// - revised 2026-11-18
