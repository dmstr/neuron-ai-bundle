<?php

// file generated with AI assistance: Claude Code - 2026-10-13 19:06:18 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use Dmstr\NeuronAiBundle\Knowledge\CollectionLockGuard;
use Doctrine\DBAL\Connection;

/**
 * A real {@see CollectionLockGuard} (final) over a stubbed DBAL connection
 * for kernel-free tests: the stored row is locked or not.
 */
trait CollectionLockGuardStubs
{
    private function lockGuard(bool $locked): CollectionLockGuard
    {
        $database = $this->createStub(Connection::class);
        // `locked_at` of the stored row: a timestamp when locked, null when not.
        $database->method('fetchOne')->willReturn($locked ? '2026-10-13 12:00:00' : null);

        return new CollectionLockGuard($database);
    }
}
