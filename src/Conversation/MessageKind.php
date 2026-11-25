<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:21:48 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

/**
 * Kinds of a conversation message record (`neuron_message.kind`, a string
 * column, not a database enum).
 *
 * - text: user input or the plain assistant answer;
 * - reasoning: a reasoning (thinking) block of the assistant;
 * - tool_call: one tool call of the assistant, including its result;
 * - schema: the assistant answer of a turn with a response schema;
 * - limit: the tool-call bound of the turn was reached and the next step
 *   was forced to answer (content: the reason; not replayed);
 * - rule_answer: a pre-answer rule answered the turn with a fixed text
 *   (no model call; replayed like a text answer);
 * - rule_refusal: a pre-answer rule refused the turn (no model call; the
 *   turn is not replayed);
 * - injection_refusal: the prompt-injection guard refused the turn (no
 *   retrieval, no answer call; not replayed);
 * - unanswerable_refusal: the answerability check found the retrieved
 *   context unable to answer (no answer call; the sources are kept; not
 *   replayed).
 *
 * The two gate kinds double as the close reason of their record
 * ({@see Gate\GateClose}); both fit the 20 characters of the columns.
 */
final class MessageKind
{
    public const string TEXT = 'text';
    public const string REASONING = 'reasoning';
    public const string TOOL_CALL = 'tool_call';
    public const string SCHEMA = 'schema';
    public const string LIMIT = 'limit';
    public const string RULE_ANSWER = 'rule_answer';
    public const string RULE_REFUSAL = 'rule_refusal';
    public const string INJECTION_REFUSAL = 'injection_refusal';
    public const string UNANSWERABLE_REFUSAL = 'unanswerable_refusal';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::TEXT, self::REASONING, self::TOOL_CALL, self::SCHEMA, self::LIMIT, self::RULE_ANSWER, self::RULE_REFUSAL, self::INJECTION_REFUSAL, self::UNANSWERABLE_REFUSAL];
    }
}
