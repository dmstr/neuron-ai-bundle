<?php

// file generated with AI assistance: Claude Code - 2026-10-13 18:45:18 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Collection;

use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Knowledge\CollectionLockedException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Lock state of the Collection entity and the 423 exception; kernel-free.
 */
class CollectionLockModelTest extends TestCase
{
    public function testANewCollectionIsUnlocked(): void
    {
        $collection = new Collection()->setName('docs');

        self::assertFalse($collection->isLocked());
        self::assertNull($collection->getLockedAt());
        self::assertNull($collection->getLockedBy());
    }

    public function testLockAndUnlock(): void
    {
        $collection = new Collection()->setName('docs');
        $at = new \DateTimeImmutable('2026-10-13 12:00:00+00:00');

        $collection->lock('subject-1', $at);

        self::assertTrue($collection->isLocked());
        self::assertSame($at, $collection->getLockedAt());
        self::assertSame('subject-1', $collection->getLockedBy());

        $collection->unlock();

        self::assertFalse($collection->isLocked());
        self::assertNull($collection->getLockedAt());
        self::assertNull($collection->getLockedBy());
    }

    public function testLockingALockedCollectionKeepsTheFirstLock(): void
    {
        $collection = new Collection()->setName('docs');
        $at = new \DateTimeImmutable('2026-10-13 12:00:00+00:00');
        $collection->lock('subject-1', $at);

        $collection->lock('subject-2', new \DateTimeImmutable('2026-10-14 12:00:00+00:00'));

        self::assertSame($at, $collection->getLockedAt());
        self::assertSame('subject-1', $collection->getLockedBy());
    }

    public function testUnlockingAnUnlockedCollectionChangesNothing(): void
    {
        $collection = new Collection()->setName('docs');

        $collection->unlock();

        self::assertFalse($collection->isLocked());
    }

    public function testTheExceptionIs423WithTheName(): void
    {
        $exception = new CollectionLockedException(new Collection()->setName('docs-de'));

        self::assertInstanceOf(HttpExceptionInterface::class, $exception);
        self::assertSame(423, $exception->getStatusCode());
        self::assertSame('Collection "docs-de" is locked', $exception->getMessage());
        self::assertSame('docs-de', $exception->getCollectionName());
    }

    public function testTheExceptionTakesAName(): void
    {
        $exception = new CollectionLockedException('docs-en');

        self::assertSame(423, $exception->getStatusCode());
        self::assertSame('Collection "docs-en" is locked', $exception->getMessage());
    }
}
