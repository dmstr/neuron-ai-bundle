<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:22:22 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp;

use NeuronAI\MCP\McpTransportInterface;

/**
 * Builds the MCP transport for an `mcp` connection.
 *
 * Health, catalogue scan and sync, and chat tool execution all obtain their
 * transport here, which makes this interface the test seam: under
 * `when@test` the app aliases it to
 * {@see \Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory}.
 */
interface McpTransportFactoryInterface
{
    /**
     * Creates a new, not yet connected transport. Must not perform I/O.
     *
     * $context names the purpose (scan, health, turn) and, for a turn, the
     * JWT of the person chatting; `auth_type: forward_jwt` connections pick
     * their credentials from it, other auth types ignore it. Null behaves
     * like a scan.
     *
     * @param array<string, mixed> $config the connection's configJson with
     *                                     secrets decrypted (url, transport,
     *                                     auth_type, token, header_name,
     *                                     token_url, client_id,
     *                                     client_secret, scope, scan_token_url,
     *                                     scan_client_id, scan_client_secret,
     *                                     scan_scope, timeout); extra keys
     *                                     are ignored
     *
     * @throws \InvalidArgumentException when the configuration cannot be used
     */
    public function create(array $config, ?McpTransportContext $context = null): McpTransportInterface;
}

// - revised 2026-10-09 06:42:28 UTC
// - revised 2026-10-09 13:43:23 UTC
