<?php

// file generated with AI assistance: Claude Code - 2026-07-10 16:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Provider\AnthropicModelLister;
use Dmstr\NeuronAiBundle\Provider\AnthropicSamplingSupport;
use Dmstr\NeuronAiBundle\Provider\AnthropicWithToolChoice;
use Dmstr\NeuronAiBundle\Provider\Auth\AuthType;
use Dmstr\NeuronAiBundle\Provider\Auth\OllamaHttpClientFactory;
use Dmstr\NeuronAiBundle\Provider\ModelOptions;
use Dmstr\NeuronAiBundle\Provider\ProviderType;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;

/**
 * Builds neuron-ai chat and embedding providers from provider connections.
 *
 * A provider connection is an `ApiConfiguration` of type `ollama` or
 * `anthropic` ({@see ProviderType}); there is no configured endpoint, no
 * default provider and no default model. {@see chatProvider()} and
 * {@see embeddingsProvider()} take the connection and an explicit model:
 * endpoint, `max_tokens` default, `base_url` and `timeout` come from the
 * connection, the `api_key` decrypted through
 * {@see ConfigSecrets::decrypt()} (the entity holds `enc:v1:…`). Model
 * availability is not checked here; the entry points use
 * {@see Provider\ModelAvailabilityCheck}.
 *
 * Every chat provider gets its own CurlHttpClient (Neuron's 4.x default)
 * with the connection's timeout, capped at the turn deadline; every
 * embeddings provider one with {@see EMBEDDINGS_TIMEOUT_SECONDS}. An
 * injected client (tests) replaces both and keeps its own timeout. The Anthropic provider is an
 * {@see AnthropicWithToolChoice}, which implements
 * {@see Provider\ToolChoiceAwareProviderInterface}; the Ollama provider
 * does not, so callers check the capability with `instanceof`. Optional
 * {@see ModelOptions} map to the provider request as tabled there, each
 * value only when set; Anthropic never receives `temperature`, `top_p` or
 * `top_k` for a model that rejects sampling ({@see AnthropicSamplingSupport}),
 * nor `temperature` and `top_p` together (Claude 4 rejects the pair; publish
 * refuses it, an older version keeps `temperature` and drops `top_p`),
 * and Ollama gets `options.num_ctx` only from `numCtx`: without it the
 * server applies the model's Modelfile `num_ctx`, else its own default
 * (the catalogue shows the Modelfile value as
 * {@see Entity\Refs\ProviderModel::getDefaultContextLength()}). This
 * mapping is wired, not verified against live providers.
 *
 * Authentication: the HTTP client of an `ollama` provider (chat and
 * embeddings) is wrapped by {@see OllamaHttpClientFactory} according to the
 * connection's `auth_type`; `none` (or no `auth_type`) leaves it unchanged.
 * Without the factory (subclasses, kernel-free tests) only `none`
 * connections can be built.
 *
 * Embeddings: only `ollama` connections serve embeddings, for the models of
 * the {@see EmbeddingModelRegistry}; a model's `context_length` is passed
 * as `options.num_ctx`. This factory returns the undecorated provider: task
 * prefixes are added by {@see Embeddings\EmbeddingsProviderLocator}, which
 * callers should use instead of this factory.
 *
 * Tests replace this factory with {@see Testing\FakeProviderFactory}.
 */
class ProviderFactory
{
    /**
     * HTTP timeout of embeddings requests in seconds: the default of
     * neuron-ai 3.x's GuzzleHttpClient, which embeddings providers used
     * before 4.x, kept so the upgrade does not change it.
     */
    public const float EMBEDDINGS_TIMEOUT_SECONDS = 60.0;

    /** Connect timeout of every provider HTTP client in seconds. */
    public const float CONNECT_TIMEOUT_SECONDS = 10.0;

    /**
     * @param ConfigSecrets|null $secrets        decrypts connection secrets; null only for subclasses that never build a real provider ({@see Testing\FakeProviderFactory})
     * @param int                          $timeoutSeconds    HTTP timeout cap of chat provider calls (the turn deadline)
     * @param OllamaHttpClientFactory|null $ollamaHttpClients authenticates `ollama` connections with an `auth_type` other than `none`; null = such connections are refused
     * @param HttpClientInterface|null     $httpClient        HTTP client of every provider, used as is; null = a CurlHttpClient per provider with the timeouts above (tests pass a Testing\FakeHttpClient)
     */
    public function __construct(
        protected readonly EmbeddingModelRegistry $registry,
        private readonly ?ConfigSecrets $secrets = null,
        private readonly int $timeoutSeconds = 120,
        private readonly ?OllamaHttpClientFactory $ollamaHttpClients = null,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    /**
     * The chat provider of a connection (`ollama` or `anthropic`) for
     * $model, which is passed as is (no allow-list, no default; check it at
     * the entry point with {@see Provider\ModelAvailabilityCheck}).
     *
     * Anthropic: decrypted `api_key`, `base_url` (default
     * https://api.anthropic.com/v1/), output token limit from $options,
     * else the connection's `max_tokens`, else 8192. Ollama: `url`,
     * `options.num_ctx` from $options only (else the server's default for
     * the model). The HTTP timeout
     * is the connection's `timeout`, capped at the turn deadline; an
     * `ollama` client authenticates per the connection's `auth_type`.
     *
     * @param ModelOptions|null $options the model options of the version; null = provider defaults
     *
     * @throws \InvalidArgumentException for a connection that is no provider connection, an incomplete configuration or an empty model
     * @throws \Dmstr\ApiConfiguration\Security\SecretEncryptionException when the secret cannot be decrypted
     * @throws Provider\Auth\ProviderAuthenticationException when the auth configuration of an `ollama` connection is unusable
     */
    public function chatProvider(ApiConfiguration $connection, string $model, ?ModelOptions $options = null): AIProviderInterface
    {
        $type = ProviderType::of($connection);
        $config = $this->decryptedConfig($connection);
        self::assertModel($connection, $model);
        $httpClient = $this->newHttpClient((float) $this->connectionTimeout($config));

        return match ($type) {
            ProviderType::ANTHROPIC => new AnthropicWithToolChoice(
                key: self::requiredString($connection, $config, 'api_key'),
                model: $model,
                max_tokens: $options?->maxTokens ?? self::positiveInt($config['max_tokens'] ?? null) ?? 8192,
                parameters: self::anthropicParameters($model, $options),
                httpClient: $httpClient,
                baseUri: AnthropicModelLister::baseUrl($config),
            ),
            default => new Ollama(
                url: self::requiredString($connection, $config, 'url'),
                model: $model,
                parameters: self::ollamaParameters($options),
                httpClient: $this->ollamaHttpClient($connection, $config, $httpClient),
            ),
        };
    }

    /**
     * The undecorated embeddings provider of an `ollama` connection for a
     * model of the {@see EmbeddingModelRegistry} (its `context_length` as
     * `options.num_ctx`). Callers should use
     * {@see Embeddings\EmbeddingsProviderLocator}, which adds the task
     * prefixes.
     *
     * The HTTP client is a CurlHttpClient with
     * {@see EMBEDDINGS_TIMEOUT_SECONDS}, authenticated per the connection's
     * `auth_type`.
     *
     * @throws \InvalidArgumentException for a non-`ollama` connection, an unregistered model or a model of another provider
     * @throws Provider\Auth\ProviderAuthenticationException when the auth configuration is unusable
     */
    public function embeddingsProvider(ApiConfiguration $connection, string $model): EmbeddingsProviderInterface
    {
        if (!ProviderType::isEmbedding($connection)) {
            throw new \InvalidArgumentException(\sprintf('Connection "%s" cannot serve embeddings (type "%s", expected one of: %s).', ProviderType::nameOf($connection), (string) ($connection->getConfigJson()['type'] ?? ''), implode(', ', ProviderType::EMBEDDING_TYPES)));
        }

        $definition = $this->registry->get($model);
        if ($definition->provider !== ProviderType::OLLAMA) {
            throw new \InvalidArgumentException(\sprintf('Unsupported embeddings provider "%s" for model "%s" (expected "ollama")', $definition->provider, $model));
        }

        $config = $this->decryptedConfig($connection);

        return new OllamaEmbeddingsProvider(
            model: $definition->name,
            url: self::requiredString($connection, $config, 'url'),
            parameters: $definition->contextLength !== null ? ['options' => ['num_ctx' => $definition->contextLength]] : [],
            httpClient: $this->ollamaHttpClient($connection, $config, $this->newHttpClient(self::EMBEDDINGS_TIMEOUT_SECONDS)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decryptedConfig(ApiConfiguration $connection): array
    {
        if (null === $this->secrets) {
            throw new \LogicException('ProviderFactory needs ConfigSecrets to build providers from connections.');
        }

        return $this->secrets->decrypt($connection->getConfigJson());
    }

    /**
     * The injected client, else a fresh CurlHttpClient with $timeout: the
     * timeout differs per connection, and a 4.x client cannot be
     * reconfigured through HttpClientInterface.
     */
    private function newHttpClient(float $timeout): HttpClientInterface
    {
        return $this->httpClient ?? new CurlHttpClient(timeout: $timeout, connectTimeout: self::CONNECT_TIMEOUT_SECONDS);
    }

    /**
     * $inner wrapped according to the `ollama` connection's `auth_type`.
     *
     * @param array<string, mixed> $config the decrypted configuration
     */
    private function ollamaHttpClient(ApiConfiguration $connection, #[\SensitiveParameter] array $config, HttpClientInterface $inner): HttpClientInterface
    {
        if (null === $this->ollamaHttpClients) {
            $authType = $config['auth_type'] ?? null;
            if (null === $authType || '' === $authType || AuthType::NONE->value === $authType) {
                return $inner;
            }

            throw new \LogicException(\sprintf('ProviderFactory needs OllamaHttpClientFactory to build providers of the authenticated connection "%s".', ProviderType::nameOf($connection)));
        }

        return $this->ollamaHttpClients->for($config, $inner, ProviderType::nameOf($connection));
    }

    /**
     * The connection's `timeout`, capped at the turn deadline; the
     * deadline without one.
     *
     * @param array<string, mixed> $config
     */
    private function connectionTimeout(array $config): int
    {
        $timeout = self::positiveInt($config['timeout'] ?? null);

        return null !== $timeout ? min($timeout, $this->timeoutSeconds) : $this->timeoutSeconds;
    }

    private static function assertModel(ApiConfiguration $connection, string $model): void
    {
        if ('' === trim($model)) {
            throw new \InvalidArgumentException(\sprintf('No model given for connection "%s".', ProviderType::nameOf($connection)));
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function requiredString(ApiConfiguration $connection, array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw new \InvalidArgumentException(\sprintf('Connection "%s" has no "%s".', ProviderType::nameOf($connection), $key));
        }

        return $value;
    }

    private static function positiveInt(mixed $value): ?int
    {
        return \is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * Ollama request parameters: `options` with `num_ctx`, the sampling
     * values, `num_predict`,
     * `repeat_penalty` and `seed`, plus the top-level `think`; each only
     * when set, [] when none is.
     *
     * @return array<string, mixed>
     */
    private static function ollamaParameters(?ModelOptions $options): array
    {
        $values = array_filter([
            'num_ctx' => $options?->numCtx,
            'temperature' => $options?->temperature,
            'top_p' => $options?->topP,
            'top_k' => $options?->topK,
            'num_predict' => $options?->maxTokens,
            'repeat_penalty' => $options?->repeatPenalty,
            'seed' => $options?->seed,
        ], static fn (int|float|null $value): bool => null !== $value);

        $parameters = [] !== $values ? ['options' => $values] : [];
        if (null !== $options?->think) {
            $parameters['think'] = $options->think;
        }

        return $parameters;
    }

    /**
     * Anthropic request parameters besides `max_tokens`: the top-level
     * `cache_control` (automatic prompt caching, always: every tool round
     * of a turn re-sends the same instructions, tools and history, which
     * are then read from the cache), the sampling values (dropped for a
     * model that rejects sampling), `output_config` with the effort and
     * adaptive `thinking` with its display; each only when set.
     *
     * @return array<string, mixed>
     */
    private static function anthropicParameters(string $model, ?ModelOptions $options): array
    {
        $cache = ['cache_control' => ['type' => 'ephemeral']];
        if (null === $options) {
            return $cache;
        }

        $parameters = false === AnthropicSamplingSupport::of($model) ? [] : array_filter([
            'temperature' => $options->temperature,
            'top_p' => $options->topP,
            'top_k' => $options->topK,
        ], static fn (int|float|null $value): bool => null !== $value);
        if (isset($parameters['temperature'], $parameters['top_p'])) {
            unset($parameters['top_p']);
        }
        if (null !== $options->effort) {
            $parameters['output_config'] = ['effort' => $options->effort];
        }
        if (null !== $options->thinkingDisplay) {
            $parameters['thinking'] = ['type' => 'adaptive', 'display' => $options->thinkingDisplay];
        }

        return $cache + $parameters;
    }
}

// - revised 2026-10-07
// - revised 2026-10-14
// - revised 2026-11-09
