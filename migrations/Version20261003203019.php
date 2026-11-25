<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:30:19 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Output templates: `neuron_message.frame` holds the rendered
 * `frame` template of the agent version, split at its `{{answer}}` into
 * `{"prefix": ..., "suffix": ...}`, on the final record of a framed turn
 * only. Existing rows keep NULL (unframed); no data migration.
 */
final class Version20261003203019 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add neuron_message.frame (output frame of the final answer, of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_message ADD frame JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_message DROP frame');
    }
}
