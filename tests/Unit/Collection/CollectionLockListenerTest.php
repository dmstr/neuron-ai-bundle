<?php

// file generated with AI assistance: Claude Code - 2026-10-13 18:45:18 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Collection;

use Dmstr\NeuronAiBundle\Doctrine\CollectionLockListener;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Knowledge\CollectionLockedException;
use Dmstr\NeuronAiBundle\Knowledge\CollectionLockGuard;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Change-set logic of {@see CollectionLockListener} and the stored-state
 * read of {@see CollectionLockGuard}, with a stubbed DBAL connection
 * (`fetchOne` of `locked_at`: false for no row, null for unlocked, a
 * timestamp for locked); kernel-free.
 */
class CollectionLockListenerTest extends TestCase
{
    private const string STORED_LOCK = '2026-10-13 12:00:00+00';

    /**
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function changeSets(): iterable
    {
        yield 'lock fields only' => [['lockedAt', 'lockedBy'], []];
        yield 'lock fields and updatedAt' => [['lockedAt', 'lockedBy', 'updatedAt'], []];
        yield 'description' => [['description'], ['description']];
        yield 'mixed' => [['lockedAt', 'name', 'keywordLanguage'], ['name', 'keywordLanguage']];
        yield 'association' => [['embeddingProviderModel'], ['embeddingProviderModel']];
    }

    /**
     * @param list<string> $fields
     * @param list<string> $guarded
     */
    #[DataProvider('changeSets')]
    public function testGuardedChanges(array $fields, array $guarded): void
    {
        self::assertSame($guarded, CollectionLockListener::guardedChanges($fields));
    }

    /**
     * @return iterable<string, array{array<string, array{mixed, mixed}>, list<string>}>
     */
    public static function reindexChangeSets(): iterable
    {
        $cancel = [
            'pendingEmbeddingModel' => ['mxbai-embed-large', null],
            'pendingEmbeddingProviderModel' => [new \stdClass(), null],
            'pendingEmbeddingProviderConnection' => [new \stdClass(), null],
            'reindexJobId' => ['0199c0de-0000-7000-8000-000000000001', null],
        ];
        yield 'reindex cancel' => [$cancel, []];
        yield 'reindex cancel and updatedAt' => [$cancel + ['updatedAt' => [new \DateTimeImmutable(), new \DateTimeImmutable()]], []];
        yield 'job id cleared only' => [['reindexJobId' => ['0199c0de-0000-7000-8000-000000000001', null]], []];
        yield 'pending model set' => [['pendingEmbeddingModel' => [null, 'mxbai-embed-large']], ['pendingEmbeddingModel']];
        yield 'job id set' => [['reindexJobId' => [null, '0199c0de-0000-7000-8000-000000000001']], ['reindexJobId']];
        yield 'cancel together with a change' => [$cancel + ['description' => [null, 'neu']], ['description']];
        yield 'another field cleared' => [['description' => ['alt', null]], ['description']];
    }

    /**
     * @param array<string, array{mixed, mixed}> $changeSet
     * @param list<string>                       $refused
     */
    #[DataProvider('reindexChangeSets')]
    public function testRefusedChanges(array $changeSet, array $refused): void
    {
        self::assertSame($refused, CollectionLockListener::refusedChanges($changeSet));
    }

    /**
     * `POST .../reindex/cancel` on a locked collection: clearing the
     * reindex state passes without reading the database.
     */
    public function testAReindexCancelOfALockedCollectionPasses(): void
    {
        $collection = self::locked('docs');
        $changeSet = [
            'pendingEmbeddingModel' => ['mxbai-embed-large', null],
            'pendingEmbeddingProviderConnection' => [new \stdClass(), null],
            'reindexJobId' => ['0199c0de-0000-7000-8000-000000000001', null],
        ];
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('fetchOne');

        new CollectionLockListener(new CollectionLockGuard($database))->preUpdate($collection, $this->updateArgs($collection, $changeSet));

        $this->addToAssertionCount(1);
    }

    public function testSettingAPendingModelOfALockedCollectionIsRefused(): void
    {
        $collection = self::locked('docs');
        $changeSet = ['pendingEmbeddingModel' => [null, 'mxbai-embed-large'], 'reindexJobId' => ['0199c0de-0000-7000-8000-000000000001', null]];

        $this->expectException(CollectionLockedException::class);

        $this->listener(self::STORED_LOCK)->preUpdate($collection, $this->updateArgs($collection, $changeSet));
    }

    public function testAChangeOfALockedCollectionIsRefused(): void
    {
        $collection = self::locked('docs');
        $changeSet = ['description' => [null, 'neu']];

        $this->expectException(CollectionLockedException::class);
        $this->expectExceptionMessage('Collection "docs" is locked');

        $this->listener(self::STORED_LOCK)->preUpdate($collection, $this->updateArgs($collection, $changeSet));
    }

    public function testARenameOfALockedCollectionNamesTheStoredName(): void
    {
        $collection = self::locked('renamed');
        $changeSet = ['name' => ['docs', 'renamed']];

        $this->expectExceptionMessage('Collection "docs" is locked');

        $this->listener(self::STORED_LOCK)->preUpdate($collection, $this->updateArgs($collection, $changeSet));
    }

    public function testUnlockingPassesWithoutReadingTheDatabase(): void
    {
        $collection = new Collection()->setName('docs');
        $changeSet = ['lockedAt' => [new \DateTimeImmutable(), null], 'lockedBy' => ['subject-1', null]];
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('fetchOne');

        new CollectionLockListener(new CollectionLockGuard($database))->preUpdate($collection, $this->updateArgs($collection, $changeSet));

        $this->addToAssertionCount(1);
    }

    public function testLockingTogetherWithAChangeOfAnUnlockedCollectionPasses(): void
    {
        $collection = self::locked('docs');
        $changeSet = ['lockedAt' => [null, $collection->getLockedAt()], 'lockedBy' => [null, 'subject-1'], 'description' => [null, 'neu']];

        $this->listener(null)->preUpdate($collection, $this->updateArgs($collection, $changeSet));

        $this->addToAssertionCount(1);
    }

    public function testUnlockingTogetherWithAChangeIsRefused(): void
    {
        $collection = new Collection()->setName('docs');
        $changeSet = ['lockedAt' => [new \DateTimeImmutable(), null], 'lockedBy' => ['subject-1', null], 'description' => [null, 'neu']];

        $this->expectException(CollectionLockedException::class);

        $this->listener(null)->preUpdate($collection, $this->updateArgs($collection, $changeSet));
    }

    public function testAChangeOfAnUnlockedCollectionPasses(): void
    {
        $collection = new Collection()->setName('docs');
        $changeSet = ['description' => [null, 'neu']];

        $this->listener(null)->preUpdate($collection, $this->updateArgs($collection, $changeSet));

        $this->addToAssertionCount(1);
    }

    /**
     * A stale entity (unlocked in memory) of a collection locked in the
     * database is refused.
     */
    public function testAStaleUnlockedEntityOfALockedRowIsRefused(): void
    {
        $collection = new Collection()->setName('docs');
        $changeSet = ['description' => [null, 'neu']];

        $this->expectException(CollectionLockedException::class);

        $this->listener(self::STORED_LOCK)->preUpdate($collection, $this->updateArgs($collection, $changeSet));
    }

    public function testRemovingALockedCollectionIsRefused(): void
    {
        $collection = self::locked('docs');

        $this->expectException(CollectionLockedException::class);

        $this->listener(self::STORED_LOCK)->preRemove($collection, new PreRemoveEventArgs($collection, $this->createStub(EntityManagerInterface::class)));
    }

    public function testRemovingAStaleEntityOfALockedRowIsRefused(): void
    {
        $collection = new Collection()->setName('docs');

        $this->expectException(CollectionLockedException::class);

        $this->listener(self::STORED_LOCK)->preRemove($collection, new PreRemoveEventArgs($collection, $this->createStub(EntityManagerInterface::class)));
    }

    public function testRemovingAnUnlockedCollectionPasses(): void
    {
        $collection = new Collection()->setName('docs');

        $this->listener(null)->preRemove($collection, new PreRemoveEventArgs($collection, $this->createStub(EntityManagerInterface::class)));

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string|false|null, bool}>
     */
    public static function storedStates(): iterable
    {
        yield 'no row (new collection)' => [false, true];
        yield 'unlocked' => [null, true];
        yield 'locked' => [self::STORED_LOCK, false];
    }

    #[DataProvider('storedStates')]
    public function testTheGuardReadsTheStoredState(string|false|null $lockedAt, bool $writable): void
    {
        // The entity says locked; the stored row decides.
        $collection = self::locked('docs');
        $guard = new CollectionLockGuard($this->database($lockedAt));

        self::assertSame($writable, $guard->isWritable($collection));
        if (!$writable) {
            $this->expectException(CollectionLockedException::class);
            $this->expectExceptionMessage('Collection "docs" is locked');
        }
        $guard->assertWritable($collection);
    }

    private static function locked(string $name): Collection
    {
        $collection = new Collection()->setName($name);
        $collection->lock('subject-1', new \DateTimeImmutable('2026-10-13 12:00:00+00:00'));

        return $collection;
    }

    private function listener(string|false|null $storedLockedAt): CollectionLockListener
    {
        return new CollectionLockListener(new CollectionLockGuard($this->database($storedLockedAt)));
    }

    private function database(string|false|null $lockedAt): Connection
    {
        $database = $this->createStub(Connection::class);
        $database->method('fetchOne')->willReturn($lockedAt);

        return $database;
    }

    /**
     * @param array<string, array{mixed, mixed}> $changeSet
     */
    private function updateArgs(Collection $collection, array $changeSet): PreUpdateEventArgs
    {
        return new PreUpdateEventArgs($collection, $this->createStub(EntityManagerInterface::class), $changeSet);
    }
}
// - revised 2026-10-13 20:10:00 UTC
