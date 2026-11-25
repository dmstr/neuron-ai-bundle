<?php

// file generated with AI assistance: Claude Code - 2026-10-06 00:15:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider\Auth;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Access token of a connection with `auth_type: client_credentials` (an
 * `ollama` provider connection or an `mcp` connection): OAuth2 client
 * credentials grant against `token_url` (Keycloak), cached in the
 * `cache.neuron_provider_tokens` pool.
 *
 * Cache entry: `{token, expires_at}` under
 * `<key prefix><sha256(token_url \0 client_id \0 client_secret
 * [\0 scope])>`, so a changed secret or scope never reuses an old token and
 * the key reveals none of the inputs. The prefix tells the connection kinds
 * apart in the shared pool: {@see OLLAMA_KEY_PREFIX} for `ollama`,
 * {@see MCP_KEY_PREFIX} for `mcp` connections. Messages and log entries
 * name the connection kind passed as `$connectionKind` (e.g. "Ollama",
 * "MCP"). The entry lives `expires_in - 30` s
 * (at least 1 s) in the backend; independently of the backend's own expiry,
 * `expires_at` is compared with the injected clock on every read, because
 * the backend clock is not under test control and an entry may be served
 * slightly late (Symfony's early-expiration logic, Redis eviction lag).
 *
 * Concurrency: Symfony's CacheInterface::get() computes a missing entry
 * once per cache instance (with lock where the adapter supports it), so
 * concurrent first requests in one process make at most one token call.
 * Across processes (php-fpm workers, messenger workers) a stampede on an
 * empty or expired entry is possible; this is best effort and harmless for
 * Keycloak, every fetched token is valid.
 *
 * Cache failures (e.g. Redis down in dev) degrade to a freshly fetched,
 * uncached token instead of failing the request; they are logged with the
 * exception class only.
 */
final class ClientCredentialsTokenProvider implements AccessTokenProviderInterface
{
    /** Cache key prefix of `ollama` connections (the default). */
    public const string OLLAMA_KEY_PREFIX = 'neuron_ollama_token_';

    /** Cache key prefix of `mcp` connections. */
    public const string MCP_KEY_PREFIX = 'neuron_mcp_token_';

    /** Timeout of the token request in seconds. */
    public const float TIMEOUT = 5.0;

    /** Seconds a token is dropped before Keycloak's expiry. */
    public const int EXPIRY_MARGIN = 30;

    private readonly string $cacheKey;

    /** @var \Closure(): int */
    private readonly \Closure $now;

    private readonly LoggerInterface $logger;

    /** Set by invalidate(): the next token() must not reuse the cached entry. */
    private bool $forceRefresh = false;

    /**
     * @param (\Closure(): int)|null $now            current Unix time in seconds
     * @param string                 $keyPrefix      cache key prefix, see the class docblock
     * @param string                 $connectionKind human name of the connection type in
     *                                               messages and logs, e.g. "Ollama" or "MCP"
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly HttpClientInterface $httpClient,
        private readonly string $tokenUrl,
        private readonly string $clientId,
        #[\SensitiveParameter]
        private readonly string $clientSecret,
        private readonly ?string $scope = null,
        private readonly string $connectionName = '',
        ?\Closure $now = null,
        ?LoggerInterface $logger = null,
        private readonly string $keyPrefix = self::OLLAMA_KEY_PREFIX,
        private readonly string $connectionKind = 'Ollama',
    ) {
        $this->cacheKey = self::cacheKey($tokenUrl, $clientId, $clientSecret, $scope, $keyPrefix);
        $this->now = $now ?? static fn (): int => time();
        $this->logger = $logger ?? new NullLogger();
    }

    public static function cacheKey(string $tokenUrl, string $clientId, #[\SensitiveParameter] string $clientSecret, ?string $scope = null, string $keyPrefix = self::OLLAMA_KEY_PREFIX): string
    {
        $material = $tokenUrl."\0".$clientId."\0".$clientSecret;
        if (null !== $scope && '' !== $scope) {
            $material .= "\0".$scope;
        }

        return $keyPrefix.hash('sha256', $material);
    }

    public function token(): string
    {
        /** @var array{token: string, expires_at: int}|null $fetched */
        $fetched = null;
        $compute = function (ItemInterface $item) use (&$fetched): array {
            // A ProviderAuthenticationException leaves the callback before
            // anything is stored: failures are never cached.
            $fetched = $this->fetch();
            $item->expiresAfter($fetched['ttl']);

            return ['token' => $fetched['token'], 'expires_at' => $fetched['expires_at']];
        };

        try {
            $entry = $this->cache->get($this->cacheKey, $compute, $this->forceRefresh ? \INF : null);
            if (!$this->isFresh($entry)) {
                // Expired by our clock or not ours: recompute and overwrite.
                $entry = $this->cache->get($this->cacheKey, $compute, \INF);
            }
        } catch (ProviderAuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // The cache backend failed. Use the token the callback may
            // already have fetched, otherwise fetch one without cache.
            $this->logger->warning('Token cache unavailable for {kind} connection {connection}, using an uncached token ({exception_class}).', [
                'kind' => $this->connectionKind,
                'connection' => $this->connectionName,
                'exception_class' => $e::class,
            ]);
            $entry = $fetched ?? $this->fetch();
        }
        $this->forceRefresh = false;

        if (!$this->isFresh($entry)) {
            // Only reachable with a cache that returns foreign data.
            $entry = $this->fetch();
        }

        return $entry['token'];
    }

    public function invalidate(): bool
    {
        // The flag makes the next token() recompute even if delete() fails.
        $this->forceRefresh = true;
        try {
            $this->cache->delete($this->cacheKey);
        } catch (\Throwable $e) {
            $this->logger->warning('Token cache unavailable for {kind} connection {connection} while evicting a rejected token ({exception_class}).', [
                'kind' => $this->connectionKind,
                'connection' => $this->connectionName,
                'exception_class' => $e::class,
            ]);
        }

        return true;
    }

    /**
     * @phpstan-assert-if-true array{token: non-empty-string, expires_at: int} $entry
     */
    private function isFresh(mixed $entry): bool
    {
        return \is_array($entry)
            && \is_string($entry['token'] ?? null) && '' !== $entry['token']
            && \is_int($entry['expires_at'] ?? null) && $entry['expires_at'] > ($this->now)();
    }

    /**
     * POSTs the client credentials grant and validates the answer.
     *
     * @return array{token: string, expires_at: int, ttl: int}
     *
     * @throws ProviderAuthenticationException
     */
    private function fetch(): array
    {
        $form = [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];
        if (null !== $this->scope && '' !== $this->scope) {
            $form['scope'] = $this->scope;
        }

        $name = TokenErrors::name($this->connectionName);
        try {
            // A string body: Neuron sends an array body as JSON. The
            // timeout travels on the request; the shared client is never
            // configured.
            $request = new HttpRequest(
                HttpMethod::POST,
                $this->tokenUrl,
                ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
                http_build_query($form, '', '&', \PHP_QUERY_RFC1738),
                self::TIMEOUT,
            );
            $response = $this->httpClient->request($request);
        } catch (HttpException $e) {
            // Never chained: the exception carries the form body with the secret.
            throw $this->endpointFailure($e->response?->statusCode, $e->response?->body);
        } catch (\Throwable) {
            throw new ProviderAuthenticationException(\sprintf('Token request for %s connection "%s" failed before an answer was received.', $this->connectionKind, $name), null, $this->connectionName);
        }

        if (200 !== $response->statusCode) {
            throw $this->endpointFailure($response->statusCode, $response->body);
        }

        try {
            $data = json_decode($response->body, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }
        if (!\is_array($data)) {
            throw new ProviderAuthenticationException(\sprintf('Token endpoint of %s connection "%s" answered HTTP 200 without a JSON object.', $this->connectionKind, $name), 200, $this->connectionName);
        }

        $token = $data['access_token'] ?? null;
        if (!\is_string($token) || '' === $token) {
            throw new ProviderAuthenticationException(\sprintf('Token endpoint of %s connection "%s" answered HTTP 200 without "access_token".', $this->connectionKind, $name), 200, $this->connectionName);
        }
        if (TokenErrors::hasLineBreak($token)) {
            throw new ProviderAuthenticationException(\sprintf('Token endpoint of %s connection "%s" returned an access token with a line break; refused.', $this->connectionKind, $name), 200, $this->connectionName);
        }

        $expiresIn = $data['expires_in'] ?? null;
        if (\is_string($expiresIn) && is_numeric($expiresIn)) {
            $expiresIn = (int) $expiresIn;
        } elseif (\is_float($expiresIn)) {
            $expiresIn = (int) $expiresIn;
        }
        if (!\is_int($expiresIn) || $expiresIn <= 0) {
            throw new ProviderAuthenticationException(\sprintf('Token endpoint of %s connection "%s" answered HTTP 200 without a positive "expires_in".', $this->connectionKind, $name), 200, $this->connectionName);
        }

        $ttl = max(1, $expiresIn - self::EXPIRY_MARGIN);
        $this->logger->info('Fetched an access token for {kind} connection {connection} (expires_in {expires_in} s).', [
            'kind' => $this->connectionKind,
            'connection' => $this->connectionName,
            'expires_in' => $expiresIn,
        ]);

        return ['token' => $token, 'expires_at' => ($this->now)() + $ttl, 'ttl' => $ttl];
    }

    private function endpointFailure(?int $status, ?string $body): ProviderAuthenticationException
    {
        $name = TokenErrors::name($this->connectionName);
        if (null === $status) {
            return new ProviderAuthenticationException(\sprintf('Token endpoint of %s connection "%s" is unreachable.', $this->connectionKind, $name), null, $this->connectionName);
        }

        $detail = TokenErrors::oauthDetail($body, [$this->clientSecret]);

        return new ProviderAuthenticationException(
            \sprintf('Token endpoint of %s connection "%s" answered HTTP %d%s.', $this->connectionKind, $name, $status, null !== $detail ? ' ('.$detail.')' : ''),
            $status,
            $this->connectionName,
        );
    }
}

// - revised 2026-10-09 06:42:28 UTC
// - revised 2026-10-14
