<?php

// file generated with AI assistance: Claude Code - 2026-10-05 13:30:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp;

use Dmstr\ApiConfiguration\ApiClient\AuthenticationFailedException;
use Dmstr\ApiConfiguration\Normalizer\HealthNormalizer;
use Dmstr\NeuronAiBundle\Mcp\McpExtension;
use Dmstr\NeuronAiBundle\Mcp\McpHealthClient;
use Dmstr\NeuronAiBundle\Mcp\McpTransportContext;
use Dmstr\NeuronAiBundle\Mcp\McpTransportFactoryInterface;
use Dmstr\NeuronAiBundle\Provider\Auth\ProviderAuthenticationException;
use Dmstr\NeuronAiBundle\Testing\FakeHttpClient;
use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\MCP\McpException;
use NeuronAI\MCP\McpTransportInterface;
use NeuronAI\MCP\StreamableHttpTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the health probe of `mcp` connections against the
 * fake transport. Every result is also run through the platform's
 * HealthNormalizer, which validates it against health.json.
 *
 * neuron-ai 4.x: the classification reads the HTTP status of the chained
 * HttpException and the structured JSON-RPC error, never exception texts;
 * the cases marked "through the vendor transport" run the real
 * StreamableHttpTransport over {@see FakeHttpClient}.
 */
final class McpHealthClientTest extends TestCase
{
    private const string URL = 'https://mcp.example.org/mcp';

    private FakeMcpTransportFactory $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeMcpTransportFactory();
    }

    public function testCreateClientPerformsNoTransportCall(): void
    {
        $client = $this->client();

        self::assertSame(self::URL, $client->getEndpoint());
        self::assertSame('mcp', $client->getApiName());
        self::assertSame('rest', $client->getType());
        self::assertSame(0, $this->fake->getTransportCount());
        self::assertSame(0, $this->fake->getRequestCount());

        $client->getHealthInfo();

        self::assertSame(1, $this->fake->getTransportCount());
        self::assertSame(['initialize', 'notifications/initialized', 'tools/list'], $this->fake->getMethods(self::URL));
    }

    public function testHealthyServerReportsToolsAndServerInfo(): void
    {
        $this->fake
            ->serveServerInfo('docs-server', '2.1.0')
            ->serveTools([
                ['name' => 'search', 'description' => 'Search', 'inputSchema' => ['type' => 'object']],
                ['name' => 'fetch', 'inputSchema' => ['type' => 'object']],
            ]);

        $health = $this->client()->getHealthInfo();

        self::assertSame('ok', $health['status']);
        self::assertTrue($health['authenticated']);
        self::assertSame([
            'toolCount' => 2,
            'protocolVersion' => FakeMcpTransportFactory::DEFAULT_PROTOCOL_VERSION,
            'serverName' => 'docs-server',
            'serverVersion' => '2.1.0',
        ], $health['metadata']['custom']);
        self::assertArrayNotHasKey('error', $health);

        $normalized = $this->normalize($health);
        self::assertSame(self::URL, $normalized['endpoint']);
        self::assertSame(2, $normalized['metadata']['custom']['toolCount']);
    }

    public function testAuthenticateMirrorsTheProbe(): void
    {
        $this->client()->authenticate();

        $this->fake->rejectCredentials();
        $this->expectException(AuthenticationFailedException::class);
        $this->client()->authenticate();
    }

    public function testToolListingIsPaged(): void
    {
        $this->fake
            ->queueResult('tools/list', ['tools' => [['name' => 'a']], 'nextCursor' => 'page-2'])
            ->queueResult('tools/list', ['tools' => [['name' => 'b'], ['name' => 'c']]]);

        $health = $this->client()->getHealthInfo();

        self::assertSame(3, $health['metadata']['custom']['toolCount']);
        self::assertSame(['cursor' => 'page-2'], $this->fake->getRequests(null, 'tools/list')[1]['request']['params']);
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function unreachable(): iterable
    {
        yield 'vendor network failure' => [new McpException('HTTP request failed: Network error during POST https://mcp.example.org/mcp: Failed to connect', 0, HttpException::networkError(self::request(), 'Failed to connect to mcp.example.org port 443'))];
        yield 'server error' => [new McpException('HTTP request failed: HTTP 502 error during POST https://mcp.example.org/mcp: Bad Gateway', 0, HttpException::statusError(self::request(), new HttpResponse(502, 'Bad Gateway')))];
        yield '401 only in the message text' => [new McpException('HTTP request failed: Client error: `POST https://mcp.example.org/mcp` resulted in a `401 Unauthorized` response')];
        yield 'generic exception' => [new \RuntimeException('Connection refused')];
        yield 'token endpoint unreachable' => [new ProviderAuthenticationException('Token endpoint of MCP connection "https://tasks.example.org/mcp" is unreachable.', null, 'https://tasks.example.org/mcp')];
    }

    #[DataProvider('unreachable')]
    public function testThrowingTransportIsUnreachable(\Throwable $exception): void
    {
        $this->fake->failWith($exception);

        $health = $this->client()->getHealthInfo();

        self::assertSame('error', $health['status']);
        self::assertFalse($health['authenticated']);
        self::assertSame(McpHealthClient::CODE_UNREACHABLE, $health['error']['code']);
        self::assertStringContainsString($exception->getMessage(), $health['message']);
        $this->normalize($health);
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function unauthenticated(): iterable
    {
        yield 'vendor 401' => [new McpException('Authentication failed: Invalid or expired token', 0, HttpException::statusError(self::request(), new HttpResponse(401, '')))];
        yield 'vendor 403' => [new McpException('Authorization failed: Insufficient permissions', 0, HttpException::statusError(self::request(), new HttpResponse(403, '')))];
        yield '401 deeper in the chain' => [new \RuntimeException('wrapped', 0, new McpException('any wording', 0, HttpException::statusError(self::request(), new HttpResponse(401, ''))))];
        yield 'token endpoint refused the client' => [new ProviderAuthenticationException('Token endpoint of MCP connection "https://tasks.example.org/mcp" answered HTTP 400 (invalid_client).', 400, 'https://tasks.example.org/mcp')];
    }

    #[DataProvider('unauthenticated')]
    public function testAuthenticationFailureIsUnauthenticated(\Throwable $exception): void
    {
        $this->fake->queueException('initialize', $exception);

        $health = $this->client()->getHealthInfo();

        self::assertSame('error', $health['status']);
        self::assertFalse($health['authenticated']);
        self::assertSame(McpHealthClient::CODE_UNAUTHENTICATED, $health['error']['code']);
        $this->normalize($health);
    }

    public function testJsonRpcAuthErrorIsUnauthenticated(): void
    {
        $this->fake->queueError('initialize', -32001, 'Unauthorized');

        self::assertSame(McpHealthClient::CODE_UNAUTHENTICATED, $this->client()->getHealthInfo()['error']['code']);
    }

    /**
     * @return iterable<string, array{callable(FakeMcpTransportFactory): void}>
     */
    public static function malformed(): iterable
    {
        yield 'initialize without id' => [static fn (FakeMcpTransportFactory $f) => $f->queueRaw('initialize', ['jsonrpc' => '2.0', 'result' => []])];
        yield 'initialize with wrong id' => [static fn (FakeMcpTransportFactory $f) => $f->queueRaw('initialize', ['jsonrpc' => '2.0', 'id' => 99, 'result' => []])];
        yield 'initialize without result' => [static fn (FakeMcpTransportFactory $f) => $f->queueRaw('initialize', ['jsonrpc' => '2.0', 'id' => 1])];
        yield 'tools/list without tools' => [static fn (FakeMcpTransportFactory $f) => $f->queueResult('tools/list', ['items' => []])];
        yield 'tool without name' => [static fn (FakeMcpTransportFactory $f) => $f->queueResult('tools/list', ['tools' => [['description' => 'nameless']]])];
        yield 'tools/list rpc error' => [static fn (FakeMcpTransportFactory $f) => $f->queueError('tools/list', -32601, 'Method not found')];
        yield 'repeated cursor' => [static fn (FakeMcpTransportFactory $f) => $f
            ->queueResult('tools/list', ['tools' => [], 'nextCursor' => 'x'])
            ->queueResult('tools/list', ['tools' => [], 'nextCursor' => 'x'])
            ->queueResult('tools/list', ['tools' => [], 'nextCursor' => 'x'])];
    }

    /**
     * @return iterable<string, array{list<HttpResponse>, string}>
     */
    public static function throughTheVendorTransport(): iterable
    {
        yield 'invalid json' => [[new HttpResponse(200, '{not json', ['Content-Type' => 'application/json'])], McpHealthClient::CODE_PROTOCOL];
        yield 'empty body' => [[new HttpResponse(200, '')], McpHealthClient::CODE_PROTOCOL];
        yield 'sse without data' => [[new HttpResponse(200, ": keep-alive\n\n", ['Content-Type' => 'text/event-stream'])], McpHealthClient::CODE_PROTOCOL];
        yield 'http 401' => [[new HttpResponse(401, '{"error":"invalid_token"}')], McpHealthClient::CODE_UNAUTHENTICATED];
        yield 'http 403' => [[new HttpResponse(403, '')], McpHealthClient::CODE_UNAUTHENTICATED];
        yield 'http 502' => [[new HttpResponse(502, 'Bad Gateway')], McpHealthClient::CODE_UNREACHABLE];
    }

    /**
     * @param list<HttpResponse> $responses
     */
    #[DataProvider('throughTheVendorTransport')]
    public function testVendorTransportFailuresAreClassified(array $responses, string $code): void
    {
        $health = $this->clientOverHttp(new FakeHttpClient($responses))->getHealthInfo();

        self::assertSame('error', $health['status']);
        self::assertSame($code, $health['error']['code'], $health['message']);
        $this->normalize($health);
    }

    /**
     * Through the vendor transport: an SSE answer may carry a notification
     * before the response; the guard lets it pass and the 4.x client skips
     * it. Later requests carry the negotiated protocol revision.
     */
    public function testNotificationBeforeTheResponseIsSkipped(): void
    {
        $initialize = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => ['tools' => new \stdClass()],
            'serverInfo' => ['name' => 'sse-server', 'version' => '1'],
        ]], \JSON_THROW_ON_ERROR);
        $log = json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/message', 'params' => ['level' => 'info', 'data' => 'hello']], \JSON_THROW_ON_ERROR);
        $tools = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [['name' => 'a']]]], \JSON_THROW_ON_ERROR);
        $http = new FakeHttpClient([
            new HttpResponse(200, "event: message\ndata: {$log}\n\nevent: message\ndata: {$initialize}\n\n", ['Content-Type' => 'text/event-stream']),
            new HttpResponse(202, ''),
            new HttpResponse(200, $tools, ['Content-Type' => 'application/json']),
        ]);

        $health = $this->clientOverHttp($http)->getHealthInfo();

        self::assertSame('ok', $health['status'], $health['message']);
        self::assertSame(1, $health['metadata']['custom']['toolCount']);
        self::assertSame('2025-11-25', $health['metadata']['custom']['protocolVersion']);
        $sent = $http->sent();
        self::assertSame('2025-11-25', json_decode((string) $sent[0]['body'], true)['params']['protocolVersion']);
        self::assertNull(FakeHttpClient::headerOf($sent[0], 'MCP-Protocol-Version'));
        self::assertSame('2025-11-25', FakeHttpClient::headerOf($sent[2], 'MCP-Protocol-Version'));
    }

    /**
     * @param callable(FakeMcpTransportFactory): void $script
     */
    #[DataProvider('malformed')]
    public function testMalformedResponseIsAProtocolError(callable $script): void
    {
        $script($this->fake);

        $health = $this->client()->getHealthInfo();

        self::assertSame('error', $health['status']);
        self::assertFalse($health['authenticated']);
        self::assertSame(McpHealthClient::CODE_PROTOCOL, $health['error']['code'], $health['message']);
        $this->normalize($health);
    }

    public function testUnusableConfigurationIsReportedNotThrown(): void
    {
        $client = (new McpExtension(new \Dmstr\NeuronAiBundle\Mcp\StreamableHttpTransportFactory()))
            ->createClient(['url' => self::URL, 'transport' => 'streamable-http', 'auth_type' => 'bearer']);

        $health = $client->getHealthInfo();

        self::assertSame('error', $health['status']);
        self::assertSame(McpHealthClient::CODE_UNREACHABLE, $health['error']['code']);
        self::assertStringContainsString('token', $health['message']);
    }

    public function testDomainMethodsAreNoOps(): void
    {
        $client = $this->client();

        self::assertSame([], $client->getProjects());
        self::assertSame([], $client->getTodos('p'));
        self::assertNull($client->getTodo('p', 't'));
        self::assertSame(0, $this->fake->getTransportCount());
    }

    public function testConfigurationWithoutUrlIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new McpExtension($this->fake))->createClient(['type' => 'mcp']);
    }

    private static function request(): HttpRequest
    {
        return new HttpRequest(HttpMethod::POST, self::URL);
    }

    /**
     * A health client whose transport is the vendor's StreamableHttpTransport
     * over $http.
     */
    private function clientOverHttp(FakeHttpClient $http): McpHealthClient
    {
        $factory = new class($http) implements McpTransportFactoryInterface {
            public function __construct(private readonly FakeHttpClient $http)
            {
            }

            public function create(array $config, ?McpTransportContext $context = null): McpTransportInterface
            {
                return new StreamableHttpTransport(['url' => $config['url']], $this->http);
            }
        };

        return new McpHealthClient(['url' => self::URL], $factory);
    }

    private function client(): McpHealthClient
    {
        $client = (new McpExtension($this->fake))->createClient([
            'mcp' => true,
            'type' => 'mcp',
            'endpoint_type' => 'rest',
            'url' => self::URL,
            'transport' => 'streamable-http',
            'auth_type' => 'none',
            // Added by ApiClientFactory::createFromEntity().
            '_apiConfigurationId' => '0199a1b2-0000-4000-8000-000000000001',
        ]);
        self::assertInstanceOf(McpHealthClient::class, $client);

        return $client;
    }

    /**
     * @param array<string, mixed> $health
     *
     * @return array<string, mixed>
     */
    private function normalize(array $health): array
    {
        return (new HealthNormalizer())->normalize($health, self::URL, 12.5);
    }
}
