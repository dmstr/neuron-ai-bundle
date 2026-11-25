<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:16:39 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Retrieval\CollectionReferenceResolver;
use Dmstr\NeuronAiBundle\Retrieval\UnknownCollectionReferenceException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Collection references without database for the unit tests of the
 * knowledge MCP tools: a reference is the id (any UUID spelling) or the
 * name of one of the given collections, anything else is unknown. Records
 * every lookup in {@see $lookups}.
 */
final class InMemoryCollectionReferenceResolver extends CollectionReferenceResolver
{
    /** @var list<string> references looked up, in order */
    public array $lookups = [];

    /** @var array<string, Collection> */
    private array $byId = [];

    /**
     * @param EntityManagerInterface $entityManager a stub; never used
     */
    public function __construct(EntityManagerInterface $entityManager, Collection ...$collections)
    {
        parent::__construct($entityManager);

        foreach ($collections as $collection) {
            $this->byId[$collection->getId()->toRfc4122()] = $collection;
        }
    }

    /** A collection that is not persisted, with a fixed id if given. */
    public static function make(string $name, ?string $id = null): Collection
    {
        $collection = (new Collection())->setName($name)->setEmbeddingModel('nomic-embed-text');
        if (null !== $id) {
            (new \ReflectionProperty(Collection::class, 'id'))->setValue($collection, Uuid::fromString($id));
        }

        return $collection;
    }

    public function collection(string $ref): Collection
    {
        $this->lookups[] = $ref;

        if (Uuid::isValid($ref)) {
            $found = $this->byId[Uuid::fromString($ref)->toRfc4122()] ?? null;
        } else {
            $found = null;
            foreach ($this->byId as $collection) {
                if ($collection->getName() === $ref) {
                    $found = $collection;
                    break;
                }
            }
        }

        if (null === $found) {
            throw new UnknownCollectionReferenceException($ref);
        }

        return $found;
    }
}
