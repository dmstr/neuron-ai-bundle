<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:35:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Per-conversation turn lock: a session-level PostgreSQL advisory lock on
 * the default connection, keyed by {@see keyFor()}.
 *
 * Session-level locks need the same database session for lock and unlock,
 * so this does not work behind a transaction-pooling connection pooler. The
 * running flag of a conversation is derived from `pg_locks` with the same
 * key (classid = high 32 bits, objid = low 32 bits, objsubid = 1), see
 * {@see isHeld()}.
 */
final class TurnLock
{
    /**
     * PostgreSQL stores a 64-bit advisory key as `classid` (high 32 bits)
     * and `objid` (low 32 bits) with `objsubid = 1`; the bigint shift wraps,
     * so the signed key compares correctly.
     */
    private const string HELD_SQL = <<<'SQL'
        SELECT count(*) FROM pg_locks
        WHERE locktype = 'advisory'
          AND database = (SELECT oid FROM pg_database WHERE datname = current_database())
          AND objsubid = 1
          AND ((classid::bigint << 32) | objid::bigint) = :key
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * The signed 64-bit advisory lock key: the first 8 bytes of the UUID,
     * big-endian.
     */
    public static function keyFor(Uuid $id): int
    {
        // 'J' reads an unsigned 64-bit big-endian value; PHP has no unsigned
        // integers, so values >= 2^63 come back as the negative two's
        // complement, which is the signed bigint PostgreSQL expects.
        $value = unpack('J', substr($id->toBinary(), 0, 8));
        if (false === $value) {
            throw new \LogicException('Could not unpack the UUID.');
        }

        return (int) $value[1];
    }

    /**
     * @return bool false when another session holds the lock
     */
    public function acquire(Uuid $id): bool
    {
        return (bool) $this->connection->fetchOne('SELECT pg_try_advisory_lock(CAST(:key AS BIGINT))', ['key' => self::keyFor($id)], ['key' => ParameterType::INTEGER]);
    }

    public function release(Uuid $id): void
    {
        $this->connection->fetchOne('SELECT pg_advisory_unlock(CAST(:key AS BIGINT))', ['key' => self::keyFor($id)], ['key' => ParameterType::INTEGER]);
    }

    /**
     * Whether any session (this one included) holds the lock, i.e. whether
     * a turn of the conversation runs. The lock dies with its worker, so
     * this is never stale.
     */
    public function isHeld(Uuid $id): bool
    {
        return (int) $this->connection->fetchOne(self::HELD_SQL, ['key' => self::keyFor($id)], ['key' => ParameterType::INTEGER]) > 0;
    }
}
