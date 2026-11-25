<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:11:43 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Run comparison: the fingerprint of the question an evaluation
 * result answered, the sha256 hex digest of the question text, a NUL byte
 * and the expected answer at the time the result was recorded. The import
 * updates a question in place, so this is the only trace of the text a
 * result ran with; the comparison of two runs flags a changed question
 * when the fingerprints differ. Existing rows keep NULL (no data
 * migration); for them the comparison falls back to the first user message
 * of the result conversations.
 */
final class Version20261003221000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add neuron_eval_result.question_fingerprint (run comparison of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_eval_result ADD question_fingerprint VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_eval_result DROP question_fingerprint');
    }
}
