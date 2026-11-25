<?php

// file generated with AI assistance: Claude Code - 2026-10-06 19:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Renames the editable agent configuration from `draft` to `candidate`:
 * `neuron_agent.draft` becomes `neuron_agent.candidate`. The
 * column keeps its type and content; only the name changes, together with
 * the API field, the schema file and the violation paths. Published
 * versions (`neuron_agent_version.configuration`) are not touched.
 */
final class Version20261006190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename neuron_agent.draft to candidate (of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_agent RENAME COLUMN draft TO candidate');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_agent RENAME COLUMN candidate TO draft');
    }
}
