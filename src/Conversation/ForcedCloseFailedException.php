<?php

// file generated with AI assistance: Claude Code - 2026-10-02 06:02:17 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

/**
 * The forced step of a bounded tool loop answered with another tool call
 * instead of an answer; thrown before any tool of that response runs, so
 * the turn fails ({@see ToolCallBoundMiddleware}).
 */
final class ForcedCloseFailedException extends \RuntimeException
{
    public function __construct(string $message = 'The forced close did not produce an answer.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
