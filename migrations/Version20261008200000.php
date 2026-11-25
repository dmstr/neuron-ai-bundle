<?php

// file generated with AI assistance: Claude Code - 2026-10-08 20:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Dmstr\NeuronAiBundle\Provider\ModelReferenceMigrationSql as Sql;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Collections reference the model catalogue:
 *  - `neuron_collection.embedding_model_id`: the entry of the embedding
 *    model, next to `embedding_connection_id` and the vector label
 *    `embedding_model`, which stay as the entry's connection and the
 *    registered name;
 *  - `neuron_collection.pending_embedding_model_id`: the entry of the
 *    target model of a running reindex, next to
 *    `pending_embedding_connection_id` and `pending_embedding_model`.
 * Both are foreign keys to `neuron_provider_model` without cascade (the
 * delete guard refuses to delete a connection whose entries are
 * referenced with 409; the keys are the backstop).
 *
 * Every collection with a connection and a label gets the entry of that
 * pair, the current and the pending one: the exact name first, else with
 * or without `:latest` (the label `nomic-embed-text` finds the entry
 * `nomic-embed-text:latest` the scan created); a pair without an entry gets
 * a placeholder entry (`available` false, stale fingerprint, an untagged
 * Ollama name with `:latest`), see {@see Sql}. The labels stay as they
 * are: store rows, chunk ids and the partial vector indexes are keyed by
 * them. Ingest, retrieval and reindex runs read the connection and the
 * label and never the entry, so a placeholder changes nothing for them.
 *
 * Then `embedding_model_id` becomes NOT NULL. `pending_embedding_model_id`
 * stays nullable: a pending target exists only during a reindex, and a
 * pending label without a pending connection (queued before provider
 * connections) keeps no entry; the reindex run refuses such a target.
 *
 * A collection whose current model cannot get an entry (no
 * `embedding_connection_id`, as stored before provider connections, a
 * connection type the catalogue does not hold, or a label too long for an
 * entry, {@see Sql::withoutEntry()}) is not deleted here: up() aborts
 * before any statement ran, with the number of such collections and the
 * query that lists them, and the operator deletes them before running the
 * migration again: with SQL (their vectors in the store table
 * `dmstr_neuron_ai.store.table`, raw documents and documents first, the
 * foreign keys restrict), or through
 * the API of the previous release before the upgrade (this release maps
 * the new columns, so its API does not load collections before the
 * migration ran).
 *
 * down() drops the columns. Placeholder entries stay: other references may
 * use them, and the down() of `Version20261008180000` deletes the ones no
 * scan took over.
 */
final class Version20261008200000 extends AbstractMigration
{
    /** Current embedding models: `row_id`, `connection_id`, `name`. */
    private const string CURRENT_PAIRS = <<<'SQL'
        SELECT c.id AS row_id, c.embedding_connection_id AS connection_id, c.embedding_model AS name
        FROM neuron_collection c
        WHERE c.embedding_connection_id IS NOT NULL AND c.embedding_model <> ''
        SQL;

    /** Target models of running reindexes: `row_id`, `connection_id`, `name`. */
    private const string PENDING_PAIRS = <<<'SQL'
        SELECT c.id AS row_id, c.pending_embedding_connection_id AS connection_id, c.pending_embedding_model AS name
        FROM neuron_collection c
        WHERE c.pending_embedding_connection_id IS NOT NULL AND c.pending_embedding_model <> ''
        SQL;

    /** Collections whose current model gets no catalogue entry: `id`, `name`. */
    private static function withoutEntryQuery(): string
    {
        return 'SELECT c.id, c.name FROM neuron_collection c WHERE '.Sql::withoutEntry('c.embedding_connection_id', 'c.embedding_model').' ORDER BY c.name';
    }

    public function getDescription(): string
    {
        return 'Add neuron_collection.embedding_model_id (NOT NULL) and pending_embedding_model_id (FKs to the model catalogue), filled from connection and label with placeholder entries (of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $query = self::withoutEntryQuery();
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ('.$query.') w');
        $this->abortIf($count > 0, \sprintf(
            '%d collection(s) cannot get an entry of the model catalogue for their embedding model (no embedding connection, a connection type the catalogue does not hold, or a label too long for an entry), so neuron_collection.embedding_model_id cannot become NOT NULL. Delete them, then migrate again: with SQL (their vectors in the store table dmstr_neuron_ai.store.table, raw documents and documents first, the foreign keys restrict), or through the API of the previous release before its upgrade (DELETE /api/admin/documents/{id} and /api/admin/raw_documents/{id}, then /api/admin/collections/{id}). List them with: %s',
            $count,
            $query,
        ));

        $this->addSql('ALTER TABLE neuron_collection ADD embedding_model_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE neuron_collection ADD pending_embedding_model_id UUID DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN neuron_collection.embedding_model_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_collection.pending_embedding_model_id IS '(DC2Type:uuid)'");
        $this->addSql('CREATE INDEX IDX_9A8D89A0A4CC6317 ON neuron_collection (embedding_model_id)');
        $this->addSql('CREATE INDEX IDX_9A8D89A0EAD3CF77 ON neuron_collection (pending_embedding_model_id)');
        $this->addSql('ALTER TABLE neuron_collection ADD CONSTRAINT FK_9A8D89A0A4CC6317 FOREIGN KEY (embedding_model_id) REFERENCES neuron_provider_model (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE neuron_collection ADD CONSTRAINT FK_9A8D89A0EAD3CF77 FOREIGN KEY (pending_embedding_model_id) REFERENCES neuron_provider_model (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(Sql::insertPlaceholders(self::CURRENT_PAIRS.' UNION ALL '.self::PENDING_PAIRS));
        foreach (['embedding_model_id' => self::CURRENT_PAIRS, 'pending_embedding_model_id' => self::PENDING_PAIRS] as $column => $pairs) {
            $this->addSql(\sprintf(
                <<<'SQL'
                    UPDATE neuron_collection c
                    SET %1$s = r.model_id
                    FROM (SELECT p.row_id, %2$s AS model_id FROM (%3$s) p) r
                    WHERE c.id = r.row_id AND r.model_id IS NOT NULL
                    SQL,
                $column,
                Sql::entryId('p.connection_id', 'p.name'),
                $pairs,
            ));
        }
        $this->addSql('ALTER TABLE neuron_collection ALTER embedding_model_id SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_collection DROP CONSTRAINT FK_9A8D89A0EAD3CF77');
        $this->addSql('ALTER TABLE neuron_collection DROP CONSTRAINT FK_9A8D89A0A4CC6317');
        $this->addSql('DROP INDEX IDX_9A8D89A0EAD3CF77');
        $this->addSql('DROP INDEX IDX_9A8D89A0A4CC6317');
        $this->addSql('ALTER TABLE neuron_collection DROP pending_embedding_model_id');
        $this->addSql('ALTER TABLE neuron_collection DROP embedding_model_id');
    }
}
