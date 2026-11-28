<?php

// file generated with AI assistance: Claude Code - 2026-11-27 22:48:58 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Chat;

use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolPropertyInterface;

/**
 * A per-run guard on one argument of every tool call, for a tool server
 * whose credentials see more tenants than the caller: the caller names,
 * per tool, the one argument that identifies the tenant or project a call
 * concerns, and the values the run may use. Passed as
 * {@see AgentRunOptions::$toolGuard}, it makes {@see AgentRunner} replace
 * every tool of the run by a {@see GuardedTool}; a call that does not
 * concern an allowed value is refused in code, before the tool runs (for an
 * MCP tool: before handshake and `tools/call`), and returned to the model
 * as a tool error. The run goes on; refused calls count against the
 * tool-call bound like any other call.
 *
 *     $guard = new ToolArgumentGuard(['list_issues' => 'project', 'get_issue' => 'project'], ['acme/app', '42']);
 *     $runner->run($agent, $messages, new AgentRunOptions(toolGuard: $guard));
 *
 * The decision ({@see refusal()}) is fail-closed:
 *  - a tool without an entry in the map is refused, also a tool that has
 *    no such argument at all or two of them (there is no exception list);
 *  - an argument the tool's schema does not declare is refused: the
 *    framework would drop its value before the call;
 *  - a missing argument (absent or null) is refused;
 *  - only an `int` or a `string` is a value; `bool`, `float`, arrays,
 *    objects are refused;
 *  - the value, as a string, must equal an allowed value exactly: no trim,
 *    no case folding, no URL decoding. Which spellings are valid is
 *    entirely the allowed set (an allowed `42` matches int 42 and string
 *    "42"; `group%2Fproject` or another letter case only when listed). An
 *    empty set refuses every call.
 * The check runs on the values as cast by the tool's declared property
 * types (a `string` property turns int 42 into "42", an `integer` property
 * "42" into 42), i.e. on what the tool itself would receive.
 *
 * This guard is unrelated to restricted MCP connections
 * ({@see \Dmstr\NeuronAiBundle\Mcp\McpConnectionRestriction}, permission
 * `neuronai:mcp_connection:use_restricted`) and to retrieval scopes
 * ({@see \Dmstr\NeuronAiBundle\Agent\RetrievalScope},
 * {@see \Dmstr\NeuronAiBundle\Mcp\Server\CollectionScopeResolver}): it
 * neither decides which connections or collections a version may use nor
 * changes them, it only checks the arguments of the calls of one run.
 */
final readonly class ToolArgumentGuard
{
    /** @var array<string, string> tool name => argument name */
    public array $arguments;

    /** @var list<string> the allowed values, as strings */
    public array $allowed;

    /**
     * @param array<string, string> $arguments tool name => name of the argument that identifies the tenant or project
     * @param list<int|string>      $allowed   the values the run may use; compared exactly, as strings
     *
     * @throws \InvalidArgumentException for an empty tool or argument name or an allowed value that is neither int nor string
     */
    public function __construct(array $arguments, array $allowed)
    {
        foreach ($arguments as $tool => $argument) {
            if (!\is_string($tool) || '' === $tool || !\is_string($argument) || '' === $argument) {
                throw new \InvalidArgumentException('The tool argument guard maps non-empty tool names to non-empty argument names.');
            }
        }

        $values = [];
        foreach ($allowed as $value) {
            if (!\is_int($value) && !\is_string($value)) {
                throw new \InvalidArgumentException(\sprintf('An allowed value of the tool argument guard must be an int or a string, %s given.', get_debug_type($value)));
            }
            $values[] = (string) $value;
        }

        $this->arguments = $arguments;
        $this->allowed = array_values(array_unique($values));
    }

    /**
     * Why the call of $tool with $inputs is refused, or null when it may
     * run.
     *
     * @param array<string, mixed> $inputs   the bound (cast) inputs of the call
     * @param list<string>         $declared the property names the tool's schema declares
     */
    public function refusal(string $tool, array $inputs, array $declared): ?string
    {
        $argument = $this->arguments[$tool] ?? null;
        if (null === $argument) {
            return \sprintf('Tool "%s" is refused: it is not available in this run.', $tool);
        }

        if (!\in_array($argument, $declared, true)) {
            return \sprintf('Tool "%s" is refused: it does not declare the argument "%s".', $tool, $argument);
        }

        $value = $inputs[$argument] ?? null;
        if (null === $value) {
            return \sprintf('Tool "%s" is refused: the argument "%s" is missing.', $tool, $argument);
        }

        if (!\is_int($value) && !\is_string($value)) {
            return \sprintf('Tool "%s" is refused: the argument "%s" must be a string or an integer.', $tool, $argument);
        }

        if (!\in_array((string) $value, $this->allowed, true)) {
            return \sprintf('Tool "%s" is refused: the value of the argument "%s" is not allowed in this run.', $tool, $argument);
        }

        return null;
    }

    /**
     * {@see refusal()} for a tool with its inputs bound.
     */
    public function refusalFor(ToolInterface $tool): ?string
    {
        return $this->refusal(
            $tool->getName(),
            $tool->getInputs(),
            array_map(static fn (ToolPropertyInterface $property): string => $property->getName(), $tool->getProperties()),
        );
    }

    /**
     * Every tool of $tools, guarded, in the same order.
     *
     * @param list<ToolInterface> $tools
     *
     * @return list<GuardedTool>
     */
    public function guard(array $tools): array
    {
        return array_values(array_map(fn (ToolInterface $tool): GuardedTool => new GuardedTool($tool, $this), $tools));
    }
}
