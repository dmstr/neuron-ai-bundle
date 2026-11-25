<?php

// file generated with AI assistance: Claude Code - 2026-10-03 18:38:08 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Evaluation model: the maintained test set
 * (neuron_eval_question, unique key), the batch runs against a published
 * agent version (neuron_eval_run, version ON DELETE RESTRICT) and one result
 * per executed question (neuron_eval_result: run ON DELETE CASCADE, question
 * ON DELETE RESTRICT, conversation ON DELETE SET NULL). The unique index on
 * (eval_run_id, eval_question_id) is the idempotence guarantee of the batch
 * job; NULL runs (single executions) are distinct for PostgreSQL and not
 * constrained.
 *
 * neuron_conversation.origin marks the conversations of an evaluation run
 * (`eval`); existing rows keep NULL (ordinary conversations), no backfill.
 *
 * Every foreign-key column has an explicitly named index, so the ORM adds no
 * implicit IDX_ index; the FK_ names are the ones Doctrine derives from table
 * and column names, so the ORM schema diff stays empty.
 */
final class Version20261003183808 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create neuron_eval_question, neuron_eval_run and neuron_eval_result, add neuron_conversation.origin (evaluation model, of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE neuron_eval_question (id UUID NOT NULL, key VARCHAR(100) NOT NULL, question TEXT NOT NULL, request_data JSON DEFAULT NULL, expected_answer TEXT NOT NULL, status VARCHAR(20) DEFAULT 'active' NOT NULL, markers JSON DEFAULT NULL, negative BOOLEAN DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_neuron_eval_question_key ON neuron_eval_question (key)');
        $this->addSql("COMMENT ON COLUMN neuron_eval_question.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_eval_question.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN neuron_eval_question.updated_at IS '(DC2Type:datetimetz_immutable)'");

        $this->addSql("CREATE TABLE neuron_eval_run (id UUID NOT NULL, agent_version_id UUID NOT NULL, owner_subject VARCHAR(190) NOT NULL, status VARCHAR(20) DEFAULT 'pending' NOT NULL, mode VARCHAR(20) DEFAULT NULL, job_id VARCHAR(36) DEFAULT NULL, cancel_requested_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, questions_total INT DEFAULT 0 NOT NULL, tokens_total INT DEFAULT 0 NOT NULL, interactions_total INT DEFAULT 0 NOT NULL, verdict_counts JSON DEFAULT NULL, started_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_neuron_eval_run_status ON neuron_eval_run (status)');
        $this->addSql('CREATE INDEX idx_neuron_eval_run_owner_subject ON neuron_eval_run (owner_subject)');
        $this->addSql('CREATE INDEX idx_neuron_eval_run_agent_version ON neuron_eval_run (agent_version_id)');
        $this->addSql("COMMENT ON COLUMN neuron_eval_run.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_eval_run.agent_version_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_eval_run.cancel_requested_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN neuron_eval_run.started_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN neuron_eval_run.finished_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN neuron_eval_run.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN neuron_eval_run.updated_at IS '(DC2Type:datetimetz_immutable)'");

        $this->addSql("CREATE TABLE neuron_eval_result (id UUID NOT NULL, eval_run_id UUID DEFAULT NULL, eval_question_id UUID NOT NULL, conversation_id UUID DEFAULT NULL, source VARCHAR(20) NOT NULL, status VARCHAR(20) DEFAULT 'pending' NOT NULL, error_message TEXT DEFAULT NULL, duration_ms INT DEFAULT NULL, tokens_input INT DEFAULT NULL, tokens_output INT DEFAULT NULL, interactions INT DEFAULT NULL, human_rating JSON DEFAULT NULL, recall_hit BOOLEAN DEFAULT NULL, first_relevant_rank INT DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_neuron_eval_result_run_question ON neuron_eval_result (eval_run_id, eval_question_id)');
        $this->addSql('CREATE INDEX idx_neuron_eval_result_run ON neuron_eval_result (eval_run_id)');
        $this->addSql('CREATE INDEX idx_neuron_eval_result_question ON neuron_eval_result (eval_question_id)');
        $this->addSql('CREATE INDEX idx_neuron_eval_result_conversation ON neuron_eval_result (conversation_id)');
        $this->addSql("COMMENT ON COLUMN neuron_eval_result.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_eval_result.eval_run_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_eval_result.eval_question_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_eval_result.conversation_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_eval_result.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN neuron_eval_result.updated_at IS '(DC2Type:datetimetz_immutable)'");

        $this->addSql('ALTER TABLE neuron_eval_run ADD CONSTRAINT FK_A23D8083AFAF60A FOREIGN KEY (agent_version_id) REFERENCES neuron_agent_version (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE neuron_eval_result ADD CONSTRAINT FK_145D95309DB39863 FOREIGN KEY (eval_run_id) REFERENCES neuron_eval_run (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE neuron_eval_result ADD CONSTRAINT FK_145D953035D19A4E FOREIGN KEY (eval_question_id) REFERENCES neuron_eval_question (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE neuron_eval_result ADD CONSTRAINT FK_145D95309AC0396 FOREIGN KEY (conversation_id) REFERENCES neuron_conversation (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE neuron_conversation ADD origin VARCHAR(20) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_neuron_conversation_origin ON neuron_conversation (origin)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_neuron_conversation_origin');
        $this->addSql('ALTER TABLE neuron_conversation DROP origin');

        $this->addSql('ALTER TABLE neuron_eval_result DROP CONSTRAINT FK_145D95309DB39863');
        $this->addSql('ALTER TABLE neuron_eval_result DROP CONSTRAINT FK_145D953035D19A4E');
        $this->addSql('ALTER TABLE neuron_eval_result DROP CONSTRAINT FK_145D95309AC0396');
        $this->addSql('ALTER TABLE neuron_eval_run DROP CONSTRAINT FK_A23D8083AFAF60A');
        $this->addSql('DROP TABLE neuron_eval_result');
        $this->addSql('DROP TABLE neuron_eval_run');
        $this->addSql('DROP TABLE neuron_eval_question');
    }
}
