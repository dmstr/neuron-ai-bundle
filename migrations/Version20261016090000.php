<?php

// file generated with AI assistance: Claude Code - 2026-10-16 08:40:52 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Artifact ledger: one row per file an agent wrote with the
 * artifact tools, keyed by owner and lower-cased path (unique, so names
 * differing only in case are caught before a case-insensitive share merges
 * them). The conversation reference is SET NULL when the conversation is
 * deleted: the file outlives it, `created_by`/`updated_by` keep the ids.
 *
 * The SQL follows the ORM schema tool's output for the entity mapping
 * (FK name, types, DC2Type comments), so the ORM diff stays clean.
 */
final class Version20261016090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create neuron_artifact (artifact ledger of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE neuron_artifact (id UUID NOT NULL, conversation_id UUID DEFAULT NULL, owner_subject VARCHAR(255) NOT NULL, path VARCHAR(1024) NOT NULL, path_lower VARCHAR(1024) NOT NULL, size_bytes INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, created_by VARCHAR(36) NOT NULL, updated_by VARCHAR(36) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_neuron_artifact_owner_path_lower ON neuron_artifact (owner_subject, path_lower)');
        $this->addSql('CREATE INDEX idx_neuron_artifact_conversation ON neuron_artifact (conversation_id)');
        $this->addSql("COMMENT ON COLUMN neuron_artifact.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_artifact.conversation_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_artifact.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN neuron_artifact.updated_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql('ALTER TABLE neuron_artifact ADD CONSTRAINT FK_936408169AC0396 FOREIGN KEY (conversation_id) REFERENCES neuron_conversation (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE neuron_artifact');
    }
}
