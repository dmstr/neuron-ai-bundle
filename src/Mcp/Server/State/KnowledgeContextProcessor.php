<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:30:10 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp\Server\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\NeuronAiBundle\Conversation\ContextChunk;
use Dmstr\NeuronAiBundle\Mcp\Server\Dto\KnowledgeContext;
use Dmstr\NeuronAiBundle\Mcp\Server\Dto\KnowledgeContextInput;
use Dmstr\NeuronAiBundle\Mcp\Server\ChunkEmbeddings;
use Dmstr\NeuronAiBundle\Mcp\Server\EndpointRetrievalScope;
use Dmstr\NeuronAiBundle\Retrieval\Neighbour;
use Dmstr\NeuronAiBundle\Retrieval\NeighbourSourceInterface;
use NeuronAI\RAG\Document;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * MCP tool `rag_get_context`: the embeddings of one chunk plus its
 * neighbours before and after in the source document, restricted to the
 * collection scope of the current endpoint ({@see EndpointRetrievalScope}).
 *
 * The scope is checked before anything is returned, in this order:
 *
 * 1. Without endpoint or with an empty scope: "not found", no database.
 * 2. The chunk's embeddings in the store table (embedding metadata
 *    `chunk_id`); only embeddings whose `collection_id` lies in the
 *    scope count ({@see EndpointRetrievalScope::contains()}). None left:
 *    "not found".
 * 3. Their collection must still exist ({@see EndpointRetrievalScope::collection()});
 *    a deleted collection whose id is still in the scope: "not found".
 * 4. Only embeddings of the collection's current embedding model are
 *    returned, so a chunk in the middle of a reindex is not returned twice;
 *    none of that model: "not found" (rag_search could not find it either).
 *
 * Every refusal is the same {@see EndpointRetrievalScope::chunkNotFound()},
 * so an answer never tells whether the chunk exists elsewhere.
 *
 * Neighbours come from the application's {@see NeighbourSourceInterface}
 * (none without one) and are asked only after the scope check passed. A
 * neighbour is returned only when its own embeddings lie in the scope as
 * well: the port promises the same source document, and a source document
 * belongs to one collection, but the port only returns chunk id and text,
 * so the store is asked rather than trusted. A neighbour that has no
 * embeddings (not yet searchable) is therefore left out too.
 *
 * Every hit has the score 1: it is looked up by id, not ranked.
 *
 * @implements ProcessorInterface<KnowledgeContextInput, KnowledgeContext>
 */
final readonly class KnowledgeContextProcessor implements ProcessorInterface
{
    /** Score of every returned embedding: looked up by id, not ranked. */
    public const float SCORE = 1.0;

    public function __construct(
        private EndpointRetrievalScope $scope,
        private ChunkEmbeddings $embeddings,
        private ?NeighbourSourceInterface $neighbours = null,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): KnowledgeContext
    {
        if (!$data instanceof KnowledgeContextInput) {
            throw new \InvalidArgumentException('Expected KnowledgeContextInput payload');
        }

        // Validated before (validate: true): a UUID string in any spelling.
        $ref = (string) $data->chunkId;
        if (!Uuid::isValid($ref) || [] === $this->scope->collectionIds()) {
            throw EndpointRetrievalScope::chunkNotFound($ref);
        }
        $chunkId = Uuid::fromString($ref)->toRfc4122();

        $inScope = array_values(array_filter(
            $this->embeddings->embeddings($chunkId),
            fn (Document $embedding): bool => $this->scope->contains(self::collectionId($embedding)),
        ));
        if ([] === $inScope) {
            throw EndpointRetrievalScope::chunkNotFound($ref);
        }

        $collectionId = (string) self::collectionId($inScope[0]);
        try {
            $collection = $this->scope->collection($collectionId);
        } catch (NotFoundHttpException) {
            throw EndpointRetrievalScope::chunkNotFound($ref);
        }
        $collectionId = $collection->getId()->toRfc4122();
        $names = [$collectionId => $collection->getName()];
        $model = $collection->getEmbeddingModel();

        $hit = [];
        foreach ($inScope as $embedding) {
            if ($collectionId !== self::collectionId($embedding) || $model !== ($embedding->getMetadata()['model'] ?? null)) {
                continue;
            }
            $embedding->setScore(self::SCORE);
            $hit[] = ContextChunk::fromDocument($embedding, $names);
        }
        if ([] === $hit) {
            throw EndpointRetrievalScope::chunkNotFound($ref);
        }
        usort($hit, static fn (ContextChunk $a, ContextChunk $b): int => [null === $a->chunkNumber, $a->chunkNumber] <=> [null === $b->chunkNumber, $b->chunkNumber]);

        $found = $this->neighbours?->neighbours($chunkId) ?? [];

        return new KnowledgeContext(
            $hit,
            $this->inScope($found['before'] ?? null),
            $this->inScope($found['after'] ?? null),
        );
    }

    /** $neighbour if its embeddings lie in the scope, else null. */
    private function inScope(?Neighbour $neighbour): ?Neighbour
    {
        if (null === $neighbour) {
            return null;
        }

        $id = $neighbour->chunkId;
        $collectionIds = $this->embeddings->collectionIds(Uuid::isValid($id) ? Uuid::fromString($id)->toRfc4122() : $id);
        if ([] === $collectionIds) {
            return null;
        }
        foreach ($collectionIds as $collectionId) {
            if (!$this->scope->contains($collectionId)) {
                return null;
            }
        }

        return $neighbour;
    }

    /** The embedding's collection id in RFC 4122 spelling, null when missing. */
    private static function collectionId(Document $embedding): ?string
    {
        $id = $embedding->getMetadata()['collection_id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            return null;
        }

        return Uuid::isValid($id) ? Uuid::fromString($id)->toRfc4122() : $id;
    }
}

// - revised 2026-10-14
