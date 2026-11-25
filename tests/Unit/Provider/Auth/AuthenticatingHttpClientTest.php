<?php

// file generated with AI assistance: Claude Code - 2026-10-06 00:15:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Provider\Auth;

use Dmstr\NeuronAiBundle\Provider\Auth\AuthenticatingHttpClient;
use Dmstr\NeuronAiBundle\Provider\Auth\OllamaHttpClientFactory;
use Dmstr\NeuronAiBundle\Provider\Auth\ProviderAuthenticationException;
use Dmstr\NeuronAiBundle\Testing\FakeHttpClient;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Kernel-free checks of OllamaHttpClientFactory and the decorator it
 * returns: which auth_type gets which client, the Authorization header on
 * request() and stream(), the single retry after 401/403 for client
 * credentials, no retry for a static token, other errors keeping their
 * type without the token anywhere in the exception tree, and redaction of
 * the resulting ProviderAuthenticationException. Both the
 * token endpoint and the Ollama proxy are FakeHttpClients, which throw
 * Neuron's HttpException for status >= 400 like the production client.
 */
final class AuthenticatingHttpClientTest extends TestCase
{
    private const string SECRET = 'cc-secret-placeholder-4711';
    private const string BEARER = 'static-bearer-placeholder-42';
    private const string BASE = 'https://ollama.example.test/api';

    /** @var list<array<string, mixed>> records of FakeHttpClient */
    private array $tokenCalls = [];

    /** @var list<array<string, mixed>> records of FakeHttpClient */
    private array $upstreamCalls = [];

    private FakeHttpClient $tokenEndpoint;

    private int $time = 1_000_000;

    protected function setUp(): void
    {
        $this->tokenEndpoint = new FakeHttpClient()->recordInto($this->tokenCalls);
    }

    public function testNoneAndMissingAuthTypeReturnTheInnerClient(): void
    {
        $inner = $this->upstream([new HttpResponse(200, '{}')]);

        self::assertSame($inner, $this->factory()->for(['url' => self::BASE], $inner, 'plain'));
        self::assertSame($inner, $this->factory()->for(['url' => self::BASE, 'auth_type' => 'none'], $inner, 'plain'));

        $inner->request(HttpRequest::get(self::BASE.'/tags'));
        self::assertNull(FakeHttpClient::headerOf($this->upstreamCalls[0], 'Authorization'));
        self::assertCount(0, $this->tokenCalls);
    }

    public function testBearerSendsTheStaticToken(): void
    {
        $client = $this->factory()->for(['auth_type' => 'bearer', 'token' => self::BEARER], $this->upstream([new HttpResponse(200, '{}')]), 'static');

        self::assertInstanceOf(AuthenticatingHttpClient::class, $client);
        $client->request(HttpRequest::get(self::BASE.'/tags'));

        self::assertSame('Bearer '.self::BEARER, FakeHttpClient::headerOf($this->upstreamCalls[0], 'Authorization'));
        self::assertSame(self::BASE.'/tags', $this->upstreamCalls[0]['url']);
        self::assertCount(0, $this->tokenCalls);
    }

    public function testClientCredentialsRequestCarriesTheFetchedToken(): void
    {
        $this->tokenEndpoint->queue(self::tokenResponse('tok-1'));
        $client = $this->clientCredentials([new HttpResponse(200, '{"message":{"content":"hi"}}')]);

        $request = HttpRequest::post(self::BASE.'/chat', ['model' => 'llama3.2'], ['X-Trace' => 'abc']);
        $response = $client->request($request);

        self::assertSame(200, $response->statusCode);
        self::assertSame('Bearer tok-1', FakeHttpClient::headerOf($this->upstreamCalls[0], 'Authorization'));
        self::assertSame('abc', FakeHttpClient::headerOf($this->upstreamCalls[0], 'X-Trace'));
        // Never on the caller's HttpRequest, which Neuron's HttpException carries.
        self::assertSame(['X-Trace' => 'abc'], $request->headers);
        self::assertNotSame($request, $this->upstreamCalls[0]['request']);
    }

    public function testStreamCarriesTheFetchedToken(): void
    {
        $this->tokenEndpoint->queue(self::tokenResponse('tok-1'));
        $client = $this->clientCredentials([FakeHttpClient::chunked(["{\"done\":false}\n{\"do", "ne\":true}\n"])]);

        $stream = $client->stream(HttpRequest::post(self::BASE.'/chat', ['stream' => true]));

        self::assertSame("{\"done\":false}\n", $stream->readLine());
        self::assertSame("{\"done\":true}\n", $stream->readLine());
        self::assertSame('Bearer tok-1', FakeHttpClient::headerOf($this->upstreamCalls[0], 'Authorization'));
        self::assertTrue($this->upstreamCalls[0]['stream']);
    }

    public function testTokenIsResolvedOnEveryCall(): void
    {
        $this->tokenEndpoint->queue(self::tokenResponse('tok-1', 300), self::tokenResponse('tok-2', 300));
        $client = $this->clientCredentials([new HttpResponse(200, '{}'), new HttpResponse(200, '{}'), new HttpResponse(200, '{}')]);

        $client->request(HttpRequest::get(self::BASE.'/tags'));
        $client->request(HttpRequest::get(self::BASE.'/tags'));
        self::assertCount(1, $this->tokenCalls);

        $this->time += 270; // the long-lived provider outlives the token
        $client->request(HttpRequest::get(self::BASE.'/tags'));

        self::assertCount(2, $this->tokenCalls);
        self::assertSame('Bearer tok-1', FakeHttpClient::headerOf($this->upstreamCalls[1], 'Authorization'));
        self::assertSame('Bearer tok-2', FakeHttpClient::headerOf($this->upstreamCalls[2], 'Authorization'));
    }

    public function testRejectedTwiceFailsAfterExactlyOneRetry(): void
    {
        $this->tokenEndpoint->queue(self::tokenResponse('tok-1'), self::tokenResponse('tok-2'));
        $client = $this->clientCredentials([
            new HttpResponse(403, 'proxy body: forbidden for tok-1'),
            new HttpResponse(403, 'proxy body: forbidden for tok-2'),
        ]);

        try {
            $client->request(HttpRequest::post(self::BASE.'/chat', ['model' => 'llama3.2']));
            self::fail('ProviderAuthenticationException expected');
        } catch (ProviderAuthenticationException $e) {
            self::assertSame(403, $e->statusCode);
            self::assertSame('ollama-sso', $e->connectionName);
            self::assertStringContainsString('"ollama-sso"', $e->getMessage());
            self::assertStringContainsString('403', $e->getMessage());
            self::assertStringNotContainsString('tok-', $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            self::assertStringNotContainsString('proxy body', $e->getMessage());
            self::assertNull($e->getPrevious());
        }

        self::assertCount(2, $this->upstreamCalls);
        self::assertCount(2, $this->tokenCalls);
        self::assertSame('Bearer tok-1', FakeHttpClient::headerOf($this->upstreamCalls[0], 'Authorization'));
        self::assertSame('Bearer tok-2', FakeHttpClient::headerOf($this->upstreamCalls[1], 'Authorization'));
    }

    public function testRejectedOnceSucceedsWithOneRetry(): void
    {
        $this->tokenEndpoint->queue(self::tokenResponse('tok-1'), self::tokenResponse('tok-2'));
        $client = $this->clientCredentials([new HttpResponse(401, 'expired'), new HttpResponse(200, '{"ok":true}')]);

        $response = $client->request(HttpRequest::get(self::BASE.'/tags'));

        self::assertSame('{"ok":true}', $response->body);
        self::assertCount(2, $this->upstreamCalls);
        self::assertCount(2, $this->tokenCalls);
        self::assertSame('Bearer tok-2', FakeHttpClient::headerOf($this->upstreamCalls[1], 'Authorization'));
    }

    public function testStreamRejectedBeforeReturningIsRetriedOnce(): void
    {
        $this->tokenEndpoint->queue(self::tokenResponse('tok-1'), self::tokenResponse('tok-2'));
        $client = $this->clientCredentials([new HttpResponse(403, 'nope'), new HttpResponse(200, "{\"done\":true}\n")]);

        $stream = $client->stream(HttpRequest::post(self::BASE.'/chat', ['stream' => true]));

        self::assertSame("{\"done\":true}\n", $stream->readLine());
        self::assertCount(2, $this->upstreamCalls);
        self::assertCount(2, $this->tokenCalls);
    }

    public function testRejectedBearerIsNotRetried(): void
    {
        $client = $this->factory()->for(['auth_type' => 'bearer', 'token' => self::BEARER], $this->upstream([new HttpResponse(401, 'nope'), new HttpResponse(200, '{}')]), 'static');

        try {
            $client->request(HttpRequest::get(self::BASE.'/tags'));
            self::fail('ProviderAuthenticationException expected');
        } catch (ProviderAuthenticationException $e) {
            self::assertSame(401, $e->statusCode);
            self::assertStringContainsString('bearer token', $e->getMessage());
            self::assertStringNotContainsString(self::BEARER, $e->getMessage());
            self::assertNull($e->getPrevious());
        }
        self::assertCount(1, $this->upstreamCalls);
    }

    /**
     * @return iterable<string, array{string, \Closure(string): (HttpResponse|\Closure)}>
     */
    public static function upstreamFailures(): iterable
    {
        $serverError = static fn (string $token): HttpResponse => new HttpResponse(500, 'model crashed, request had Authorization: Bearer '.$token);
        // The transport error names the header, and the HttpException carries
        // the request the inner client received, which holds it.
        $networkError = static fn (string $token): \Closure => FakeHttpClient::networkError('cURL error 28: timeout with header Authorization: Bearer '.$token);

        foreach (['bearer' => self::BEARER, 'client credentials' => 'tok-1'] as $auth => $token) {
            yield $auth.', HTTP 500' => [$auth, $serverError];
            yield $auth.', network error' => [$auth, $networkError];
        }
    }

    /**
     * Criterion of the feature: the token is in no exception object the
     * turn's failure logging can reach. The failure keeps Neuron's
     * HttpException type and status, and carries the caller's request
     * (the one the inner client received holds the header).
     *
     * @param \Closure(string): (HttpResponse|\Closure) $failure
     */
    #[DataProvider('upstreamFailures')]
    public function testOtherErrorsKeepTheirTypeWithoutTheToken(string $auth, \Closure $failure): void
    {
        $token = 'bearer' === $auth ? self::BEARER : 'tok-1';
        $this->tokenEndpoint->queue(self::tokenResponse('tok-1'));
        $client = 'bearer' === $auth
            ? $this->factory()->for(['auth_type' => 'bearer', 'token' => self::BEARER], $this->upstream([$failure($token)]), 'static')
            : $this->clientCredentials([$failure($token)]);
        $request = HttpRequest::post(self::BASE.'/chat', ['model' => 'llama3.2']);

        try {
            $client->request($request);
            self::fail('HttpException expected');
        } catch (HttpException $e) {
            self::assertSame(HttpException::class, $e::class);
            self::assertSame($request, $e->request);
            if ($failure($token) instanceof HttpResponse) {
                self::assertSame(500, $e->response?->statusCode);
                self::assertStringContainsString('model crashed', $e->response->body);
                self::assertStringContainsString('HTTP 500', $e->getMessage());
            } else {
                self::assertNull($e->response);
                self::assertStringContainsString('cURL error 28', $e->getMessage());
            }
            self::assertNull($e->getPrevious());
            self::assertTokenNowhere($token, $e);
        }
        // The token really was sent, so the check above could have failed.
        self::assertSame('Bearer '.$token, FakeHttpClient::headerOf($this->upstreamCalls[0], 'Authorization'));
        self::assertCount(1, $this->upstreamCalls);
    }

    public function testWithoutTheDecoratorNeuronChainsTheHeader(): void
    {
        // Counter-check of the leak test: Neuron's own exception does carry
        // the header, on the request it holds.
        $inner = $this->upstream([new HttpResponse(500, 'model crashed')]);

        try {
            $inner->request(HttpRequest::get(self::BASE.'/tags', ['Authorization' => 'Bearer '.self::BEARER]));
            self::fail('HttpException expected');
        } catch (HttpException $e) {
            self::assertStringContainsString(self::BEARER, self::dump($e));
        }
    }

    public function testOtherErrorsPassThroughUnchanged(): void
    {
        $this->tokenEndpoint->queue(self::tokenResponse('tok-1'));
        $client = $this->clientCredentials([new HttpResponse(500, 'model crashed')]);

        try {
            $client->request(HttpRequest::get(self::BASE.'/tags'));
            self::fail('HttpException expected');
        } catch (HttpException $e) {
            self::assertNotInstanceOf(ProviderAuthenticationException::class, $e);
            self::assertSame(500, $e->response?->statusCode);
        }
        self::assertCount(1, $this->upstreamCalls);
        self::assertCount(1, $this->tokenCalls);
    }

    public function testTokenEndpointFailureStopsBeforeTheProvider(): void
    {
        $this->tokenEndpoint->queue(new HttpResponse(401, '{"error":"unauthorized_client","error_description":"Invalid client secret"}'));
        $client = $this->clientCredentials([new HttpResponse(200, '{}')]);

        try {
            $client->request(HttpRequest::get(self::BASE.'/tags'));
            self::fail('ProviderAuthenticationException expected');
        } catch (ProviderAuthenticationException $e) {
            self::assertStringContainsString('unauthorized_client', $e->getMessage());
            self::assertStringContainsString('Invalid client secret', $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
        self::assertCount(0, $this->upstreamCalls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lineBreakTokens(): iterable
    {
        yield 'LF' => ["abc\nX-Evil: 1"];
        yield 'CR' => ["abc\rX-Evil: 1"];
        yield 'CRLF' => ["abc\r\nX-Evil: 1"];
    }

    #[DataProvider('lineBreakTokens')]
    public function testBearerWithLineBreakIsRejectedWithoutRequest(string $token): void
    {
        $inner = new FakeHttpClient([new HttpResponse(200, '{}')]);
        try {
            $this->factory()->for(['auth_type' => 'bearer', 'token' => $token], $inner, 'evil')
                ->request(HttpRequest::get(self::BASE.'/tags'));
            self::fail('ProviderAuthenticationException expected');
        } catch (ProviderAuthenticationException $e) {
            self::assertStringNotContainsString('X-Evil', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
        // The queued response is still there: nothing was sent.
        self::assertSame(1, $inner->remaining());
        self::assertSame([], $inner->sent());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unusableConfigs(): iterable
    {
        yield 'unknown auth_type' => [['auth_type' => 'basic']];
        yield 'bearer without token' => [['auth_type' => 'bearer']];
        yield 'client_credentials without secret' => [['auth_type' => 'client_credentials', 'token_url' => 'https://sso.example.test/token', 'client_id' => 'x']];
        yield 'client_credentials without token_url' => [['auth_type' => 'client_credentials', 'client_id' => 'x', 'client_secret' => self::SECRET]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('unusableConfigs')]
    public function testUnusableConfigurationFailsInTheFactory(array $config): void
    {
        try {
            $this->factory()->for($config, $this->upstream([]), 'broken');
            self::fail('ProviderAuthenticationException expected');
        } catch (ProviderAuthenticationException $e) {
            self::assertNull($e->statusCode);
            self::assertSame('broken', $e->connectionName);
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
    }

    public function testRequestSettingsPassThroughAndTheTokenWins(): void
    {
        // neuron-ai 4.x clients are not configured after construction: the
        // decorator has no with*() methods, URL, timeout and headers travel
        // on the request, and a caller's Authorization header (any case) is
        // replaced by the token.
        self::assertFalse(method_exists(AuthenticatingHttpClient::class, 'withBaseUri'));
        self::assertFalse(method_exists(AuthenticatingHttpClient::class, 'withHeaders'));
        self::assertFalse(method_exists(AuthenticatingHttpClient::class, 'withTimeout'));

        $this->tokenEndpoint->queue(self::tokenResponse('tok-1'));
        $client = $this->clientCredentials([new HttpResponse(200, '{}')]);

        $client->request(new HttpRequest(HttpMethod::GET, self::BASE.'/tags', ['authorization' => 'Bearer stale', 'X-Trace' => 'abc'], timeout: 30.0));

        $sent = $this->upstreamCalls[0];
        self::assertSame(self::BASE.'/tags', $sent['url']);
        self::assertSame(['X-Trace' => 'abc', 'Authorization' => 'Bearer tok-1'], $sent['headers']);
        self::assertSame(30.0, $sent['timeout']);
    }

    /**
     * @param list<HttpResponse|\Closure> $responses
     */
    private function clientCredentials(array $responses): HttpClientInterface
    {
        return $this->factory()->for($this->ccConfig(), $this->upstream($responses), 'ollama-sso');
    }

    /**
     * @return array<string, mixed>
     */
    private function ccConfig(): array
    {
        return [
            'url' => self::BASE,
            'auth_type' => 'client_credentials',
            'token_url' => 'https://sso.example.test/realms/ai/protocol/openid-connect/token',
            'client_id' => 'neuron-ai-client',
            'client_secret' => self::SECRET,
        ];
    }

    private function factory(): OllamaHttpClientFactory
    {
        return new OllamaHttpClientFactory(new ArrayAdapter(), $this->tokenEndpoint, fn (): int => $this->time);
    }

    /**
     * @param list<HttpResponse|\Closure> $responses
     */
    private function upstream(array $responses): FakeHttpClient
    {
        return new FakeHttpClient($responses)->recordInto($this->upstreamCalls);
    }

    /**
     * Messages, properties and the `previous` chain of $e, plus the
     * arguments of every bundle frame in its trace, must not contain
     * $token. Trace frames of PHPUnit are left out: they hold the test case
     * with its request history.
     */
    private static function assertTokenNowhere(string $token, \Throwable $e): void
    {
        $dump = self::dump($e);
        self::assertStringNotContainsString($token, $dump);
        self::assertStringContainsString('HttpException', $dump, 'the dump covers the exception');
    }

    private static function dump(\Throwable $e): string
    {
        $parts = [];
        for ($current = $e; null !== $current; $current = $current->getPrevious()) {
            $parts[] = $current::class.': '.$current->getMessage();
            foreach ((array) $current as $key => $value) {
                if (!str_ends_with((string) $key, 'trace') && !str_ends_with((string) $key, 'previous')) {
                    $parts[] = self::flatten($value, 4);
                }
            }
            foreach ($current->getTrace() as $frame) {
                if (str_starts_with($frame['class'] ?? '', 'Dmstr\\') || str_starts_with($frame['class'] ?? '', 'NeuronAI\\')) {
                    $parts[] = self::flatten($frame['args'] ?? [], 4);
                }
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Every string reachable from $value up to $depth levels, objects by
     * their properties (private ones included).
     */
    private static function flatten(mixed $value, int $depth, array &$seen = []): string
    {
        if (\is_scalar($value) || null === $value) {
            return (string) $value;
        }
        if ($depth <= 0) {
            return '';
        }
        if (\is_object($value)) {
            if (isset($seen[spl_object_id($value)]) || $value instanceof \Closure && [] === new \ReflectionFunction($value)->getClosureUsedVariables()) {
                return '';
            }
            $seen[spl_object_id($value)] = true;
            $value = $value instanceof \Closure ? new \ReflectionFunction($value)->getClosureUsedVariables() : (array) $value;
        }
        if (!\is_array($value)) {
            return '';
        }
        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = $key.'='.self::flatten($item, $depth - 1, $seen);
        }

        return implode(' ', $parts);
    }

    private static function tokenResponse(string $token, int $expiresIn = 300): HttpResponse
    {
        return new HttpResponse(200, json_encode(['access_token' => $token, 'expires_in' => $expiresIn], \JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json']);
    }
}

// - revised 2026-10-14
