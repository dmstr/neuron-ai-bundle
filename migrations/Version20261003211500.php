<?php

// file generated with AI assistance: Claude Code - 2026-10-03 21:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Feedback: neuron_feedback holds feedback on a conversation
 * (ON DELETE CASCADE) or one of its messages (ON DELETE SET NULL) with
 * rating, reason, comment, the derived source (`enduser`, `reviewer`) and
 * the submitter's user identifier.
 *
 * Every foreign-key column has an explicitly named index, so the ORM adds no
 * implicit IDX_ index; the FK_ names are the ones Doctrine derives from table
 * and column names, so the ORM schema diff stays empty.
 */
final class Version20261003211500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create neuron_feedback (feedback on conversations and messages, of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE neuron_feedback (id UUID NOT NULL, conversation_id UUID NOT NULL, message_id UUID DEFAULT NULL, rating VARCHAR(10) DEFAULT NULL, reason VARCHAR(255) DEFAULT NULL, comment TEXT DEFAULT NULL, source VARCHAR(20) NOT NULL, subject VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_neuron_feedback_conversation ON neuron_feedback (conversation_id)');
        $this->addSql('CREATE INDEX idx_neuron_feedback_message ON neuron_feedback (message_id)');
        $this->addSql("COMMENT ON COLUMN neuron_feedback.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_feedback.conversation_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_feedback.message_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_feedback.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql('ALTER TABLE neuron_feedback ADD CONSTRAINT FK_9A82C629AC0396 FOREIGN KEY (conversation_id) REFERENCES neuron_conversation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE neuron_feedback ADD CONSTRAINT FK_9A82C62537A1329 FOREIGN KEY (message_id) REFERENCES neuron_message (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_feedback DROP CONSTRAINT FK_9A82C629AC0396');
        $this->addSql('ALTER TABLE neuron_feedback DROP CONSTRAINT FK_9A82C62537A1329');
        $this->addSql('DROP TABLE neuron_feedback');
    }
}
