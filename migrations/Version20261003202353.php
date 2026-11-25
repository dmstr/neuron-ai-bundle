<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:23:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Evaluation judge: the judge's evaluations of a result
 * (neuron_evaluation: result ON DELETE CASCADE, judge version ON DELETE SET
 * NULL) and their improvement suggestions (neuron_suggestion: evaluation ON
 * DELETE CASCADE, indexed status and assignee).
 *
 * neuron_eval_result gains the reference to its latest evaluation
 * (evaluation_id, ON DELETE SET NULL) and who rated it when
 * (human_rated_by, human_rated_at); existing rows keep NULL, no backfill.
 * The two tables reference each other; deleting a result cascades to its
 * evaluations, whose deletion sets the result's evaluation_id to NULL.
 *
 * Every foreign-key column has an explicitly named index, so the ORM adds no
 * implicit IDX_ index; the FK_ names are the ones Doctrine derives from table
 * and column names, so the ORM schema diff stays empty.
 */
final class Version20261003202353 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create neuron_evaluation and neuron_suggestion, add evaluation_id, human_rated_by and human_rated_at to neuron_eval_result (evaluation judge, of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE neuron_evaluation (id UUID NOT NULL, eval_result_id UUID NOT NULL, status VARCHAR(20) DEFAULT 'pending' NOT NULL, verdict VARCHAR(20) DEFAULT NULL, reasoning TEXT DEFAULT NULL, judge_agent_version_id UUID DEFAULT NULL, model VARCHAR(255) DEFAULT NULL, tokens_input INT DEFAULT NULL, tokens_output INT DEFAULT NULL, duration_ms INT DEFAULT NULL, error_message TEXT DEFAULT NULL, job_id VARCHAR(36) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_neuron_evaluation_eval_result ON neuron_evaluation (eval_result_id)');
        $this->addSql('CREATE INDEX idx_neuron_evaluation_status ON neuron_evaluation (status)');
        $this->addSql('CREATE INDEX idx_neuron_evaluation_judge_agent_version ON neuron_evaluation (judge_agent_version_id)');
        $this->addSql("COMMENT ON COLUMN neuron_evaluation.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_evaluation.eval_result_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_evaluation.judge_agent_version_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_evaluation.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN neuron_evaluation.updated_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN neuron_evaluation.finished_at IS '(DC2Type:datetimetz_immutable)'");

        $this->addSql("CREATE TABLE neuron_suggestion (id UUID NOT NULL, evaluation_id UUID NOT NULL, type VARCHAR(20) NOT NULL, category VARCHAR(100) NOT NULL, description TEXT NOT NULL, action TEXT NOT NULL, priority VARCHAR(20) NOT NULL, status VARCHAR(20) DEFAULT 'open' NOT NULL, assignee VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_neuron_suggestion_evaluation ON neuron_suggestion (evaluation_id)');
        $this->addSql('CREATE INDEX idx_neuron_suggestion_status ON neuron_suggestion (status)');
        $this->addSql('CREATE INDEX idx_neuron_suggestion_assignee ON neuron_suggestion (assignee)');
        $this->addSql("COMMENT ON COLUMN neuron_suggestion.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_suggestion.evaluation_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_suggestion.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN neuron_suggestion.updated_at IS '(DC2Type:datetimetz_immutable)'");

        $this->addSql('ALTER TABLE neuron_eval_result ADD evaluation_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE neuron_eval_result ADD human_rated_by VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE neuron_eval_result ADD human_rated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_neuron_eval_result_evaluation ON neuron_eval_result (evaluation_id)');
        $this->addSql("COMMENT ON COLUMN neuron_eval_result.evaluation_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_eval_result.human_rated_at IS '(DC2Type:datetimetz_immutable)'");

        $this->addSql('ALTER TABLE neuron_evaluation ADD CONSTRAINT FK_75E349E7835C606A FOREIGN KEY (eval_result_id) REFERENCES neuron_eval_result (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE neuron_evaluation ADD CONSTRAINT FK_75E349E7C1BBC303 FOREIGN KEY (judge_agent_version_id) REFERENCES neuron_agent_version (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE neuron_suggestion ADD CONSTRAINT FK_BB401F89456C5646 FOREIGN KEY (evaluation_id) REFERENCES neuron_evaluation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE neuron_eval_result ADD CONSTRAINT FK_145D9530456C5646 FOREIGN KEY (evaluation_id) REFERENCES neuron_evaluation (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_eval_result DROP CONSTRAINT FK_145D9530456C5646');
        $this->addSql('DROP INDEX idx_neuron_eval_result_evaluation');
        $this->addSql('ALTER TABLE neuron_eval_result DROP evaluation_id');
        $this->addSql('ALTER TABLE neuron_eval_result DROP human_rated_by');
        $this->addSql('ALTER TABLE neuron_eval_result DROP human_rated_at');

        $this->addSql('ALTER TABLE neuron_suggestion DROP CONSTRAINT FK_BB401F89456C5646');
        $this->addSql('ALTER TABLE neuron_evaluation DROP CONSTRAINT FK_75E349E7835C606A');
        $this->addSql('ALTER TABLE neuron_evaluation DROP CONSTRAINT FK_75E349E7C1BBC303');
        $this->addSql('DROP TABLE neuron_suggestion');
        $this->addSql('DROP TABLE neuron_evaluation');
    }
}
