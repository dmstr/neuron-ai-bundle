<?php

// file generated with AI assistance: Claude Code - 2026-09-30 06:17:47 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Doctrine;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Removes the vector store table from the schema the ORM generates from its
 * mapping (`postGenerateSchema`).
 *
 * The store table is owned by migrations that mirror
 * `PgVectorStore::setupTable()` (dimensionless pgvector column, `model`
 * column, GIN and source indexes, the partial HNSW index of the initial
 * model), not by the ORM. The partial HNSW indexes of further models are
 * created at runtime by `PgVectorStore::ensureModelIndex()`. An
 * application may still map the table onto a read-only entity, e.g. to
 * expose chunks via API Platform. The ORM's schema tool, however, ignores
 * the DBAL `schema_filter` for every table present in the mapped schema: it
 * introspects such tables from the database anyway and compares them, which
 * yields bogus diffs for the hand-written DDL (and used to crash on the
 * introspected `vector(N)` column). Dropping the table from the generated
 * schema keeps `doctrine:schema:validate`/`update`/`create` away from it;
 * the app's `schema_filter` then applies to the database side as intended.
 */
final class ExcludeStoreTableListener
{
    public function __construct(
        private readonly string $table,
    ) {
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable($this->table)) {
            $schema->dropTable($this->table);
        }
    }
}
