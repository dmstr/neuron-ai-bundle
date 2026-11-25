<?php

// file generated with AI assistance: Claude Code - 2026-10-09 06:42:28 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp;

use Dmstr\NeuronAiBundle\Mcp\StreamableHttpTransportFactory;
use Dmstr\NeuronAiBundle\Mcp\TokenAuthenticatedStreamableHttpTransport;
use Dmstr\NeuronAiBundle\Provider\Auth\ClientCredentialsTokenProvider;
use Dmstr\NeuronAiBundle\Provider\Auth\ProviderAuthenticationException;
use Dmstr\NeuronAiBundle\Testing\FakeHttpClient;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\MCP\McpClient;
use NeuronAI\MCP\McpException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Kernel-free checks of `auth_type: client_credentials` for MCP
 * connections: no I/O in create(), the token fetched lazily on the first
 * request and sent as bearer, one retry with a fresh token after HTTP 401
 * (an expired token mid-turn), no retry for other failures. The token
 * endpoint and the MCP server are FakeHttpClients.
 */
final class TokenAuthenticatedStreamableHttpTransportTest extends TestCase
{
    private const string MCP_URL = 'http://mcp.example.org/mcp/flowable';
    private const string TOKEN_URL = 'http://sso.example.org/realms/example/protocol/openid-connect/token';
    private const string SECRET = 'mcp-agent-dev-secret';

    private const array CONFIG = [
        'mcp' => true,
        'type' => 'mcp',
        'endpoint_type' => 'rest',
        'url' => self::MCP_URL,
        'transport' => 'streamable-http',
        'auth_type' => 'client_credentials',
        'token_url' => self::TOKEN_URL,
        'client_id' => 'mcp-agent',
        'client_secret' => self::SECRET,
        'restricted' => true,
    ];

    /** @var list<array<string, mixed>> records of FakeHttpClient */
    private array $tokenRequests = [];

    /** @var list<array<string, mixed>> records of FakeHttpClient */
    private array $mcpRequests = [];

    private FakeHttpClient $tokenEndpoint;

    private FakeHttpClient $mcpServer;

    private ArrayAdapter $cache;

    private int $time = 1_000_000;

    protected function setUp(): void
    {
        $this->tokenEndpoint = new FakeHttpClient()->recordInto($this->tokenRequests);
        $this->mcpServer = new FakeHttpClient()->recordInto($this->mcpRequests);
        $this->cache = new ArrayAdapter();
    }

    public function testCreateReturnsTheTokenTransportWithoutAnyRequest(): void
    {
        $transport = $this->factory()->create(self::CONFIG);

        self::assertInstanceOf(TokenAuthenticatedStreamableHttpTransport::class, $transport);
        self::assertSame([], $this->tokenRequests);
    }

    public function testTransportConfigCarriesNoCredentials(): void
    {
        self::assertSame(
            ['url' => self::MCP_URL, 'timeout' => 20],
            $this->factory()->transportConfig(self::CONFIG + ['timeout' => 20, 'token' => 'stale']),
        );
    }

    public function testMissingCredentialsAreRejected(): void
    {
        foreach (['token_url', 'client_id', 'client_secret'] as $key) {
            $config = self::CONFIG;
            unset($config[$key]);
            try {
                $this->factory()->create($config);
                self::fail('InvalidArgumentException expected without '.$key);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('client_credentials', $e->getMessage());
                self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            }
        }
    }

    public function testTokenIsFetchedOnceOnTheFirstRequestAndSentAsBearer(): void
    {
        $this->tokenEndpoint->queue(self::tokenResponse('tok-1'));
        $this->mcpServer->queue(self::initializeResponse(1), new HttpResponse(202, ''), self::toolResponse(2, 'done'));

        $transport = $this->transport();
        self::assertSame([], $this->tokenRequests, 'no token before the first request');

        $client = new McpClient(['transport' => $transport]);
        $result = $client->callTool('process_list');

        self::assertSame('done', $result['result']['content'][0]['text']);
        self::assertCount(1, $this->tokenRequests);
        parse_str((string) $this->tokenRequests[0]['body'], $form);
        self::assertSame(['grant_type' => 'client_credentials', 'client_id' => 'mcp-agent', 'client_secret' => self::SECRET], $form);
        self::assertSame(self::TOKEN_URL, $this->tokenRequests[0]['url']);

        self::assertCount(3, $this->mcpRequests);
        foreach ($this->mcpRequests as $entry) {
            self::assertSame('Bearer tok-1', FakeHttpClient::headerOf($entry, 'Authorization'));
            self::assertSame(self::MCP_URL, $entry['url']);
        }
        // neuron-ai 4.x: the client requests revision 2025-11-25 and sends
        // the negotiated one on every request after the handshake.
        self::assertSame('2025-11-25', json_decode((string) $this->mcpRequests[0]['body'], true)['params']['protocolVersion']);
        self::assertNull(FakeHttpClient::headerOf($this->mcpRequests[0], 'MCP-Protocol-Version'));
        self::assertSame('2025-11-25', FakeHttpClient::headerOf($this->mcpRequests[1], 'MCP-Protocol-Version'));
        self::assertSame('2025-11-25', FakeHttpClient::headerOf($this->mcpRequests[2], 'MCP-Protocol-Version'));
        self::assertTrue($this->cache->hasItem(ClientCredentialsTokenProvider::cacheKey(self::TOKEN_URL, 'mcp-agent', self::SECRET, null, ClientCredentialsTokenProvider::MCP_KEY_PREFIX)));
    }

    public function testExpiredTokenMidTurnMakesTheNextToolCallFetchANewToken(): void
    {
        $this->tokenEndpoint->queue(self::tokenResponse('tok-1'), self::tokenResponse('tok-2'));
        $this->mcpServer->queue(
            self::initializeResponse(1),
            new HttpResponse(202, ''),
            self::toolResponse(2, 'first'),
            // The server no longer accepts tok-1 (e.g. Keycloak session ended
            // before our cached expiry).
            new HttpResponse(401, '{"error":"invalid_token"}', ['WWW-Authenticate' => 'Bearer error="invalid_token"']),
            self::toolResponse(3, 'second'),
        );

        $client = new McpClient(['transport' => $this->transport()]);
        self::assertSame('first', $client->callTool('a')['result']['content'][0]['text']);
        self::assertSame('second', $client->callTool('b')['result']['content'][0]['text']);

        self::assertCount(2, $this->tokenRequests);
        $authorization = array_map(static fn (array $e): string => FakeHttpClient::headerOf($e, 'Authorization'), $this->mcpRequests);
        self::assertSame(['Bearer tok-1', 'Bearer tok-1', 'Bearer tok-1', 'Bearer tok-1', 'Bearer tok-2'], $authorization);
        // The retry is the same JSON-RPC request, with the same protocol revision.
        self::assertSame((string) $this->mcpRequests[3]['body'], (string) $this->mcpRequests[4]['body']);
        self::assertSame('2025-11-25', FakeHttpClient::headerOf($this->mcpRequests[4], 'MCP-Protocol-Version'));
    }

    public function testTokenExpiringByTheClockIsRenewedBeforeTheNextRequest(): void
    {
        $this->tokenEndpoint->queue(self::tokenResponse('tok-1', 300), self::tokenResponse('tok-2', 300));
        $this->mcpServer->queue(self::initializeResponse(1), new HttpResponse(202, ''), self::toolResponse(2, 'a'), self::toolResponse(3, 'b'));

        $client = new McpClient(['transport' => $this->transport()]);
        $client->callTool('a');
        $this->time += 270; // a long turn: 300 s token minus the 30 s margin
        $client->callTool('b');

        self::assertCount(2, $this->tokenRequests);
        self::assertSame('Bearer tok-2', FakeHttpClient::headerOf($this->mcpRequests[3], 'Authorization'));
    }

    public function testSecondRejectionFailsAfterExactlyOneRetry(): void
    {
        $this->tokenEndpoint->queue(self::tokenResponse('tok-1'), self::tokenResponse('tok-2'), self::tokenResponse('tok-3'));
        $this->mcpServer->queue(new HttpResponse(401, ''), new HttpResponse(401, ''));

        try {
            new McpClient(['transport' => $this->transport()]);
            self::fail('McpException expected');
        } catch (McpException $e) {
            self::assertSame(401, self::httpStatus($e));
            self::assertStringNotContainsString('tok-', $e->getMessage());
        }
        self::assertCount(2, $this->tokenRequests);
        self::assertCount(2, $this->mcpRequests);
    }

    public function testOtherFailuresAreNotRetried(): void
    {
        $this->tokenEndpoint->queue(self::tokenResponse('tok-1'), self::tokenResponse('tok-2'));
        $this->mcpServer->queue(new HttpResponse(403, ''), new HttpResponse(500, ''));

        foreach ([403, 500] as $status) {
            try {
                $this->transport()->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
                self::fail('McpException expected');
            } catch (McpException $e) {
                self::assertSame($status, self::httpStatus($e));
            }
        }
        self::assertCount(1, $this->tokenRequests, 'the cached token is kept');
        self::assertCount(2, $this->mcpRequests);
    }

    public function testTokenEndpointFailureStopsBeforeTheMcpServer(): void
    {
        $this->tokenEndpoint->queue(new HttpResponse(401, '{"error":"unauthorized_client","error_description":"Invalid client credentials"}'));

        try {
            $this->transport()->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
            self::fail('ProviderAuthenticationException expected');
        } catch (ProviderAuthenticationException $e) {
            self::assertSame(401, $e->statusCode);
            self::assertStringContainsString('MCP connection "http://mcp.example.org/mcp/flowable"', $e->getMessage());
            self::assertStringContainsString('unauthorized_client', $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
        self::assertSame([], $this->mcpRequests);
    }

    public function testConfiguredHeadersAndTokenKeyDoNotOverrideTheFetchedToken(): void
    {
        $this->tokenEndpoint->queue(self::tokenResponse('tok-1'));
        $this->mcpServer->queue(self::initializeResponse(1));

        $transport = new TokenAuthenticatedStreamableHttpTransport(
            ['url' => self::MCP_URL, 'token' => 'static', 'headers' => ['Authorization' => 'Basic x', 'X-Trace' => 't']],
            $this->factory()->tokenProvider(self::CONFIG),
            $this->mcpServer,
        );
        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);

        self::assertSame('Bearer tok-1', FakeHttpClient::headerOf($this->mcpRequests[0], 'Authorization'));
        self::assertSame('t', FakeHttpClient::headerOf($this->mcpRequests[0], 'X-Trace'));
    }

    private function factory(): StreamableHttpTransportFactory
    {
        return new StreamableHttpTransportFactory($this->cache, $this->tokenEndpoint, fn (): int => $this->time);
    }

    private function transport(): TokenAuthenticatedStreamableHttpTransport
    {
        $factory = $this->factory();

        return new TokenAuthenticatedStreamableHttpTransport($factory->transportConfig(self::CONFIG), $factory->tokenProvider(self::CONFIG), $this->mcpServer);
    }

    /**
     * The HTTP status behind an McpException of the vendor transport: the
     * chained HttpException's response status.
     */
    private static function httpStatus(McpException $e): ?int
    {
        $previous = $e->getPrevious();

        return $previous instanceof HttpException ? $previous->response?->statusCode : null;
    }

    private static function tokenResponse(string $token, int $expiresIn = 300): HttpResponse
    {
        return new HttpResponse(200, json_encode(['access_token' => $token, 'expires_in' => $expiresIn, 'token_type' => 'Bearer'], \JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json']);
    }

    private static function initializeResponse(int $id): HttpResponse
    {
        return new HttpResponse(200, json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => ['tools' => new \stdClass()],
            'serverInfo' => ['name' => 'flowable', 'version' => '1.0.0'],
        ]], \JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json']);
    }

    private static function toolResponse(int $id, string $text): HttpResponse
    {
        return new HttpResponse(200, json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => false,
        ]], \JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json']);
    }
}

// - revised 2026-10-14
