<?php

// file generated with AI assistance: Claude Code - 2026-10-13 20:10:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Collection;

use ApiPlatform\Metadata\Delete;
use Dmstr\McpEndpoint\Entity\McpEndpoint;
use Dmstr\McpEndpoint\Scope\ScopePruner;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Knowledge\CollectionContentPurgerInterface;
use Dmstr\NeuronAiBundle\Knowledge\CollectionLockedException;
use Dmstr\NeuronAiBundle\Knowledge\CollectionPurgeReport;
use Dmstr\NeuronAiBundle\State\CollectionDeleteProcessor;
use Dmstr\NeuronAiBundle\Tests\Support\CollectionLockGuardStubs;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Order and refusals of {@see CollectionDeleteProcessor}, kernel-free:
 * 423 before anything (stored lock), 409 while a reindex is pending, 409
 * for content without an application purger, and with a purger the order
 * transaction, purge, scope pruning, removal, flush, commit, then the
 * purge's after-commit work (stored files); a failing flush rolls back and
 * leaves the files. The deletion against the database: CollectionDeleteTest
 * (CI).
 */
final class CollectionDeleteProcessorTest extends TestCase
{
    use CollectionLockGuardStubs;

    private const string STORE_TABLE = 'embedding';

    /** @var list<string> */
    private array $log = [];

    public function testANonCollectionIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->processor($this->entityManager(), false)->process(new \stdClass(), new Delete());
    }

    public function testALockedCollectionIsLockedBeforeAnythingIsDeleted(): void
    {
        $purger = $this->purger();

        try {
            $this->processor($this->entityManager(), true, $purger)->process(self::collection(), new Delete());
            self::fail('No 423.');
        } catch (CollectionLockedException $e) {
            self::assertSame(423, $e->getStatusCode());
        }

        self::assertSame([], $this->log);
    }

    public function testAPendingReindexIsAConflict(): void
    {
        $collection = self::collection()->setPendingEmbeddingModel('mxbai-embed-large');

        try {
            $this->processor($this->entityManager(), false, $this->purger())->process($collection, new Delete());
            self::fail('No 409.');
        } catch (ConflictHttpException $e) {
            self::assertSame(409, $e->getStatusCode());
            self::assertStringContainsString('Collection "docs" has a pending reindex to "mxbai-embed-large"; cancel it first', $e->getMessage());
        }

        self::assertSame([], $this->log);
    }

    public function testWithoutPurgerACollectionWithVectorsIsAConflict(): void
    {
        $entityManager = $this->entityManager(vectorRow: 1);

        try {
            $this->processor($entityManager, false)->process(self::collection(), new Delete());
            self::fail('No 409.');
        } catch (ConflictHttpException $e) {
            self::assertStringContainsString('Collection "docs" still has content', $e->getMessage());
        }

        self::assertSame([], $this->log);
    }

    public function testWithoutPurgerAnEmptyCollectionIsRemoved(): void
    {
        $result = $this->processor($this->entityManager(), false)->process(self::collection(), new Delete());

        self::assertNull($result);
        self::assertSame(['begin', 'remove', 'flush', 'commit'], $this->log);
    }

    public function testWithoutPurgerARestrictingForeignKeyIsAConflict(): void
    {
        $entityManager = $this->entityManager(failingFlush: true);

        try {
            $this->processor($entityManager, false)->process(self::collection(), new Delete());
            self::fail('No 409.');
        } catch (ConflictHttpException $e) {
            self::assertStringContainsString('still has content', $e->getMessage());
        }

        self::assertSame(['begin', 'remove', 'flush', 'rollback'], $this->log);
    }

    public function testPurgePruneAndRemoveRunInOneTransactionAndTheFilesAfterTheCommit(): void
    {
        $collection = self::collection();
        $id = $collection->getId()->toRfc4122();
        $endpoint = (new McpEndpoint())->setSlug('kb')->setName('KB')->setScope(['collections' => [$id, '0199c0de-0000-7000-8000-0000000000a2']]);
        $other = (new McpEndpoint())->setSlug('other')->setName('Other')->setScope(['collections' => ['0199c0de-0000-7000-8000-0000000000a2']]);

        $result = $this->processor($this->entityManager(), false, $this->purger(), $this->pruner([$endpoint, $other]))->process($collection, new Delete());

        self::assertNull($result);
        self::assertSame(['begin', 'purge', 'endpoints flush', 'remove', 'flush', 'commit', 'files'], $this->log);
        self::assertSame(['collections' => ['0199c0de-0000-7000-8000-0000000000a2']], $endpoint->getScope());
        self::assertSame(['collections' => ['0199c0de-0000-7000-8000-0000000000a2']], $other->getScope());
    }

    public function testAFailingFlushRollsBackAndKeepsTheFiles(): void
    {
        try {
            $this->processor($this->entityManager(failingFlush: true), false, $this->purger())->process(self::collection(), new Delete());
            self::fail('No 409.');
        } catch (ConflictHttpException $e) {
            self::assertStringContainsString('received new content while it was being deleted', $e->getMessage());
        }

        self::assertSame(['begin', 'purge', 'remove', 'flush', 'rollback'], $this->log);
    }

    public function testTheAfterCommitWorkRunsOnce(): void
    {
        $runs = 0;
        $report = new CollectionPurgeReport(1, 2, 3, ['nomic-embed-text'], ['a/b.md'], function () use (&$runs): void {
            ++$runs;
        });

        $report->afterCommit();
        $report->afterCommit();

        self::assertSame(1, $runs);
        self::assertFalse($report->isEmpty());
        self::assertTrue(new CollectionPurgeReport()->isEmpty());
    }

    private function processor(EntityManagerInterface $entityManager, bool $locked, ?CollectionContentPurgerInterface $purger = null, ?ScopePruner $pruner = null): CollectionDeleteProcessor
    {
        return new CollectionDeleteProcessor($entityManager, $this->lockGuard($locked), self::STORE_TABLE, $purger, $pruner);
    }

    /**
     * An entity manager whose connection logs the transaction calls and
     * answers the vector check with $vectorRow (false: no row).
     */
    private function entityManager(int|false $vectorRow = false, bool $failingFlush = false): EntityManagerInterface
    {
        $active = false;
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn($vectorRow);
        $connection->method('beginTransaction')->willReturnCallback(function () use (&$active): bool {
            $active = true;
            $this->log[] = 'begin';

            return true;
        });
        $connection->method('commit')->willReturnCallback(function () use (&$active): bool {
            $active = false;
            $this->log[] = 'commit';

            return true;
        });
        $connection->method('rollBack')->willReturnCallback(function () use (&$active): bool {
            $active = false;
            $this->log[] = 'rollback';

            return true;
        });
        $connection->method('isTransactionActive')->willReturnCallback(static function () use (&$active): bool {
            return $active;
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('remove')->willReturnCallback(function (): void {
            $this->log[] = 'remove';
        });
        $entityManager->method('flush')->willReturnCallback(function () use ($failingFlush): void {
            $this->log[] = 'flush';
            if ($failingFlush) {
                throw new ForeignKeyConstraintViolationException($this->createStub(DriverException::class), null);
            }
        });

        return $entityManager;
    }

    private function purger(): CollectionContentPurgerInterface
    {
        $log = &$this->log;

        return new class($log) implements CollectionContentPurgerInterface {
            /** @param list<string> $log */
            public function __construct(private array &$log)
            {
            }

            public function purge(Collection $collection): CollectionPurgeReport
            {
                $this->log[] = 'purge';

                return new CollectionPurgeReport(1, 3, 6, ['nomic-embed-text'], ['c/d.md'], function (): void {
                    $this->log[] = 'files';
                });
            }
        };
    }

    /**
     * @param list<McpEndpoint> $endpoints
     */
    private function pruner(array $endpoints): ScopePruner
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findAll')->willReturn($endpoints);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            $this->log[] = 'endpoints flush';
        });

        return new ScopePruner($entityManager);
    }

    private static function collection(): Collection
    {
        return new Collection()->setName('docs');
    }
}
