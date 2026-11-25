<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:30:10 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use Dmstr\NeuronAiBundle\Mcp\Server\ChunkEmbeddings;
use Dmstr\NeuronAiBundle\Mcp\Server\KnowledgeStoreTable;
use Doctrine\DBAL\Connection;
use NeuronAI\RAG\Document;

/**
 * Stored embeddings without database for the unit tests of
 * `rag_get_context`: embeddings added with {@see add()}, every read recorded
 * in {@see $reads}.
 */
final class InMemoryChunkEmbeddings extends ChunkEmbeddings
{
    /** @var list<string> chunk ids read, in order */
    public array $reads = [];

    /** @var array<string, list<Document>> */
    private array $embeddings = [];

    /**
     * @param Connection $connection a stub; never used
     */
    public function __construct(Connection $connection)
    {
        parent::__construct(new KnowledgeStoreTable($connection, 'store'));
    }

    public function add(string $chunkId, string $collectionId, string $content, int $chunkNumber, string $model = 'nomic-embed-text'): self
    {
        $this->embeddings[$chunkId][] = (new Document($content))->setMetadata([
            'collection_id' => $collectionId,
            'chunk_id' => $chunkId,
            'chunk_number' => $chunkNumber,
            'model' => $model,
        ]);

        return $this;
    }

    public function embeddings(string $chunkId): array
    {
        $this->reads[] = $chunkId;

        return array_map(static fn (Document $document): Document => clone $document, $this->embeddings[$chunkId] ?? []);
    }

    public function collectionIds(string $chunkId): array
    {
        $this->reads[] = $chunkId;

        return array_values(array_unique(array_map(
            static fn (Document $document): string => $document->getMetadata()['collection_id'],
            $this->embeddings[$chunkId] ?? [],
        )));
    }
}

