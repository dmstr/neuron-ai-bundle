<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider\Connection;

use Dmstr\NeuronAiBundle\Provider\ProviderType;

/**
 * The `ollama` connection type (ollama.schema.json): `url` with the `/api`
 * suffix, optional `timeout`, and optional authentication
 * via `auth_type`: `none` (default), `bearer` (static `token`) or
 * `client_credentials` (OAuth2 against `token_url` with `client_id`,
 * `client_secret` and an optional `scope`, e.g. Keycloak). `token` and
 * `client_secret` are `writeOnly` secrets: encrypted at rest, masked on
 * read. Health: `GET {url}/tags`. There is no `num_ctx`: the context
 * length is a model setting (agent `modelOptions.numCtx`, else the
 * Modelfile, else the server default).
 */
final class OllamaConnectionType extends AbstractProviderConnectionType
{
    public const string NAME = ProviderType::OLLAMA;

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSchemaPath(): string
    {
        return __DIR__.'/ollama.schema.json';
    }

    public function getEndpoint(array $config): string
    {
        return \is_string($config['url'] ?? null) ? self::withoutUserInfo($config['url']) : 'urn:dmstr:ollama';
    }

    private static function withoutUserInfo(string $url): string
    {
        return (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@\s]+@#i', '$1', $url);
    }
}

// - revised 2026-10-07
// - revised 2026-11-25
