<?php

// file generated with AI assistance: Claude Code - 2026-10-16 08:40:52 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Artifact;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;

/**
 * The artifact ledger on the table `neuron_artifact`
 * ({@see \Dmstr\NeuronAiBundle\Entity\Artifact}).
 *
 * Plain DBAL instead of the EntityManager: the ledger is written while a
 * turn runs, and a flush there would also flush whatever the turn has
 * pending in the unit of work. The upsert is one `INSERT ... ON CONFLICT`
 * on the unique key `(owner_subject, path_lower)`. The conversation
 * reference is looked up in the same statement, so a conversation deleted
 * in the meantime leaves it null instead of failing the foreign key.
 */
final class DoctrineArtifactLedger implements ArtifactLedgerInterface
{
    private const string UPSERT_SQL = <<<'SQL'
        INSERT INTO neuron_artifact (id, owner_subject, path, path_lower, size_bytes, conversation_id, created_at, updated_at, created_by, updated_by)
        VALUES (:id, :owner, :path, :pathLower, :size, (SELECT c.id FROM neuron_conversation c WHERE c.id = :conversation), :now, :now, :conversation, :conversation)
        ON CONFLICT (owner_subject, path_lower) DO UPDATE
        SET path = EXCLUDED.path, size_bytes = EXCLUDED.size_bytes, updated_at = EXCLUDED.updated_at, updated_by = EXCLUDED.updated_by
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function find(string $ownerSubject, string $pathLower): ?ArtifactLedgerEntry
    {
        $row = $this->connection->fetchAssociative(
            'SELECT path, size_bytes FROM neuron_artifact WHERE owner_subject = :owner AND path_lower = :pathLower',
            ['owner' => $ownerSubject, 'pathLower' => $pathLower],
        );

        return false === $row ? null : new ArtifactLedgerEntry((string) $row['path'], (int) $row['size_bytes']);
    }

    public function countInFolder(string $ownerSubject, string $folder): int
    {
        // LIKE metacharacters of the folder name are escaped (backslash is
        // the PostgreSQL default escape character).
        $prefix = addcslashes(ArtifactPath::lower(rtrim($folder, '/')), '\\%_').'/%';

        return (int) $this->connection->fetchOne(
            'SELECT count(*) FROM neuron_artifact WHERE owner_subject = :owner AND path_lower LIKE :prefix',
            ['owner' => $ownerSubject, 'prefix' => $prefix],
        );
    }

    public function totalSize(string $ownerSubject): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(size_bytes), 0) FROM neuron_artifact WHERE owner_subject = :owner',
            ['owner' => $ownerSubject],
        );
    }

    public function record(string $ownerSubject, string $path, int $sizeBytes, string $conversationId): void
    {
        $this->connection->executeStatement(
            self::UPSERT_SQL,
            [
                'id' => Uuid::v4()->toRfc4122(),
                'owner' => $ownerSubject,
                'path' => $path,
                'pathLower' => ArtifactPath::lower($path),
                'size' => $sizeBytes,
                'conversation' => $conversationId,
                'now' => new \DateTimeImmutable(),
            ],
            ['size' => Types::INTEGER, 'now' => Types::DATETIMETZ_IMMUTABLE],
        );
    }
}
