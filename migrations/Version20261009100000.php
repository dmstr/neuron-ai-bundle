<?php

// file generated with AI assistance: Claude Code - 2026-10-09 06:42:28 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The MCP tool catalogue keeps three more fields of a `tools/list`
 * definition: `neuron_mcp_tool.title` (display name), `annotations`
 * (behaviour hints such as `readOnlyHint`) and `meta` (the tool's `_meta`).
 * All nullable without backfill: the next scan or sync fills them from the
 * server.
 *
 * down() drops the three columns.
 */
final class Version20261009100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add neuron_mcp_tool.title, annotations and meta (MCP tool catalogue of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_mcp_tool ADD title VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE neuron_mcp_tool ADD annotations JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE neuron_mcp_tool ADD meta JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_mcp_tool DROP meta');
        $this->addSql('ALTER TABLE neuron_mcp_tool DROP annotations');
        $this->addSql('ALTER TABLE neuron_mcp_tool DROP title');
    }
}
