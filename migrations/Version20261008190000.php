<?php

// file generated with AI assistance: Claude Code - 2026-10-08 18:10:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Dmstr\NeuronAiBundle\Provider\ModelReferenceMigrationSql as Sql;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Conversations reference the model catalogue:
 * `neuron_conversation.provider_model_id`, a foreign key to
 * `neuron_provider_model` without cascade (the delete guard refuses to
 * delete a connection whose entries are referenced with 409; the key is the
 * backstop), next to `provider_connection_id` and the model name `model`,
 * which stay as the entry's connection and name.
 *
 * Every conversation with a connection and a model name gets the entry of
 * that pair: the exact name first, else with or without `:latest`; a pair
 * without an entry gets a placeholder entry (`available` false, stale
 * fingerprint, an untagged Ollama name with `:latest`), see {@see Sql}.
 * The turns keep running on the stored connection and name and never read
 * the entry, so a placeholder changes nothing for them. Then the column
 * becomes NOT NULL.
 *
 * A conversation that cannot get an entry (no `provider_connection_id`,
 * as stored before provider connections, a connection type the catalogue
 * does not hold, or a model name too long for an entry,
 * {@see Sql::withoutEntry()}) is not deleted here: up() aborts before any
 * statement ran, with the number of such conversations and the query that
 * lists them, and the operator deletes them before running the
 * migration again: with SQL (messages, stream events and replay records
 * cascade), or through `DELETE /api/conversations/{id}` of the previous
 * release before the upgrade (this release maps the new column, so its
 * API does not load conversations before the migration ran).
 *
 * down() drops the column. Placeholder entries stay: other references may
 * use them, and the down() of `Version20261008180000` deletes the ones no
 * scan took over.
 */
final class Version20261008190000 extends AbstractMigration
{
    /** Conversations with a connection and a model name: `row_id`, `connection_id`, `name`. */
    private const string CONVERSATION_PAIRS = <<<'SQL'
        SELECT c.id AS row_id, c.provider_connection_id AS connection_id, c.model AS name
        FROM neuron_conversation c
        WHERE c.provider_connection_id IS NOT NULL AND c.model <> ''
        SQL;

    /** Conversations that get no catalogue entry: `id`, `created_at`. */
    private static function withoutEntryQuery(): string
    {
        return 'SELECT c.id, c.created_at FROM neuron_conversation c WHERE '.Sql::withoutEntry('c.provider_connection_id', 'c.model').' ORDER BY c.created_at';
    }

    public function getDescription(): string
    {
        return 'Add neuron_conversation.provider_model_id (NOT NULL FK to the model catalogue), filled from connection and model name with placeholder entries (of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $query = self::withoutEntryQuery();
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ('.$query.') w');
        $this->abortIf($count > 0, \sprintf(
            '%d conversation(s) cannot get an entry of the model catalogue (no provider connection, a connection type the catalogue does not hold, or a model name too long for an entry), so neuron_conversation.provider_model_id cannot become NOT NULL. Delete them, then migrate again: with SQL (messages, stream events and replay records cascade), or through DELETE /api/conversations/{id} of the previous release, before its upgrade. List them with: %s',
            $count,
            $query,
        ));

        $this->addSql('ALTER TABLE neuron_conversation ADD provider_model_id UUID DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN neuron_conversation.provider_model_id IS '(DC2Type:uuid)'");
        $this->addSql('CREATE INDEX IDX_604506E23137D90E ON neuron_conversation (provider_model_id)');
        $this->addSql('ALTER TABLE neuron_conversation ADD CONSTRAINT FK_604506E23137D90E FOREIGN KEY (provider_model_id) REFERENCES neuron_provider_model (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(Sql::insertPlaceholders(self::CONVERSATION_PAIRS));
        $this->addSql(\sprintf(
            <<<'SQL'
                UPDATE neuron_conversation c
                SET provider_model_id = r.model_id
                FROM (SELECT p.row_id, %s AS model_id FROM (%s) p) r
                WHERE c.id = r.row_id AND r.model_id IS NOT NULL
                SQL,
            Sql::entryId('p.connection_id', 'p.name'),
            self::CONVERSATION_PAIRS,
        ));
        $this->addSql('ALTER TABLE neuron_conversation ALTER provider_model_id SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_conversation DROP CONSTRAINT FK_604506E23137D90E');
        $this->addSql('DROP INDEX IDX_604506E23137D90E');
        $this->addSql('ALTER TABLE neuron_conversation DROP provider_model_id');
    }
}
