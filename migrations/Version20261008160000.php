<?php

// file generated with AI assistance: Claude Code - 2026-10-08 16:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `available` to the provider model catalogue. The scan no longer
 * deletes a model the connection stops offering but marks it unavailable,
 * so catalogue rows can be referenced by foreign keys. Existing rows were
 * all offered at their last scan and start as available.
 */
final class Version20261008160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add available flag to neuron_provider_model (dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_provider_model ADD available BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_provider_model DROP available');
    }
}
