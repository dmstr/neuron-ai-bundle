<?php

// file generated with AI assistance: Claude Code - 2026-10-13 18:45:18 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Collection lock: `neuron_collection.locked_at` (when the collection was
 * locked) and `locked_by` (identifier of the user who locked it). Both
 * nullable without backfill: every existing collection stays unlocked.
 *
 * down() drops the two columns, which unlocks every collection.
 */
final class Version20261013120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add neuron_collection.locked_at and locked_by (collection lock of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_collection ADD locked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE neuron_collection ADD locked_by VARCHAR(190) DEFAULT NULL');
        // DBAL 3 reads the mapped type from the column comment; without it
        // the schema tool sees a mutable datetimetz and reports a diff.
        $this->addSql("COMMENT ON COLUMN neuron_collection.locked_at IS '(DC2Type:datetimetz_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_collection DROP locked_by');
        $this->addSql('ALTER TABLE neuron_collection DROP locked_at');
    }
}

// - revised 2026-10-13 20:23:34 UTC
