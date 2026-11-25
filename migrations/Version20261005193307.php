<?php

// file generated with AI assistance: Claude Code - 2026-10-05 19:33:07 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Provider connections of conversations and messages:
 *  - `neuron_conversation.provider_connection_id`: the `ollama` or
 *    `anthropic` connection the turns run on, with a foreign key to
 *    `dmstr_api_configuration` without cascade (the delete guard refuses
 *    to delete a referenced connection with 409; the key is the backstop);
 *  - `neuron_message.connection_id`: the connection an assistant record ran
 *    on, a plain UUID without foreign key.
 *
 * Both columns are nullable because the migration runs on filled dev
 * schemas; `provider` stays as the derived type (`neuron_conversation.provider`
 * NOT NULL). Conversations without `provider_connection_id` are stale on
 * their next turn (422). No data migration.
 */
final class Version20261005193307 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add neuron_conversation.provider_connection_id (FK) and neuron_message.connection_id (provider connections, of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_conversation ADD provider_connection_id UUID DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN neuron_conversation.provider_connection_id IS '(DC2Type:uuid)'");
        $this->addSql('CREATE INDEX IDX_604506E2B853863C ON neuron_conversation (provider_connection_id)');
        $this->addSql('ALTER TABLE neuron_conversation ADD CONSTRAINT FK_604506E2B853863C FOREIGN KEY (provider_connection_id) REFERENCES dmstr_api_configuration (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE neuron_message ADD connection_id UUID DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN neuron_message.connection_id IS '(DC2Type:uuid)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neuron_message DROP connection_id');
        $this->addSql('ALTER TABLE neuron_conversation DROP CONSTRAINT FK_604506E2B853863C');
        $this->addSql('DROP INDEX IDX_604506E2B853863C');
        $this->addSql('ALTER TABLE neuron_conversation DROP provider_connection_id');
    }
}
