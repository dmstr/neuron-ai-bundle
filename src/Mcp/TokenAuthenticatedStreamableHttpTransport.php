<?php

// file generated with AI assistance: Claude Code - 2026-10-09 06:42:28 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp;

use Dmstr\NeuronAiBundle\Provider\Auth\AccessTokenProviderInterface;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\MCP\McpException;
use NeuronAI\MCP\StreamableHttpTransport;

/**
 * Neuron's StreamableHttpTransport with a bearer token from an
 * {@see AccessTokenProviderInterface}, used for `auth_type:
 * client_credentials` (token from Keycloak, see
 * {@see \Dmstr\NeuronAiBundle\Provider\Auth\ClientCredentialsTokenProvider}).
 *
 * - Lazy: the token is asked for in getAuthHeaders(), i.e. on every
 *   request and never before the first one. Creating the transport performs
 *   no I/O. Asking on every request lets the provider's cache renew an
 *   expiring token between the requests of a long turn.
 * - 401: when the server rejects a request with HTTP 401 (an expired or
 *   revoked token), the token is invalidated and the same request is sent
 *   once more with a freshly fetched token. A second 401 surfaces as the
 *   vendor's McpException. Other failures are not retried.
 *
 * A failing token request raises
 * {@see \Dmstr\NeuronAiBundle\Provider\Auth\ProviderAuthenticationException}
 * out of send(); its message names the connection kind and the HTTP status
 * of the token endpoint, never the client secret.
 *
 * The vendor sends `Authorization` from getAuthHeaders(), which merges the
 * configured `headers` and `token`; this class overrides only the token
 * source and leaves session handling, SSE parsing, the request shape and
 * the protocol revision header (`MCP-Protocol-Version`, set by the client
 * through setProtocolVersion() after the handshake) to the vendor. A retried
 * request is sent with the same session and revision headers.
 */
class TokenAuthenticatedStreamableHttpTransport extends StreamableHttpTransport
{
    /**
     * @param array<string, mixed>     $config     StreamableHttpTransport config (url, timeout, headers); a
     *                                             `token` key is ignored
     * @param HttpClientInterface|null $httpClient Neuron HTTP client to send through (tests); null = the
     *                                             vendor's default (CurlHttpClient)
     */
    public function __construct(
        array $config,
        private readonly AccessTokenProviderInterface $tokens,
        ?HttpClientInterface $httpClient = null,
    ) {
        unset($config['token']);
        parent::__construct($config, $httpClient);
    }

    public function send(array $data): void
    {
        try {
            parent::send($data);
        } catch (McpException $e) {
            if (!self::isUnauthorized($e) || !$this->tokens->invalidate()) {
                throw $e;
            }
            parent::send($data);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function getAuthHeaders(): array
    {
        $headers = parent::getAuthHeaders();
        $headers['Authorization'] = 'Bearer '.$this->tokens->token();

        return $headers;
    }

    /**
     * HTTP 401 as neuron-ai 4.x reports it: the McpException of send()
     * chains Neuron's HttpException, whose response carries the status
     * (the exception's own code is 0).
     */
    private static function isUnauthorized(McpException $e): bool
    {
        $previous = $e->getPrevious();

        return $previous instanceof HttpException && 401 === $previous->response?->statusCode;
    }
}

// - revised 2026-10-14
// - revised 2026-10-14
