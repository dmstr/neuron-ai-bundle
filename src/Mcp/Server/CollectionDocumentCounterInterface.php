<?php

// file generated with AI assistance: Claude Code - 2026-10-13 20:10:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp\Server;

/**
 * Document and chunk counts of collections for the MCP tool
 * `rag_list_collections`.
 *
 * The bundle default {@see CollectionDocumentCounter} counts in the vector
 * store table (the only table the bundle owns) and knows no chunk count.
 * An application that keeps documents and chunks in its own tables aliases
 * this interface to an implementation that counts there.
 */
interface CollectionDocumentCounterInterface
{
    /**
     * Number of searchable documents per collection.
     *
     * @param list<string> $collectionIds collection ids (RFC 4122)
     *
     * @return array<string, int> collection id => document count, one entry per given id
     */
    public function count(array $collectionIds): array;

    /**
     * Number of chunks per collection, where the implementation knows it.
     *
     * @param list<string> $collectionIds collection ids (RFC 4122)
     *
     * @return array<string, int> collection id => chunk count; ids without a known count are missing
     */
    public function countChunks(array $collectionIds): array;
}
