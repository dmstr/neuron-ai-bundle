<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:12 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Stream;

/**
 * One event of a server-sent event stream: a type, a JSON-serializable
 * payload and, once stored, the monotonic id the client resumes from.
 *
 * The types and their payloads are the wire contract of the streaming
 * turn: `sources` first, then at most one `turn_limit`, any number of
 * `reasoning`, `token`, `tool_call` and `tool_result` events, at most one
 * `limit` and at most one `terminal_tool`, at most one `frame`, at most
 * one `follow_ups`, then exactly one terminal `done` or `error`. A turn a
 * pre-answer rule decided sends exactly `sources`, `rule`, `done`; a turn
 * an answer gate refused sends exactly `sources` (empty for the injection
 * guard, the retrieved chunks for the answerability check), `gate`,
 * `done`. A complete text answer ends `frame` (if any), `follow_ups` (if
 * any), `done`. The class itself carries no conversation specifics.
 *
 * The vocabulary ({@see isKnownType()}) has fourteen types:
 *
 * - `sources`, `reasoning`, `token`, `tool_call`, `tool_result`;
 * - `limit`: the tool-call bound of the turn was reached and the next
 *   inference is forced to answer. Sent once per turn, after the deltas
 *   and the tool events of the batch that reached the bound and before the
 *   first delta of the forced step, also when the turn ends without such a
 *   delta (empty forced answer, failed forced close, deadline,
 *   cancellation). Payload, all keys always present: `messageId` (id of
 *   the final record, as in `sources`), `step` (index of the forced step),
 *   `reason` (the text of the `limit` record), `maxToolCalls` (the bound),
 *   `toolCalls` (executed calls when the bound was reached, parallel calls
 *   counted), `mode` (`tool_choice`, `terminal_tool` or `fallback`). Not
 *   terminal;
 * - `terminal_tool`: a terminal tool named by the request was called and
 *   its result became the answer; the turn ends without a further model
 *   call. Sent once per turn, after the `tool_result` events of the batch
 *   (and after `limit` when the step was forced), before `done`. Payload,
 *   all keys always present: `messageId` (id of the final record, as in
 *   `sources`), `step` (index of the tool-call step), `name` and `callId`
 *   (the terminal call that answered), `kind` (`text` or `schema`),
 *   `answer` (the answer text), `structured` (the decoded answer, or null),
 *   `schemaErrors` (the schema violations, or null). Not terminal, not a
 *   delta;
 * - `turn_limit`: the turn is the last allowed user turn of the
 *   conversation and is answered under a forced close from its first
 *   inference (no tool loop). Sent once per run of the last allowed user
 *   turn, after `sources` and before the first model event (`reasoning`,
 *   `token`, `tool_call`, `tool_result`), also on failure and cancel paths;
 *   before `limit` should both be sent. Payload, all keys always present:
 *   `messageId` (id of the final record, as in `sources`), `step` (index
 *   of the forced step, 1), `reason` (the text of its `limit` record),
 *   `maxUserTurns` (the conversation's limit), `turn` (the 1-based number
 *   of this turn), `mode` (`tool_choice`, `terminal_tool` or `fallback`).
 *   Not terminal, not a delta;
 * - `rule`: a pre-answer rule of the agent version answered or refused the
 *   turn before retrieval and before any model call. Sent once, after
 *   `sources` (empty) and before `done`; no `turn_limit`, `limit` or
 *   `token` event on such a turn. Payload, all keys always present:
 *   `messageId` (id of the rule record, as in `sources`), `index` (0-based
 *   position of the rule), `rule` (the rule name, or null), `match`
 *   (`keywords`, `pattern` or `language`), `outcome` (`answer` or
 *   `refuse`), `template` (template name), `language` (effective
 *   language), `message` (the rendered text). `done` then carries
 *   `closeReason` `rule_answer` or `rule_refusal`. Not terminal, not a
 *   delta;
 * - `frame`: the agent version wraps the final answer in its `frame`
 *   template; clients show `prefix` + the streamed answer + `suffix`. Sent
 *   once, after the run's last event (after `limit`/`turn_limit` and the
 *   `token` of a forced-close fallback answer) and before `done`, only
 *   when the final record is a complete text answer (normal, forced or
 *   fallback); never with `terminal_tool`, never on a rule, schema, failed
 *   or cancelled turn. Payload, all keys always present: `messageId` (id of
 *   the final record, which carries the same `frame` object), `prefix`,
 *   `suffix` (rendered, either may be empty). Not terminal, not a delta;
 * - `gate`: an answer gate of the agent version refused the turn: the prompt-injection guard before retrieval, or the
 *   answerability check after retrieval and before the answer. Sent once,
 *   after `sources` and before `done`; no `turn_limit`, `limit`, `token`
 *   or `frame` event on such a turn. Payload, all keys always present:
 *   `messageId` (id of the refusal record, as in `sources`), `gate`
 *   (`injection` or `answerability`), `language` (effective language),
 *   `message` (the refusal text). The gate's reason is never sent. `done`
 *   then carries `closeReason` `injection_refusal` or
 *   `unanswerable_refusal`. Not terminal, not a delta;
 * - `follow_ups`: suggested follow-up questions of a complete text answer
 *  , when the agent version has `followUps` on. Sent at most
 *   once, after `frame` (if any) and before `done`, only when the stored
 *   final record is `complete` and its `followUps` is a non-empty list
 *   (none when the gate failed or found nothing). Payload, all keys always
 *   present: `messageId` (id of the final record, which carries the same
 *   list), `followUps` (list of strings). Not terminal, not a delta;
 * - `done`, `error`: the terminal events ({@see isTerminal()}). `done`
 *   payload, all keys always present: `messageId` (id of the final
 *   record), `status`, `provider` (the connection's type, `ollama` or
 *   `anthropic`), `model`, `connection` (the name of the provider
 *   connection the turn ran on), `usage` (tokens, including the answer
 *   gates), `closeReason` (the stored close reason or null). `error` carries
 *   `messageId`, `status` (`error`) and `message`.
 *
 * {@see RESERVED} lists names promised for a later run but not sent yet;
 * it is empty: every type of the vocabulary is sent.
 */
final readonly class StreamEvent
{
    public const string SOURCES = 'sources';
    public const string TOKEN = 'token';
    public const string REASONING = 'reasoning';
    public const string TOOL_CALL = 'tool_call';
    public const string TOOL_RESULT = 'tool_result';
    public const string LIMIT = 'limit';
    public const string DONE = 'done';
    public const string ERROR = 'error';

    /** A terminal tool's result became the answer; sent before `done`. */
    public const string TERMINAL_TOOL = 'terminal_tool';

    /** The last allowed user turn is answered with a forced close; sent before the first model event. */
    public const string TURN_LIMIT = 'turn_limit';

    /** A pre-answer rule answered or refused before retrieval; sent between `sources` and `done`. */
    public const string RULE = 'rule';

    /** The final answer's frame (prefix, suffix); sent after the run's last event, before `done`. */
    public const string FRAME = 'frame';

    /** An answer gate refused the turn; sent between `sources` and `done`. */
    public const string GATE = 'gate';

    /** Follow-up suggestions of a complete text answer; sent after `frame`, before `done`. */
    public const string FOLLOW_UPS = 'follow_ups';

    /** Types named in the contract but not sent yet; none. */
    public const array RESERVED = [];

    private const array TYPES = [
        self::SOURCES, self::TOKEN, self::REASONING, self::TOOL_CALL, self::TOOL_RESULT, self::LIMIT, self::DONE, self::ERROR,
        self::TERMINAL_TOOL, self::TURN_LIMIT, self::RULE, self::FRAME, self::GATE, self::FOLLOW_UPS,
    ];

    /**
     * @param array<string, mixed> $payload
     * @param int|null             $id      the stored row id; null for events that were not (or could not be) stored
     */
    public function __construct(
        public string $type,
        public array $payload,
        public ?int $id = null,
    ) {
    }

    /**
     * The `gate` event of a turn an answer gate refused, with every key of
     * its payload.
     *
     * @param string $messageId id of the refusal record
     * @param string $gate      `injection` or `answerability`
     * @param string $language  effective language of the turn
     * @param string $message   the refusal text
     */
    public static function gate(string $messageId, string $gate, string $language, string $message): self
    {
        return new self(self::GATE, [
            'messageId' => $messageId,
            'gate' => $gate,
            'language' => $language,
            'message' => $message,
        ]);
    }

    /**
     * The `follow_ups` event of a complete text answer, with every key of
     * its payload.
     *
     * @param string       $messageId id of the final record
     * @param list<string> $followUps the suggestions, as on the record
     */
    public static function followUps(string $messageId, array $followUps): self
    {
        return new self(self::FOLLOW_UPS, [
            'messageId' => $messageId,
            'followUps' => $followUps,
        ]);
    }

    /**
     * The same event with the id of its stored row.
     */
    public function withId(int $id): self
    {
        return new self($this->type, $this->payload, $id);
    }

    /**
     * Whether the event ends a stream (`done` or `error`).
     */
    public function isTerminal(): bool
    {
        return self::DONE === $this->type || self::ERROR === $this->type;
    }

    public static function isKnownType(string $type): bool
    {
        return \in_array($type, self::TYPES, true);
    }
}
