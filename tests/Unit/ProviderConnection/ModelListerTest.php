<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\ProviderConnection;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Provider\AnthropicModelLister;
use Dmstr\NeuronAiBundle\Provider\Auth\OllamaHttpClientFactory;
use Dmstr\NeuronAiBundle\Provider\ModelListingException;
use Dmstr\NeuronAiBundle\Provider\OllamaModelLister;
use Dmstr\NeuronAiBundle\Provider\ProviderModelLister;
use Dmstr\NeuronAiBundle\Testing\ConfigSecretsFixture;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Testing\FakeHttpClient;
use NeuronAI\HttpClient\HttpResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Kernel-free checks of the live model listers against a FakeHttpClient:
 * request shape (URL, decrypted key header), name extraction, pagination
 * and the failure mapping, which never reveals a key. For authenticated
 * `ollama` connections the token endpoint is a second FakeHttpClient.
 */
final class ModelListerTest extends TestCase
{
    private const string KEY = 'sk-ant-secret-placeholder-4711';

    /** @var list<array<string, mixed>> records of FakeHttpClient */
    private array $history = [];

    /** @var list<array<string, mixed>> records of FakeHttpClient */
    private array $tokenCalls = [];

    public function testOllamaListsModelNamesFromTags(): void
    {
        $lister = new OllamaModelLister(ConfigSecretsFixture::create(), $this->client([
            new HttpResponse(200, json_encode(['models' => [['name' => 'llama3.2:latest', 'model' => 'llama3.2:latest'], ['name' => 'nomic-embed-text:latest'], ['model' => 'qwen3:8b'], ['size' => 1]]], \JSON_THROW_ON_ERROR)),
        ]));

        $models = $lister->listModels(ProviderConnectionFixtures::ollama('local', url: 'http://ollama:11434/api/'));

        self::assertSame(['llama3.2:latest', 'nomic-embed-text:latest', 'qwen3:8b'], $models);
        self::assertSame('http://ollama:11434/api/tags', $this->history[0]['url']);
        self::assertSame('GET', $this->history[0]['method']);
    }

    public function testAnthropicSendsTheDecryptedKeyAndFollowsPages(): void
    {
        $secrets = ConfigSecretsFixture::create();
        $connection = ProviderConnectionFixtures::anthropic('claude', apiKey: self::KEY);
        // As stored since dmstr/api-configuration-bundle 0.5.
        $connection->setConfigJson($secrets->encrypt($connection->getConfigJson()));

        $lister = new AnthropicModelLister($secrets, $this->client([
            new HttpResponse(200, json_encode(['data' => [['id' => 'claude-haiku-4-5-20251001']], 'has_more' => true, 'last_id' => 'claude-haiku-4-5-20251001'], \JSON_THROW_ON_ERROR)),
            new HttpResponse(200, json_encode(['data' => [['id' => 'claude-sonnet-4-5']], 'has_more' => false, 'last_id' => 'claude-sonnet-4-5'], \JSON_THROW_ON_ERROR)),
        ]));

        self::assertSame(['claude-haiku-4-5-20251001', 'claude-sonnet-4-5'], $lister->listModels($connection));
        self::assertCount(2, $this->history);
        $first = $this->history[0];
        self::assertSame('https://api.anthropic.com/v1/models?limit=1000', $first['url']);
        self::assertSame(self::KEY, FakeHttpClient::headerOf($first, 'x-api-key'));
        self::assertSame(AnthropicModelLister::API_VERSION, FakeHttpClient::headerOf($first, 'anthropic-version'));
        self::assertStringContainsString('after_id=claude-haiku-4-5-20251001', $this->history[1]['url']);
    }

    public function testAnthropicUsesTheConfiguredBaseUrl(): void
    {
        $lister = new AnthropicModelLister(ConfigSecretsFixture::create(), $this->client([
            new HttpResponse(200, '{"data": [], "has_more": false}'),
        ]));

        self::assertSame([], $lister->listModels(ProviderConnectionFixtures::anthropic('proxy', options: ['base_url' => 'https://proxy.example.org/anthropic/v1'])));
        self::assertSame('https://proxy.example.org/anthropic/v1/models?limit=1000', $this->history[0]['url']);
    }

    public function testRejectedKeyIsUnauthenticatedWithoutTheKey(): void
    {
        $lister = new AnthropicModelLister(ConfigSecretsFixture::create(), $this->client([
            // A provider that echoes the key must not leak it either.
            new HttpResponse(401, json_encode(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key '.self::KEY]], \JSON_THROW_ON_ERROR)),
        ]));

        $e = $this->listingFailure(fn () => $lister->listModels(ProviderConnectionFixtures::anthropic('claude', apiKey: self::KEY)));

        self::assertSame(ModelListingException::UNAUTHENTICATED, $e->reason);
        self::assertSame(401, $e->statusCode);
        self::assertStringContainsString('rejected the credentials', $e->getMessage());
        self::assertStringContainsString('invalid x-api-key', $e->getMessage());
        self::assertStringNotContainsString(self::KEY, $e->getMessage());
        self::assertNull($e->getPrevious(), 'the transport exception carries the key header');
    }

    public function testNetworkErrorIsUnreachableWithoutUserInfo(): void
    {
        $lister = new OllamaModelLister(ConfigSecretsFixture::create(), $this->client([
            FakeHttpClient::networkError('cURL error 7: Failed to connect to user:pw@ollama.example.org port 443'),
        ]));

        $e = $this->listingFailure(fn () => $lister->listModels(ProviderConnectionFixtures::ollama('remote', url: 'https://user:pw@ollama.example.org/api')));

        self::assertSame(ModelListingException::UNREACHABLE, $e->reason);
        self::assertStringContainsString('"remote" is unreachable', $e->getMessage());
        self::assertStringContainsString('Failed to connect', $e->getMessage());
        self::assertStringNotContainsString('user:pw', $e->getMessage());
    }

    public function testServerErrorIsUnreachableAndOtherErrorsAreProtocol(): void
    {
        $lister = new OllamaModelLister(ConfigSecretsFixture::create(), $this->client([
            new HttpResponse(503, 'Service Unavailable'),
            new HttpResponse(404, '{"error": "page not found"}'),
            new HttpResponse(200, 'not json'),
            new HttpResponse(200, '{"items": []}'),
        ]));
        $connection = ProviderConnectionFixtures::ollama('local');

        self::assertSame(ModelListingException::UNREACHABLE, $this->listingFailure(fn () => $lister->listModels($connection))->reason);
        $notFound = $this->listingFailure(fn () => $lister->listModels($connection));
        self::assertSame(ModelListingException::PROTOCOL, $notFound->reason);
        self::assertStringContainsString('page not found', $notFound->getMessage());
        self::assertSame(ModelListingException::PROTOCOL, $this->listingFailure(fn () => $lister->listModels($connection))->reason);
        self::assertSame(ModelListingException::PROTOCOL, $this->listingFailure(fn () => $lister->listModels($connection))->reason);
    }

    public function testWrongTypesAreConfigurationErrors(): void
    {
        $secrets = ConfigSecretsFixture::create();
        $mcp = new ApiConfiguration()->setName('docs')->setConfigJson(['mcp' => true, 'type' => 'mcp', 'endpoint_type' => 'rest', 'url' => 'https://mcp.example.org/mcp']);

        $dispatching = new ProviderModelLister(new OllamaModelLister($secrets, $this->client([])), new AnthropicModelLister($secrets, $this->client([])));

        self::assertSame(ModelListingException::CONFIGURATION, $this->listingFailure(fn () => $dispatching->listModels($mcp))->reason);
        self::assertSame(ModelListingException::CONFIGURATION, $this->listingFailure(fn () => new OllamaModelLister($secrets, $this->client([]))->listModels(ProviderConnectionFixtures::anthropic()))->reason);
        self::assertSame([], $this->history, 'no request for an unusable connection');
    }

    public function testDispatchesByType(): void
    {
        $secrets = ConfigSecretsFixture::create();
        $dispatching = new ProviderModelLister(
            new OllamaModelLister($secrets, $this->client([new HttpResponse(200, '{"models": [{"name": "llama3.2:latest"}]}')])),
            new AnthropicModelLister($secrets, $this->client([new HttpResponse(200, '{"data": [{"id": "claude-haiku-4-5-20251001"}]}')])),
        );

        self::assertSame(['llama3.2:latest'], $dispatching->listModels(ProviderConnectionFixtures::ollama()));
        self::assertSame(['claude-haiku-4-5-20251001'], $dispatching->listModels(ProviderConnectionFixtures::anthropic()));
    }

    public function testOllamaBearerSendsTheToken(): void
    {
        $lister = $this->authLister([new HttpResponse(200, '{"models": [{"name": "llama3.2:latest"}]}')], []);

        self::assertSame(['llama3.2:latest'], $lister->listModels(ProviderConnectionFixtures::ollamaBearer('static')));
        self::assertSame(ProviderConnectionFixtures::OLLAMA_URL.'/tags', $this->history[0]['url']);
        self::assertSame('Bearer '.ProviderConnectionFixtures::PLACEHOLDER_TOKEN, FakeHttpClient::headerOf($this->history[0], 'Authorization'));
        self::assertCount(0, $this->tokenCalls);
    }

    public function testOllamaClientCredentialsSendsTheFetchedToken(): void
    {
        $secrets = ConfigSecretsFixture::create();
        $connection = ProviderConnectionFixtures::ollamaClientCredentials('sso');
        $connection->setConfigJson($secrets->encrypt($connection->getConfigJson()));
        $lister = $this->authLister([new HttpResponse(200, '{"models": [{"name": "llama3.2:latest"}]}')], [self::tokenResponse('cc-access-token-1')]);

        self::assertSame(['llama3.2:latest'], $lister->listModels($connection));
        self::assertSame('Bearer cc-access-token-1', FakeHttpClient::headerOf($this->history[0], 'Authorization'));
        self::assertCount(1, $this->tokenCalls);
        self::assertSame(ProviderConnectionFixtures::TOKEN_URL, $this->tokenCalls[0]['url']);
    }

    public function testNoHeaderStaysBehindForTheNextConnection(): void
    {
        // One lister instance serves every connection; Neuron's client
        // keeps headers set on it, so the token must not end up there.
        $lister = $this->authLister([new HttpResponse(200, '{"models": []}'), new HttpResponse(200, '{"models": []}')], []);

        $lister->listModels(ProviderConnectionFixtures::ollamaBearer('static'));
        $lister->listModels(ProviderConnectionFixtures::ollama('plain'));

        self::assertTrue(null !== FakeHttpClient::headerOf($this->history[0], 'Authorization'));
        self::assertFalse(null !== FakeHttpClient::headerOf($this->history[1], 'Authorization'));
    }

    public function testTokenEndpointFailureIsUnauthenticatedWithItsStatus(): void
    {
        $lister = $this->authLister([new HttpResponse(200, '{"models": []}')], [
            new HttpResponse(401, json_encode(['error' => 'unauthorized_client', 'error_description' => 'Invalid client secret '.ProviderConnectionFixtures::PLACEHOLDER_CLIENT_SECRET], \JSON_THROW_ON_ERROR)),
        ]);

        $e = $this->listingFailure(fn () => $lister->listModels(ProviderConnectionFixtures::ollamaClientCredentials('sso')));

        self::assertSame(ModelListingException::UNAUTHENTICATED, $e->reason);
        self::assertSame(401, $e->statusCode);
        self::assertStringContainsString('"sso" could not authenticate', $e->getMessage());
        self::assertStringContainsString('unauthorized_client', $e->getMessage());
        self::assertStringNotContainsString(ProviderConnectionFixtures::PLACEHOLDER_CLIENT_SECRET, $e->getMessage());
        self::assertNull($e->getPrevious());
        self::assertSame([], $this->history, 'no provider request without a token');
    }

    public function testUnreachableTokenEndpointIsUnauthenticatedWithoutStatus(): void
    {
        $lister = $this->authLister([], [FakeHttpClient::networkError('cURL error 7: Failed to connect')]);

        $e = $this->listingFailure(fn () => $lister->listModels(ProviderConnectionFixtures::ollamaClientCredentials('sso')));

        self::assertSame(ModelListingException::UNAUTHENTICATED, $e->reason);
        self::assertNull($e->statusCode);
    }

    /**
     * @return iterable<string, array{\Closure(): ApiConfiguration, list<HttpResponse>, int}>
     */
    public static function rejectedTokens(): iterable
    {
        yield 'bearer, 403' => [static fn (): ApiConfiguration => ProviderConnectionFixtures::ollamaBearer('static'), [self::tokenResponse('unused')], 0];
        yield 'client credentials, 403 twice' => [static fn (): ApiConfiguration => ProviderConnectionFixtures::ollamaClientCredentials('sso'), [self::tokenResponse('cc-access-token-1'), self::tokenResponse('cc-access-token-2')], 2];
    }

    /**
     * @param \Closure(): ApiConfiguration $connection
     * @param list<HttpResponse>           $tokenEndpoint
     */
    #[DataProvider('rejectedTokens')]
    public function testRejectedTokenIsUnauthenticatedWithoutTheToken(\Closure $connection, array $tokenEndpoint, int $tokenRequests): void
    {
        $lister = $this->authLister([
            new HttpResponse(403, '{"error": "forbidden for '.ProviderConnectionFixtures::PLACEHOLDER_TOKEN.' cc-access-token-1"}'),
            new HttpResponse(403, '{"error": "forbidden for cc-access-token-2"}'),
        ], $tokenEndpoint);

        $e = $this->listingFailure(fn () => $lister->listModels($connection()));

        self::assertSame(ModelListingException::UNAUTHENTICATED, $e->reason);
        self::assertSame(403, $e->statusCode);
        self::assertStringContainsString('403', $e->getMessage());
        self::assertStringNotContainsString(ProviderConnectionFixtures::PLACEHOLDER_TOKEN, $e->getMessage());
        self::assertStringNotContainsString('cc-access-token', $e->getMessage());
        self::assertNull($e->getPrevious());
        self::assertCount($tokenRequests, $this->tokenCalls);
    }

    public function testUnusableAuthConfigurationIsAConfigurationError(): void
    {
        $broken = ProviderConnectionFixtures::ollama('broken', options: ['auth_type' => 'bearer']);

        self::assertSame(ModelListingException::CONFIGURATION, $this->listingFailure(fn () => $this->authLister([], [])->listModels($broken))->reason);
        // Without the factory an authenticated connection is refused, not sent without a token.
        self::assertSame(ModelListingException::CONFIGURATION, $this->listingFailure(fn () => new OllamaModelLister(ConfigSecretsFixture::create(), $this->client([]))->listModels(ProviderConnectionFixtures::ollamaBearer()))->reason);
        self::assertSame([], $this->history);
    }

    /**
     * @param list<HttpResponse|\Throwable|\Closure> $provider
     * @param list<HttpResponse|\Throwable|\Closure> $tokenEndpoint
     */
    private function authLister(array $provider, array $tokenEndpoint): OllamaModelLister
    {
        return new OllamaModelLister(
            ConfigSecretsFixture::create(),
            $this->client($provider),
            authClients: new OllamaHttpClientFactory(new ArrayAdapter(), new FakeHttpClient($tokenEndpoint)->recordInto($this->tokenCalls)),
        );
    }

    private static function tokenResponse(string $token): HttpResponse
    {
        return new HttpResponse(200, json_encode(['access_token' => $token, 'expires_in' => 300], \JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json']);
    }

    /**
     * @param list<HttpResponse|\Throwable|\Closure> $queue
     */
    private function client(array $queue): FakeHttpClient
    {
        return new FakeHttpClient($queue)->recordInto($this->history);
    }

    private function listingFailure(callable $call): ModelListingException
    {
        try {
            $call();
        } catch (ModelListingException $e) {
            return $e;
        }

        self::fail('Expected a ModelListingException.');
    }
}
// - revised 2026-10-14
