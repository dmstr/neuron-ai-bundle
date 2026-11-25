<?php

// file generated with AI assistance: Claude Code - 2026-10-09 13:43:23 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp\ForwardJwt;

use Dmstr\NeuronAiBundle\Mcp\McpTransportContext;
use Dmstr\NeuronAiBundle\Mcp\StreamableHttpTransportFactory;
use Dmstr\NeuronAiBundle\Mcp\TokenAuthenticatedStreamableHttpTransport;
use Dmstr\NeuronAiBundle\Testing\FakeHttpClient;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\MCP\McpTransportInterface;
use NeuronAI\MCP\StreamableHttpTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Kernel-free checks of `auth_type: forward_jwt` in the transport factory:
 * a turn with a JWT sends that JWT as bearer, everything else uses the scan
 * service account (client credentials grant with the `scan_*` keys). The
 * token endpoint is a FakeHttpClient; the authorization header is read
 * from the transport without sending an MCP request.
 */
final class StreamableHttpTransportFactoryForwardJwtTest extends TestCase
{
    private const string MCP_URL = 'http://mcp.example.org/mcp/flowable';
    private const string TOKEN_URL = 'http://sso.example.org/realms/example/protocol/openid-connect/token';
    private const string SCAN_SECRET = 'mcp-scan-dev-secret';
    private const string JWT = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJwZXJzb24ifQ.c2lnbmF0dXJl';

    private const array CONFIG = [
        'mcp' => true,
        'type' => 'mcp',
        'endpoint_type' => 'rest',
        'url' => self::MCP_URL,
        'transport' => 'streamable-http',
        'auth_type' => 'forward_jwt',
        'scan_token_url' => self::TOKEN_URL,
        'scan_client_id' => 'mcp-scan',
        'scan_client_secret' => self::SCAN_SECRET,
        'scan_scope' => 'mcp',
    ];

    /** @var list<array<string, mixed>> records of FakeHttpClient */
    private array $tokenRequests = [];

    private FakeHttpClient $tokenEndpoint;

    protected function setUp(): void
    {
        $this->tokenEndpoint = new FakeHttpClient()->recordInto($this->tokenRequests);
    }

    /**
     * @return iterable<string, array{McpTransportContext|null}>
     */
    public static function serviceAccountContexts(): iterable
    {
        yield 'scan' => [McpTransportContext::scan()];
        yield 'health' => [McpTransportContext::health()];
        yield 'turn without JWT' => [McpTransportContext::turn(null)];
        yield 'turn with empty JWT' => [McpTransportContext::turn('')];
        yield 'no context' => [null];
    }

    #[DataProvider('serviceAccountContexts')]
    public function testScanServiceAccountWithoutAPersonsJwt(?McpTransportContext $context): void
    {
        $transport = $this->factory()->create(self::CONFIG + ['client_id' => 'ignored'], $context);

        self::assertInstanceOf(TokenAuthenticatedStreamableHttpTransport::class, $transport);
        self::assertSame([], $this->tokenRequests, 'create() performs no I/O');

        $this->tokenEndpoint->queue(new HttpResponse(200, json_encode(['access_token' => 'scan-token', 'expires_in' => 300, 'token_type' => 'Bearer'], \JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json']));
        self::assertSame('Bearer scan-token', self::authHeaders($transport)['Authorization']);

        self::assertCount(1, $this->tokenRequests);
        self::assertSame(self::TOKEN_URL, $this->tokenRequests[0]['url']);
        parse_str((string) $this->tokenRequests[0]['body'], $form);
        self::assertSame(['grant_type' => 'client_credentials', 'client_id' => 'mcp-scan', 'client_secret' => self::SCAN_SECRET, 'scope' => 'mcp'], $form);
    }

    public function testTurnWithJwtSendsTheJwtAsBearer(): void
    {
        $transport = $this->factory()->create(self::CONFIG + ['timeout' => 20], McpTransportContext::turn(self::JWT));

        self::assertInstanceOf(StreamableHttpTransport::class, $transport);
        self::assertNotInstanceOf(TokenAuthenticatedStreamableHttpTransport::class, $transport);
        self::assertSame(['Authorization' => 'Bearer '.self::JWT], self::authHeaders($transport));
        self::assertSame([], $this->tokenRequests, 'the scan service account is not asked');
    }

    public function testOtherAuthTypesIgnoreTheJwt(): void
    {
        $transport = $this->factory()->create(['url' => self::MCP_URL, 'auth_type' => 'none'], McpTransportContext::turn(self::JWT));

        self::assertSame([], self::authHeaders($transport));
    }

    public function testTransportConfigCarriesNoCredentials(): void
    {
        self::assertSame(['url' => self::MCP_URL], $this->factory()->transportConfig(self::CONFIG));
    }

    /**
     * @return iterable<string, array{McpTransportContext|null}>
     */
    public static function allContexts(): iterable
    {
        yield from self::serviceAccountContexts();
        yield 'turn with JWT' => [McpTransportContext::turn(self::JWT)];
    }

    #[DataProvider('allContexts')]
    public function testMissingScanCredentialsAreRejected(?McpTransportContext $context): void
    {
        foreach (['scan_token_url', 'scan_client_id', 'scan_client_secret'] as $key) {
            $config = self::CONFIG + ['token_url' => self::TOKEN_URL, 'client_id' => 'a', 'client_secret' => 'b'];
            unset($config[$key]);
            try {
                $this->factory()->create($config, $context);
                self::fail('InvalidArgumentException expected without '.$key);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('forward_jwt', $e->getMessage());
                self::assertStringContainsString($key, $e->getMessage());
                self::assertStringNotContainsString(self::SCAN_SECRET, $e->getMessage());
            }
        }
    }

    public function testContextKeepsTheJwtOutOfDumps(): void
    {
        $context = McpTransportContext::turn(self::JWT);

        self::assertTrue($context->forwardsJwt());
        self::assertSame(McpTransportContext::PURPOSE_TURN, $context->purpose);
        self::assertStringNotContainsString(self::JWT, print_r($context, true));
        self::assertFalse(McpTransportContext::turn(null)->forwardsJwt());
        self::assertNull(McpTransportContext::turn('')->jwt);
        self::assertFalse(McpTransportContext::scan()->forwardsJwt());
        self::assertSame(McpTransportContext::PURPOSE_HEALTH, McpTransportContext::health()->purpose);
    }

    private function factory(): StreamableHttpTransportFactory
    {
        return new StreamableHttpTransportFactory(new ArrayAdapter(), $this->tokenEndpoint, static fn (): int => 1_000_000);
    }

    /**
     * The headers the transport would authenticate its next request with.
     *
     * @return array<string, string>
     */
    private static function authHeaders(McpTransportInterface $transport): array
    {
        return (new \ReflectionMethod($transport, 'getAuthHeaders'))->invoke($transport);
    }
}

// - revised 2026-10-14
