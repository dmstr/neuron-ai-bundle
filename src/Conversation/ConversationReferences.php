<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:54:19 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Resolves the collection and MCP connection references of a new
 * conversation to the full UUIDs it stores. Shared by the authenticated
 * creation (`ConversationCreateProcessor`, references from the request) and
 * the anonymous creation (references from the released policy).
 *
 *  - collections: each reference is a collection UUID, an IRI ending in
 *    one, or a collection name; an unknown reference answers 422;
 *  - MCP connections: any reference {@see McpConnectionResolver::resolveMany()}
 *    accepts (IRI, full or partial UUID, name; 422 for unknown, inactive,
 *    ambiguous or non-`mcp` ones), so the turn passes the stored list
 *    straight back to it. References a caller names directly go through
 *    {@see self::connectionIdsForDirectUse()} (restricted connections: 403
 *    without `neuronai:mcp_connection:use_restricted`).
 *
 * Both lists keep the first-seen order and drop duplicates.
 */
final class ConversationReferences
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly McpConnectionResolver $connectionResolver,
    ) {
    }

    /**
     * @param list<string> $references
     *
     * @return list<string> full UUIDs, first-seen order
     *
     * @throws UnprocessableEntityHttpException for an unknown reference
     */
    public function collectionIds(array $references): array
    {
        $ids = [];
        foreach ($references as $reference) {
            $collection = $this->findCollection(trim($reference));
            if (null === $collection) {
                throw new UnprocessableEntityHttpException(\sprintf('Unknown collection "%s".', $reference));
            }
            $ids[$collection->getId()->toRfc4122()] = true;
        }

        return array_keys($ids);
    }

    /**
     * @param list<string> $references
     *
     * @return list<string> full UUIDs of the resolved connections, first-seen order
     *
     * @throws UnprocessableEntityHttpException for a reference the resolver rejects
     */
    public function connectionIds(array $references): array
    {
        if ([] === $references) {
            return [];
        }

        $ids = [];
        foreach ($this->connectionResolver->resolveMany($references) as $connection) {
            $ids[$connection->getId()->toRfc4122()] = true;
        }

        return array_keys($ids);
    }

    /**
     * {@see self::connectionIds()} for references a caller names directly
     * (`POST /conversations` without an agent): a restricted connection
     * additionally requires `neuronai:mcp_connection:use_restricted`
     * ({@see McpConnectionResolver::resolveManyForDirectUse()}).
     *
     * @param list<string> $references
     *
     * @return list<string> full UUIDs of the resolved connections, first-seen order
     *
     * @throws UnprocessableEntityHttpException for a reference the resolver rejects
     * @throws AccessDeniedHttpException        for a restricted connection the caller may not name directly
     */
    public function connectionIdsForDirectUse(array $references): array
    {
        if ([] === $references) {
            return [];
        }

        $ids = [];
        foreach ($this->connectionResolver->resolveManyForDirectUse($references) as $connection) {
            $ids[$connection->getId()->toRfc4122()] = true;
        }

        return array_keys($ids);
    }

    private function findCollection(string $reference): ?Collection
    {
        if ('' === $reference) {
            return null;
        }

        // IRI such as /api/admin/collections/<uuid>: the last path segment.
        $candidate = str_contains($reference, '/') ? basename(rtrim($reference, '/')) : $reference;
        if (Uuid::isValid($candidate)) {
            return $this->entityManager->find(Collection::class, Uuid::fromString($candidate));
        }
        if (str_contains($reference, '/')) {
            return null;
        }

        return $this->entityManager->getRepository(Collection::class)->findOneBy(['name' => $reference]);
    }
}

// - revised 2026-10-09
