<?php

// file generated with AI assistance: Claude Code - 2026-10-02 06:02:17 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Middleware\AgentMiddleware;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Events\Event;

/**
 * Bounds the tool loop of one turn: neuron-ai stops the loop only per tool
 * (max runs) and the turn only at its deadline, so a model could call tools
 * until the deadline. Registered on the tool node (subclass-aware, so the
 * parallel tool node and {@see TerminalToolNode} too) by {@see TurnRunner};
 * one instance per run. Its counters live in the run's {@see TurnLoopState},
 * because neuron-ai 4.x runs a copy of the instance per execution segment.
 *
 * before() counts the executed tool calls, including parallel ones; the
 * whole batch of the response that crosses the bound still runs. after()
 * sees the inference event the tool node hands back to the chat node,
 * which sends the run's working instructions (`AgentState::$request`) and
 * the segment's tools (`AgentResources::$tools`) with every call: once the
 * bound is reached, the next inference is the forced step,
 * armed by {@see ForcedClose::arm()}: the tool choice `tool:<first terminal
 * name>` when the request names terminal tools and the provider supports
 * it, else `none` when supported, else without tools; with `none` and
 * without tools {@see ForcedClose::INSTRUCTION} is added to the system
 * prompt. The limit is
 * reported to the {@see TurnRecorder} with the executed calls, the bound
 * and the mode (the `limit` record and the stream's `limit` event).
 *
 * A batch with a terminal call that succeeded ends the turn in
 * {@see TerminalToolNode}, which returns a stop event instead of an
 * inference event (`AgentOutputEvent`), so after() neither arms nor
 * reports for it, even when the batch crossed the bound.
 *
 * A forced step that answers with tool calls fails the turn with a
 * {@see ForcedCloseFailedException} before any tool runs, unless the batch
 * contains a terminal call: then it runs whole, and when the terminal call
 * fails (so the batch does not end the turn), after() fails the turn.
 *
 * On the last allowed user turn the {@see UserTurnLimitMiddleware} is
 * registered on the tool node before this one, so its guard fails a
 * non-terminal batch first; that turn is forced from its first inference,
 * so this middleware never arms there.
 *
 * Calls are counted here: AgentState::getToolRuns() has a wrong return type
 * upstream and counts per tool, not per turn.
 */
final class ToolCallBoundMiddleware extends AgentMiddleware
{
    /**
     * @param int                 $bound         executed tool calls after which the next inference is forced (>= 1)
     * @param AIProviderInterface $provider      the provider set on the agent (the tool choice is set on it)
     * @param list<string>        $terminalTools terminal tool names in request order ({@see TerminalClose})
     * @param TurnLoopState       $loop          the run's counters, shared by the copies neuron-ai makes per segment
     */
    public function __construct(
        private readonly int $bound,
        private readonly AIProviderInterface $provider,
        private readonly TurnRecorder $recorder,
        private readonly array $terminalTools = [],
        private readonly TurnLoopState $loop = new TurnLoopState(),
    ) {
        if ($bound < 1) {
            throw new \InvalidArgumentException(sprintf('The tool-call bound must be at least 1, %d given.', $bound));
        }
    }

    protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state, AgentResources $resources): void
    {
        if (!$event instanceof ToolCallEvent) {
            return;
        }
        if ($this->loop->boundForced) {
            if (!$this->containsTerminalCall($event)) {
                throw new ForcedCloseFailedException();
            }
            $this->loop->boundForcedBatchRan = true;
        }

        $this->loop->executedCalls += \count($event->toolCallMessage->getToolCalls());
    }

    protected function afterAgentNode(AgentNodeInterface $node, Event $result, AgentState $state, AgentResources $resources): void
    {
        if (!$result instanceof AIInferenceEvent) {
            return;
        }
        if ($this->loop->boundForcedBatchRan) {
            // The forced step's terminal call failed: the loop would go on
            // with the forced choice still set.
            throw new ForcedCloseFailedException('The forced close did not produce an answer: the terminal tool call failed.');
        }
        if ($this->loop->boundForced || $this->loop->executedCalls < $this->bound) {
            return;
        }
        $this->loop->boundForced = true;

        ForcedClose::arm($state->request, $resources->tools, $this->provider, $this->terminalTools, $this->recorder, $this->loop->executedCalls, $this->bound);
    }

    public function getExecutedCalls(): int
    {
        return $this->loop->executedCalls;
    }

    public function isForced(): bool
    {
        return $this->loop->boundForced;
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
// - revised 2026-11-09
