<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:22:22 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Testing;

use Dmstr\NeuronAiBundle\Mcp\McpTransportContext;
use Dmstr\NeuronAiBundle\Mcp\McpTransportFactoryInterface;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\MCP\McpException;

/**
 * Transport factory for tests: no MCP server is contacted.
 *
 * Every create() call returns a new {@see FakeMcpTransport} bound to this
 * factory, which holds the scripted server behaviour and the log of all
 * requests. A test scripts the fake before running the code under test and
 * inspects the recorded requests afterwards. Under `when@test` the app
 * aliases McpTransportFactoryInterface to this class (public service), so a
 * functional test fetches it from the container:
 *
 *     $fake = static::getContainer()->get(FakeMcpTransportFactory::class);
 *     $fake->serveTools([['name' => 'search', 'description' => 'Search', 'inputSchema' => ['type' => 'object']]]);
 *     // ... code under test performs handshake + tools/list ...
 *     self::assertSame(['initialize', 'notifications/initialized', 'tools/list'], $fake->getMethods());
 *
 * Scripting. Every method takes an optional `$url`: null applies to every
 * connection, a URL only to transports created for that `url`. URL-specific
 * scripts win over global ones.
 *  - serveTools($tools)            persistent `tools/list` answer (default: no tools)
 *  - serveServerInfo($name, $version, $protocolVersion)
 *                                  persistent `initialize` answer (default: fake-mcp 1.0.0, 2025-11-25,
 *                                  the revision the neuron-ai 4.x client requests)
 *  - queueResult($method, $result) one-shot JSON-RPC `result` for the next request of $method
 *  - queueError($method, $code, $message)  one-shot JSON-RPC `error`
 *  - queueRaw($method, $response) one-shot response returned verbatim (malformed answers)
 *  - queueException($method, $e)  one-shot exception thrown by send()
 *  - queueToolResult($content)    one-shot `tools/call` result; a string becomes one text content item
 *  - failWith($e)                 every request throws $e (server unreachable)
 *  - rejectCredentials()          every request throws the vendor's 401 McpException (chaining
 *                                 Neuron's HttpException with status 401, as neuron-ai 4.x does)
 *  - useFixtures($byTool)         persistent `tools/call` answers by tool name and arguments (below)
 *  - clearFixtures()              forgets the fixtures
 *  - reset()                      forgets all scripts, fixtures and recordings
 * One-shot entries are consumed in FIFO order per method. Unscripted
 * `tools/call` requests get a JSON-RPC error, other methods an empty result.
 *
 * Fixtures answer `tools/call` independently of the call order. Keys are
 * `"<toolName>"` (any arguments) or `"<toolName>#<hash>"` with
 * `<hash>` = {@see argumentsHash()} of the call's arguments; a fixture is
 * a text or `{content: string|list, isError?: bool}`. Lookup order: exact
 * `name#hash`, bare `name` (each URL-specific before global), then the
 * one-shot queue, then the unscripted behaviour. Fixtures are not
 * consumed. {@see FixtureSet::loadMcp()} reads them from JSON.
 *
 * Recording:
 *  - getRequests($url, $method)   list of ['url' => string, 'method' => ?string, 'request' => array]
 *  - getMethods($url)             list of the recorded JSON-RPC method names
 *  - getRequestCount($url, $method)
 *  - getCreatedConfigs()          configs passed to create(), in order
 *  - getCreatedContexts()         contexts passed to create() (McpTransportContext or null), in
 *                                 the same order: purpose scan/health/turn and the forwarded JWT
 *  - getLastCreatedContext()      the context of the last create() call (null without one)
 *  - getTransportCount()          number of transports created
 *
 * State lives in this service instance: script it after the kernel serving
 * the code under test has booted (e.g. after createClient(), before the
 * first request) and do not rely on it across kernel reboots.
 */
class FakeMcpTransportFactory implements McpTransportFactoryInterface
{
    /** The protocol revision the neuron-ai 4.x McpClient requests (McpClient::PROTOCOL_VERSION). */
    public const string DEFAULT_PROTOCOL_VERSION = '2025-11-25';

    private const string ANY_URL = '*';

    /** @var array<string, list<array<string, mixed>>> */
    private array $tools = [];

    /** @var array<string, array<string, mixed>> */
    private array $initializeResults = [];

    /** @var array<string, array<string, list<array{kind: string, value: mixed}>>> url => method => queue */
    private array $queues = [];

    /** @var array<string, \Throwable> */
    private array $failures = [];

    /** @var list<array{url: string, method: ?string, request: array<string, mixed>}> */
    private array $requests = [];

    /** @var list<array<string, mixed>> */
    private array $configs = [];

    /** @var list<McpTransportContext|null> */
    private array $contexts = [];

    /** @var array<string, array<string, array{content: list<mixed>, isError: bool}>> url => key => tools/call result */
    private array $toolFixtures = [];

    /**
     * sha1 of the canonical JSON of tool call arguments: object keys sorted
     * recursively, encoded with JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE.
     * Build `"<toolName>#<hash>"` fixture keys with it.
     *
     * @param array<array-key, mixed> $arguments
     */
    public static function argumentsHash(array $arguments): string
    {
        return sha1(json_encode(self::canonicalize($arguments), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
    }

    /**
     * Replaces the `tools/call` fixtures for $url (null = every connection).
     *
     * @param array<string, string|array{content: string|list<array<string, mixed>>, isError?: bool}> $byTool
     *
     * @throws \InvalidArgumentException on an empty key or a malformed fixture
     */
    public function useFixtures(array $byTool, ?string $url = null): self
    {
        $fixtures = [];
        foreach ($byTool as $key => $fixture) {
            $key = (string) $key;
            if ('' === $key || str_starts_with($key, '#')) {
                throw new \InvalidArgumentException(\sprintf('FakeMcpTransportFactory: invalid fixture key "%s"; expected "<toolName>" or "<toolName>#<hash>".', $key));
            }
            if (\is_string($fixture)) {
                $fixture = ['content' => $fixture];
            }
            if (!\is_array($fixture) || !(\is_string($fixture['content'] ?? null) || (\is_array($fixture['content'] ?? null) && array_is_list($fixture['content'])))
                || !\is_bool($fixture['isError'] ?? false)) {
                throw new \InvalidArgumentException(\sprintf('FakeMcpTransportFactory: fixture "%s" must be a string or {content: string|list, isError?: bool}.', $key));
            }
            $content = \is_string($fixture['content']) ? [['type' => 'text', 'text' => $fixture['content']]] : $fixture['content'];
            $fixtures[$key] = ['content' => $content, 'isError' => $fixture['isError'] ?? false];
        }

        $this->toolFixtures[$url ?? self::ANY_URL] = $fixtures;

        return $this;
    }

    /**
     * Forgets all `tools/call` fixtures (global and URL-specific).
     */
    public function clearFixtures(): self
    {
        $this->toolFixtures = [];

        return $this;
    }

    public function create(array $config, ?McpTransportContext $context = null): FakeMcpTransport
    {
        $this->configs[] = $config;
        $this->contexts[] = $context;
        $url = $config['url'] ?? '';

        return new FakeMcpTransport($this, \is_string($url) ? $url : '');
    }

    /**
     * @param list<array<string, mixed>> $tools tool definitions as an MCP server lists them
     *                                          (name, description, inputSchema, annotations)
     */
    public function serveTools(array $tools, ?string $url = null): self
    {
        $this->tools[$url ?? self::ANY_URL] = array_values($tools);

        return $this;
    }

    public function serveServerInfo(string $name, string $version, string $protocolVersion = self::DEFAULT_PROTOCOL_VERSION, ?string $url = null): self
    {
        $this->initializeResults[$url ?? self::ANY_URL] = [
            'protocolVersion' => $protocolVersion,
            'capabilities' => ['tools' => new \stdClass()],
            'serverInfo' => ['name' => $name, 'version' => $version],
        ];

        return $this;
    }

    /**
     * @param array<string, mixed> $result
     */
    public function queueResult(string $method, array $result, ?string $url = null): self
    {
        return $this->enqueue($url, $method, 'result', $result);
    }

    public function queueError(string $method, int $code, string $message, ?string $url = null): self
    {
        return $this->enqueue($url, $method, 'error', ['code' => $code, 'message' => $message]);
    }

    /**
     * @param array<string, mixed> $response returned as is, without id or jsonrpc
     */
    public function queueRaw(string $method, array $response, ?string $url = null): self
    {
        return $this->enqueue($url, $method, 'raw', $response);
    }

    public function queueException(string $method, \Throwable $exception, ?string $url = null): self
    {
        return $this->enqueue($url, $method, 'exception', $exception);
    }

    /**
     * @param string|list<array<string, mixed>> $content MCP content items, or a text
     */
    public function queueToolResult(string|array $content, bool $isError = false, ?string $url = null): self
    {
        if (\is_string($content)) {
            $content = [['type' => 'text', 'text' => $content]];
        }

        return $this->queueResult('tools/call', ['content' => $content, 'isError' => $isError], $url);
    }

    public function failWith(\Throwable $exception, ?string $url = null): self
    {
        $this->failures[$url ?? self::ANY_URL] = $exception;

        return $this;
    }

    /**
     * Every request fails like the vendor transport on HTTP 401: an
     * McpException (code 0) chaining Neuron's HttpException with the 401
     * response.
     */
    public function rejectCredentials(?string $url = null): self
    {
        $request = new HttpRequest(HttpMethod::POST, $url ?? 'http://fake-mcp/mcp');
        $response = new HttpResponse(401, '{"error":"invalid_token"}');

        return $this->failWith(new McpException('Authentication failed: Invalid or expired token', 0, HttpException::statusError($request, $response)), $url);
    }

    public function reset(): void
    {
        $this->tools = [];
        $this->initializeResults = [];
        $this->queues = [];
        $this->failures = [];
        $this->requests = [];
        $this->configs = [];
        $this->contexts = [];
        $this->toolFixtures = [];
    }

    /**
     * @return list<array{url: string, method: ?string, request: array<string, mixed>}>
     */
    public function getRequests(?string $url = null, ?string $method = null): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $entry): bool => (null === $url || $entry['url'] === $url)
                && (null === $method || $entry['method'] === $method),
        ));
    }

    /**
     * @return list<string|null>
     */
    public function getMethods(?string $url = null): array
    {
        return array_column($this->getRequests($url), 'method');
    }

    public function getRequestCount(?string $url = null, ?string $method = null): int
    {
        return \count($this->getRequests($url, $method));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCreatedConfigs(): array
    {
        return $this->configs;
    }

    /**
     * The context of every create() call, parallel to
     * {@see getCreatedConfigs()}.
     *
     * @return list<McpTransportContext|null>
     */
    public function getCreatedContexts(): array
    {
        return $this->contexts;
    }

    /**
     * The context of the last create() call; null when nothing was created
     * or the last call passed none.
     */
    public function getLastCreatedContext(): ?McpTransportContext
    {
        return [] === $this->contexts ? null : $this->contexts[array_key_last($this->contexts)];
    }

    public function getTransportCount(): int
    {
        return \count($this->configs);
    }

    /**
     * Records a request and returns the scripted JSON-RPC response (null for
     * notifications). Called by FakeMcpTransport::send().
     *
     * @internal
     *
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>|null
     *
     * @throws \Throwable as scripted
     */
    public function handle(string $url, array $request): ?array
    {
        $method = \is_string($request['method'] ?? null) ? $request['method'] : null;
        $this->requests[] = ['url' => $url, 'method' => $method, 'request' => $request];

        $failure = $this->failures[$url] ?? $this->failures[self::ANY_URL] ?? null;
        if (null !== $failure) {
            throw $failure;
        }

        $fixture = 'tools/call' === $method ? $this->matchToolFixture($url, $request) : null;
        $entry = null !== $fixture ? ['kind' => 'result', 'value' => $fixture] : $this->dequeue($url, (string) $method);
        if (null !== $entry && 'exception' === $entry['kind']) {
            throw $entry['value'];
        }

        if (!\array_key_exists('id', $request)) {
            // Notification: no response expected.
            return null;
        }
        $id = $request['id'];

        if (null !== $entry) {
            return match ($entry['kind']) {
                'raw' => $entry['value'],
                'error' => ['jsonrpc' => '2.0', 'id' => $id, 'error' => $entry['value']],
                default => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $entry['value']],
            };
        }

        return match ($method) {
            'initialize' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $this->initializeResults[$url]
                ?? $this->initializeResults[self::ANY_URL]
                ?? [
                    'protocolVersion' => self::DEFAULT_PROTOCOL_VERSION,
                    'capabilities' => ['tools' => new \stdClass()],
                    'serverInfo' => ['name' => 'fake-mcp', 'version' => '1.0.0'],
                ]],
            'tools/list' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'tools' => $this->tools[$url] ?? $this->tools[self::ANY_URL] ?? [],
            ]],
            'tools/call' => ['jsonrpc' => '2.0', 'id' => $id, 'error' => [
                'code' => -32603,
                'message' => \sprintf('FakeMcpTransport: no scripted result for tools/call "%s"', \is_scalar($request['params']['name'] ?? null) ? $request['params']['name'] : '?'),
            ]],
            default => ['jsonrpc' => '2.0', 'id' => $id, 'result' => []],
        };
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{content: list<mixed>, isError: bool}|null
     */
    private function matchToolFixture(string $url, array $request): ?array
    {
        if ([] === $this->toolFixtures) {
            return null;
        }
        $name = $request['params']['name'] ?? null;
        if (!\is_string($name)) {
            return null;
        }
        $arguments = $request['params']['arguments'] ?? [];
        $arguments = \is_array($arguments) || \is_object($arguments) ? (array) self::canonicalize($arguments) : [];

        foreach ([$name.'#'.self::argumentsHash($arguments), $name] as $key) {
            foreach ([$url, self::ANY_URL] as $scope) {
                if (isset($this->toolFixtures[$scope][$key])) {
                    return $this->toolFixtures[$scope][$key];
                }
            }
        }

        return null;
    }

    /**
     * Objects become arrays and associative arrays get their keys sorted,
     * recursively; lists keep their order.
     */
    private static function canonicalize(mixed $value): mixed
    {
        if (\is_object($value)) {
            $value = get_object_vars($value);
        }
        if (!\is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonicalize(...), $value);
        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }

        return $value;
    }

    private function enqueue(?string $url, string $method, string $kind, mixed $value): self
    {
        $this->queues[$url ?? self::ANY_URL][$method][] = ['kind' => $kind, 'value' => $value];

        return $this;
    }

    /**
     * @return array{kind: string, value: mixed}|null
     */
    private function dequeue(string $url, string $method): ?array
    {
        foreach ([$url, self::ANY_URL] as $key) {
            if (!empty($this->queues[$key][$method])) {
                return array_shift($this->queues[$key][$method]);
            }
        }

        return null;
    }
}

// - revised 2026-10-09 13:43:23 UTC
// - revised 2026-10-14
