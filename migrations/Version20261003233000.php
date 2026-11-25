<?php

// file generated with AI assistance: Claude Code - 2026-10-03 23:30:03 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retrieval-only runs: neuron_eval_run gains retrieval_only, set
 * at start when the batch measures each question's retrieval without chat
 * call and without conversation. NOT NULL with default false, so existing
 * runs read as normal runs without a backfill.
 */
final class Version20261003233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add retrieval_only to neuron_eval_run (retrieval-only runs, of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_eval_run ADD retrieval_only BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_eval_run DROP retrieval_only');
    }
}
