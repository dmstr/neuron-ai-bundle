<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:54:19 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Anonymous conversations: the anonymous flag, the SHA-256 hash
 * of the capability token (unique), the keyed hash of the creating client
 * address, the turn and token counters, and when and why a conversation was
 * closed. The status keeps VARCHAR(20) (`closed` is a new value). Existing
 * rows become non-anonymous conversations with zero counters; no data
 * migration.
 */
final class Version20261001215419 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Anonymous conversations: token and address hashes, turn and token counters, close time and reason (dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_conversation ADD anonymous BOOLEAN DEFAULT false NOT NULL, ADD token_hash VARCHAR(64) DEFAULT NULL, ADD address_hash VARCHAR(64) DEFAULT NULL, ADD turn_count INT DEFAULT 0 NOT NULL, ADD tokens_used INT DEFAULT 0 NOT NULL, ADD closed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ADD close_reason VARCHAR(20) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_neuron_conversation_token_hash ON neuron_conversation (token_hash)');
        $this->addSql('CREATE INDEX idx_neuron_conversation_anonymous_closed_at ON neuron_conversation (anonymous, closed_at)');
        $this->addSql("COMMENT ON COLUMN neuron_conversation.closed_at IS '(DC2Type:datetimetz_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_neuron_conversation_anonymous_closed_at');
        $this->addSql('DROP INDEX uniq_neuron_conversation_token_hash');
        $this->addSql('ALTER TABLE neuron_conversation DROP anonymous, DROP token_hash, DROP address_hash, DROP turn_count, DROP tokens_used, DROP closed_at, DROP close_reason');
    }
}
