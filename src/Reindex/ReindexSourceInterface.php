<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:37:11 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Reindex;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Entity\Collection;

/**
 * Port between the {@see CollectionReindexer} and the application that owns
 * the documents of a collection. The bundle does not know where documents
 * live or how they are chunked; the application implements this interface
 * and aliases it in its service configuration.
 *
 * A document is identified by the store's source group
 * (source_type, source_name): the reindexer uses the pair to check whether
 * the document already has rows of the target model.
 */
interface ReindexSourceInterface
{
    /**
     * Every document of the collection as (sourceType, sourceName), in a
     * stable order so that a resumed reindex walks the same sequence.
     *
     * @return iterable<array{string, string}>
     */
    public function enumerate(Collection $collection): iterable;

    /**
     * Embeds one document with $model through the `ollama` connection
     * $connection (the reindex target, not necessarily the collection's
     * current connection), deleting that model's previous rows of the
     * document first; rows of other models are left alone.
     *
     * @return int number of chunks written
     */
    public function embed(Collection $collection, string $sourceType, string $sourceName, ApiConfiguration $connection, string $model): int;
}
