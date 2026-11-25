<?php

// file generated with AI assistance: Claude Code - 2026-11-20 09:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Review state of feedback: `status` (`open`, `fixed`, `wontfix`; existing
 * rows become `open`) and a free `review_note`, so answers rated down can be
 * worked through like judge suggestions.
 *
 * The SQL follows the ORM schema tool's output for the entity mapping, so
 * the ORM diff stays clean.
 */
final class Version20261120090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add status and review_note to neuron_feedback (dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE neuron_feedback ADD status VARCHAR(20) DEFAULT 'open' NOT NULL");
        $this->addSql('ALTER TABLE neuron_feedback ADD review_note TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_feedback DROP status');
        $this->addSql('ALTER TABLE neuron_feedback DROP review_note');
    }
}
