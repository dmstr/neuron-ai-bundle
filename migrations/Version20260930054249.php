<?php

// file generated with AI assistance: Claude Code - 2026-09-30 05:42:49 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930054249 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create neuron_collection (knowledge collections of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE neuron_collection (id UUID NOT NULL, name VARCHAR(190) NOT NULL, description TEXT DEFAULT NULL, embedding_model VARCHAR(190) NOT NULL, embedding_dimension INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_neuron_collection_name ON neuron_collection (name)');
        $this->addSql("COMMENT ON COLUMN neuron_collection.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_collection.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN neuron_collection.updated_at IS '(DC2Type:datetimetz_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE neuron_collection');
    }
}
