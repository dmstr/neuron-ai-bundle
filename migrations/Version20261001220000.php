<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:12 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stream events of the streaming conversation turn (resumption via
 * `Last-Event-ID`) and the cancel flag of a conversation. Events are
 * deleted with their conversation (ON DELETE CASCADE); `message_id` names
 * the in-flight assistant record without a foreign key. The id is a
 * BIGSERIAL because DBAL 3 maps an IDENTITY-generated bigint to it on
 * PostgreSQL; an identity column would show up in the ORM schema diff.
 */
final class Version20261001220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create neuron_stream_event and add neuron_conversation.cancel_requested_at (streaming turn of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE neuron_stream_event (id BIGSERIAL NOT NULL, conversation_id UUID NOT NULL, message_id UUID NOT NULL, type VARCHAR(20) NOT NULL, payload JSON NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_neuron_stream_event_conversation ON neuron_stream_event (conversation_id, id)');
        // The ORM schema tool adds this foreign-key index next to the
        // composite one; created here so the schema diff stays empty.
        $this->addSql('CREATE INDEX IDX_9E0DCFC79AC0396 ON neuron_stream_event (conversation_id)');
        $this->addSql("COMMENT ON COLUMN neuron_stream_event.conversation_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_stream_event.message_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_stream_event.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql('ALTER TABLE neuron_stream_event ADD CONSTRAINT FK_9E0DCFC79AC0396 FOREIGN KEY (conversation_id) REFERENCES neuron_conversation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE neuron_conversation ADD cancel_requested_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN neuron_conversation.cancel_requested_at IS '(DC2Type:datetimetz_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_conversation DROP cancel_requested_at');
        $this->addSql('DROP TABLE neuron_stream_event');
    }
}
