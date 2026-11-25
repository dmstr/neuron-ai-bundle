<?php

// file generated with AI assistance: Claude Code - 2026-10-02 06:02:17 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bounded tool loop: the close reason of a message record, set
 * on the answer of a forced step (`forced_bound`). Existing rows keep
 * NULL; no data migration. The `limit` record kind needs no schema change
 * (`kind` is a string column).
 */
final class Version20261002060217 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add neuron_message.close_reason (bounded tool loop of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_message ADD close_reason VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_message DROP close_reason');
    }
}
