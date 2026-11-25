<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:35:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use Dmstr\NeuronAiBundle\Conversation\Gate\GateClose;
use Dmstr\NeuronAiBundle\Entity\Message;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
use NeuronAI\Chat\Messages\MessageDeserializer;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolCall;

/**
 * Rebuilds the Neuron messages of a conversation from its records, for
 * replay into the next turn.
 *
 * Only complete turns are replayed: a turn starts at a user record and is
 * skipped when any of its records is not `complete` (streaming, aborted,
 * error), when a step has no anchor payload, when a tool call has no
 * record, or when it does not end with a non-tool response. Skipping whole
 * turns keeps the user/assistant alternation the providers require.
 *
 * A turn a terminal tool ended ({@see TerminalClose}) is the exception to
 * the last rule: its last step is a tool call, marked by a record with the
 * close reason {@see TerminalClose::REASON}. It is replayed as the tool
 * call and its results, with no synthetic assistant message after them;
 * the terminal record itself has no `message` payload and is not replayed.
 *
 * Each step is replayed from the `message` payload of its anchor record
 * (the Neuron serialization written by {@see TurnRecorder}), so reasoning
 * signatures, tool call ids and provider metadata survive; usage is kept
 * with all four counters. Tool results are rebuilt from the tool-call
 * records (name, call id, input, result). The framework's persistent chat
 * histories are not used.
 *
 * Only the neuron-ai 4.x serialization is read (metadata under `__meta`,
 * which 4.x always writes): a `message` payload without it counts as
 * missing, so a step anchored on it is not replayed and a user record
 * falls back to its content. Payloads stored by neuron-ai 3.x are converted
 * by the bundle migration `Version20261014120000`.
 *
 * `limit` records (the tool-call bound was reached) carry no replayable
 * payload and are dropped before any of these checks; the forced answer
 * after them is replayed like any final answer.
 *
 * A turn a pre-answer rule refused (a {@see MessageKind::RULE_REFUSAL}
 * record) is skipped by kind: neither the refused input nor the refusal
 * reaches the model. A turn a rule answered ({@see MessageKind::RULE_ANSWER})
 * is replayed as a normal user/assistant pair from the record's `message`
 * payload (the rendered text).
 *
 * A turn an answer gate refused (a record of a kind
 * {@see GateClose::isRefusalKind()}: `injection_refusal`,
 * `unanswerable_refusal`) is skipped the same way.
 */
final class HistoryReconstructor
{
    private readonly MessageDeserializer $deserializer;

    public function __construct()
    {
        // neuron-ai's own deserialization: content blocks, tool calls, the
        // full usage, citations and the metadata under `__meta`.
        $this->deserializer = new MessageDeserializer();
    }

    /**
     * @param iterable<Message> $records the conversation's records ordered by sequence
     *
     * @return list<NeuronMessage>
     */
    public function reconstruct(iterable $records): array
    {
        $turns = [];
        $current = null;
        foreach ($records as $record) {
            if (Message::SPEAKER_USER === $record->getRole()) {
                if (null !== $current) {
                    $turns[] = $current;
                }
                $current = [$record];
            } elseif (null !== $current) {
                $current[] = $record;
            }
        }
        if (null !== $current) {
            $turns[] = $current;
        }

        $messages = [];
        foreach ($turns as $turn) {
            foreach ($this->turnMessages($turn) ?? [] as $message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * @param non-empty-list<Message> $records user record first
     *
     * @return list<NeuronMessage>|null null when the turn is incomplete
     */
    private function turnMessages(array $records): ?array
    {
        $records = array_values(array_filter(
            $records,
            static fn (Message $record): bool => MessageKind::LIMIT !== $record->getKind(),
        ));

        foreach ($records as $record) {
            if (MessageStatus::COMPLETE !== $record->getStatus()) {
                return null;
            }
            if (MessageKind::RULE_REFUSAL === $record->getKind() || GateClose::isRefusalKind($record->getKind())) {
                return null;
            }
        }

        $user = array_shift($records);
        $steps = [];
        foreach ($records as $record) {
            $steps[$record->getStep()][] = $record;
        }
        if ([] === $steps) {
            return null;
        }
        ksort($steps);

        $messages = [$this->userMessage($user)];
        $lastStep = array_key_last($steps);
        foreach ($steps as $index => $stepRecords) {
            $message = $this->anchorMessage($stepRecords);
            if (null === $message) {
                return null;
            }

            if (!$message instanceof ToolCallMessage) {
                if ($index !== $lastStep) {
                    return null;
                }
                $messages[] = $message;
                continue;
            }

            if ($index === $lastStep && !self::endedByTerminalTool($stepRecords)) {
                return null;
            }
            $results = $this->toolResults($message, $stepRecords);
            if (null === $results) {
                return null;
            }
            $messages[] = $message;
            $messages[] = $results;
        }

        return $messages;
    }

    /**
     * Whether a step's records mark it as the end of a turn by a terminal
     * tool.
     *
     * @param list<Message> $stepRecords
     */
    private static function endedByTerminalTool(array $stepRecords): bool
    {
        foreach ($stepRecords as $record) {
            if (TerminalClose::REASON === $record->getCloseReason()) {
                return true;
            }
        }

        return false;
    }

    private function userMessage(Message $record): NeuronMessage
    {
        $payload = $record->getProviderPayload()['message'] ?? null;
        if (self::isMessagePayload($payload)) {
            return $this->deserializer->deserialize($payload);
        }

        return new UserMessage((string) $record->getContent());
    }

    /**
     * @param list<Message> $stepRecords
     */
    private function anchorMessage(array $stepRecords): ?NeuronMessage
    {
        foreach ($stepRecords as $record) {
            $payload = $record->getProviderPayload()['message'] ?? null;
            if (self::isMessagePayload($payload)) {
                return $this->deserializer->deserialize($payload);
            }
        }

        return null;
    }

    /**
     * Whether $payload is a message in the neuron-ai 4.x serialization
     * (`Message::jsonSerialize()` always writes `__meta`).
     *
     * @phpstan-assert-if-true array<string, mixed> $payload
     */
    private static function isMessagePayload(mixed $payload): bool
    {
        return \is_array($payload) && \array_key_exists('__meta', $payload);
    }

    /**
     * @param list<Message> $stepRecords
     */
    private function toolResults(ToolCallMessage $message, array $stepRecords): ?ToolResultMessage
    {
        // Records are in execution order, which is the order of the calls in
        // the message; call ids are compared where the provider has them
        // (calls Ollama made before neuron-ai 4.x have none).
        $records = array_values(array_filter(
            $stepRecords,
            static fn (Message $record): bool => MessageKind::TOOL_CALL === $record->getKind(),
        ));
        $calls = $message->getToolCalls();
        if (\count($records) !== \count($calls)) {
            return null;
        }

        $results = [];
        foreach (array_values($calls) as $i => $call) {
            $record = $records[$i];
            if (null !== $call->getCallId() && $call->getCallId() !== $record->getToolCallId()) {
                return null;
            }
            $results[] = ToolCall::make(
                name: (string) $record->getToolName(),
                callId: $record->getToolCallId(),
                inputs: $record->getToolInput() ?? [],
                description: $call->getDescription(),
            )->setResult($record->getToolResult() ?? '');
        }

        return new ToolResultMessage($results);
    }
}

// - revised 2026-10-14
