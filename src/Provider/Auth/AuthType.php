<?php

// file generated with AI assistance: Claude Code - 2026-10-06 00:15:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider\Auth;

/**
 * Values of the `auth_type` key of an `ollama` connection. A missing key
 * means NONE, so connections created before authentication existed keep
 * working unchanged.
 */
enum AuthType: string
{
    /** Plain Ollama without authentication (default). */
    case NONE = 'none';

    /** Static `token`, sent as `Authorization: Bearer <token>`. */
    case BEARER = 'bearer';

    /** OAuth2 client credentials grant against `token_url` (Keycloak). */
    case CLIENT_CREDENTIALS = 'client_credentials';
}
