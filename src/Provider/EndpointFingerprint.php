<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;

/**
 * The endpoint a provider connection points to, as a sha256 of its
 * normalised base URL: `configJson.url` of an `ollama` connection,
 * `configJson.base_url` (or the public API) of an `anthropic` connection.
 *
 * Every catalogue row ({@see \Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel})
 * stores the fingerprint of the endpoint it was described from. A row whose
 * fingerprint differs from the connection's current one describes another
 * server: it is `stale`, and the capability check treats it as unknown
 * until the next scan. Comparing at read time needs no listener on the
 * vendor entity and also catches edits made outside the ORM.
 */
final class EndpointFingerprint
{
    private function __construct()
    {
    }

    public static function of(ApiConfiguration $connection): string
    {
        return hash('sha256', self::normalizedUrl($connection->getConfigJson()));
    }

    /**
     * The base URL without surrounding whitespace and trailing slashes,
     * scheme and host lowercased.
     *
     * @param array<string, mixed> $config
     */
    public static function normalizedUrl(array $config): string
    {
        $url = ProviderType::ANTHROPIC === ($config['type'] ?? null)
            ? AnthropicModelLister::baseUrl($config)
            : (\is_string($config['url'] ?? null) ? $config['url'] : '');

        $url = rtrim(trim($url), '/');

        return (string) preg_replace_callback('#^([a-z][a-z0-9+.-]*://)([^/?\#]*)#i', static fn (array $m): string => strtolower($m[1].$m[2]), $url);
    }
}
