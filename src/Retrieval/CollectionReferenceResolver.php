<?php

// file generated with AI assistance: Claude Code - 2026-10-08 20:34:12 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Retrieval;

use Dmstr\NeuronAiBundle\Entity\Collection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Resolves collection references to collections: a reference is a
 * collection UUID (any RFC 4122 spelling) or a collection name (exact
 * match). An unknown reference throws {@see UnknownCollectionReferenceException}
 * (HTTP 422). Also maps the collection ids found in chunk metadata back to
 * the current collection names.
 *
 * The one resolver for the search request ({@see KnowledgeSearch}), the
 * stateless chat and the document upload of the app, and for the
 * `collections` scope of MCP endpoints, which stores the ids
 * {@see resolve()} returns. IRIs are not references here; a caller that
 * accepts them strips the IRI prefix first.
 *
 * Not final, so callers can be tested with a double.
 */
class CollectionReferenceResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Collection ids (RFC 4122) of the references, one per reference in the
     * given order; duplicates are kept.
     *
     * @param list<string> $refs collection UUIDs or names
     *
     * @return list<string>
     *
     * @throws UnknownCollectionReferenceException for the first reference that matches no collection
     */
    public function resolve(array $refs): array
    {
        return array_map(
            static fn (Collection $collection): string => $collection->getId()->toRfc4122(),
            $this->collections($refs),
        );
    }

    /**
     * Collections of the references, one per reference in the given order;
     * duplicates are kept.
     *
     * @param list<string> $refs collection UUIDs or names
     *
     * @return list<Collection>
     *
     * @throws UnknownCollectionReferenceException for the first reference that matches no collection
     */
    public function collections(array $refs): array
    {
        return array_map($this->collection(...), array_values($refs));
    }

    /**
     * The collection of one reference.
     *
     * @throws UnknownCollectionReferenceException when no collection matches
     */
    public function collection(string $ref): Collection
    {
        $collection = Uuid::isValid($ref)
            ? $this->entityManager->find(Collection::class, Uuid::fromString($ref))
            : $this->entityManager->getRepository(Collection::class)->findOneBy(['name' => $ref]);

        if (!$collection instanceof Collection) {
            throw new UnknownCollectionReferenceException($ref);
        }

        return $collection;
    }

    /**
     * Maps collection ids to names with one query. Ids that are not valid
     * UUIDs or no longer exist are missing from the result.
     *
     * @param iterable<string|null> $ids
     *
     * @return array<string, string> collection id (RFC 4122) => name
     */
    public function namesByIds(iterable $ids): array
    {
        $valid = [];
        foreach ($ids as $id) {
            if (null !== $id && Uuid::isValid($id)) {
                $valid[Uuid::fromString($id)->toRfc4122()] = true;
            }
        }
        if ([] === $valid) {
            return [];
        }

        $rows = $this->entityManager
            ->createQuery(\sprintf('SELECT c.id, c.name FROM %s c WHERE c.id IN (:ids)', Collection::class))
            ->setParameter('ids', array_keys($valid), ArrayParameterType::STRING)
            ->getArrayResult();

        $names = [];
        foreach ($rows as $row) {
            $names[(string) $row['id']] = $row['name'];
        }

        return $names;
    }
}
