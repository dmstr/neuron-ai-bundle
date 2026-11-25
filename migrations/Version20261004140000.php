<?php

// file generated with AI assistance: Claude Code - 2026-10-04 13:44:59 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Follow-up suggestions: `neuron_message.follow_ups`
 * holds the suggested follow-up questions (a JSON list of strings) on the
 * final record of a complete text answer whose agent version has
 * `followUps` on; `[]` when the gate failed or found nothing. Existing rows
 * keep NULL (the gate did not run); no data migration.
 */
final class Version20261004140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add neuron_message.follow_ups (follow-up suggestions, of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_message ADD follow_ups JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_message DROP follow_ups');
    }
}
