<?php

// file generated with AI assistance: Claude Code - 2026-10-14 12:30:38 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Testing;

use NeuronAI\Tools\Tool;

/**
 * A tool whose execution is a closure, for tests: neuron-ai 4.x made
 * `Tool` abstract and removed `setCallable()` (neuron 4.x guide 3, case 2),
 * so a test tool built inline needs a class. Name and description are
 * passed as before; the closure receives the declared properties as named
 * arguments and its return value is the tool result (a string, an array
 * that is JSON-encoded, or a `ToolOutput`):
 *
 *     new CallbackTool('search', 'Full-text search', static fn (): string => 'Treffer');
 */
class CallbackTool extends Tool
{
    private readonly \Closure $handler;

    public function __construct(string $name, ?string $description, callable $handler)
    {
        $this->name = $name;
        $this->description = $description;
        $this->handler = $handler(...);
    }

    public function __invoke(mixed ...$arguments): mixed
    {
        return ($this->handler)(...$arguments);
    }
}
