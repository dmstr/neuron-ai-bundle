<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:35:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;

/**
 * One provider response of a turn with the tool calls it requested.
 * Steps are numbered from 1; step 0 is the user message.
 */
final class TurnStep
{
    /** @var list<TurnToolCall> */
    private array $toolCalls = [];

    public function __construct(
        public readonly int $index,
        public readonly Message $response,
        public readonly ?int $durationMs,
    ) {
    }

    public function addToolCall(TurnToolCall $call): void
    {
        $this->toolCalls[] = $call;
    }

    /**
     * @return list<TurnToolCall> in execution order
     */
    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }

    public function isToolCall(): bool
    {
        return $this->response instanceof ToolCallMessage;
    }
}
