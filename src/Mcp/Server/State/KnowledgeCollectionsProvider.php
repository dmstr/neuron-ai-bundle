<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:16:39 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp\Server\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Dmstr\NeuronAiBundle\Mcp\Server\CollectionDocumentCounterInterface;
use Dmstr\NeuronAiBundle\Mcp\Server\Dto\KnowledgeCollection;
use Dmstr\NeuronAiBundle\Mcp\Server\Dto\KnowledgeCollectionList;
use Dmstr\NeuronAiBundle\Mcp\Server\EndpointRetrievalScope;

/**
 * MCP tool `rag_list_collections`: the existing collections of the current
 * endpoint's scope ({@see EndpointRetrievalScope::collections()}, ids of
 * deleted collections skipped silently) with their document counts and,
 * where the counter knows it, their chunk counts
 * ({@see CollectionDocumentCounterInterface}). Without
 * endpoint or with an empty scope the list is empty.
 *
 * @implements ProviderInterface<KnowledgeCollectionList>
 */
final readonly class KnowledgeCollectionsProvider implements ProviderInterface
{
    public function __construct(
        private EndpointRetrievalScope $scope,
        private CollectionDocumentCounterInterface $documents,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): KnowledgeCollectionList
    {
        $collections = $this->scope->collections();
        if ([] === $collections) {
            return new KnowledgeCollectionList();
        }

        $ids = [];
        foreach ($collections as $collection) {
            $ids[] = $collection->getId()->toRfc4122();
        }
        $counts = $this->documents->count($ids);
        $chunks = $this->documents->countChunks($ids);

        $list = [];
        foreach ($collections as $index => $collection) {
            $list[] = new KnowledgeCollection(
                id: $ids[$index],
                name: $collection->getName(),
                description: $collection->getDescription(),
                documentCount: $counts[$ids[$index]] ?? 0,
                embeddingModel: $collection->getEmbeddingModel(),
                chunkCount: $chunks[$ids[$index]] ?? null,
            );
        }

        return new KnowledgeCollectionList($list);
    }
}
// - revised 2026-10-13 20:10:00 UTC
