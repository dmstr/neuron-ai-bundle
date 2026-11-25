<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:16:39 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp\Server\Dto;

use ApiPlatform\Metadata\ApiProperty;

/**
 * Result of the MCP tool `rag_list_collections`.
 */
final readonly class KnowledgeCollectionList
{
    public function __construct(
        /** @var list<KnowledgeCollection> */
        #[ApiProperty(description: 'The collections this endpoint may search, each with id, name, description, documentCount and embeddingModel.')]
        public array $collections = [],
    ) {
    }
}
