<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:35:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Agent\Rule\RuleOutcome;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateClose;
use Dmstr\NeuronAiBundle\Conversation\Gate\GateOutcome;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use NeuronAI\Agent\Observability\InferenceStop;
use NeuronAI\Agent\Observability\MessageSaved;
use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\Agent\Observability\ToolCalling;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\ObserverInterface;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;

/**
 * Observes one agent run and turns it into message records.
 *
 * As an observer (one instance per run) it collects every provider response
 * as a {@see TurnStep} with its inference time, every executed tool call
 * with its duration, result and error flag, and the messages the agent
 * commits to its history (the transcript). Its {@see toolErrorHandler()} is
 * set on the agent, so a failing tool becomes a tool result with the error
 * message and the run continues.
 *
 * A tool result that is a `ToolOutput` (MCP tools, neuron-ai 4.x) is
 * recorded as its text: the text blocks joined by a blank line, as the 3.x
 * answer text of an MCP content list was (`ToolOutput::getText()` joins
 * with a space); image and audio blocks are not kept. Its error flag
 * (`isError()`) flags the call as failed like the error handler does.
 *
 * The static part builds the records of a turn (not persisted, no
 * sequence):
 *
 * - user: kind `text`, step 0;
 * - per step: `reasoning` (if any), `text` (or `schema` for the final answer
 *   of a schema turn; for a tool-call response only if it has text), one
 *   `tool_call` per call; all with `step`, `provider`, `model` and the
 *   id of the request's provider connection (`connectionId`, null without
 *   one);
 * - on failure: an `error` record (kind `text` or `schema`) after the
 *   completed steps; for a cancelled run ({@see TurnResult::isAborted()})
 *   an `aborted` record instead. Both carry the streamed text of the step
 *   in flight (`TurnResult::$partialText`, null for a non-streamed run) as
 *   content and the step number after the completed steps;
 * - when the tool-call bound was reached ({@see ToolCallBoundMiddleware},
 *   {@see TurnResult::$limitReached}): one `limit` record (content: the
 *   reason ({@see TurnTexts::limitReason()}), payload `step`/`part` only, no usage) with the step index of
 *   the forced inference, directly before the first record of that step,
 *   or before the error or aborted record when that step did not complete
 *   or left no record. The answer of a forced step
 *   ({@see TurnResult::$forced}) gets the close reason
 *   {@see ForcedClose::REASON_BOUND}; error and aborted records get none.
 *   The observer side keeps the executed calls and the bound
 *   ({@see getLimitCalls()}, {@see getLimitBound()}) for the `limit` event
 *   of the stream ({@see TurnRunner::stream()}).
 * - for the last allowed user turn ({@see UserTurnLimitMiddleware},
 *   {@see TurnResult::$turnLimit}): one `limit` record (content
 *   {@see ForcedClose::turnLimitReason()} or the version's template
 *   ({@see TurnTexts}), payload `step`/`part` and
 *   `limit: user_turns`, no usage) with the step index of the forced
 *   inference (1), placed like the bound's `limit` record; should both
 *   appear in one turn, they are placed in step order (turn limit first on
 *   the same step). The text answer of such a turn gets the close reason
 *   {@see ForcedClose::REASON_TURN_LIMIT} (over {@see ForcedClose::REASON_BOUND};
 *   a terminal answer keeps {@see TerminalClose::REASON}). The observer side
 *   keeps the limit, reason, mode and step ({@see getTurnLimit()} etc.) for
 *   the stream's `turn_limit` event.
 * - for a turn a terminal tool ended ({@see TurnResult::$terminalCall}):
 *   the step's records as above (`reasoning`, the model's `text` before
 *   the call as the anchor when there is one, the `tool_call` records with
 *   the raw results), then the final record at the same step index: kind
 *   `text` (or `schema`), status `complete`, content the answer text
 *   ({@see TerminalClose::answerText()}), `structured`, and for a schema
 *   turn `error` and `schema_errors` as for a text answer, the close
 *   reason {@see TerminalClose::REASON} (also when the step was forced),
 *   the duration of the terminal call, no usage, and `providerPayload`
 *   `{step, part: 'terminal', tool: {name, callId}}` without `message`
 *   (nothing to replay: the tool call and its results are replayed from
 *   the step's other records).
 * - for a turn a pre-answer rule decided ({@see TurnResult::$ruleOutcome}):
 *   one record only, at step 1: kind {@see MessageKind::RULE_ANSWER} or
 *   {@see MessageKind::RULE_REFUSAL}, status `complete`, content the
 *   rendered rule text, the close reason of the same name
 *   ({@see RuleClose}), sources `[]`, no usage, `providerPayload`
 *   `{step: 1, part: 'rule', rule: {index, name, match, outcome, template,
 *   language}}`; a fixed answer also holds `message` (an assistant message
 *   with the text) so {@see HistoryReconstructor} replays it as a normal
 *   pair, a refusal does not.
 * - for a forced close answered by the fallback
 *   ({@see TurnResult::$fallbackAnswer}): the steps' records as above, but
 *   the final record is the fallback record at the forced step's index
 *   (the last step): kind `text`, status `complete`, content the rendered
 *   fallback, the close reason of a forced text answer
 *   ({@see ForcedClose::REASON_TURN_LIMIT} over {@see ForcedClose::REASON_BOUND}),
 *   `providerPayload` `{step, part: 'fallback', mode}` (the mode of the
 *   forced step that gave the close reason) without `message`. For an empty
 *   forced answer it replaces that step's text record (and carries its
 *   usage and duration); after a forced step that called a tool it follows
 *   that step's records, and no `error` record is written. Without a
 *   `message` anchor (or with a tool-call anchor on the last step) the turn
 *   is not replayed by {@see HistoryReconstructor}.
 * - for a turn an answer gate refused ({@see TurnResult::gateRefusal()}):
 *   one record only, at step 1: kind and close reason
 *   {@see GateClose::reason()} (`injection_refusal`,
 *   `unanswerable_refusal`), status `complete`, content the refusal text,
 *   the sources retrieved before the refusal (`[]` for the injection
 *   guard), `providerPayload` `{step: 1, part: 'gate', gate}` without
 *   `message` (never replayed, {@see HistoryReconstructor}).
 * - when answer gates ran ({@see TurnResult::$gates}, any final record):
 *   `providerPayload.gates` is {@see TurnResult::gatesPayload()} (verdict,
 *   internal reason, votes, failure note, tokens per gate; never in
 *   `message:read`), and the final record's `usage` adds the gate tokens
 *   to its `input_tokens` and `output_tokens` and lists them per gate
 *   under `gates` ({@see TurnResult::gateUsage()}). The record usages of a
 *   turn therefore sum to {@see TurnResult::totalUsage()}; a refusal
 *   record's usage is {@see TurnResult::usageWithGates()}. The final
 *   record's `followUps` is {@see TurnResult::followUps()} (null when the
 *   follow-up gate did not run, `[]` when it failed or found nothing).
 *
 * The streaming turn stores its final record up front as a `streaming`
 * placeholder ({@see placeholderRecord()}); passed to
 * {@see assistantRecords()}, the placeholder becomes the final record (its
 * fields are set in place, it stays the last element), so the message id
 * announced in the stream stays valid.
 *
 * `providerPayload` of every record holds `step` and `part`; the anchor
 * record of a step (its text record, else its first tool call) also holds
 * `message`, the full Neuron serialization of the response (content blocks
 * with reasoning signatures, tool calls with ids, metadata, usage), which is
 * what {@see HistoryReconstructor} replays. Tool-call records hold `tool`
 * (call id, name, description, inputs, result). The anchor carries the
 * step's `usage`; the final record carries the `sources`.
 */
final class TurnRecorder implements ObserverInterface
{
    /** @var list<TurnStep> */
    private array $steps = [];

    /** @var list<NeuronMessage> */
    private array $transcript = [];

    private ?int $inferenceStartedAt = null;

    /** @var array<string, int> tool key => start (hrtime ns) */
    private array $toolStarts = [];

    /** @var array<string, true> tool keys whose call failed */
    private array $toolErrors = [];

    private ?string $limitReason = null;

    private ?string $forcedMode = null;

    private ?int $limitStep = null;

    private ?int $limitCalls = null;

    private ?int $limitBound = null;

    private ?UserTurnLimit $turnLimit = null;

    private ?string $turnLimitReason = null;

    private ?string $turnLimitMode = null;

    private ?int $turnLimitStep = null;

    /**
     * @param TurnTexts $texts the texts of the `limit` reasons ({@see limitReached()}, {@see turnLimitReached()}); the default gives {@see ForcedClose}'s English reasons
     */
    public function __construct(
        private readonly TurnTexts $texts = new TurnTexts(),
    ) {
    }

    public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
    {
        switch ($event) {
            case 'inference-start':
                $this->inferenceStartedAt = hrtime(true);
                break;
            case 'inference-stop':
                if ($data instanceof InferenceStop) {
                    $this->onResponse($data->response->message());
                }
                break;
            case 'tool-calling':
                if ($data instanceof ToolCalling) {
                    $this->toolStarts[self::toolKey($data->tool)] = hrtime(true);
                }
                break;
            case 'tool-called':
                if ($data instanceof ToolCalled) {
                    $this->onToolCalled($data->tool);
                }
                break;
            case 'message-saved':
                if ($data instanceof MessageSaved) {
                    $this->transcript[] = $data->message;
                }
                break;
        }
    }

    /**
     * Handler for Agent::toolErrorHandler(): the error message becomes the
     * tool result and the call is flagged as failed.
     *
     * @return \Closure(\Throwable, ToolCall): string
     */
    public function toolErrorHandler(): \Closure
    {
        return function (\Throwable $error, ToolCall $tool): string {
            $this->toolErrors[self::toolKey($tool)] = true;

            return '' !== $error->getMessage() ? $error->getMessage() : $error::class;
        };
    }

    /**
     * Called by {@see ToolCallBoundMiddleware} when the tool-call bound is
     * reached, before the forced inference: that inference is the next
     * step. The reason is {@see TurnTexts::limitReason()} of the two
     * counts ({@see ForcedClose::limitReason()} without a template).
     *
     * @param int    $calls executed tool calls when the bound was reached (parallel calls counted)
     * @param int    $bound the bound of the turn
     * @param string $mode  {@see ForcedClose::MODE_TERMINAL_TOOL}, {@see ForcedClose::MODE_TOOL_CHOICE} or {@see ForcedClose::MODE_FALLBACK}
     */
    public function limitReached(int $calls, int $bound, string $mode): void
    {
        $this->limitReason = $this->texts->limitReason($calls, $bound);
        $this->forcedMode = $mode;
        $this->limitStep = \count($this->steps) + 1;
        $this->limitCalls = $calls;
        $this->limitBound = $bound;
    }

    public function getLimitReason(): ?string
    {
        return $this->limitReason;
    }

    public function getForcedMode(): ?string
    {
        return $this->forcedMode;
    }

    /**
     * Step index of the forced inference; null when the bound was not
     * reached.
     */
    public function getLimitStep(): ?int
    {
        return $this->limitStep;
    }

    /**
     * Executed tool calls when the bound was reached; null when it was not.
     */
    public function getLimitCalls(): ?int
    {
        return $this->limitCalls;
    }

    /**
     * The bound that was reached; null when it was not.
     */
    public function getLimitBound(): ?int
    {
        return $this->limitBound;
    }

    /**
     * Called by {@see UserTurnLimitMiddleware} once, before the first
     * inference of the last allowed user turn: that inference is the next
     * step. Kept apart from the bound's fields, which drive the `limit`
     * event. The reason is {@see TurnTexts::turnLimitReason()} of the
     * limit's turn and maximum ({@see ForcedClose::turnLimitReason()}
     * without a template).
     *
     * @param string $mode {@see ForcedClose::MODE_TERMINAL_TOOL}, {@see ForcedClose::MODE_TOOL_CHOICE} or {@see ForcedClose::MODE_FALLBACK}
     */
    public function turnLimitReached(UserTurnLimit $limit, string $mode): void
    {
        $this->turnLimit = $limit;
        $this->turnLimitReason = $this->texts->turnLimitReason($limit->turn, $limit->max);
        $this->turnLimitMode = $mode;
        $this->turnLimitStep = \count($this->steps) + 1;
    }

    /**
     * The user-turn limit when this last allowed turn was armed; null
     * otherwise.
     */
    public function getTurnLimit(): ?UserTurnLimit
    {
        return $this->turnLimit;
    }

    public function getTurnLimitReason(): ?string
    {
        return $this->turnLimitReason;
    }

    public function getTurnLimitMode(): ?string
    {
        return $this->turnLimitMode;
    }

    /**
     * Step index of the inference forced by the turn limit; null when it
     * was not armed.
     */
    public function getTurnLimitStep(): ?int
    {
        return $this->turnLimitStep;
    }

    /**
     * @return list<TurnStep>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * @return list<NeuronMessage>
     */
    public function getTranscript(): array
    {
        return $this->transcript;
    }

    /**
     * The user record of a turn (step 0, complete).
     *
     * @param array<string, mixed>|null $responseSchema
     */
    public static function userRecord(Conversation $conversation, string $content, ?array $responseSchema = null): Message
    {
        return new Message()
            ->setConversation($conversation)
            ->setRole(Message::SPEAKER_USER)
            ->setKind(MessageKind::TEXT)
            ->setStatus(MessageStatus::COMPLETE)
            ->setStep(0)
            ->setContent($content)
            ->setResponseSchema($responseSchema)
            ->setProviderPayload(['step' => 0, 'part' => 'user', 'message' => self::normalize(new UserMessage($content))]);
    }

    /**
     * The in-flight final record of a streaming turn: assistant, status
     * `streaming`, kind `text` (or `schema` for a schema turn), step 1 until
     * {@see assistantRecords()} fills it.
     *
     * @param array<string, mixed>|null $responseSchema
     * @param ApiConfiguration|null     $connection     the provider connection of the turn (its id is stored); null = none
     * @param ProviderModel|null        $providerModel  the model catalogue entry of the turn ({@see TurnRequest::$providerModel}); null = none
     */
    public static function placeholderRecord(Conversation $conversation, string $provider, string $model, ?array $responseSchema = null, ?ApiConfiguration $connection = null, ?ProviderModel $providerModel = null): Message
    {
        return new Message()
            ->setConversation($conversation)
            ->setRole(Message::SPEAKER_ASSISTANT)
            ->setKind(null !== $responseSchema ? MessageKind::SCHEMA : MessageKind::TEXT)
            ->setStatus(MessageStatus::STREAMING)
            ->setStep(1)
            ->setProvider($provider)
            ->setModel($model)
            ->setConnectionId($connection?->getId()->toRfc4122())
            ->setProviderModel($providerModel)
            ->setResponseSchema($responseSchema);
    }

    /**
     * The assistant records of a run; the last one is the final record
     * (the answer, or the error or aborted record of a run that ended
     * early).
     *
     * @param Message|null $placeholder the streaming placeholder; when given it is the final record (fields set in place) and the last element
     *
     * @return non-empty-list<Message>
     */
    public static function assistantRecords(Conversation $conversation, TurnResult $result, ?Message $placeholder = null): array
    {
        $request = $result->request;
        $schemaTurn = null !== $request->responseSchema;
        $records = [];
        $final = null;
        $lastIndex = array_key_last($result->steps);
        // Pending `limit` records in step order (stable: the turn limit's
        // before the bound's on the same step); each goes directly before
        // the first record of its step, the rest before the error or
        // aborted record.
        $limits = [];
        if (null !== $result->turnLimit) {
            $limits[] = self::turnLimitRecord($conversation, $result);
        }
        if ($result->limitReached) {
            $limits[] = self::limitRecord($conversation, $result);
        }
        usort($limits, static fn (Message $a, Message $b): int => $a->getStep() <=> $b->getStep());
        $closeReason = match (true) {
            null !== $result->turnLimit && !$result->isFailed() => ForcedClose::REASON_TURN_LIMIT,
            $result->forced => ForcedClose::REASON_BOUND,
            default => null,
        };

        foreach ($result->steps as $position => $step) {
            while ([] !== $limits && $step->index >= $limits[0]->getStep()) {
                $records[] = array_shift($limits);
            }
            $isFinal = !$result->isFailed() && $position === $lastIndex && !$step->isToolCall();
            $response = $step->response;
            $base = ['step' => $step->index];

            $reasoning = self::reasoningOf($response);
            if (null !== $reasoning) {
                $records[] = self::assistantRecord($conversation, $request, $step->index, MessageKind::REASONING)
                    ->setContent($reasoning['content'])
                    ->setProviderPayload($base + ['part' => 'reasoning', 'signature' => $reasoning['signature']]);
            }

            $anchor = null;
            $text = $response->getContent();
            if ($isFinal && $result->fallbackAnswer) {
                // The empty forced answer is replaced, not kept as a second
                // record: the step must not replay as an empty answer.
                $anchor = $final = self::fallbackRecord($conversation, $result, $step->index, $closeReason)
                    ->setDurationMs($step->durationMs);
                $records[] = $anchor;
            } elseif ($isFinal || null !== $text) {
                $anchor = self::assistantRecord($conversation, $request, $step->index, $isFinal && $schemaTurn ? MessageKind::SCHEMA : MessageKind::TEXT)
                    ->setContent($text)
                    ->setDurationMs($step->durationMs)
                    ->setProviderPayload($base + ['part' => 'text', 'message' => self::normalize($response)]);
                if ($isFinal && $schemaTurn) {
                    $anchor
                        ->setResponseSchema($request->responseSchema)
                        ->setStructured($result->structured)
                        ->setError(self::schemaErrorText($result->schemaErrors))
                        ->setProviderPayload($anchor->getProviderPayload() + ['schema_errors' => $result->schemaErrors]);
                }
                $records[] = $anchor;
                if ($isFinal) {
                    $final = $anchor;
                    if (null !== $closeReason) {
                        $anchor->setCloseReason($closeReason);
                    }
                }
            }

            foreach ($step->getToolCalls() as $call) {
                $payload = $base + ['part' => 'tool_call', 'tool' => $call->payload];
                $record = self::assistantRecord($conversation, $request, $step->index, MessageKind::TOOL_CALL)
                    ->setToolName(mb_substr($call->name, 0, Message::MAX_TOOL_NAME_LENGTH))
                    ->setToolCallId($call->callId)
                    ->setToolInput($call->inputs)
                    ->setToolResult($call->result)
                    ->setToolError($call->error)
                    ->setDurationMs($call->durationMs);
                if (null === $anchor) {
                    $anchor = $record;
                    $payload['message'] = self::normalize($response);
                }
                $records[] = $record->setProviderPayload($payload);
            }

            if (null !== $anchor && $response->getUsage() instanceof Usage) {
                $anchor->setUsage($response->getUsage()->jsonSerialize());
            }

            if (!$result->isFailed() && $position === $lastIndex && $step->isToolCall() && null !== $result->terminalCall) {
                $final = self::terminalRecord($conversation, $result, $step->index, $result->terminalCall);
                $records[] = $final;
            }
        }

        if (null === $final && $result->fallbackAnswer) {
            // The forced step called a tool: the fallback follows its
            // records at the same step.
            $step = null !== $lastIndex ? $result->steps[$lastIndex]->index : 1;
            $final = self::fallbackRecord($conversation, $result, $step, $closeReason);
            array_push($records, ...$limits);
            $limits = [];
            $records[] = $final;
        }

        if (null !== $result->ruleOutcome) {
            $final = self::ruleRecord($conversation, $result, $result->ruleOutcome);
            $records[] = $final;
        }

        $refusal = $result->gateRefusal();
        if (null !== $refusal) {
            $final = self::gateRecord($conversation, $result, $refusal);
            $records[] = $final;
        }

        if (null === $final) {
            $step = \count($result->steps) + 1;
            $final = self::assistantRecord($conversation, $request, $step, $schemaTurn ? MessageKind::SCHEMA : MessageKind::TEXT)
                ->setResponseSchema($request->responseSchema)
                ->setContent($result->partialText);
            if ($result->isAborted()) {
                $final
                    ->setStatus(MessageStatus::ABORTED)
                    ->setProviderPayload(['step' => $step, 'part' => 'aborted']);
            } else {
                $failure = $result->failure ?? new \LogicException('The run ended without a final answer.');
                $final
                    ->setStatus(MessageStatus::ERROR)
                    ->setError(self::errorText($failure))
                    ->setProviderPayload(['step' => $step, 'part' => 'error', 'exception' => $failure::class]);
            }
            array_push($records, ...$limits);
            $limits = [];
            $records[] = $final;
        }

        $final->setSources(array_map(static fn (ContextChunk $chunk): array => $chunk->toArray(), $result->context));
        self::applyGates($final, $result);

        if (null !== $placeholder) {
            // The final record is always the last one built; it is replaced
            // by the placeholder carrying the same fields.
            array_pop($records);
            $records[] = self::fillPlaceholder($placeholder, $final);
        }

        return $records;
    }

    /**
     * Neuron serialization as plain JSON data (enums as values, empty
     * objects as empty arrays), i.e. what the JSON column gives back.
     *
     * @return array<string, mixed>
     */
    public static function normalize(\JsonSerializable $value): array
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    private function onResponse(NeuronMessage $response): void
    {
        $duration = null !== $this->inferenceStartedAt ? intdiv(hrtime(true) - $this->inferenceStartedAt, 1_000_000) : null;
        $this->inferenceStartedAt = null;
        $this->steps[] = new TurnStep(\count($this->steps) + 1, $response, $duration);
    }

    private function onToolCalled(ToolCall $tool): void
    {
        $step = $this->steps[array_key_last($this->steps) ?? -1] ?? null;
        if (null === $step) {
            return;
        }

        $key = self::toolKey($tool);
        $payload = self::normalize($tool);
        $duration = isset($this->toolStarts[$key]) ? intdiv(hrtime(true) - $this->toolStarts[$key], 1_000_000) : null;
        unset($this->toolStarts[$key]);

        // A call without a result (it never ran) keeps null; a ToolOutput
        // (MCP tools) is kept as its text, its error flag marks the call.
        $result = $tool->hasResult() ? $tool->getResult() : null;
        $failed = $result instanceof ToolOutput && $result->isError();

        $step->addToolCall(new TurnToolCall(
            name: $tool->getName(),
            callId: $tool->getCallId(),
            inputs: $tool->getInputs(),
            result: $result instanceof ToolOutput ? self::outputText($result) : $result,
            error: $failed || isset($this->toolErrors[$key]),
            durationMs: $duration,
            payload: $payload,
        ));
    }

    /** The text blocks of $output, joined by a blank line. */
    private static function outputText(ToolOutput $output): string
    {
        $texts = [];
        foreach ($output->getBlocks() as $block) {
            if ($block instanceof TextContent) {
                $texts[] = $block->content;
            }
        }

        return implode("\n\n", $texts);
    }

    private static function toolKey(ToolCall $tool): string
    {
        return $tool->getCallId() ?? $tool->getName().'#'.spl_object_id($tool);
    }

    /**
     * @return array{content: string, signature: ?string}|null all reasoning blocks of the response joined
     */
    private static function reasoningOf(NeuronMessage $response): ?array
    {
        $parts = [];
        $signature = null;
        foreach ($response->getContentBlocks() as $block) {
            if ($block instanceof ReasoningContent) {
                $parts[] = $block->content;
                $signature ??= $block->id;
            }
        }

        return [] === $parts ? null : ['content' => implode("\n\n", $parts), 'signature' => $signature];
    }

    /**
     * The final record of a turn the terminal call $call ended at step
     * $step.
     */
    private static function terminalRecord(Conversation $conversation, TurnResult $result, int $step, TurnToolCall $call): Message
    {
        $request = $result->request;
        $schemaTurn = null !== $request->responseSchema;
        $payload = ['step' => $step, 'part' => 'terminal', 'tool' => ['name' => $call->name, 'callId' => $call->callId]];
        $record = self::assistantRecord($conversation, $request, $step, $schemaTurn ? MessageKind::SCHEMA : MessageKind::TEXT)
            ->setContent($result->answer)
            ->setStructured($result->structured)
            ->setDurationMs($call->durationMs)
            ->setCloseReason(TerminalClose::REASON);
        if ($schemaTurn) {
            $record
                ->setResponseSchema($request->responseSchema)
                ->setError(self::schemaErrorText($result->schemaErrors));
            $payload['schema_errors'] = $result->schemaErrors;
        }

        return $record->setProviderPayload($payload);
    }

    /**
     * The only assistant record of a turn the pre-answer rule $outcome
     * decided.
     */
    private static function ruleRecord(Conversation $conversation, TurnResult $result, RuleOutcome $outcome): Message
    {
        $payload = ['step' => 1, 'part' => 'rule', 'rule' => RuleClose::payload($outcome)];
        if (!$outcome->isRefusal()) {
            $payload['message'] = self::normalize(new AssistantMessage($outcome->text));
        }

        return self::assistantRecord($conversation, $result->request, 1, RuleClose::reason($outcome))
            ->setContent($outcome->text)
            ->setCloseReason(RuleClose::reason($outcome))
            ->setProviderPayload($payload);
    }

    /**
     * The only assistant record of a turn the answer gate $refusal refused.
     */
    private static function gateRecord(Conversation $conversation, TurnResult $result, GateOutcome $refusal): Message
    {
        $kind = GateClose::reason($refusal->gate);

        return self::assistantRecord($conversation, $result->request, 1, $kind)
            ->setContent($result->answer)
            ->setCloseReason($kind)
            ->setProviderPayload(['step' => 1, 'part' => 'gate', 'gate' => $refusal->gate]);
    }

    /**
     * The answer gates of $result on its final record: their payload under
     * `providerPayload.gates`, their tokens added to the record's usage and
     * listed per gate under `usage.gates`, the follow-up suggestions as
     * `followUps`. No change without gates.
     */
    private static function applyGates(Message $final, TurnResult $result): void
    {
        if ([] === $result->gates) {
            return;
        }

        $input = 0;
        $output = 0;
        $perGate = $result->gateUsage();
        foreach ($perGate as $tokens) {
            $input += $tokens['input_tokens'];
            $output += $tokens['output_tokens'];
        }
        $usage = $final->getUsage() ?? [];

        $final
            ->setUsage([
                'input_tokens' => (int) ($usage['input_tokens'] ?? 0) + $input,
                'output_tokens' => (int) ($usage['output_tokens'] ?? 0) + $output,
                'cached_input_tokens' => (int) ($usage['cached_input_tokens'] ?? 0),
                'reasoning_tokens' => (int) ($usage['reasoning_tokens'] ?? 0),
            ] + $usage + ['gates' => $perGate])
            ->setProviderPayload(($final->getProviderPayload() ?? []) + ['gates' => $result->gatesPayload()])
            ->setFollowUps($result->followUps());
    }

    /**
     * The final record of a forced close answered by the fallback
     * ({@see TurnResult::$fallbackAnswer}): no `message`, so the turn is not
     * replayed.
     */
    private static function fallbackRecord(Conversation $conversation, TurnResult $result, int $step, ?string $closeReason): Message
    {
        $mode = null !== $result->turnLimit ? $result->turnLimitMode : $result->forcedMode;

        return self::assistantRecord($conversation, $result->request, $step, MessageKind::TEXT)
            ->setContent($result->answer)
            ->setCloseReason($closeReason)
            ->setProviderPayload(['step' => $step, 'part' => 'fallback', 'mode' => $mode]);
    }

    private static function limitRecord(Conversation $conversation, TurnResult $result): Message
    {
        $step = $result->limitStep ?? \count($result->steps) + 1;

        return self::assistantRecord($conversation, $result->request, $step, MessageKind::LIMIT)
            ->setContent($result->limitReason)
            ->setProviderPayload(['step' => $step, 'part' => 'limit']);
    }

    /**
     * The `limit` record of the last allowed user turn; unlike the bound's,
     * its payload names the limit (`limit: user_turns`).
     */
    private static function turnLimitRecord(Conversation $conversation, TurnResult $result): Message
    {
        $step = $result->turnLimitStep ?? 1;

        return self::assistantRecord($conversation, $result->request, $step, MessageKind::LIMIT)
            ->setContent($result->turnLimitReason)
            ->setProviderPayload(['step' => $step, 'part' => 'limit', 'limit' => 'user_turns']);
    }

    private static function assistantRecord(Conversation $conversation, TurnRequest $request, int $step, string $kind): Message
    {
        return new Message()
            ->setConversation($conversation)
            ->setRole(Message::SPEAKER_ASSISTANT)
            ->setKind($kind)
            ->setStatus(MessageStatus::COMPLETE)
            ->setStep($step)
            ->setProvider($request->provider)
            ->setModel($request->model)
            ->setConnectionId($request->connection?->getId()->toRfc4122())
            ->setProviderModel($request->providerModel);
    }

    private static function fillPlaceholder(Message $placeholder, Message $final): Message
    {
        return $placeholder
            ->setKind($final->getKind())
            ->setStatus($final->getStatus())
            ->setStep($final->getStep())
            ->setContent($final->getContent())
            ->setUsage($final->getUsage())
            ->setSources($final->getSources())
            ->setError($final->getError())
            ->setCloseReason($final->getCloseReason())
            ->setResponseSchema($final->getResponseSchema())
            ->setStructured($final->getStructured())
            ->setFollowUps($final->getFollowUps())
            ->setProviderPayload($final->getProviderPayload())
            ->setProvider($final->getProvider())
            ->setModel($final->getModel())
            ->setConnectionId($final->getConnectionId())
            ->setProviderModel($final->getProviderModel())
            ->setDurationMs($final->getDurationMs());
    }

    /**
     * The `error` of a schema answer that does not match the schema.
     *
     * @param array<string, mixed>|null $schemaErrors
     */
    private static function schemaErrorText(?array $schemaErrors): ?string
    {
        return null !== $schemaErrors ? 'The answer does not match the response schema: '.json_encode($schemaErrors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    }

    private static function errorText(\Throwable $failure): string
    {
        return '' !== $failure->getMessage() ? $failure->getMessage() : $failure::class;
    }
}

// - revised 2026-10-14
// - revised 2026-10-14
