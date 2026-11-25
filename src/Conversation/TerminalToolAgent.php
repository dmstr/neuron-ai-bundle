<?php

// file generated with AI assistance: Claude Code - 2026-10-02 22:30:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Workflow\Node;

/**
 * The agent of a turn with terminal tools: the bundle's {@see TurnAgent}
 * with {@see TerminalToolNode} in place of `ToolNode`, so a terminal tool
 * call ends the turn after its batch. {@see TurnRunner} uses it only when
 * the request names terminal tools, else a plain {@see TurnAgent}.
 *
 * The terminal names come through {@see setTerminalTools()} before the
 * first chat() or stream() call (the graph is built per execution segment
 * then):
 *
 *     $agent = TerminalToolAgent::make(workflowId: $threadId)->setTerminalTools(['submit_answer']);
 *
 * {@see nodes()} together with {@see TerminalToolNode::__invoke()} is the
 * one adapter to neuron-ai internals (the graph hook of neuron-ai 4.x,
 * neuron 4.x guide 26, case 4); an upstream change costs this adapter.
 * Parallel tool execution is not supported (the bundle never enables it):
 * nodes() throws a \LogicException then.
 */
final class TerminalToolAgent extends TurnAgent
{
    /** @var list<string> */
    private array $terminalTools = [];

    /**
     * @param list<string> $names names of the offered tools that end the turn
     */
    public function setTerminalTools(array $names): static
    {
        $this->terminalTools = array_values($names);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getTerminalTools(): array
    {
        return $this->terminalTools;
    }

    /**
     * Upstream's graph with {@see TerminalToolNode} as the tool node, with
     * the agent's tool run limit and error handler (a handler set with
     * toolErrorHandler() wins over the resolveToolErrorHandler() hook, as
     * upstream).
     *
     * @return Node[]
     */
    protected function nodes(): array
    {
        if ($this->parallelToolCalls) {
            throw new \LogicException('Terminal tools are not supported with parallel tool calls.');
        }

        return array_map(
            fn (Node $node): Node => $node instanceof ToolNode
                ? new TerminalToolNode($this->toolMaxRuns, $this->toolErrorHandler ?? $this->resolveToolErrorHandler(), $this->terminalTools)
                : $node,
            parent::nodes(),
        );
    }
}

// - revised 2026-10-14
