<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:16:39 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp\Server\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Dmstr\NeuronAiBundle\Conversation\ContextChunk;

/**
 * Result of the MCP tool `rag_search`: the hits in the field shape of the
 * search API (`POST /api/search`, `hits`), one {@see ContextChunk} each.
 */
final readonly class KnowledgeSearchResult
{
    public function __construct(
        /** @var list<ContextChunk> */
        #[ApiProperty(description: 'Hits ranked by score, best first; each with content, score, collectionId, collectionName, topic, sourceUrl, chunkId, chunkNumber, documentId, filename and breadcrumb.')]
        public array $hits = [],
    ) {
    }
}
