<?php

// file generated with AI assistance: Claude Code - 2026-10-05 20:27:34 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Embedding connections of collections:
 *  - `neuron_collection.embedding_connection_id`: the `ollama` connection
 *    the collection's vectors are computed through;
 *  - `neuron_collection.pending_embedding_connection_id`: the target
 *    connection of a running reindex, next to `pending_embedding_model`.
 *
 * Both reference `dmstr_api_configuration` without cascade (the delete
 * guard refuses to delete a referenced connection with 409; the keys are
 * the backstop). Both are nullable because the migration runs on filled
 * dev schemas; a collection without `embedding_connection_id` is stale
 * (ingest, retrieval and reindex runs refuse it). No data migration.
 */
final class Version20261005202734 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add neuron_collection.embedding_connection_id and pending_embedding_connection_id (FKs, provider connections, of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_collection ADD embedding_connection_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE neuron_collection ADD pending_embedding_connection_id UUID DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN neuron_collection.embedding_connection_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_collection.pending_embedding_connection_id IS '(DC2Type:uuid)'");
        $this->addSql('CREATE INDEX IDX_9A8D89A012CB3F5F ON neuron_collection (embedding_connection_id)');
        $this->addSql('CREATE INDEX IDX_9A8D89A0F5C46869 ON neuron_collection (pending_embedding_connection_id)');
        $this->addSql('ALTER TABLE neuron_collection ADD CONSTRAINT FK_9A8D89A012CB3F5F FOREIGN KEY (embedding_connection_id) REFERENCES dmstr_api_configuration (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE neuron_collection ADD CONSTRAINT FK_9A8D89A0F5C46869 FOREIGN KEY (pending_embedding_connection_id) REFERENCES dmstr_api_configuration (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_collection DROP CONSTRAINT FK_9A8D89A0F5C46869');
        $this->addSql('ALTER TABLE neuron_collection DROP CONSTRAINT FK_9A8D89A012CB3F5F');
        $this->addSql('DROP INDEX IDX_9A8D89A0F5C46869');
        $this->addSql('DROP INDEX IDX_9A8D89A012CB3F5F');
        $this->addSql('ALTER TABLE neuron_collection DROP pending_embedding_connection_id');
        $this->addSql('ALTER TABLE neuron_collection DROP embedding_connection_id');
    }
}
