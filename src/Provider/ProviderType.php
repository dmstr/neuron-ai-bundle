<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Doctrine\Persistence\Proxy;

/**
 * The provider connection types: `ollama` and `anthropic` connections of
 * dmstr/api-configuration-bundle carry the endpoint and the credentials of
 * a chat or embeddings provider (see {@see Connection\OllamaConnectionType}
 * and {@see Connection\AnthropicConnectionType}).
 *
 * The type of a connection is its `configJson.type`, the same value the
 * vendor entity extracts into its `type` column.
 */
final class ProviderType
{
    public const string OLLAMA = 'ollama';
    public const string ANTHROPIC = 'anthropic';

    /** Types that serve chat (and agent) turns. */
    public const array CHAT_TYPES = [self::OLLAMA, self::ANTHROPIC];

    /** Types that serve embeddings of collections. */
    public const array EMBEDDING_TYPES = [self::OLLAMA];

    private function __construct()
    {
    }

    /**
     * The provider type of a connection.
     *
     * @throws \InvalidArgumentException when the connection is no provider connection (e.g. `mcp`, `firecrawl`)
     */
    public static function of(ApiConfiguration $connection): string
    {
        $type = self::typeOf($connection);
        if (!\in_array($type, self::CHAT_TYPES, true)) {
            throw new \InvalidArgumentException(\sprintf('Connection "%s" is not a provider connection (type "%s", expected one of: %s).', self::nameOf($connection), $type ?? '', implode(', ', self::CHAT_TYPES)));
        }

        return $type;
    }

    /**
     * Whether the connection is an `ollama` or `anthropic` connection.
     */
    public static function isProvider(ApiConfiguration $connection): bool
    {
        return \in_array(self::typeOf($connection), self::CHAT_TYPES, true);
    }

    /**
     * Whether the connection can serve embeddings (`ollama` only).
     */
    public static function isEmbedding(ApiConfiguration $connection): bool
    {
        return \in_array(self::typeOf($connection), self::EMBEDDING_TYPES, true);
    }

    private static function typeOf(ApiConfiguration $connection): ?string
    {
        $type = $connection->getConfigJson()['type'] ?? null;

        return \is_string($type) ? $type : null;
    }

    /**
     * The name of a connection, also for an entity built without one.
     *
     * A connection reached through an association (e.g. the connection of
     * a model catalogue entry) can be an uninitialised Doctrine proxy whose
     * properties are all unset; it is loaded first, otherwise its name would
     * read as empty.
     */
    public static function nameOf(ApiConfiguration $connection): string
    {
        if ($connection instanceof Proxy && !$connection->__isInitialized()) {
            $connection->__load();
        }

        return (new \ReflectionProperty(ApiConfiguration::class, 'name'))->isInitialized($connection) ? $connection->getName() : '';
    }
}
