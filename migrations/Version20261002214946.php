<?php

// file generated with AI assistance: Claude Code - 2026-10-02 21:49:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Agents and their immutable versions. The agent points to its
 * current version and every version to its agent; neither foreign key
 * cascades, versions are never deleted. The reserved column
 * neuron_conversation.agent_version gets its foreign key to the version and
 * the index the ORM schema tool generates for it (the column was never
 * written, so no data check is needed). The foreign-key and index names
 * prefixed FK_/IDX_ are the ones Doctrine derives from table and column
 * names, so the ORM schema diff stays empty. No backfill.
 *
 * neuron_agent.current_version_number mirrors the number of the current
 * version; it is the audited field (Gedmo loggable), because the identifier
 * of the association cannot be stored in the audit log. down() drops it
 * with the table.
 */
final class Version20261002214946 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create neuron_agent and neuron_agent_version, add the foreign key of neuron_conversation.agent_version (agents of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE neuron_agent (id UUID NOT NULL, current_version_id UUID DEFAULT NULL, name VARCHAR(190) NOT NULL, slug VARCHAR(190) NOT NULL, enabled BOOLEAN DEFAULT true NOT NULL, current_version_number INT DEFAULT NULL, draft JSON NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_neuron_agent_slug ON neuron_agent (slug)');
        $this->addSql('CREATE INDEX idx_neuron_agent_current_version ON neuron_agent (current_version_id)');
        $this->addSql("COMMENT ON COLUMN neuron_agent.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_agent.current_version_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_agent.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN neuron_agent.updated_at IS '(DC2Type:datetimetz_immutable)'");

        $this->addSql('CREATE TABLE neuron_agent_version (id UUID NOT NULL, agent_id UUID NOT NULL, version_number INT NOT NULL, configuration JSON NOT NULL, published_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, published_by VARCHAR(190) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_neuron_agent_version_agent_number ON neuron_agent_version (agent_id, version_number)');
        $this->addSql('CREATE INDEX idx_neuron_agent_version_agent ON neuron_agent_version (agent_id)');
        $this->addSql("COMMENT ON COLUMN neuron_agent_version.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_agent_version.agent_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_agent_version.published_at IS '(DC2Type:datetimetz_immutable)'");

        $this->addSql('ALTER TABLE neuron_agent ADD CONSTRAINT FK_9A33B2819407EE77 FOREIGN KEY (current_version_id) REFERENCES neuron_agent_version (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE neuron_agent_version ADD CONSTRAINT FK_FA77A3C13414710B FOREIGN KEY (agent_id) REFERENCES neuron_agent (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE INDEX IDX_604506E26D4FB169 ON neuron_conversation (agent_version)');
        $this->addSql('ALTER TABLE neuron_conversation ADD CONSTRAINT FK_604506E26D4FB169 FOREIGN KEY (agent_version) REFERENCES neuron_agent_version (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_conversation DROP CONSTRAINT FK_604506E26D4FB169');
        $this->addSql('DROP INDEX IDX_604506E26D4FB169');
        $this->addSql('ALTER TABLE neuron_agent DROP CONSTRAINT FK_9A33B2819407EE77');
        $this->addSql('ALTER TABLE neuron_agent_version DROP CONSTRAINT FK_FA77A3C13414710B');
        $this->addSql('DROP TABLE neuron_agent_version');
        $this->addSql('DROP TABLE neuron_agent');
    }
}
