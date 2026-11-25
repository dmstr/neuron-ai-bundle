<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:30:10 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp\Server\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Dmstr\NeuronAiBundle\Conversation\ContextChunk;
use Dmstr\NeuronAiBundle\Retrieval\Neighbour;

/**
 * Result of the MCP tool `rag_get_context`: the embeddings of one chunk
 * in the field shape of the `rag_search` hits ({@see ContextChunk}), plus
 * the neighbouring chunks of its source document.
 */
final readonly class KnowledgeContext
{
    public function __construct(
        /** @var list<ContextChunk> */
        #[ApiProperty(description: 'The passage itself: its embeddings in chunkNumber order, each with content, score (always 1, looked up by id), collectionId, collectionName, topic, sourceUrl, chunkId, chunkNumber, documentId, filename and breadcrumb.')]
        public array $hit = [],
        #[ApiProperty(description: 'The passage directly before it in its uploaded document, with chunkId and content; omitted at the start of a document and for documents without order (ingest, web imports).')]
        public ?Neighbour $before = null,
        #[ApiProperty(description: 'The passage directly after it in its uploaded document, with chunkId and content; omitted at the end of a document and for documents without order (ingest, web imports).')]
        public ?Neighbour $after = null,
    ) {
    }
}
// - revised 2026-10-09 18:40:00 UTC
