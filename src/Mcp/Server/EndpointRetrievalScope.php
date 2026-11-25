<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:16:39 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp\Server;

use Dmstr\McpEndpoint\Context\McpEndpointContext;
use Dmstr\McpEndpoint\Entity\McpEndpoint;
use Dmstr\McpEndpoint\Policy\McpEndpointPolicyInterface;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Retrieval\CollectionReferenceResolver;
use Dmstr\NeuronAiBundle\Retrieval\UnknownCollectionReferenceException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * What the knowledge tools of the current MCP endpoint may see and how they
 * search: the collection scope (`scope.collections`) and the retrieval
 * settings (`retrieval.maxK`, `hybrid`, `hybridWeight`) of the endpoint in
 * {@see McpEndpointContext}, with the defaults of
 * {@see McpEndpointPolicyInterface} for unset keys.
 *
 * The one way the RAG tools read their endpoint. Default deny: without an
 * endpoint in the context (a call outside `/mcp/{slug}`) the scope is empty.
 * A collection outside the scope and an unknown one are both "not found"
 * with the same message ({@see collectionNotFound()}), so an answer never
 * tells whether a collection exists elsewhere.
 *
 * Scope ids carry no foreign key: an id of a deleted collection stays in the
 * endpoint until its next save. {@see collectionIds()} and {@see contains()}
 * work on the stored ids without database access; {@see collections()} and
 * {@see collection()} load the collections and skip dead ids silently.
 */
final readonly class EndpointRetrievalScope
{
    public function __construct(
        private McpEndpointContext $context,
        private McpEndpointPolicyInterface $policy,
        private CollectionReferenceResolver $collections,
    ) {
    }

    /** Whether the request runs on an MCP endpoint at all. */
    public function hasEndpoint(): bool
    {
        return null !== $this->context->get();
    }

    /**
     * The collection ids of the endpoint scope (RFC 4122 where the stored id
     * is a UUID), dead ids included; empty without endpoint. No database
     * access.
     *
     * @return list<string>
     */
    public function collectionIds(): array
    {
        $endpoint = $this->context->get();
        if (null === $endpoint) {
            return [];
        }

        $ids = [];
        foreach ($endpoint->getScopeIds(CollectionScopeResolver::SCOPE_KEY) as $id) {
            $normalized = self::normalizeId($id);
            $ids[$normalized] = $normalized;
        }

        return array_values($ids);
    }

    /**
     * Whether $collectionId (any UUID spelling) is in the scope; for checks
     * of ids read from chunk or document metadata. No database access.
     */
    public function contains(?string $collectionId): bool
    {
        if (null === $collectionId || '' === $collectionId) {
            return false;
        }

        return \in_array(self::normalizeId($collectionId), $this->collectionIds(), true);
    }

    /**
     * The existing collections of the scope, in scope order; ids of deleted
     * collections are skipped silently.
     *
     * @return list<Collection>
     */
    public function collections(): array
    {
        $collections = [];
        foreach ($this->collectionIds() as $id) {
            try {
                $collections[] = $this->collections->collection($id);
            } catch (UnknownCollectionReferenceException) {
                // Dead id: the collection was deleted after the endpoint was saved.
            }
        }

        return $collections;
    }

    /**
     * The collection of a reference (UUID or name) if it lies in the scope.
     *
     * A UUID outside the scope and an empty scope are refused before the
     * database is asked; a name is resolved first and then checked.
     *
     * @throws NotFoundHttpException for a reference that is unknown or outside the scope ({@see collectionNotFound()})
     */
    public function collection(string $ref): Collection
    {
        $scope = $this->collectionIds();
        if ([] === $scope || (Uuid::isValid($ref) && !\in_array(self::normalizeId($ref), $scope, true))) {
            throw self::collectionNotFound($ref);
        }

        try {
            $collection = $this->collections->collection($ref);
        } catch (UnknownCollectionReferenceException) {
            throw self::collectionNotFound($ref);
        }

        if (!\in_array($collection->getId()->toRfc4122(), $scope, true)) {
            throw self::collectionNotFound($ref);
        }

        return $collection;
    }

    /**
     * Upper bound of the result count: the endpoint's `retrieval.maxK`, else
     * the policy default, always within 1..{@see McpEndpointPolicyInterface::MAX_K_LIMIT}.
     */
    public function maxK(): int
    {
        $maxK = $this->retrieval()['maxK'] ?? null;
        if (!\is_int($maxK)) {
            $maxK = $this->policy->defaultMaxK();
        }

        return max(1, min($maxK, McpEndpointPolicyInterface::MAX_K_LIMIT));
    }

    /** $k clamped to 1..{@see maxK()}: the store is never asked for 0 or more than maxK rows. */
    public function clampK(int $k): int
    {
        return max(1, min($k, $this->maxK()));
    }

    /** Hybrid retrieval: the endpoint's `retrieval.hybrid`, else the policy default. */
    public function hybrid(): bool
    {
        $hybrid = $this->retrieval()['hybrid'] ?? null;

        return \is_bool($hybrid) ? $hybrid : $this->policy->defaultHybrid();
    }

    /** Keyword share of hybrid retrieval: the endpoint's `retrieval.hybridWeight`, else the policy default, within 0..1. */
    public function hybridWeight(): float
    {
        $weight = $this->retrieval()['hybridWeight'] ?? null;
        $weight = \is_int($weight) || \is_float($weight) ? (float) $weight : $this->policy->defaultHybridWeight();

        return max(0.0, min($weight, 1.0));
    }

    /**
     * The one "not found" answer of the knowledge tools for a collection
     * reference, unknown and outside the scope alike; the text equals the
     * one of an unknown collection in the search API.
     */
    public static function collectionNotFound(string $ref): NotFoundHttpException
    {
        return new NotFoundHttpException(\sprintf('Collection "%s" not found', $ref));
    }

    /**
     * The one "not found" answer of `rag_get_context` for a chunk id:
     * unknown, without chunks, in a collection outside the scope or in a
     * deleted collection alike.
     */
    public static function chunkNotFound(string $chunkId): NotFoundHttpException
    {
        return new NotFoundHttpException(\sprintf('Chunk "%s" not found', $chunkId));
    }

    /**
     * @return array{maxK?: int, hybrid?: bool, hybridWeight?: float|int}
     */
    private function retrieval(): array
    {
        $endpoint = $this->context->get();

        return $endpoint instanceof McpEndpoint ? $endpoint->getRetrieval() : [];
    }

    private static function normalizeId(string $id): string
    {
        return Uuid::isValid($id) ? Uuid::fromString($id)->toRfc4122() : $id;
    }
}
// - revised 2026-10-09 16:30:10 UTC
