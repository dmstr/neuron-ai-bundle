<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:41:04 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MCP tool catalogue: one row per tool an `mcp` connection announces. Rows
 * are deleted together with their connection (ON DELETE CASCADE onto the
 * platform table dmstr_api_configuration, which the api-configuration
 * bundle's earlier migrations create).
 */
final class Version20260930213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create neuron_mcp_tool (MCP tool catalogue of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE neuron_mcp_tool (id UUID NOT NULL, api_configuration_id UUID NOT NULL, name VARCHAR(190) NOT NULL, description TEXT DEFAULT NULL, input_schema JSON DEFAULT NULL, synced_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, last_error TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_neuron_mcp_tool_api_configuration_name ON neuron_mcp_tool (api_configuration_id, name)');
        $this->addSql('CREATE INDEX idx_neuron_mcp_tool_api_configuration ON neuron_mcp_tool (api_configuration_id)');
        $this->addSql("COMMENT ON COLUMN neuron_mcp_tool.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_mcp_tool.api_configuration_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_mcp_tool.synced_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN neuron_mcp_tool.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN neuron_mcp_tool.updated_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql('ALTER TABLE neuron_mcp_tool ADD CONSTRAINT FK_FFC5E057316D888C FOREIGN KEY (api_configuration_id) REFERENCES dmstr_api_configuration (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE neuron_mcp_tool');
    }
}
