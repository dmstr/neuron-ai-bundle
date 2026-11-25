<?php

// file generated with AI assistance: Claude Code - 2026-10-03 06:58:57 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * User-turn limit per conversation: the maximum number of user
 * turns a conversation accepts, fixed at creation. Existing rows keep NULL
 * (unlimited); no data migration. The close reason `user_turn_limit` needs
 * no schema change (`close_reason` is VARCHAR(20)).
 */
final class Version20261003065857 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add neuron_conversation.max_user_turns (user-turn limit of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_conversation ADD max_user_turns INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_conversation DROP max_user_turns');
    }
}
