<?php

// file generated with AI assistance: Claude Code - 2026-10-02 22:30:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\AwaitToolResultsEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;

/**
 * The tool node of a turn with terminal tools ({@see TerminalToolAgent}).
 *
 * Together with {@see TerminalToolAgent::nodes()} this is the one adapter
 * to neuron-ai internals (protected 4.x API): upstream's
 * `ToolNode::__invoke()` always hands the tool results back to the
 * inference node.
 *
 * The node runs the batch exactly as upstream does (`parent::__invoke()`:
 * every tool runs and its chunks are yielded; the tool-call message and
 * the results become the next request's messages). When the batch
 * contains a terminal call that did not fail ({@see TerminalClose}), it
 * commits those messages to the segment's chat history itself (upstream
 * leaves that to the next inference, which does not happen; the commit
 * emits `message-saved`, so the transcript contains the call and its
 * results) and returns an `AgentOutputEvent`: the turn ends without a
 * further model call, through the agent's end node. Otherwise it returns
 * upstream's event unchanged.
 *
 * A call failed when the tool error handler handled it, or when its
 * result is a `ToolOutput` marked as an error (an MCP `isError` result, a
 * rejected input); the node wraps the handler to know the first kind. This
 * is the condition that flags `TurnToolCall::$error` in
 * {@see TurnRecorder}. Without an error handler a failing tool throws, as
 * upstream.
 */
class TerminalToolNode extends ToolNode
{
    /** @var array<int, true> spl_object_id of the calls whose execution failed */
    private array $failed = [];

    /**
     * @param list<string> $terminalTools names of the tools that end the turn
     * @param callable|null $errorHandler fn(\Throwable $e, ToolCall $call): string|ToolOutput|null, as upstream
     */
    public function __construct(
        int $maxRuns = 10,
        ?callable $errorHandler = null,
        private readonly array $terminalTools = [],
    ) {
        $wrapped = null;
        if (null !== $errorHandler) {
            $wrapped = function (\Throwable $error, ToolCall $call) use ($errorHandler): mixed {
                $this->failed[spl_object_id($call)] = true;

                return $errorHandler($error, $call);
            };
        }

        parent::__construct($maxRuns, $wrapped);
    }

    /**
     * @return list<string>
     */
    public function getTerminalTools(): array
    {
        return $this->terminalTools;
    }

    /**
     * Same signature as upstream (the graph routes on it); the generator
     * returns an `AgentOutputEvent` after a batch with a terminal call.
     */
    public function __invoke(ToolCallEvent $event, AgentState $state, AgentResources $resources): AIInferenceEvent|AwaitToolResultsEvent|\Generator
    {
        $next = yield from parent::__invoke($event, $state, $resources);

        if (!$next instanceof AIInferenceEvent || !$this->hasTerminalCall($event)) {
            return $next;
        }

        // Upstream carries the call and its results to the next inference,
        // which commits them; the turn ends here, so they are committed now.
        $this->addToChatHistory($resources->history, $state, $state->request->messages);

        return new AgentOutputEvent();
    }

    private function hasTerminalCall(ToolCallEvent $event): bool
    {
        foreach ($event->toolCallMessage->getToolCalls() as $call) {
            if (TerminalClose::isTerminal($call->getName(), $this->terminalTools) && !$this->callFailed($call)) {
                return true;
            }
        }

        return false;
    }

    private function callFailed(ToolCall $call): bool
    {
        if (isset($this->failed[spl_object_id($call)])) {
            return true;
        }
        $result = $call->hasResult() ? $call->getResult() : null;

        return $result instanceof ToolOutput && $result->isError();
    }
}

// - revised 2026-10-14
