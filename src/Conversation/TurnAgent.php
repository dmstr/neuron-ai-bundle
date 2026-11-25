<?php

// file generated with AI assistance: Claude Code - 2026-10-14 12:30:38 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\History\HistoryTrimmerInterface;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * The agent of one turn ({@see TurnRunner}): upstream's agent whose
 * history uses {@see historyTrimmer()}. {@see TerminalToolAgent} extends it.
 *
 * neuron-ai 4.x builds the history of every execution segment itself, with
 * its default trimmer; the documented seam for an own trimmer is the
 * `resources()` hook (neuron 4.x guide 31, case 9). Store, thread, context
 * window and trim ratio stay the agent's (`setMessageStore()`,
 * `setContextWindow()`, the default ratio of half the window).
 */
class TurnAgent extends Agent
{
    protected function resources(): AgentResources
    {
        $resources = parent::resources();

        return new AgentResources(
            $resources->provider,
            new ChatHistory(
                $this->resolveMessageStore(),
                $resources->history->getThreadId(),
                $this->contextWindow ?? $this->contextWindow(),
                self::historyTrimmer(),
                $this->historyTrimRatio ?? $this->historyTrimRatio(),
            ),
            $resources->instructions,
            $resources->tools,
            $resources->context,
        );
    }

    /**
     * neuron-ai's history trimmer, except that a user message may directly
     * follow a tool-result message. A turn a terminal tool ended is replayed
     * as its tool call and results ({@see HistoryReconstructor}), so the
     * next user message follows the results; upstream's alternation check
     * (protected `HistoryTrimmer::validateAlternation()`, unchanged in
     * neuron-ai 4.1.11) rejects that sequence with "expected role
     * assistant". Here the messages are split at each such boundary and
     * every part is checked by upstream's rule (positions in its error
     * messages count from the start of the part). A history without that
     * sequence is checked exactly as upstream; trimming itself is
     * unchanged. A new instance per history: trimmers are stateful.
     */
    public static function historyTrimmer(): HistoryTrimmerInterface
    {
        return new class extends HistoryTrimmer {
            protected function validateAlternation(array $messages): void
            {
                $part = [];
                $previous = null;
                foreach ($messages as $message) {
                    if ($previous instanceof ToolResultMessage && $message instanceof UserMessage && !$message instanceof ToolResultMessage) {
                        parent::validateAlternation($part);
                        $part = [];
                    }
                    $part[] = $message;
                    $previous = $message;
                }
                parent::validateAlternation($part);
            }
        };
    }
}
