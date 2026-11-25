<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Provider;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Provider\AnthropicWithToolChoice;
use Dmstr\NeuronAiBundle\Provider\Auth\OllamaHttpClientFactory;
use Dmstr\NeuronAiBundle\Provider\Auth\ProviderAuthenticationException;
use Dmstr\NeuronAiBundle\Provider\ModelOptions;
use Dmstr\NeuronAiBundle\Provider\ToolChoice;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ConfigSecretsFixture;
use Dmstr\NeuronAiBundle\Testing\FakeEmbeddingsProvider;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Testing\FakeHttpClient;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Kernel-free checks of the connection-based seam (Run A, WP1):
 * ProviderFactory::chatProvider() and embeddingsProvider() build the
 * providers from `ollama` and `anthropic` connections with the decrypted
 * key, and the FakeProviderFactory records them without a provider call.
 * Authenticated `ollama` connections (`auth_type` bearer and
 * client_credentials) send `Authorization: Bearer <token>` on chat and
 * embeddings; provider and token endpoint are FakeHttpClients.
 */
final class ProviderFactoryConnectionTest extends TestCase
{
    private const string KEY = 'sk-ant-factory-placeholder-1234';

    private const string CHAT_ANSWER = '{"message":{"role":"assistant","content":"ok"},"prompt_eval_count":1,"eval_count":1}';

    private const string EMBED_ANSWER = '{"embeddings":[[0.1,0.2]]}';

    /** @var list<array<string, mixed>> records of FakeHttpClient */
    private array $providerCalls = [];

    /** @var list<array<string, mixed>> records of FakeHttpClient */
    private array $tokenCalls = [];

    public function testAnthropicGetsTheDecryptedKeyAndTheDefaults(): void
    {
        $secrets = ConfigSecretsFixture::create();
        $connection = ProviderConnectionFixtures::anthropic('claude', apiKey: self::KEY);
        // As stored since dmstr/api-configuration-bundle 0.5.
        $connection->setConfigJson($secrets->encrypt($connection->getConfigJson()));
        self::assertStringStartsWith('enc:v1:', $connection->getConfigJson()['api_key']);

        $provider = $this->factory()->chatProvider($connection, 'claude-sonnet-4-5');

        self::assertInstanceOf(AnthropicWithToolChoice::class, $provider);
        self::assertSame(self::KEY, self::property($provider, 'key'));
        self::assertSame('claude-sonnet-4-5', self::property($provider, 'model'));
        self::assertSame(8192, self::property($provider, 'max_tokens'));
        self::assertSame(['cache_control' => ['type' => 'ephemeral']], self::property($provider, 'parameters'));
        self::assertSame('https://api.anthropic.com/v1/', self::property($provider, 'baseUri'));
        self::assertSame(120.0, self::property(self::property($provider, 'httpClient'), 'timeout'));
    }

    public function testAnthropicConnectionOptionsAndModelOptions(): void
    {
        $connection = ProviderConnectionFixtures::anthropic('proxy', options: ['base_url' => 'https://proxy.example.org/v1', 'max_tokens' => 4096, 'timeout' => 30]);

        $plain = $this->factory()->chatProvider($connection, 'claude-haiku-4-5-20251001');
        self::assertSame(4096, self::property($plain, 'max_tokens'));
        self::assertSame('https://proxy.example.org/v1/', self::property($plain, 'baseUri'));
        self::assertSame(30.0, self::property(self::property($plain, 'httpClient'), 'timeout'));

        $sampled = $this->factory()->chatProvider($connection, 'claude-haiku-4-5-20251001', new ModelOptions(temperature: 0.2, maxTokens: 512));
        self::assertSame(512, self::property($sampled, 'max_tokens'));
        self::assertSame(['cache_control' => ['type' => 'ephemeral'], 'temperature' => 0.2], self::property($sampled, 'parameters'));
    }

    public function testOllamaGetsUrlNumCtxAndACappedTimeout(): void
    {
        $connection = ProviderConnectionFixtures::ollama('remote', url: 'https://ollama.example.org/api', options: ['timeout' => 600]);

        $provider = $this->factory()->chatProvider($connection, 'llama3.2', new ModelOptions(temperature: 0.5, numCtx: 16384));

        self::assertInstanceOf(Ollama::class, $provider);
        self::assertSame('https://ollama.example.org/api', self::property($provider, 'baseUri'));
        self::assertSame('llama3.2', self::property($provider, 'model'));
        self::assertSame(['options' => ['num_ctx' => 16384, 'temperature' => 0.5]], self::property($provider, 'parameters'));
        // Without numCtx no num_ctx is sent: the server applies the Modelfile value.
        self::assertSame(['options' => ['temperature' => 0.5]], self::property($this->factory()->chatProvider($connection, 'llama3.2', new ModelOptions(temperature: 0.5)), 'parameters'));
        // The connection's timeout is capped at the turn deadline.
        self::assertSame(120.0, self::property(self::property($provider, 'httpClient'), 'timeout'));
        self::assertSame([], self::property($this->factory()->chatProvider(ProviderConnectionFixtures::ollama(), 'llama3.2'), 'parameters'));
    }

    public function testEmbeddingsOnOllamaOnly(): void
    {
        $provider = $this->factory()->embeddingsProvider(ProviderConnectionFixtures::ollama('remote', url: 'https://ollama.example.org/api'), 'nomic-embed-text');

        self::assertInstanceOf(OllamaEmbeddingsProvider::class, $provider);
        self::assertSame('https://ollama.example.org/api', self::property($provider, 'baseUri'));
        self::assertSame(['options' => ['num_ctx' => 2048]], self::property($provider, 'parameters'));

        $this->expectException(\InvalidArgumentException::class);
        $this->factory()->embeddingsProvider(ProviderConnectionFixtures::anthropic(), 'nomic-embed-text');
    }

    public function testUnregisteredEmbeddingModelIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->factory()->embeddingsProvider(ProviderConnectionFixtures::ollama(), 'unknown-model');
    }

    public function testNonProviderConnectionAndEmptyModelAreRejected(): void
    {
        $mcp = new ApiConfiguration()->setName('docs')->setConfigJson(['mcp' => true, 'type' => 'mcp', 'endpoint_type' => 'rest', 'url' => 'https://mcp.example.org/mcp']);

        foreach ([[$mcp, 'llama3.2'], [ProviderConnectionFixtures::ollama(), ' ']] as [$connection, $model]) {
            try {
                $this->factory()->chatProvider($connection, $model);
                self::fail('Expected an InvalidArgumentException.');
            } catch (\InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testWithoutConfigSecretsTheConnectionPathFails(): void
    {
        $factory = new ProviderFactory($this->registry());

        $this->expectException(\LogicException::class);
        $factory->chatProvider(ProviderConnectionFixtures::ollama(), 'llama3.2');
    }

    /**
     * @return iterable<string, array{\Closure(): ApiConfiguration, string, int}>
     */
    public static function authenticatedConnections(): iterable
    {
        yield 'bearer' => [static fn (): ApiConfiguration => ProviderConnectionFixtures::ollamaBearer('ollama-static'), ProviderConnectionFixtures::PLACEHOLDER_TOKEN, 0];
        yield 'client credentials' => [static fn (): ApiConfiguration => ProviderConnectionFixtures::ollamaClientCredentials('ollama-sso'), 'cc-access-token-1', 1];
    }

    #[DataProvider('authenticatedConnections')]
    public function testOllamaChatAndEmbeddingsCarryTheBearerToken(\Closure $connection, string $token, int $tokenRequests): void
    {
        $secrets = ConfigSecretsFixture::create();
        $stored = $connection();
        // As stored: secrets encrypted where the bundle marks them secret.
        $stored->setConfigJson($secrets->encrypt($stored->getConfigJson()));
        $factory = $this->authFactory([new HttpResponse(200, self::CHAT_ANSWER), new HttpResponse(200, self::EMBED_ANSWER)], [self::tokenResponse('cc-access-token-1')]);

        $answer = $factory->chatProvider($stored, 'llama3.2')->chat(new UserMessage('hi'))->message();
        $vector = $factory->embeddingsProvider($stored, 'nomic-embed-text')->embedText('hallo');

        self::assertSame([0.1, 0.2], $vector);
        self::assertCount(2, $this->providerCalls);
        [$chat, $embed] = [$this->providerCalls[0], $this->providerCalls[1]];
        self::assertSame(['POST', ProviderConnectionFixtures::OLLAMA_URL.'/chat'], [$chat['method'], $chat['url']]);
        self::assertSame(['POST', ProviderConnectionFixtures::OLLAMA_URL.'/embed'], [$embed['method'], $embed['url']]);
        self::assertSame('Bearer '.$token, FakeHttpClient::headerOf($chat, 'Authorization'));
        self::assertSame('Bearer '.$token, FakeHttpClient::headerOf($embed, 'Authorization'));
        // The token is cached across both providers of the connection.
        self::assertCount($tokenRequests, $this->tokenCalls);
        self::assertSame('ok', $answer->getContent());
    }

    public function testOllamaWithoutAuthSendsNoAuthorizationHeader(): void
    {
        $factory = $this->authFactory([new HttpResponse(200, self::CHAT_ANSWER), new HttpResponse(200, self::EMBED_ANSWER)], []);
        $connection = ProviderConnectionFixtures::ollama('plain', options: ['auth_type' => 'none']);

        $factory->chatProvider($connection, 'llama3.2')->chat(new UserMessage('hi'));
        $factory->embeddingsProvider(ProviderConnectionFixtures::ollama('plain'), 'nomic-embed-text')->embedText('hallo');

        self::assertCount(2, $this->providerCalls);
        foreach ($this->providerCalls as $call) {
            self::assertNull(FakeHttpClient::headerOf($call, 'Authorization'));
        }
        self::assertCount(0, $this->tokenCalls);
    }

    public function testOllamaWithoutAuthKeepsTheProvidersDefaults(): void
    {
        // Same client as before authentication existed: Neuron's default
        // client (CurlHttpClient since 4.x), the capped timeout for chat,
        // the 60 s of neuron-ai 3.x's Guzzle default for embeddings.
        $chat = $this->factory()->chatProvider(ProviderConnectionFixtures::ollama(), 'llama3.2');
        $embeddings = $this->factory()->embeddingsProvider(ProviderConnectionFixtures::ollama(), 'nomic-embed-text');

        self::assertSame(CurlHttpClient::class, self::property($chat, 'httpClient')::class);
        self::assertSame(120.0, self::property(self::property($chat, 'httpClient'), 'timeout'));
        self::assertSame(10.0, self::property(self::property($chat, 'httpClient'), 'connectTimeout'));
        self::assertSame(CurlHttpClient::class, self::property($embeddings, 'httpClient')::class);
        self::assertSame(60.0, self::property(self::property($embeddings, 'httpClient'), 'timeout'));
        self::assertSame(10.0, self::property(self::property($embeddings, 'httpClient'), 'connectTimeout'));
        // An injected client is used as is.
        self::assertSame(FakeHttpClient::class, self::property($this->authFactory([], [])->chatProvider(ProviderConnectionFixtures::ollama(), 'llama3.2'), 'httpClient')::class);
    }

    public function testChatRejectedTwiceIsAProviderAuthenticationException(): void
    {
        $factory = $this->authFactory(
            [new HttpResponse(403, 'forbidden for cc-access-token-1'), new HttpResponse(403, 'forbidden for cc-access-token-2')],
            [self::tokenResponse('cc-access-token-1'), self::tokenResponse('cc-access-token-2')],
        );
        $provider = $factory->chatProvider(ProviderConnectionFixtures::ollamaClientCredentials('ollama-sso'), 'llama3.2');

        try {
            $provider->chat(new UserMessage('hi'));
            self::fail('ProviderAuthenticationException expected');
        } catch (ProviderAuthenticationException $e) {
            self::assertSame(403, $e->statusCode);
            self::assertSame('ollama-sso', $e->connectionName);
            self::assertStringContainsString('"ollama-sso"', $e->getMessage());
            self::assertStringNotContainsString('cc-access-token', $e->getMessage());
            self::assertStringNotContainsString(ProviderConnectionFixtures::PLACEHOLDER_CLIENT_SECRET, $e->getMessage());
            self::assertNull($e->getPrevious());
        }
        self::assertCount(2, $this->providerCalls);
        self::assertCount(2, $this->tokenCalls);
    }

    public function testAuthenticatedConnectionWithoutAuthFactoryIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->factory()->chatProvider(ProviderConnectionFixtures::ollamaBearer(), 'llama3.2');
    }

    public function testFakeRecordsTypeAndConnectionName(): void
    {
        $fake = new FakeProviderFactory($this->registry());
        $anthropic = $fake->chatProvider(ProviderConnectionFixtures::anthropic(), 'claude-any-model', new ModelOptions(temperature: 0.1, maxTokens: 100));
        self::assertTrue($fake->getChatProvider()->supportsToolChoice(ToolChoice::any()));
        $fake->chatProvider(ProviderConnectionFixtures::ollama(), 'llama3.2:latest');
        self::assertFalse($fake->getChatProvider()->supportsToolChoice(ToolChoice::any()), 'ollama declines a tool choice');

        self::assertSame($fake->getChatProvider(), $anthropic);
        self::assertSame([
            ['provider' => 'anthropic', 'connection' => FakeProviderFactory::ANTHROPIC_CONNECTION, 'model' => 'claude-any-model', 'temperature' => 0.1, 'maxTokens' => 100],
            ['provider' => 'ollama', 'connection' => FakeProviderFactory::OLLAMA_CONNECTION, 'model' => 'llama3.2:latest', 'temperature' => null, 'maxTokens' => null],
        ], $fake->getRequestedProviders());
    }

    public function testFakeEmbeddingsRecordTheConnection(): void
    {
        $fake = new FakeProviderFactory($this->registry());

        $provider = $fake->embeddingsProvider(ProviderConnectionFixtures::ollama('remote'), 'nomic-embed-text');

        self::assertInstanceOf(FakeEmbeddingsProvider::class, $provider);
        self::assertSame([['provider' => 'ollama', 'connection' => 'remote', 'model' => 'nomic-embed-text']], $fake->getRequestedEmbeddings());

        $this->expectException(\InvalidArgumentException::class);
        $fake->embeddingsProvider(ProviderConnectionFixtures::anthropic(), 'nomic-embed-text');
    }

    private function factory(): ProviderFactory
    {
        return new ProviderFactory($this->registry(), ConfigSecretsFixture::create());
    }

    /**
     * A ProviderFactory whose provider and token requests go to
     * FakeHttpClients.
     *
     * @param list<HttpResponse> $provider
     * @param list<HttpResponse> $tokenEndpoint
     */
    private function authFactory(array $provider, array $tokenEndpoint): ProviderFactory
    {
        return new ProviderFactory(
            $this->registry(),
            ConfigSecretsFixture::create(),
            120,
            new OllamaHttpClientFactory(new ArrayAdapter(), new FakeHttpClient($tokenEndpoint)->recordInto($this->tokenCalls)),
            new FakeHttpClient($provider)->recordInto($this->providerCalls),
        );
    }

    private static function tokenResponse(string $token): HttpResponse
    {
        return new HttpResponse(200, json_encode(['access_token' => $token, 'expires_in' => 300], \JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json']);
    }

    private function registry(): EmbeddingModelRegistry
    {
        return new EmbeddingModelRegistry([
            'nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768, 'context_length' => 2048],
        ]);
    }

    private static function property(object $object, string $name): mixed
    {
        return new \ReflectionProperty($object, $name)->getValue($object);
    }
}

// - revised 2026-10-07
// - revised 2026-10-14
