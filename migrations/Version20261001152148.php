<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:21:48 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Persisted conversations and their message records. Messages are deleted
 * with their conversation (ON DELETE CASCADE); the sequence of a message is
 * unique per conversation. No reference to application tables: bundle
 * migrations run before every application migration (namespace-first
 * ordering).
 */
final class Version20261001152148 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create neuron_conversation and neuron_message (conversations of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE neuron_conversation (id UUID NOT NULL, owner_subject VARCHAR(190) NOT NULL, title VARCHAR(190) DEFAULT NULL, status VARCHAR(20) NOT NULL, provider VARCHAR(20) NOT NULL, model VARCHAR(190) NOT NULL, collections JSON NOT NULL, mcp_connections JSON NOT NULL, retrieval_depth INT NOT NULL, agent_version UUID DEFAULT NULL, language VARCHAR(16) DEFAULT NULL, next_sequence INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_neuron_conversation_owner_subject ON neuron_conversation (owner_subject)');
        $this->addSql('CREATE INDEX idx_neuron_conversation_status ON neuron_conversation (status)');
        $this->addSql("COMMENT ON COLUMN neuron_conversation.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_conversation.agent_version IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_conversation.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN neuron_conversation.updated_at IS '(DC2Type:datetimetz_immutable)'");

        $this->addSql('CREATE TABLE neuron_message (id UUID NOT NULL, conversation_id UUID NOT NULL, sequence INT NOT NULL, step INT NOT NULL, role VARCHAR(16) NOT NULL, kind VARCHAR(20) NOT NULL, status VARCHAR(16) NOT NULL, content TEXT DEFAULT NULL, provider VARCHAR(20) DEFAULT NULL, model VARCHAR(190) DEFAULT NULL, usage JSON DEFAULT NULL, sources JSON DEFAULT NULL, tool_name VARCHAR(190) DEFAULT NULL, tool_call_id VARCHAR(190) DEFAULT NULL, tool_input JSON DEFAULT NULL, tool_result TEXT DEFAULT NULL, tool_error BOOLEAN DEFAULT false NOT NULL, duration_ms INT DEFAULT NULL, error TEXT DEFAULT NULL, response_schema JSON DEFAULT NULL, structured JSON DEFAULT NULL, provider_payload JSON DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_neuron_message_conversation ON neuron_message (conversation_id)');
        $this->addSql('CREATE INDEX idx_neuron_message_status ON neuron_message (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_neuron_message_conversation_sequence ON neuron_message (conversation_id, sequence)');
        $this->addSql("COMMENT ON COLUMN neuron_message.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_message.conversation_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_message.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql('ALTER TABLE neuron_message ADD CONSTRAINT FK_8C1CDC559AC0396 FOREIGN KEY (conversation_id) REFERENCES neuron_conversation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE neuron_message');
        $this->addSql('DROP TABLE neuron_conversation');
    }
}
