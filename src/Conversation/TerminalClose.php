<?php

// file generated with AI assistance: Claude Code - 2026-10-02 22:30:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

/**
 * The terminal-tool rule of a turn, in one place: a request may name some
 * of its offered tools as terminal (`TurnRequest::$terminalTools`). When
 * the model calls one, the batch runs whole, the turn ends without a
 * further model call ({@see TerminalToolNode}) and the tool's result is the
 * answer.
 *
 * - Which call answers ({@see firstTerminalCall()}): the first call of the
 *   step in execution order whose name is terminal and whose execution did
 *   not fail. A failed terminal call (the tool error handler turned the
 *   exception into the result) is not terminal: the loop continues.
 * - The answer text ({@see answerText()}): the text items of an MCP content
 *   list, else the raw result.
 * - The final record of such a turn carries {@see REASON} as its close
 *   reason ({@see TurnRecorder}); {@see HistoryReconstructor} keys on it to
 *   replay a turn that ends in a tool call.
 *
 * Used by {@see TerminalToolNode}, {@see ToolCallBoundMiddleware},
 * {@see TurnRunner} and {@see TurnRecorder}.
 */
final class TerminalClose
{
    /** `neuron_message.close_reason` of the final record of a turn a terminal tool ended. */
    public const string REASON = 'terminal_tool';

    /**
     * Whether $name is one of the terminal tool names.
     *
     * @param list<string> $terminalTools
     */
    public static function isTerminal(string $name, array $terminalTools): bool
    {
        return \in_array($name, $terminalTools, true);
    }

    /**
     * The call of $step that answers the turn: the first one in execution
     * order whose name is terminal and whose error flag is false; null when
     * there is none.
     *
     * @param list<string> $terminalTools
     */
    public static function firstTerminalCall(TurnStep $step, array $terminalTools): ?TurnToolCall
    {
        if ([] === $terminalTools) {
            return null;
        }
        foreach ($step->getToolCalls() as $call) {
            if (!$call->error && self::isTerminal($call->name, $terminalTools)) {
                return $call;
            }
        }

        return null;
    }

    /**
     * The answer text of a terminal tool's raw result.
     *
     * MCP tools return their `content` block list, which neuron-ai
     * JSON-encodes (`[{"type":"text","text":"…"}]`). When $rawResult decodes
     * to a non-empty list of such items (arrays with a string `type`), the
     * answer is the `text` of the `text` items joined by a blank line
     * (other item types are ignored; empty when there is no text item).
     * Anything else, including an empty JSON list, is the answer as it is.
     */
    public static function answerText(string $rawResult): string
    {
        try {
            $decoded = json_decode($rawResult, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $rawResult;
        }
        if (!\is_array($decoded) || [] === $decoded || !array_is_list($decoded)) {
            return $rawResult;
        }

        $texts = [];
        foreach ($decoded as $item) {
            if (!\is_array($item) || !\is_string($item['type'] ?? null)) {
                return $rawResult;
            }
            if ('text' === $item['type'] && \is_string($item['text'] ?? null)) {
                $texts[] = $item['text'];
            }
        }

        return implode("\n\n", $texts);
    }
}
