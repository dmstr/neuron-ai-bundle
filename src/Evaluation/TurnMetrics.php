<?php

// file generated with AI assistance: Claude Code - 2026-10-03 19:10:10 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Entity\Message;

/**
 * Token usage and tool calls of one turn, read from its stored assistant
 * records ({@see \Dmstr\NeuronAiBundle\Conversation\TurnRecorder} puts the
 * usage of each provider response on the first record of its step and
 * stores one `tool_call` record per executed call).
 *
 * - tokensInput, tokensOutput: `input_tokens` and `output_tokens` summed
 *   over the records; null when no record reported usage (cached and
 *   reasoning tokens are not counted, as for `Conversation::tokensUsed`).
 *   The final record's totals include the tokens of the answer gates
 *   (the per-gate share under `usage.gates` is not added
 *   again), so a gate-refused question and the gate calls of an answered
 *   one count in the run's `tokensTotal`;
 * - interactions: the number of `tool_call` records.
 */
final readonly class TurnMetrics
{
    public function __construct(
        public ?int $tokensInput,
        public ?int $tokensOutput,
        public int $interactions,
    ) {
    }

    /**
     * @param iterable<Message> $records the assistant records of the turn; user records are ignored
     */
    public static function fromRecords(iterable $records): self
    {
        $input = null;
        $output = null;
        $interactions = 0;

        foreach ($records as $record) {
            if (Message::SPEAKER_ASSISTANT !== $record->getRole()) {
                continue;
            }
            if (MessageKind::TOOL_CALL === $record->getKind()) {
                ++$interactions;
            }
            $usage = $record->getUsage();
            if (null === $usage) {
                continue;
            }
            $input = ($input ?? 0) + (int) ($usage['input_tokens'] ?? 0);
            $output = ($output ?? 0) + (int) ($usage['output_tokens'] ?? 0);
        }

        return new self($input, $output, $interactions);
    }

    /**
     * Input plus output tokens; 0 without usage.
     */
    public function tokensTotal(): int
    {
        return ($this->tokensInput ?? 0) + ($this->tokensOutput ?? 0);
    }
}
