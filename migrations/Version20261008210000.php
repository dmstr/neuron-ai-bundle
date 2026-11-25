<?php

// file generated with AI assistance: Claude Code - 2026-10-08 20:08:39 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Dmstr\NeuronAiBundle\Provider\ModelReferenceMigrationSql as Sql;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Message and evaluation records reference the model catalogue next to the
 * model name they keep: `neuron_message.provider_model_id` and
 * `neuron_evaluation.provider_model_id`, nullable foreign keys to
 * `neuron_provider_model` with ON DELETE SET NULL. A record is history: it
 * never blocks the deletion of a connection (and with it its entries), it
 * loses the reference and keeps provider, model name and connection id.
 *
 * Backfill, only where an entry exists (no placeholder entries, unlike the
 * migrations of agent versions, conversations and collections: the keys
 * are nullable and a record needs no runnable reference):
 *
 *  - a message with `connection_id` and a model name gets the entry of
 *    that pair, the exact name first, else with or without `:latest`
 *    ({@see Sql::entryId()}); the placeholder entries of the earlier
 *    migrations count, so the records of a migrated conversation usually
 *    find the conversation's entry. A message whose connection is gone, or
 *    that was stored before provider connections, stays without one;
 *  - an evaluation gets the entry its judge version's `configuration.model`
 *    names (the UUID written by `Version20261008180000`) when that entry's
 *    name matches the evaluation's model name. An evaluation whose judge
 *    version is gone, unmigrated or on another model stays without one.
 *
 * down() drops both columns.
 */
final class Version20261008210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add neuron_message.provider_model_id and neuron_evaluation.provider_model_id (FK to the model catalogue, SET NULL), filled where an entry matches (of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_message ADD provider_model_id UUID DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN neuron_message.provider_model_id IS '(DC2Type:uuid)'");
        $this->addSql('CREATE INDEX idx_neuron_message_provider_model ON neuron_message (provider_model_id)');
        $this->addSql('ALTER TABLE neuron_message ADD CONSTRAINT FK_8C1CDC553137D90E FOREIGN KEY (provider_model_id) REFERENCES neuron_provider_model (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE neuron_evaluation ADD provider_model_id UUID DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN neuron_evaluation.provider_model_id IS '(DC2Type:uuid)'");
        $this->addSql('CREATE INDEX idx_neuron_evaluation_provider_model ON neuron_evaluation (provider_model_id)');
        $this->addSql('ALTER TABLE neuron_evaluation ADD CONSTRAINT FK_75E349E73137D90E FOREIGN KEY (provider_model_id) REFERENCES neuron_provider_model (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(\sprintf(
            <<<'SQL'
                UPDATE neuron_message m
                SET provider_model_id = %s
                WHERE m.connection_id IS NOT NULL AND m.model IS NOT NULL AND m.model <> ''
                SQL,
            Sql::entryId('m.connection_id', 'm.model'),
        ));
        $this->addSql(\sprintf(
            <<<'SQL'
                UPDATE neuron_evaluation e
                SET provider_model_id = pm.id
                FROM neuron_agent_version v
                JOIN neuron_provider_model pm ON pm.id::text = (v.configuration->>'model')
                WHERE e.judge_agent_version_id = v.id AND e.model IS NOT NULL AND e.model <> '' AND %s
                SQL,
            Sql::equivalent('pm.name', 'e.model'),
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_evaluation DROP CONSTRAINT FK_75E349E73137D90E');
        $this->addSql('DROP INDEX idx_neuron_evaluation_provider_model');
        $this->addSql('ALTER TABLE neuron_evaluation DROP provider_model_id');

        $this->addSql('ALTER TABLE neuron_message DROP CONSTRAINT FK_8C1CDC553137D90E');
        $this->addSql('DROP INDEX idx_neuron_message_provider_model');
        $this->addSql('ALTER TABLE neuron_message DROP provider_model_id');
    }
}
