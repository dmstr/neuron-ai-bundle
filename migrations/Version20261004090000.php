<?php

// file generated with AI assistance: Claude Code - 2026-10-04 08:55:15 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hybrid retrieval: neuron_collection gains keyword_language, the
 * text search configuration of the collection's keyword search. Existing
 * collections are backfilled with 'english' through a temporary column
 * default, which is dropped again: the entity sets 'german' for new
 * collections and maps the column without a database default.
 */
final class Version20261004090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add keyword_language to neuron_collection (hybrid retrieval, of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE neuron_collection ADD keyword_language VARCHAR(16) DEFAULT 'english' NOT NULL");
        $this->addSql('ALTER TABLE neuron_collection ALTER keyword_language DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_collection DROP keyword_language');
    }
}
