<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Conversation replay: neuron_conversation_replay holds one
 * replay of a source conversation onto an agent version. Its id is the id
 * of the replay conversation (conversation_id, unique, ON DELETE CASCADE);
 * the source is referenced with ON DELETE SET NULL at database level,
 * because the anonymous purge deletes conversations with DQL and no ORM
 * cascade runs; the target version with ON DELETE RESTRICT. The new origin
 * value `replay` of neuron_conversation.origin needs no schema change.
 *
 * Every foreign-key column has an explicitly named index (the unique index
 * on conversation_id covers its foreign key), so the ORM adds no implicit
 * IDX_ index; the FK_ names are the ones Doctrine derives from table and
 * column names, so the ORM schema diff stays empty.
 */
final class Version20261003220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create neuron_conversation_replay (conversation replay, of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE neuron_conversation_replay (id UUID NOT NULL, conversation_id UUID NOT NULL, source_conversation_id UUID DEFAULT NULL, target_version_id UUID NOT NULL, owner_subject VARCHAR(190) NOT NULL, status VARCHAR(20) DEFAULT 'pending' NOT NULL, language VARCHAR(16) DEFAULT NULL, overrides JSON DEFAULT NULL, source_message_ids JSON NOT NULL, job_id VARCHAR(36) DEFAULT NULL, cancel_requested_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, turns_replayed INT DEFAULT 0 NOT NULL, turns_total INT DEFAULT 0 NOT NULL, error_message TEXT DEFAULT NULL, started_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_neuron_conversation_replay_conversation ON neuron_conversation_replay (conversation_id)');
        $this->addSql('CREATE INDEX idx_neuron_conversation_replay_source ON neuron_conversation_replay (source_conversation_id)');
        $this->addSql('CREATE INDEX idx_neuron_conversation_replay_target_version ON neuron_conversation_replay (target_version_id)');
        $this->addSql('CREATE INDEX idx_neuron_conversation_replay_owner_subject ON neuron_conversation_replay (owner_subject)');
        $this->addSql('CREATE INDEX idx_neuron_conversation_replay_status ON neuron_conversation_replay (status)');
        $this->addSql("COMMENT ON COLUMN neuron_conversation_replay.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_conversation_replay.conversation_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_conversation_replay.source_conversation_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_conversation_replay.target_version_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_conversation_replay.cancel_requested_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN neuron_conversation_replay.started_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN neuron_conversation_replay.finished_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN neuron_conversation_replay.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN neuron_conversation_replay.updated_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql('ALTER TABLE neuron_conversation_replay ADD CONSTRAINT FK_C95767689AC0396 FOREIGN KEY (conversation_id) REFERENCES neuron_conversation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE neuron_conversation_replay ADD CONSTRAINT FK_C9576768AFB32D9D FOREIGN KEY (source_conversation_id) REFERENCES neuron_conversation (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE neuron_conversation_replay ADD CONSTRAINT FK_C95767687950FF94 FOREIGN KEY (target_version_id) REFERENCES neuron_agent_version (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_conversation_replay DROP CONSTRAINT FK_C95767689AC0396');
        $this->addSql('ALTER TABLE neuron_conversation_replay DROP CONSTRAINT FK_C9576768AFB32D9D');
        $this->addSql('ALTER TABLE neuron_conversation_replay DROP CONSTRAINT FK_C95767687950FF94');
        $this->addSql('DROP TABLE neuron_conversation_replay');
    }
}
