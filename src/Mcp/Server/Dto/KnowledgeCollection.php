<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:16:39 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp\Server\Dto;

use ApiPlatform\Metadata\ApiProperty;

/**
 * One knowledge collection as the MCP tool `rag_list_collections` lists it.
 */
final readonly class KnowledgeCollection
{
    public function __construct(
        #[ApiProperty(description: 'Id (UUID) of the collection; usable as `collection` of rag_search.')]
        public string $id,
        #[ApiProperty(description: 'Name of the collection; usable as `collection` of rag_search.')]
        public string $name,
        #[ApiProperty(description: 'What the collection contains; omitted when not described.')]
        public ?string $description,
        #[ApiProperty(description: 'Number of searchable documents: an uploaded file or a source URL counts once, every other passage once.')]
        public int $documentCount,
        #[ApiProperty(description: 'Embedding model the collection is searched with.')]
        public ?string $embeddingModel,
        #[ApiProperty(description: 'Number of passages (chunks) of the collection; omitted when not known.')]
        public ?int $chunkCount = null,
    ) {
    }
}
// - revised 2026-10-09 18:40:00 UTC
// - revised 2026-10-13 20:10:00 UTC
