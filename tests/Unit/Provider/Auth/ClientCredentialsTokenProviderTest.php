<?php

// file generated with AI assistance: Claude Code - 2026-10-06 00:15:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Provider\Auth;

use Dmstr\NeuronAiBundle\Provider\Auth\ClientCredentialsTokenProvider;
use Dmstr\NeuronAiBundle\Provider\Auth\ProviderAuthenticationException;
use Dmstr\NeuronAiBundle\Testing\FakeHttpClient;
use NeuronAI\HttpClient\HttpResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Kernel-free checks of the client credentials token source: request shape,
 * caching under the hashed key, expiry by the injected clock, validation of
 * the token response, redaction of failures and degradation on a broken
 * cache. Token endpoint: a FakeHttpClient.
 */
final class ClientCredentialsTokenProviderTest extends TestCase
{
    private const string TOKEN_URL = 'https://sso.example.test/realms/ai/protocol/openid-connect/token';
    private const string CLIENT_ID = 'neuron-ai-client';
    private const string SECRET = 'cc-secret-placeholder-4711';

    /** @var list<array<string, mixed>> records of FakeHttpClient */
    private array $history = [];

    private int $time = 1_000_000;

    public function testTokenRequestShapeAndTimeout(): void
    {
        $provider = $this->provider(new ArrayAdapter(), [self::tokenResponse('tok-1')]);

        self::assertSame('tok-1', $provider->token());
        self::assertCount(1, $this->history);
        $request = $this->history[0];
        self::assertSame('POST', $request['method']);
        self::assertSame(self::TOKEN_URL, $request['url']);
        self::assertSame('application/x-www-form-urlencoded', FakeHttpClient::headerOf($request, 'Content-Type'));
        parse_str((string) $request['body'], $form);
        self::assertSame(['grant_type' => 'client_credentials', 'client_id' => self::CLIENT_ID, 'client_secret' => self::SECRET], $form);
        // On the request: the shared token client is never reconfigured.
        self::assertSame(5.0, $request['timeout']);
        self::assertSame(ClientCredentialsTokenProvider::TIMEOUT, $request['request']->timeout);
    }

    public function testScopeIsSentWhenSetAndChangesTheKey(): void
    {
        $provider = $this->provider(new ArrayAdapter(), [self::tokenResponse('tok-1')], scope: 'ollama');

        $provider->token();
        parse_str((string) $this->history[0]['body'], $form);
        self::assertSame('ollama', $form['scope']);
        self::assertNotSame(
            ClientCredentialsTokenProvider::cacheKey(self::TOKEN_URL, self::CLIENT_ID, self::SECRET),
            ClientCredentialsTokenProvider::cacheKey(self::TOKEN_URL, self::CLIENT_ID, self::SECRET, 'ollama'),
        );
    }

    public function testCacheKeyIsTheHashOfTheInputs(): void
    {
        self::assertSame(
            'neuron_ollama_token_'.hash('sha256', self::TOKEN_URL."\0".self::CLIENT_ID."\0".self::SECRET),
            ClientCredentialsTokenProvider::cacheKey(self::TOKEN_URL, self::CLIENT_ID, self::SECRET),
        );
        self::assertSame(
            'neuron_ollama_token_'.hash('sha256', self::TOKEN_URL."\0".self::CLIENT_ID."\0".self::SECRET."\0".'a b'),
            ClientCredentialsTokenProvider::cacheKey(self::TOKEN_URL, self::CLIENT_ID, self::SECRET, 'a b'),
        );
    }

    public function testKeyPrefixSeparatesConnectionKindsInTheSharedPool(): void
    {
        $mcpKey = ClientCredentialsTokenProvider::cacheKey(self::TOKEN_URL, self::CLIENT_ID, self::SECRET, null, ClientCredentialsTokenProvider::MCP_KEY_PREFIX);
        self::assertSame('neuron_mcp_token_'.hash('sha256', self::TOKEN_URL."\0".self::CLIENT_ID."\0".self::SECRET), $mcpKey);
        self::assertSame(
            ClientCredentialsTokenProvider::cacheKey(self::TOKEN_URL, self::CLIENT_ID, self::SECRET),
            ClientCredentialsTokenProvider::cacheKey(self::TOKEN_URL, self::CLIENT_ID, self::SECRET, null, ClientCredentialsTokenProvider::OLLAMA_KEY_PREFIX),
        );

        $cache = new ArrayAdapter();
        self::assertSame('tok-mcp', $this->provider($cache, [self::tokenResponse('tok-mcp')], keyPrefix: ClientCredentialsTokenProvider::MCP_KEY_PREFIX, kind: 'MCP')->token());
        self::assertTrue($cache->hasItem($mcpKey));
        self::assertFalse($cache->hasItem(ClientCredentialsTokenProvider::cacheKey(self::TOKEN_URL, self::CLIENT_ID, self::SECRET)));

        // Same credentials, other kind: its own entry, its own token request.
        self::assertSame('tok-ollama', $this->provider($cache, [self::tokenResponse('tok-ollama')])->token());
        self::assertSame('tok-mcp', $this->provider($cache, [], keyPrefix: ClientCredentialsTokenProvider::MCP_KEY_PREFIX, kind: 'MCP')->token());
        self::assertCount(2, $this->history);
    }

    public function testMessagesAndLogsNameTheConnectionKind(): void
    {
        $logger = self::collectingLogger();
        $this->provider(new ArrayAdapter(), [self::tokenResponse('tok-1')], logger: $logger, keyPrefix: ClientCredentialsTokenProvider::MCP_KEY_PREFIX, kind: 'MCP')->token();
        self::assertSame('MCP', $logger->records[0][2]['kind']);

        try {
            $this->provider(new ArrayAdapter(), [new HttpResponse(401, '{"error":"unauthorized_client"}')], keyPrefix: ClientCredentialsTokenProvider::MCP_KEY_PREFIX, kind: 'MCP')->token();
            self::fail('ProviderAuthenticationException expected');
        } catch (ProviderAuthenticationException $e) {
            self::assertStringStartsWith('Token endpoint of MCP connection "ollama-sso" answered HTTP 401', $e->getMessage());
        }

        // The default stays the Ollama wording.
        try {
            $this->provider(new ArrayAdapter(), [new HttpResponse(401, '{"error":"unauthorized_client"}')])->token();
            self::fail('ProviderAuthenticationException expected');
        } catch (ProviderAuthenticationException $e) {
            self::assertStringStartsWith('Token endpoint of Ollama connection "ollama-sso" answered HTTP 401', $e->getMessage());
        }
    }

    public function testSecondCallWithinTtlIsACacheHit(): void
    {
        $cache = new ArrayAdapter();
        $provider = $this->provider($cache, [self::tokenResponse('tok-1')]);

        self::assertSame('tok-1', $provider->token());
        self::assertSame('tok-1', $provider->token());
        // A second instance on the same pool (another provider object) as well.
        self::assertSame('tok-1', $this->provider($cache, [])->token());
        self::assertCount(1, $this->history);
    }

    public function testTokenIsRenewedAfterExpiresInMinusMargin(): void
    {
        $provider = $this->provider(new ArrayAdapter(), [self::tokenResponse('tok-1', 300), self::tokenResponse('tok-2', 300)]);

        self::assertSame('tok-1', $provider->token());
        $this->time += 269;
        self::assertSame('tok-1', $provider->token());
        self::assertCount(1, $this->history);

        $this->time += 1; // 300 - 30 s reached
        self::assertSame('tok-2', $provider->token());
        self::assertCount(2, $this->history);
    }

    public function testChangedSecretUsesANewKey(): void
    {
        $cache = new ArrayAdapter();
        self::assertSame('tok-1', $this->provider($cache, [self::tokenResponse('tok-1')])->token());
        self::assertSame('tok-2', $this->provider($cache, [self::tokenResponse('tok-2')], secret: 'rotated-secret-0815')->token());
        self::assertCount(2, $this->history);
    }

    public function testExpiresInAsNumericStringAndMinimumTtl(): void
    {
        $provider = $this->provider(new ArrayAdapter(), [
            new HttpResponse(200, '{"access_token":"tok-1","expires_in":"10"}', ['Content-Type' => 'application/json']),
            self::tokenResponse('tok-2'),
        ]);

        self::assertSame('tok-1', $provider->token());
        // 10 - 30 < 1: the entry lives 1 s.
        self::assertSame('tok-1', $provider->token());
        $this->time += 1;
        self::assertSame('tok-2', $provider->token());
    }

    public function testInvalidateForcesANewToken(): void
    {
        $provider = $this->provider(new ArrayAdapter(), [self::tokenResponse('tok-1'), self::tokenResponse('tok-2')]);

        self::assertSame('tok-1', $provider->token());
        self::assertTrue($provider->invalidate());
        self::assertSame('tok-2', $provider->token());
        self::assertSame('tok-2', $provider->token());
        self::assertCount(2, $this->history);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableTokenResponses(): iterable
    {
        yield 'expires_in 0' => ['{"access_token":"zz-leaked","expires_in":0}'];
        yield 'expires_in negative' => ['{"access_token":"zz-leaked","expires_in":-5}'];
        yield 'expires_in missing' => ['{"access_token":"zz-leaked"}'];
        yield 'expires_in non-numeric' => ['{"access_token":"zz-leaked","expires_in":"soon"}'];
        yield 'access_token missing' => ['{"expires_in":300}'];
        yield 'access_token empty' => ['{"access_token":"","expires_in":300}'];
        yield 'access_token with line break' => ['{"access_token":"zz-leaked\r\nX-Evil: 1","expires_in":300}'];
        yield 'malformed JSON' => ['{"access_token":'];
        yield 'JSON scalar' => ['"zz-leaked"'];
    }

    #[DataProvider('unusableTokenResponses')]
    public function testUnusableTokenResponseFailsAndCachesNothing(string $body): void
    {
        $cache = new ArrayAdapter();
        $provider = $this->provider($cache, [new HttpResponse(200, $body, ['Content-Type' => 'application/json']), self::tokenResponse('tok-ok')]);

        try {
            $provider->token();
            self::fail('ProviderAuthenticationException expected');
        } catch (ProviderAuthenticationException $e) {
            self::assertSame(200, $e->statusCode);
            self::assertSame('ollama-sso', $e->connectionName);
            self::assertStringContainsString('ollama-sso', $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            self::assertStringNotContainsString('zz-leaked', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
        self::assertFalse($cache->hasItem(ClientCredentialsTokenProvider::cacheKey(self::TOKEN_URL, self::CLIENT_ID, self::SECRET)));

        // Nothing cached: the next call asks the endpoint again.
        self::assertSame('tok-ok', $provider->token());
        self::assertCount(2, $this->history);
    }

    public function testKeycloakErrorIsNamedWithoutTheSecret(): void
    {
        $body = json_encode([
            'error' => 'unauthorized_client',
            'error_description' => 'Invalid client or Invalid client credentials',
        ], \JSON_THROW_ON_ERROR);
        $provider = $this->provider(new ArrayAdapter(), [new HttpResponse(401, $body, ['Content-Type' => 'application/json'])]);

        try {
            $provider->token();
            self::fail('ProviderAuthenticationException expected');
        } catch (ProviderAuthenticationException $e) {
            self::assertSame(401, $e->statusCode);
            self::assertStringContainsString('"ollama-sso"', $e->getMessage());
            self::assertStringContainsString('401', $e->getMessage());
            self::assertStringContainsString('unauthorized_client', $e->getMessage());
            self::assertStringContainsString('Invalid client or Invalid client credentials', $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            self::assertStringNotContainsString('client_secret', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
        self::assertCount(1, $this->history);
    }

    public function testKeycloakDetailIsCutAndAnEchoedSecretMasked(): void
    {
        $body = json_encode([
            'error' => 'invalid_client',
            'error_description' => 'secret '.self::SECRET.' rejected '.str_repeat('x', 400),
        ], \JSON_THROW_ON_ERROR);
        $provider = $this->provider(new ArrayAdapter(), [new HttpResponse(400, $body)]);

        try {
            $provider->token();
            self::fail('ProviderAuthenticationException expected');
        } catch (ProviderAuthenticationException $e) {
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            self::assertStringContainsString('invalid_client: secret *** rejected', $e->getMessage());
            self::assertLessThan(320, mb_strlen($e->getMessage()));
        }
    }

    public function testNonJsonErrorBodyIsNotQuoted(): void
    {
        $provider = $this->provider(new ArrayAdapter(), [new HttpResponse(502, '<html>proxy says '.self::SECRET.'</html>')]);

        try {
            $provider->token();
            self::fail('ProviderAuthenticationException expected');
        } catch (ProviderAuthenticationException $e) {
            self::assertSame(502, $e->statusCode);
            self::assertStringNotContainsString('proxy says', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testNetworkErrorFailsWithoutStatus(): void
    {
        $provider = $this->provider(new ArrayAdapter(), [FakeHttpClient::networkError('Could not resolve host')]);

        try {
            $provider->token();
            self::fail('ProviderAuthenticationException expected');
        } catch (ProviderAuthenticationException $e) {
            self::assertNull($e->statusCode);
            self::assertStringContainsString('unreachable', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testThrowingCacheDegradesToAnUncachedToken(): void
    {
        $logger = self::collectingLogger();
        $provider = $this->provider(self::brokenCache(), [self::tokenResponse('tok-1'), self::tokenResponse('tok-2')], logger: $logger);

        self::assertSame('tok-1', $provider->token());
        self::assertTrue($provider->invalidate());
        self::assertSame('tok-2', $provider->token());
        self::assertCount(2, $this->history);
        self::assertNotEmpty(array_filter($logger->records, static fn (array $r): bool => 'warning' === $r[0]));
    }

    public function testTokenEndpointFailureIsNotMaskedByABrokenCache(): void
    {
        $provider = $this->provider(self::brokenCache(), [new HttpResponse(401, '{"error":"unauthorized_client"}')]);

        $this->expectException(ProviderAuthenticationException::class);
        $this->expectExceptionMessage('unauthorized_client');
        try {
            $provider->token();
        } finally {
            self::assertCount(1, $this->history);
        }
    }

    public function testLogsNeitherSecretNorToken(): void
    {
        $logger = self::collectingLogger();
        $this->provider(new ArrayAdapter(), [self::tokenResponse('tok-secret-value')], logger: $logger)->token();

        self::assertNotEmpty($logger->records);
        $dump = json_encode($logger->records, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::SECRET, $dump);
        self::assertStringNotContainsString('tok-secret-value', $dump);
        self::assertStringContainsString('ollama-sso', $dump);
    }

    /**
     * @param list<HttpResponse|\Throwable|\Closure> $responses
     */
    private function provider(CacheInterface $cache, array $responses, ?string $scope = null, string $secret = self::SECRET, ?\Psr\Log\LoggerInterface $logger = null, ?string $keyPrefix = null, ?string $kind = null): ClientCredentialsTokenProvider
    {
        return new ClientCredentialsTokenProvider(
            $cache,
            new FakeHttpClient($responses)->recordInto($this->history),
            self::TOKEN_URL,
            self::CLIENT_ID,
            $secret,
            $scope,
            'ollama-sso',
            fn (): int => $this->time,
            $logger,
            ...(null !== $keyPrefix ? ['keyPrefix' => $keyPrefix] : []),
            ...(null !== $kind ? ['connectionKind' => $kind] : []),
        );
    }

    private static function tokenResponse(string $token, int $expiresIn = 300): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'access_token' => $token,
            'expires_in' => $expiresIn,
            'token_type' => 'Bearer',
        ], \JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json']);
    }

    private static function brokenCache(): CacheInterface
    {
        return new class implements CacheInterface {
            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                throw new \RuntimeException('Redis is down');
            }

            public function delete(string $key): bool
            {
                throw new \RuntimeException('Redis is down');
            }
        };
    }

    /**
     * @return AbstractLogger&object{records: list<array{string, string, array<string, mixed>}>}
     */
    private static function collectingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{string, string, array<string, mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
    }
}

// - revised 2026-10-09 06:42:28 UTC
// - revised 2026-10-14
