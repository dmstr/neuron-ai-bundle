<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:21:35 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retrieval metrics: neuron_eval_result gains retrieval_status
 * (`measured`, `negative`, `unmeasured`, `failed`; null for mode `agent`,
 * single executions and old rows), neuron_eval_run gains the run aggregates
 * recall and mrr plus the four denominators questions_measured,
 * questions_negative, questions_unmeasured and questions_retrieval_failed.
 * All columns are nullable without default; existing rows keep NULL, no
 * backfill. recall_hit and first_relevant_rank were reserved by
 * Version20261003183808 already.
 */
final class Version20261003222135 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add retrieval_status to neuron_eval_result and the retrieval aggregates to neuron_eval_run (retrieval metrics, of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_eval_result ADD retrieval_status VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE neuron_eval_run ADD recall DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE neuron_eval_run ADD mrr DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE neuron_eval_run ADD questions_measured INT DEFAULT NULL');
        $this->addSql('ALTER TABLE neuron_eval_run ADD questions_negative INT DEFAULT NULL');
        $this->addSql('ALTER TABLE neuron_eval_run ADD questions_unmeasured INT DEFAULT NULL');
        $this->addSql('ALTER TABLE neuron_eval_run ADD questions_retrieval_failed INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_eval_run DROP questions_retrieval_failed');
        $this->addSql('ALTER TABLE neuron_eval_run DROP questions_unmeasured');
        $this->addSql('ALTER TABLE neuron_eval_run DROP questions_negative');
        $this->addSql('ALTER TABLE neuron_eval_run DROP questions_measured');
        $this->addSql('ALTER TABLE neuron_eval_run DROP mrr');
        $this->addSql('ALTER TABLE neuron_eval_run DROP recall');
        $this->addSql('ALTER TABLE neuron_eval_result DROP retrieval_status');
    }
}
