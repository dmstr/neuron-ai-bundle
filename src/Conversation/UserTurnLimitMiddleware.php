<?php

// file generated with AI assistance: Claude Code - 2026-10-03 06:59:14 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Middleware\AgentMiddleware;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Agent\Nodes\InferenceNode;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Events\Event;

/**
 * Forces the last allowed user turn of a conversation to answer from its
 * first inference: the user cannot reply afterwards, so a tool loop would
 * only spend the turn, and a question back would stay unanswered. Applies
 * whether or not the turn has a tool-call bound.
 *
 * Registered by {@see TurnRunner} only for a request with a
 * {@see TurnRequest::$userTurnLimit}, one instance per run (its flags live
 * in the run's {@see TurnLoopState}: neuron-ai 4.x runs a copy per node
 * class and execution segment, and the inference node's copy arms what
 * the tool node's copy checks), on
 * `InferenceNode::class` (neuron-ai's abstract base of the chat, streaming
 * and structured nodes; matching is subclass-aware, so one registration
 * covers every mode) and on `ToolNode::class` (the parallel tool node and
 * {@see TerminalToolNode} too). The tool-node registration comes before the
 * {@see ToolCallBoundMiddleware} of the same run: neuron-ai keeps the
 * registration order per class, so this guard runs first and its failure
 * is the one the run reports.
 *
 * before() on an inference node arms the inference with
 * {@see ForcedClose::arm()} and {@see ForcedClose::TURN_LIMIT_INSTRUCTION}:
 * the tool choice `tool:<first terminal name>` when the request names
 * terminal tools and the provider supports it, else `none` when supported,
 * else without tools and with the instruction in the system prompt. It
 * arms before every inference of the turn (the provider's choice is set
 * again, the instruction appended once), and reports the limit to the
 * {@see TurnRecorder} on the first one only (the `limit` record with
 * `limit: user_turns` and the stream's `turn_limit` event).
 *
 * As for the forced step of the bound: a tool-call batch without a terminal
 * call fails the turn with a {@see ForcedCloseFailedException} before any
 * tool runs; a batch with a terminal call runs whole and ends the turn in
 * {@see TerminalToolNode}, so after() sees no inference event for it. When
 * the terminal call failed (the tool node hands an inference event back),
 * after() fails the turn. The bound middleware therefore never arms on
 * such a turn: its after() would need an inference event after a batch,
 * and the only batch that can run either ends the turn or fails it here
 * first.
 */
final class UserTurnLimitMiddleware extends AgentMiddleware
{
    /**
     * @param UserTurnLimit       $limit         the limit of the turn being run (it is the last allowed one)
     * @param AIProviderInterface $provider      the provider set on the agent (the tool choice is set on it)
     * @param list<string>        $terminalTools terminal tool names in request order ({@see TerminalClose})
     * @param TurnLoopState       $loop          the run's flags, shared by the copies neuron-ai makes per node class and segment
     */
    public function __construct(
        private readonly UserTurnLimit $limit,
        private readonly AIProviderInterface $provider,
        private readonly TurnRecorder $recorder,
        private readonly array $terminalTools = [],
        private readonly TurnLoopState $loop = new TurnLoopState(),
    ) {
    }

    protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state, AgentResources $resources): void
    {
        if ($node instanceof InferenceNode && $event instanceof AIInferenceEvent) {
            $mode = ForcedClose::arm($state->request, $resources->tools, $this->provider, $this->terminalTools, instruction: ForcedClose::TURN_LIMIT_INSTRUCTION);
            if (!$this->loop->turnLimitArmed) {
                $this->loop->turnLimitArmed = true;
                $this->recorder->turnLimitReached($this->limit, $mode);
            }

            return;
        }

        if (!$event instanceof ToolCallEvent || !$this->loop->turnLimitArmed) {
            return;
        }
        if (!$this->containsTerminalCall($event)) {
            throw new ForcedCloseFailedException();
        }
        $this->loop->turnLimitBatchRan = true;
    }

    protected function afterAgentNode(AgentNodeInterface $node, Event $result, AgentState $state, AgentResources $resources): void
    {
        if ($node instanceof InferenceNode || !$result instanceof AIInferenceEvent || !$this->loop->turnLimitBatchRan) {
            return;
        }

        // The terminal call of the forced turn failed: the loop would go on
        // with the forced choice still set.
        throw new ForcedCloseFailedException('The forced close did not produce an answer: the terminal tool call failed.');
    }

    public function isArmed(): bool
    {
        return $this->loop->turnLimitArmed;
    }

    private function containsTerminalCall(ToolCallEvent $event): bool
    {
        foreach ($event->toolCallMessage->getToolCalls() as $tool) {
            if (TerminalClose::isTerminal($tool->getName(), $this->terminalTools)) {
                return true;
            }
        }

        return false;
    }
}

// - revised 2026-10-14
