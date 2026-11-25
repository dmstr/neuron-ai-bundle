<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:17:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Embeddings;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Provider\ProviderType;
use Dmstr\NeuronAiBundle\ProviderFactory;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

/**
 * Hands out the embeddings provider of an `ollama` connection and a
 * registered model ({@see ProviderFactory::embeddingsProvider()}), wrapped
 * in {@see PrefixedEmbeddingsProvider} with the model's task prefixes.
 *
 * One instance per (connection id, model) is created on first use and
 * reused afterwards; the key also carries a fingerprint of the
 * connection's configuration, so a long-running worker picks up a changed
 * URL instead of reusing the provider of the old one. Every call checks
 * the connection first: a connection that is inactive or no `ollama`
 * connection raises an {@see EmbeddingConnectionException} (422), so
 * embedding jobs and reindex runs on a deactivated connection fail with a
 * clear message instead of reaching the server.
 */
final class EmbeddingsProviderLocator
{
    /** @var array<string, EmbeddingsProviderInterface> */
    private array $providers = [];

    public function __construct(
        private readonly ProviderFactory $factory,
        private readonly EmbeddingModelRegistry $registry,
    ) {
    }

    /**
     * @throws EmbeddingConnectionException for an inactive or non-`ollama` connection
     * @throws \InvalidArgumentException    when the model is not configured or not served by `ollama`
     */
    public function for(ApiConfiguration $connection, string $model): EmbeddingsProviderInterface
    {
        self::assertUsable($connection);

        return $this->providers[self::key($connection, $model)] ??= PrefixedEmbeddingsProvider::forModel(
            $this->factory->embeddingsProvider($connection, $model),
            $this->registry->get($model),
        );
    }

    /**
     * The provider of the collection's current connection and model.
     *
     * @throws EmbeddingConnectionException for a collection without embedding connection (stale) or an unusable connection
     * @throws \InvalidArgumentException    when the collection has no model or the model is not configured
     */
    public function forCollection(Collection $collection): EmbeddingsProviderInterface
    {
        $connection = $collection->getEmbeddingProviderConnection() ?? throw EmbeddingConnectionException::missing($collection);
        $model = $collection->getEmbeddingModel();
        if (null === $model || '' === $model) {
            throw new \InvalidArgumentException(\sprintf('Collection "%s" has no embedding model.', $collection->getName()));
        }

        return $this->for($connection, $model);
    }

    /**
     * Checks that the connection can serve embeddings now: an active
     * `ollama` connection.
     *
     * @throws EmbeddingConnectionException
     */
    public static function assertUsable(ApiConfiguration $connection): void
    {
        if (!ProviderType::isEmbedding($connection)) {
            throw EmbeddingConnectionException::wrongType($connection);
        }
        if (!$connection->isActive()) {
            throw EmbeddingConnectionException::inactive($connection);
        }
    }

    /**
     * Cache key of a (connection, model) pair plus a fingerprint of the
     * stored configuration (secrets as stored, i.e. encrypted).
     */
    private static function key(ApiConfiguration $connection, string $model): string
    {
        return $connection->getId()->toRfc4122()."\0".$model."\0".md5(serialize($connection->getConfigJson()));
    }
}
