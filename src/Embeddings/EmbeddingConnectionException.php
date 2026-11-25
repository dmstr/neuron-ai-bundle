<?php

// file generated with AI assistance: Claude Code - 2026-10-05 20:27:34 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Embeddings;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Provider\ProviderType;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A collection's embedding connection cannot serve embeddings: the
 * collection has none (stored before provider connections, stale), or the
 * connection is inactive or no `ollama` connection.
 *
 * HTTP 422 by itself, so a search or a stateless chat over such a
 * collection answers 422 instead of 500; in a conversation turn it ends the
 * turn with `status: error`, in an embedding job or a reindex it fails the
 * document or the job with this message (messenger retry, reindex failure
 * count). The messages name the connection, never its configuration.
 */
final class EmbeddingConnectionException extends UnprocessableEntityHttpException
{
    /** The collection has no embedding connection. */
    public const string MISSING = 'missing';

    /** The embedding connection is inactive. */
    public const string INACTIVE = 'inactive';

    /** The embedding connection is no `ollama` connection. */
    public const string WRONG_TYPE = 'wrong_type';

    /** A reindex is pending without a pending connection or catalogue entry. */
    public const string NO_PENDING = 'no_pending';

    /**
     * @param self::MISSING|self::INACTIVE|self::WRONG_TYPE|self::NO_PENDING $reason
     */
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function missing(Collection $collection): self
    {
        return new self(\sprintf(
            'Collection "%s" has no embedding connection (it was stored before provider connections). Reindex it with another embedding model of the model catalogue, which names its connection: POST /api/admin/collections/%s/reindex with {"model": "<IRI or UUID of an /api/refs/provider_models entry>"}.',
            $collection->getName(),
            $collection->getId(),
        ), self::MISSING);
    }

    public static function inactive(ApiConfiguration $connection): self
    {
        return new self(\sprintf(
            'Embedding connection "%s" is inactive. Activate it, or reindex the collection with another embedding model and an active connection.',
            ProviderType::nameOf($connection),
        ), self::INACTIVE);
    }

    public static function wrongType(ApiConfiguration $connection): self
    {
        return new self(\sprintf(
            'Connection "%s" cannot serve embeddings (expected one of: %s).',
            ProviderType::nameOf($connection),
            implode(', ', ProviderType::EMBEDDING_TYPES),
        ), self::WRONG_TYPE);
    }

    public static function noPending(Collection $collection): self
    {
        return new self(\sprintf(
            'Collection "%s" has a pending embedding model "%s" without a pending connection or catalogue entry (queued before provider connections or the model reference). Cancel the reindex and queue it again: POST /api/admin/collections/%s/reindex/cancel.',
            $collection->getName(),
            (string) $collection->getPendingEmbeddingModel(),
            $collection->getId(),
        ), self::NO_PENDING);
    }
}
