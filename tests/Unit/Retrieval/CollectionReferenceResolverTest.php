<?php

// file generated with AI assistance: Claude Code - 2026-10-08 20:34:12 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Retrieval;

use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Retrieval\CollectionReferenceResolver;
use Dmstr\NeuronAiBundle\Retrieval\UnknownCollectionReferenceException;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Kernel-free: the collection reference resolution of the bundle (UUID or
 * name, unknown → {@see UnknownCollectionReferenceException}, 422) and the
 * name lookup by collection id, over a stubbed entity manager that knows
 * the collections of {@see $known}.
 */
final class CollectionReferenceResolverTest extends TestCase
{
    /** @var list<Collection> */
    private array $known = [];

    /** @var list<array{dql: string, ids: mixed, type: mixed}> name queries issued */
    private array $nameQueries = [];

    public function testResolvesNamesAndUuidsToIdsInOrderKeepingDuplicates(): void
    {
        $handbook = $this->known('handbuch');
        $faq = $this->known('faq');

        $ids = $this->resolver()->resolve(['handbuch', strtoupper((string) $faq->getId()), 'handbuch']);

        self::assertSame([
            $handbook->getId()->toRfc4122(),
            $faq->getId()->toRfc4122(),
            $handbook->getId()->toRfc4122(),
        ], $ids);
    }

    public function testCollectionsReturnsTheEntities(): void
    {
        $handbook = $this->known('handbuch');

        self::assertSame([$handbook], $this->resolver()->collections(['handbuch']));
        self::assertSame($handbook, $this->resolver()->collection((string) $handbook->getId()));
        self::assertSame([], $this->resolver()->resolve([]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownReferences(): iterable
    {
        yield 'name' => ['gibt-es-nicht'];
        yield 'uuid' => ['0199a000-0000-4000-8000-000000000000'];
        yield 'empty' => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unknownReferences')]
    public function testUnknownReferenceIsADistinct422(string $reference): void
    {
        $this->known('handbuch');

        try {
            $this->resolver()->resolve(['handbuch', $reference]);
            self::fail('Expected UnknownCollectionReferenceException');
        } catch (UnknownCollectionReferenceException $exception) {
            self::assertSame($reference, $exception->reference);
            self::assertSame(\sprintf('Collection "%s" not found', $reference), $exception->getMessage());
            self::assertSame(422, $exception->getStatusCode());
        }
    }

    public function testNamesByIdsLooksUpValidIdsOnceNormalized(): void
    {
        $handbook = $this->known('handbuch');
        $gone = Uuid::v4()->toRfc4122();

        $names = $this->resolver()->namesByIds([strtoupper((string) $handbook->getId()), null, 'no-uuid', $gone, (string) $handbook->getId()]);

        self::assertSame([$handbook->getId()->toRfc4122() => 'handbuch'], $names);
        self::assertCount(1, $this->nameQueries);
        self::assertSame([$handbook->getId()->toRfc4122(), $gone], $this->nameQueries[0]['ids']);
        self::assertSame(ArrayParameterType::STRING, $this->nameQueries[0]['type']);
    }

    public function testNamesByIdsWithoutValidIdsRunsNoQuery(): void
    {
        self::assertSame([], $this->resolver()->namesByIds([null, '', 'no-uuid']));
        self::assertSame([], $this->nameQueries);
    }

    private function known(string $name): Collection
    {
        $collection = new Collection()->setName($name)->setEmbeddingModel('nomic-embed-text');
        $this->known[] = $collection;

        return $collection;
    }

    private function resolver(): CollectionReferenceResolver
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturnCallback(function (array $criteria): ?Collection {
            foreach ($this->known as $collection) {
                if ($collection->getName() === $criteria['name']) {
                    return $collection;
                }
            }

            return null;
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('find')->willReturnCallback(function (string $class, mixed $id): ?Collection {
            foreach ($this->known as $collection) {
                if (Collection::class === $class && $id instanceof Uuid && $collection->getId()->equals($id)) {
                    return $collection;
                }
            }

            return null;
        });
        $entityManager->method('createQuery')->willReturnCallback(function (string $dql): Query {
            $query = $this->createStub(Query::class);
            $query->method('setParameter')->willReturnCallback(function (string $key, mixed $value, mixed $type = null) use ($dql, $query): Query {
                $this->nameQueries[] = ['dql' => $dql, 'ids' => $value, 'type' => $type];

                return $query;
            });
            $query->method('getArrayResult')->willReturnCallback(function (): array {
                $ids = end($this->nameQueries)['ids'];
                $rows = [];
                foreach ($this->known as $collection) {
                    if (\in_array($collection->getId()->toRfc4122(), $ids, true)) {
                        $rows[] = ['id' => $collection->getId(), 'name' => $collection->getName()];
                    }
                }

                return $rows;
            });

            return $query;
        });

        return new CollectionReferenceResolver($entityManager);
    }
}
