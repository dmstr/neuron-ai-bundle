<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:52:48 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractPostgreSQLDriver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;

/**
 * A PostgreSQL DBAL driver without database: a real
 * {@see Connection} on top of it runs its own code (method lookup, SQL
 * parser, named-to-positional parameter expansion, binding) and hands the
 * resulting statement to this driver, which records SQL and bound values
 * in {@see $queries} and answers with the rows of {@see $responder}.
 *
 * Rows are given the way pdo_pgsql returns them: `jsonb` as a JSON string,
 * `uuid` and `text` as strings, `count()`/`bigint` as int.
 */
final class RecordingPostgreSqlDriver extends AbstractPostgreSQLDriver
{
    /** @var list<array{sql: string, params: array<int|string, mixed>}> */
    public array $queries = [];

    /** @var \Closure(string, array<int|string, mixed>): list<array<string, mixed>> */
    private \Closure $responder;

    /**
     * @param callable(string, array<int|string, mixed>): list<array<string, mixed>> $responder rows per executed SQL and bound values
     */
    public function __construct(callable $responder)
    {
        $this->responder = \Closure::fromCallable($responder);
    }

    /**
     * A DBAL connection on this driver (PostgreSQL 16 platform, no version
     * detection round trip).
     */
    public function connection(): Connection
    {
        return new Connection(['serverVersion' => '16.0'], $this);
    }

    public function connect(array $params): DriverConnection
    {
        $driver = $this;

        return new class($driver) implements DriverConnection {
            public function __construct(private readonly RecordingPostgreSqlDriver $driver)
            {
            }

            public function prepare(string $sql): Statement
            {
                return new class($this->driver, $sql) implements Statement {
                    /** @var array<int|string, mixed> */
                    private array $params = [];

                    public function __construct(private readonly RecordingPostgreSqlDriver $driver, private readonly string $sql)
                    {
                    }

                    public function bindValue($param, $value, $type = ParameterType::STRING): bool
                    {
                        $this->params[$param] = $value;

                        return true;
                    }

                    public function bindParam($param, &$variable, $type = ParameterType::STRING, $length = null): bool
                    {
                        $this->params[$param] = $variable;

                        return true;
                    }

                    public function execute($params = null): Result
                    {
                        return $this->driver->execute($this->sql, $params ?? $this->params);
                    }
                };
            }

            public function query(string $sql): Result
            {
                return $this->driver->execute($sql, []);
            }

            public function quote($value, $type = ParameterType::STRING): string
            {
                return "'".str_replace("'", "''", (string) $value)."'";
            }

            public function exec(string $sql): int
            {
                $this->driver->execute($sql, []);

                return 0;
            }

            public function lastInsertId($name = null): string
            {
                return '0';
            }

            public function beginTransaction(): bool
            {
                return true;
            }

            public function commit(): bool
            {
                return true;
            }

            public function rollBack(): bool
            {
                return true;
            }

            public function getNativeConnection(): object
            {
                return $this;
            }
        };
    }

    /**
     * Records one executed statement and answers with the responder's rows.
     *
     * @param array<int|string, mixed> $params
     *
     * @internal called by the statements of this driver
     */
    public function execute(string $sql, array $params): Result
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];

        return new class(($this->responder)($sql, $params)) implements Result {
            /** @param list<array<string, mixed>> $rows */
            public function __construct(private array $rows)
            {
            }

            public function fetchNumeric(): array|false
            {
                $row = $this->fetchAssociative();

                return false === $row ? false : array_values($row);
            }

            public function fetchAssociative(): array|false
            {
                return array_shift($this->rows) ?? false;
            }

            public function fetchOne(): mixed
            {
                $row = $this->fetchNumeric();

                return false === $row ? false : $row[0];
            }

            public function fetchAllNumeric(): array
            {
                return array_map(array_values(...), $this->drain());
            }

            public function fetchAllAssociative(): array
            {
                return $this->drain();
            }

            public function fetchFirstColumn(): array
            {
                return array_map(static fn (array $row): mixed => array_values($row)[0], $this->drain());
            }

            public function rowCount(): int
            {
                return \count($this->rows);
            }

            public function columnCount(): int
            {
                return [] === $this->rows ? 0 : \count($this->rows[0]);
            }

            public function free(): void
            {
                $this->rows = [];
            }

            /** @return list<array<string, mixed>> */
            private function drain(): array
            {
                $rows = $this->rows;
                $this->rows = [];

                return $rows;
            }
        };
    }
}
