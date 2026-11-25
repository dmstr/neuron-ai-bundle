<?php

// file generated with AI assistance: Claude Code - 2026-11-25 21:50:20 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support\Port;

use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Knowledge\CollectionContentPurgerInterface;
use Dmstr\NeuronAiBundle\Knowledge\CollectionPurgeReport;
use Doctrine\DBAL\Connection;

/**
 * Content purge of a collection before its deletion
 * ({@see CollectionContentPurgerInterface}). The test host has no documents
 * of its own, so its content is the collection's vectors in the store table:
 * they are deleted (every model) and counted in the report. Records the
 * purged collection ids. Cleared by {@see TestPorts::reset()}.
 */
final class TestCollectionContentPurger implements CollectionContentPurgerInterface
{
    /** @var list<string> */
    private static array $purged = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $storeTable,
    ) {
    }

    /**
     * @return list<string> ids (RFC 4122) of the collections purged since the last reset
     */
    public static function purged(): array
    {
        return self::$purged;
    }

    public static function reset(): void
    {
        self::$purged = [];
    }

    public function purge(Collection $collection): CollectionPurgeReport
    {
        $id = $collection->getId()->toRfc4122();
        self::$purged[] = $id;

        /** @var list<string> $models */
        $models = $this->connection->fetchFirstColumn(
            \sprintf("SELECT DISTINCT model FROM %s WHERE metadata->>'collection_id' = :id ORDER BY model", $this->storeTable),
            ['id' => $id],
        );
        $vectors = (int) $this->connection->executeStatement(
            \sprintf("DELETE FROM %s WHERE metadata->>'collection_id' = :id", $this->storeTable),
            ['id' => $id],
        );

        return new CollectionPurgeReport(vectors: $vectors, models: $models);
    }
}
