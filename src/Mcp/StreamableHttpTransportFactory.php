<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:22:22 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp;

use Dmstr\NeuronAiBundle\Provider\Auth\ClientCredentialsTokenProvider;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\MCP\McpTransportInterface;
use NeuronAI\MCP\StreamableHttpTransport;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Default transport factory: Neuron's StreamableHttpTransport.
 *
 * Authentication mapping: `bearer` passes the token as `token` (the vendor
 * sends `Authorization: Bearer <token>`), `header` passes
 * `headers[<header_name>] = <token>` (the vendor merges `headers` into every
 * request), `none` sends no credentials even if a token is stored.
 * `client_credentials` creates a {@see TokenAuthenticatedStreamableHttpTransport}
 * with a {@see ClientCredentialsTokenProvider} (`token_url`, `client_id`,
 * `client_secret`, optional `scope`): the token is fetched on the first
 * request, cached in `$tokenCache` (pool `cache.neuron_provider_tokens`,
 * key prefix `neuron_mcp_token_`) and renewed after an HTTP 401.
 * `forward_jwt` depends on the {@see McpTransportContext}: a turn with the
 * JWT of the person chatting gets a plain StreamableHttpTransport with that
 * JWT as `token`; scan, health, a turn without JWT and a call without
 * context get the client credentials transport of the scan service account
 * (`scan_token_url`, `scan_client_id`, `scan_client_secret`, optional
 * `scan_scope`, mapped onto the client credentials code path).
 *
 * create() performs no I/O for any auth type.
 */
final class StreamableHttpTransportFactory implements McpTransportFactoryInterface
{
    public const string TRANSPORT = 'streamable-http';

    public const string AUTH_CLIENT_CREDENTIALS = 'client_credentials';

    public const string AUTH_FORWARD_JWT = 'forward_jwt';

    /** Key prefix of the scan service account of a `forward_jwt` connection. */
    private const string SCAN_PREFIX = 'scan_';

    private readonly CacheInterface $tokenCache;

    private readonly HttpClientInterface $tokenHttpClient;

    /**
     * $tokenCache holds client credentials tokens across transports and
     * processes (an in-memory pool when omitted, e.g. in unit tests).
     * $tokenHttpClient is dedicated to token requests; Neuron's
     * CurlHttpClient by default. $now returns the current Unix time in
     * seconds (tests).
     *
     * @param (\Closure(): int)|null $now
     */
    public function __construct(
        ?CacheInterface $tokenCache = null,
        ?HttpClientInterface $tokenHttpClient = null,
        private readonly ?\Closure $now = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->tokenCache = $tokenCache ?? new ArrayAdapter();
        $this->tokenHttpClient = $tokenHttpClient ?? new CurlHttpClient();
    }

    public function create(array $config, ?McpTransportContext $context = null): McpTransportInterface
    {
        $transportConfig = $this->transportConfig($config);
        $authType = $config['auth_type'] ?? null;

        if (self::AUTH_FORWARD_JWT === $authType && null !== $context && $context->forwardsJwt()) {
            // The person's JWT as bearer; the vendor sends `token` as
            // 'Authorization: Bearer <token>'.
            $transportConfig['token'] = (string) $context->jwt;

            return new StreamableHttpTransport($transportConfig);
        }

        if (self::AUTH_CLIENT_CREDENTIALS === $authType || self::AUTH_FORWARD_JWT === $authType) {
            return new TokenAuthenticatedStreamableHttpTransport($transportConfig, $this->tokenProvider($config));
        }

        return new StreamableHttpTransport($transportConfig);
    }

    /**
     * The token source of a `client_credentials` connection, or of the scan
     * service account of a `forward_jwt` connection (`scan_*` keys).
     * Performs no I/O; the token is requested on the first token() call.
     *
     * @param array<string, mixed> $config the connection's configJson, secrets decrypted
     *
     * @throws \InvalidArgumentException when token_url, client_id or client_secret (forward_jwt:
     *                                   their scan_ counterparts) is missing
     */
    public function tokenProvider(#[\SensitiveParameter] array $config): ClientCredentialsTokenProvider
    {
        $credentials = self::clientCredentials($config, self::credentialsPrefix($config));
        $url = \is_string($config['url'] ?? null) ? $config['url'] : '';

        return new ClientCredentialsTokenProvider(
            $this->tokenCache,
            $this->tokenHttpClient,
            $credentials['token_url'],
            $credentials['client_id'],
            $credentials['client_secret'],
            $credentials['scope'],
            self::connectionLabel($url),
            $this->now,
            $this->logger,
            ClientCredentialsTokenProvider::MCP_KEY_PREFIX,
            'MCP',
        );
    }

    /**
     * Maps a connection configuration to the StreamableHttpTransport config.
     *
     * @param array<string, mixed> $config
     *
     * @return array{url: string, timeout?: int, token?: string, headers?: array<string, string>}
     *
     * @throws \InvalidArgumentException
     */
    public function transportConfig(array $config): array
    {
        $url = $config['url'] ?? null;
        if (!\is_string($url) || '' === $url) {
            throw new \InvalidArgumentException('MCP connection configuration has no "url".');
        }

        $transport = $config['transport'] ?? self::TRANSPORT;
        if (self::TRANSPORT !== $transport) {
            throw new \InvalidArgumentException(\sprintf('Unsupported MCP transport "%s"; only "%s" is supported.', \is_scalar($transport) ? (string) $transport : get_debug_type($transport), self::TRANSPORT));
        }

        $result = ['url' => $url];

        if (isset($config['timeout'])) {
            $result['timeout'] = (int) $config['timeout'];
        }

        $authType = $config['auth_type'] ?? 'none';
        $token = isset($config['token']) ? (string) $config['token'] : '';

        switch ($authType) {
            case 'none':
                break;
            case 'bearer':
                if ('' === $token) {
                    throw new \InvalidArgumentException('MCP connection with auth_type "bearer" has no "token".');
                }
                $result['token'] = $token;
                break;
            case 'header':
                $headerName = isset($config['header_name']) ? (string) $config['header_name'] : '';
                if ('' === $headerName || '' === $token) {
                    throw new \InvalidArgumentException('MCP connection with auth_type "header" needs "header_name" and "token".');
                }
                $result['headers'] = [$headerName => $token];
                break;
            case self::AUTH_CLIENT_CREDENTIALS:
                // Validated here so create() fails early; the token itself
                // is added per request by the transport.
                self::clientCredentials($config);
                break;
            case self::AUTH_FORWARD_JWT:
                // The scan service account is required whatever the
                // context: scan, sync, health and turns without JWT use it.
                self::clientCredentials($config, self::SCAN_PREFIX);
                break;
            default:
                throw new \InvalidArgumentException(\sprintf('Unsupported MCP auth_type "%s".', \is_scalar($authType) ? (string) $authType : get_debug_type($authType)));
        }

        return $result;
    }

    /**
     * Reads the client credentials of the connection: the plain keys, or
     * with $prefix `scan_` the scan service account of `forward_jwt`.
     *
     * @param array<string, mixed> $config
     *
     * @return array{token_url: string, client_id: string, client_secret: string, scope: ?string}
     *
     * @throws \InvalidArgumentException
     */
    private static function clientCredentials(#[\SensitiveParameter] array $config, string $prefix = ''): array
    {
        $values = [];
        foreach (['token_url', 'client_id', 'client_secret'] as $key) {
            $value = $config[$prefix.$key] ?? null;
            if (!\is_string($value) || '' === $value) {
                throw new \InvalidArgumentException(\sprintf(
                    'MCP connection with auth_type "%1$s" needs "%2$stoken_url", "%2$sclient_id" and "%2$sclient_secret".',
                    '' === $prefix ? self::AUTH_CLIENT_CREDENTIALS : self::AUTH_FORWARD_JWT,
                    $prefix,
                ));
            }
            $values[$key] = $value;
        }
        $scope = $config[$prefix.'scope'] ?? null;
        $values['scope'] = \is_string($scope) && '' !== $scope ? $scope : null;

        return $values;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function credentialsPrefix(array $config): string
    {
        return self::AUTH_FORWARD_JWT === ($config['auth_type'] ?? null) ? self::SCAN_PREFIX : '';
    }

    /**
     * Names the connection in token errors and logs: the configJson does not
     * carry the connection name, so the endpoint is used, without user info,
     * query and fragment.
     */
    private static function connectionLabel(string $url): string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['host'])) {
            return '';
        }

        return ($parts['scheme'] ?? 'http').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');
    }
}

// - revised 2026-10-09 06:42:28 UTC
// - revised 2026-10-09 13:43:23 UTC
// - revised 2026-10-14
