<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:22:22 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp;

use NeuronAI\MCP\McpException;

/**
 * The MCP server answered, but not with a usable JSON-RPC response
 * (a body the transport could not decode, missing or mismatching id,
 * missing result, JSON-RPC error on the handshake or the tool listing).
 * Thrown by {@see McpProtocolGuard}.
 *
 * For a JSON-RPC error, $rpcCode and $rpcMessage carry the server's `error`
 * object (code and message as sent), so callers can inspect it without
 * parsing the exception message.
 */
final class McpProtocolException extends McpException
{
    public function __construct(
        string $message,
        ?\Throwable $previous = null,
        public readonly ?int $rpcCode = null,
        public readonly ?string $rpcMessage = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

// - revised 2026-10-14
