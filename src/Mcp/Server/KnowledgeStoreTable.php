<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:30:10 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp\Server;

use Doctrine\DBAL\Connection;

/**
 * Read access of the knowledge MCP tools to the vector store table the
 * bundle owns (`dmstr_neuron_ai.store.table`, columns id, content,
 * source_type, source_name, metadata JSONB, model, vector), on the
 * default DBAL connection.
 *
 * Every read selects rows by metadata containment (`metadata @> :filter`),
 * which uses the GIN index on `metadata`. Shared by
 * {@see CollectionDocumentCounter} and {@see ChunkEmbeddings}, so the
 * table name and the filter live in one place. The DBAL connection
 * connects on its first query, so holding this object costs nothing.
 *
 * The table name is written into the SQL unquoted, exactly like
 * {@see \Dmstr\NeuronPgvectorStore\PgVectorStore} writes it, so both name
 * the same table (an unquoted name is folded to lower case by PostgreSQL,
 * a quoted one is not). It comes from the bundle configuration, never
 * from a request.
 */
final readonly class KnowledgeStoreTable
{
    public function __construct(
        private Connection $connection,
        private string $table,
    ) {
    }

    /**
     * The first column of the first row of
     * `SELECT $select FROM <table> WHERE metadata @> $metadata`.
     *
     * @param array<string, scalar> $metadata
     */
    public function fetchOneContaining(string $select, array $metadata): mixed
    {
        return $this->connection->fetchOne($this->sql($select), ['filter' => self::filter($metadata)]);
    }

    /**
     * All rows of `SELECT $select FROM <table> WHERE metadata @> $metadata`.
     *
     * @param array<string, scalar> $metadata
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAllContaining(string $select, array $metadata): array
    {
        return $this->connection->fetchAllAssociative($this->sql($select), ['filter' => self::filter($metadata)]);
    }

    private function sql(string $select): string
    {
        return \sprintf(
            'SELECT %s FROM %s WHERE metadata @> CAST(:filter AS jsonb)',
            $select,
            $this->table,
        );
    }

    /**
     * @param array<string, scalar> $metadata
     */
    private static function filter(array $metadata): string
    {
        return json_encode($metadata, \JSON_THROW_ON_ERROR);
    }
}
// - revised 2026-10-09 16:52:48 UTC
