<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:22:22 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp;

use NeuronAI\MCP\McpException;
use NeuronAI\MCP\McpTransportInterface;

/**
 * Transport decorator that checks every JSON-RPC response before Neuron's
 * McpClient reads it.
 *
 * McpClient accesses `$response['result']['tools']` unchecked, so a
 * malformed answer would surface as PHP warnings and type errors instead of
 * an exception. The guard turns every such answer into an
 * {@see McpProtocolException}: responses must carry the id of the pending
 * request; `initialize` and `tools/list` must return a `result` object (a
 * JSON-RPC `error` becomes an exception, too, with the server's code and
 * message on the exception); `tools/list` must return a list of tools with
 * a name, and its pagination must not repeat a cursor. A body the inner
 * transport cannot decode (it throws from receive(), after send() got an
 * HTTP success) is a protocol error as well.
 *
 * neuron-ai 4.x: McpClient reads messages until the one with its request id
 * arrives; notifications and server requests (messages with a `method`)
 * are passed through unchecked and leave the pending request open. A
 * `tools/call` error is passed through as well: McpClient raises it as an
 * McpException with the server's message and code, which the turn records
 * as a tool error. The protocol revision the client settles after the
 * handshake ({@see setProtocolVersion()}) goes to the inner transport.
 *
 * It also keeps the `initialize` result (protocol version, server info),
 * which McpClient discards.
 */
final class McpProtocolGuard implements McpTransportInterface
{
    private const int MAX_PAGES = 100;

    /** @var array{id: mixed, method: string}|null */
    private ?array $pending = null;

    /** @var array<string, mixed>|null */
    private ?array $initializeResult = null;

    /** @var array<string, true> */
    private array $seenCursors = [];

    private int $pages = 0;

    public function __construct(private readonly McpTransportInterface $inner)
    {
    }

    public function connect(): void
    {
        $this->inner->connect();
    }

    public function send(array $data): void
    {
        if (\array_key_exists('id', $data)) {
            $method = \is_string($data['method'] ?? null) ? $data['method'] : '';
            $this->pending = ['id' => $data['id'], 'method' => $method];

            if ('tools/list' === $method) {
                $this->trackPagination($data['params']['cursor'] ?? null);
            }
        }

        $this->inner->send($data);
    }

    public function receive(): array
    {
        try {
            $response = $this->inner->receive();
        } catch (McpProtocolException $e) {
            throw $e;
        } catch (McpException|\JsonException $e) {
            $method = $this->pending['method'] ?? '';
            $this->pending = null;

            throw new McpProtocolException(\sprintf('MCP response to "%s" could not be read: %s', $method, $e->getMessage()), $e);
        }

        if (\array_key_exists('method', $response)) {
            // Notification or server request, skipped by McpClient.
            return $response;
        }

        $pending = $this->pending;
        $this->pending = null;

        if (null === $pending) {
            throw new McpProtocolException('Received an MCP response without a pending request.');
        }
        $method = $pending['method'];

        if (!\array_key_exists('id', $response) || $response['id'] !== $pending['id']) {
            throw new McpProtocolException(\sprintf('Invalid response ID for "%s": expected %s, got %s.', $method, json_encode($pending['id']), json_encode($response['id'] ?? null)));
        }

        if (\array_key_exists('error', $response)) {
            if ('tools/call' === $method && \is_array($response['error'])) {
                return $response;
            }

            $message = \is_array($response['error']) && \is_scalar($response['error']['message'] ?? null)
                ? (string) $response['error']['message']
                : null;
            $code = \is_array($response['error']) && \is_int($response['error']['code'] ?? null) ? $response['error']['code'] : null;

            throw new McpProtocolException(\sprintf('MCP request "%s" failed: %s', $method, $message ?? 'unknown error'), null, $code, $message);
        }

        if (!isset($response['result']) || !\is_array($response['result'])) {
            throw new McpProtocolException(\sprintf('MCP response to "%s" has no result object.', $method));
        }

        if ('initialize' === $method) {
            $this->initializeResult = $response['result'];
        } elseif ('tools/list' === $method) {
            $this->checkToolList($response['result']);
        }

        return $response;
    }

    public function setProtocolVersion(string $version): void
    {
        $this->inner->setProtocolVersion($version);
    }

    public function disconnect(): void
    {
        $this->inner->disconnect();
    }

    /**
     * The `result` of the last successful `initialize` request, or null
     * before the handshake.
     *
     * @return array<string, mixed>|null
     */
    public function getInitializeResult(): ?array
    {
        return $this->initializeResult;
    }

    public function getInner(): McpTransportInterface
    {
        return $this->inner;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function checkToolList(array $result): void
    {
        $tools = $result['tools'] ?? null;
        if (!\is_array($tools) || !array_is_list($tools)) {
            throw new McpProtocolException('MCP response to "tools/list" has no list of tools.');
        }

        foreach ($tools as $index => $tool) {
            if (!\is_array($tool) || !\is_string($tool['name'] ?? null) || '' === $tool['name']) {
                throw new McpProtocolException(\sprintf('MCP tool definition #%d in "tools/list" has no name.', $index));
            }
            if (isset($tool['inputSchema']) && !\is_array($tool['inputSchema'])) {
                throw new McpProtocolException(\sprintf('MCP tool "%s" has an invalid inputSchema.', $tool['name']));
            }
        }

        if (isset($result['nextCursor']) && !\is_string($result['nextCursor'])) {
            throw new McpProtocolException('MCP response to "tools/list" has an invalid nextCursor.');
        }
        // neuron-ai 4.x stops on a repeated cursor itself, with a plain
        // McpException; checked here first, so it stays a protocol error.
        if (isset($result['nextCursor']) && isset($this->seenCursors[$result['nextCursor']])) {
            throw new McpProtocolException(\sprintf('MCP server repeated the "tools/list" cursor "%s".', $result['nextCursor']));
        }
    }

    private function trackPagination(mixed $cursor): void
    {
        if (null === $cursor) {
            // First page of a new listing.
            $this->seenCursors = [];
            $this->pages = 0;
        } elseif (\is_string($cursor)) {
            if (isset($this->seenCursors[$cursor])) {
                throw new McpProtocolException(\sprintf('MCP server repeated the "tools/list" cursor "%s".', $cursor));
            }
            $this->seenCursors[$cursor] = true;
        }

        if (++$this->pages > self::MAX_PAGES) {
            throw new McpProtocolException(\sprintf('MCP server returned more than %d "tools/list" pages.', self::MAX_PAGES));
        }
    }
}

// - revised 2026-10-14
