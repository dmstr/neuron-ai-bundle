<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:16:39 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp\Server\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use Dmstr\McpEndpoint\Tool\ToolInspector;
use Dmstr\NeuronAiBundle\Mcp\Server\CollectionScopeResolver;
use Dmstr\NeuronAiBundle\Mcp\Server\Dto\KnowledgeCollectionList;
use Dmstr\NeuronAiBundle\Mcp\Server\Dto\KnowledgeContext;
use Dmstr\NeuronAiBundle\Mcp\Server\Dto\KnowledgeContextInput;
use Dmstr\NeuronAiBundle\Mcp\Server\Dto\KnowledgeSearchInput;
use Dmstr\NeuronAiBundle\Mcp\Server\Dto\KnowledgeSearchResult;
use Dmstr\NeuronAiBundle\Mcp\Server\State\KnowledgeCollectionsProvider;
use Dmstr\NeuronAiBundle\Mcp\Server\State\KnowledgeContextProcessor;
use Dmstr\NeuronAiBundle\Mcp\Server\State\KnowledgeSearchProcessor;

/**
 * The knowledge base as MCP tools, served through dmstr/mcp-endpoint-bundle
 * at `/mcp/{slug}` (no HTTP operation).
 *
 * Every tool is read-only, needs the endpoint scope `collections`
 * (`meta['de.dmstr/scope']`, so an endpoint listing it must name at least
 * one collection) and sees only that scope
 * ({@see \Dmstr\NeuronAiBundle\Mcp\Server\EndpointRetrievalScope}). Tool
 * `security` is `neuronai:knowledge:read`; the endpoint's `requiredPermission` is checked
 * before, and the stricter of both wins. Arguments are validated
 * (`validate: true`, MCP tools skip validation otherwise).
 *
 * Descriptions are one line and name the returned fields, because some MCP
 * clients (Neuron) ignore `structuredContent` and the output schema.
 *
 * Mapped by API Platform only while the endpoint bundle is loaded
 * ({@see \Dmstr\NeuronAiBundle\DmstrNeuronAiBundle::prependExtension()}).
 */
#[ApiResource(
    operations: [],
    mcp: [
        self::TOOL_LIST_COLLECTIONS => new McpTool(
            name: self::TOOL_LIST_COLLECTIONS,
            description: 'List the knowledge collections this endpoint may search. Returns collections, each with id, name, description (omitted when empty), documentCount, chunkCount (omitted when unknown) and embeddingModel; pass a name or id as collection to rag_search.',
            annotations: ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true],
            meta: [ToolInspector::SCOPE_META_KEY => CollectionScopeResolver::SCOPE_KEY],
            security: "is_granted('neuronai:knowledge:read')",
            validate: true,
            provider: KnowledgeCollectionsProvider::class,
            output: KnowledgeCollectionList::class,
        ),
        self::TOOL_SEARCH => new McpTool(
            name: self::TOOL_SEARCH,
            description: 'Search the knowledge collections of this endpoint for the passages closest in meaning to the query. Returns hits ranked by score, each with content, score, collectionId, collectionName, topic, sourceUrl, chunkId, chunkNumber, documentId, filename and breadcrumb; empty fields are omitted.',
            annotations: ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true],
            meta: [ToolInspector::SCOPE_META_KEY => CollectionScopeResolver::SCOPE_KEY],
            security: "is_granted('neuronai:knowledge:read')",
            validate: true,
            input: KnowledgeSearchInput::class,
            output: KnowledgeSearchResult::class,
            processor: KnowledgeSearchProcessor::class,
        ),
        self::TOOL_GET_CONTEXT => new McpTool(
            name: self::TOOL_GET_CONTEXT,
            description: 'Get a search hit with its surrounding text by the chunkId of a rag_search hit. Returns hit (its chunks in order, with the fields of a rag_search hit) and before and after (the neighbouring passages of an uploaded document, each with chunkId and content; omitted when there is none).',
            annotations: ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true],
            meta: [ToolInspector::SCOPE_META_KEY => CollectionScopeResolver::SCOPE_KEY],
            security: "is_granted('neuronai:knowledge:read')",
            validate: true,
            input: KnowledgeContextInput::class,
            output: KnowledgeContext::class,
            processor: KnowledgeContextProcessor::class,
        ),
    ],
)]
final class Knowledge
{
    public const string TOOL_LIST_COLLECTIONS = 'rag_list_collections';
    public const string TOOL_SEARCH = 'rag_search';
    public const string TOOL_GET_CONTEXT = 'rag_get_context';
}
// - revised 2026-10-09 16:30:10 UTC
// - revised 2026-10-09 18:32:44 UTC
// - revised 2026-10-13
