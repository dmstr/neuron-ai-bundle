<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:22:22 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Testing;

use NeuronAI\MCP\McpException;
use NeuronAI\MCP\McpTransportInterface;

/**
 * In-memory MCP transport created by {@see FakeMcpTransportFactory}; the
 * factory holds the scripted answers and the request log (see its docblock
 * for the scripting and recording API).
 *
 * send() records the request and throws a scripted exception, if any;
 * receive() returns the scripted response to the last request.
 * setProtocolVersion() keeps the protocol revision the client settled
 * after the handshake ({@see getProtocolVersion()}); disconnect() forgets
 * it, like the vendor's HTTP transport.
 */
class FakeMcpTransport implements McpTransportInterface
{
    /** @var array<string, mixed>|null */
    private ?array $response = null;

    private int $connects = 0;

    private bool $connected = false;

    private ?string $protocolVersion = null;

    public function __construct(
        private readonly FakeMcpTransportFactory $factory,
        private readonly string $url,
    ) {
    }

    public function connect(): void
    {
        ++$this->connects;
        $this->connected = true;
    }

    public function send(array $data): void
    {
        $this->response = $this->factory->handle($this->url, $data) ?? $this->response;
    }

    public function receive(): array
    {
        if (null === $this->response) {
            throw new McpException('No response available. Call send() first.');
        }

        $response = $this->response;
        $this->response = null;

        return $response;
    }

    /**
     * neuron-ai 4.x: the client passes the protocol revision of the
     * `initialize` answer once the handshake has settled it.
     */
    public function setProtocolVersion(string $version): void
    {
        $this->protocolVersion = $version;
    }

    /**
     * The protocol revision set by the client after the handshake; null
     * before it and after disconnect().
     */
    public function getProtocolVersion(): ?string
    {
        return $this->protocolVersion;
    }

    public function disconnect(): void
    {
        $this->connected = false;
        $this->response = null;
        $this->protocolVersion = null;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getConnectCount(): int
    {
        return $this->connects;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }
}

// - revised 2026-10-14
// - revised 2026-10-14
