<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:17:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reindex state of a collection: the target model of a running reindex
 * (pending_embedding_model) and the job queue job of the last reindex
 * (reindex_job_id, deliberately without a foreign key so that job cleanup
 * never touches collections).
 */
final class Version20261001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pending_embedding_model and reindex_job_id to neuron_collection (dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_collection ADD pending_embedding_model VARCHAR(190) DEFAULT NULL');
        $this->addSql('ALTER TABLE neuron_collection ADD reindex_job_id VARCHAR(36) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_collection DROP reindex_job_id');
        $this->addSql('ALTER TABLE neuron_collection DROP pending_embedding_model');
    }
}
