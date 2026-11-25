<?php

// file generated with AI assistance: Claude Code - 2026-10-06 00:15:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider\Auth;

/**
 * A provider connection or an `mcp` connection could not authenticate: the
 * token endpoint refused the client credentials or answered unusably, the
 * provider rejected the token (HTTP 401/403), or the auth configuration
 * itself is unusable.
 *
 * The message names the connection, the HTTP status and, for the token
 * endpoint, Keycloak's `error` / `error_description` (cut to 200 chars).
 * It never contains the client secret, a token or a raw response body.
 * No transport exception is chained as `previous`: Neuron's HttpException
 * carries the request with its headers and the form body including
 * `client_secret`, which an exception renderer or a log handler could print
 * (same rule as ModelListingException).
 */
final class ProviderAuthenticationException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly string $connectionName = '',
    ) {
        parent::__construct($message);
    }
}

// - revised 2026-10-09 06:42:28 UTC
// - revised 2026-10-14
