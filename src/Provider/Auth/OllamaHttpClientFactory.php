<?php

// file generated with AI assistance: Claude Code - 2026-10-06 00:15:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider\Auth;

use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The single entry point of the token layer: wraps the HTTP client of an
 * `ollama` provider (chat, embeddings, model listing) according to the
 * connection's `auth_type`.
 *
 * - `none` or no `auth_type`: the inner client unchanged.
 * - `bearer`: AuthenticatingHttpClient with the static `token`.
 * - `client_credentials`: AuthenticatingHttpClient with a token from
 *   `token_url` (`client_id`, `client_secret`, optional `scope`), cached in
 *   `$tokenCache` (pool `cache.neuron_provider_tokens`).
 *
 * An unusable auth configuration (unknown `auth_type`, missing field, token
 * with CR/LF) fails here with a ProviderAuthenticationException, before any
 * request is sent. The schema of the connection type normally prevents it.
 */
final readonly class OllamaHttpClientFactory
{
    private HttpClientInterface $tokenHttpClient;

    /**
     * $tokenHttpClient is dedicated to token requests (each token request
     * carries its own 5 s timeout); Neuron's CurlHttpClient by default.
     * $now returns the current Unix time in seconds (tests).
     *
     * @param (\Closure(): int)|null $now
     */
    public function __construct(
        private CacheInterface $tokenCache,
        ?HttpClientInterface $tokenHttpClient = null,
        private ?\Closure $now = null,
        private ?LoggerInterface $logger = null,
    ) {
        $this->tokenHttpClient = $tokenHttpClient ?? new CurlHttpClient();
    }

    /**
     * @param array<string, mixed> $decryptedConfig the connection's config with secrets decrypted
     *
     * @throws ProviderAuthenticationException when the auth configuration is unusable
     */
    public function for(#[\SensitiveParameter] array $decryptedConfig, HttpClientInterface $inner, string $connectionName = ''): HttpClientInterface
    {
        return match (self::authType($decryptedConfig, $connectionName)) {
            AuthType::NONE => $inner,
            AuthType::BEARER => new AuthenticatingHttpClient(
                $inner,
                new StaticTokenProvider(self::string($decryptedConfig, 'token', $connectionName, AuthType::BEARER), $connectionName),
                $connectionName,
            ),
            AuthType::CLIENT_CREDENTIALS => new AuthenticatingHttpClient(
                $inner,
                new ClientCredentialsTokenProvider(
                    $this->tokenCache,
                    $this->tokenHttpClient,
                    self::string($decryptedConfig, 'token_url', $connectionName, AuthType::CLIENT_CREDENTIALS),
                    self::string($decryptedConfig, 'client_id', $connectionName, AuthType::CLIENT_CREDENTIALS),
                    self::string($decryptedConfig, 'client_secret', $connectionName, AuthType::CLIENT_CREDENTIALS),
                    \is_string($decryptedConfig['scope'] ?? null) && '' !== $decryptedConfig['scope'] ? $decryptedConfig['scope'] : null,
                    $connectionName,
                    $this->now,
                    $this->logger,
                    ClientCredentialsTokenProvider::OLLAMA_KEY_PREFIX,
                    'Ollama',
                ),
                $connectionName,
            ),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function authType(array $config, string $connectionName): AuthType
    {
        $value = $config['auth_type'] ?? null;
        if (null === $value || '' === $value) {
            return AuthType::NONE;
        }
        $type = \is_string($value) ? AuthType::tryFrom($value) : null;
        if (null === $type) {
            throw new ProviderAuthenticationException(\sprintf('Ollama connection "%s" has an unsupported "auth_type".', TokenErrors::name($connectionName)), null, $connectionName);
        }

        return $type;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function string(#[\SensitiveParameter] array $config, string $key, string $connectionName, AuthType $type): string
    {
        $value = $config[$key] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw new ProviderAuthenticationException(\sprintf('Ollama connection "%s" uses auth_type "%s" without "%s".', TokenErrors::name($connectionName), $type->value, $key), null, $connectionName);
        }

        return $value;
    }
}

// - revised 2026-10-09 06:42:28 UTC
// - revised 2026-10-14
