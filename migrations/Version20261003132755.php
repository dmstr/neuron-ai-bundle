<?php

// file generated with AI assistance: Claude Code - 2026-10-03 13:27:55 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Start parameters of agent conversations: the `variables`
 * object given at creation with an agent, substituted into the version's
 * instructions at every turn. Existing rows keep NULL (no agent variables);
 * no data migration. The column is not audited (personal data).
 */
final class Version20261003132755 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add neuron_conversation.variables (start parameters of agent conversations of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_conversation ADD variables JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_conversation DROP variables');
    }
}
