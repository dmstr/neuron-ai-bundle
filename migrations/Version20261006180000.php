<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Provider model catalogue: one row per model an `ollama` or
 * `anthropic` connection offers, with capabilities (JSONB, filtered with
 * `@>`), trained context length, output limit, sampling support and the
 * endpoint fingerprint of the describe. Rows are deleted together with
 * their connection (ON DELETE CASCADE onto the platform table
 * dmstr_api_configuration); they are no references, so the provider
 * connection delete guard does not count them.
 *
 * The SQL is the ORM schema tool's output for the entity mapping (same
 * FK name, json/jsonb types and index names), so the ORM diff stays clean.
 */
final class Version20261006180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create neuron_provider_model (model catalogue of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE neuron_provider_model (id UUID NOT NULL, api_configuration_id UUID NOT NULL, name VARCHAR(190) NOT NULL, display_name VARCHAR(190) DEFAULT NULL, family VARCHAR(100) DEFAULT NULL, parameter_size VARCHAR(32) DEFAULT NULL, quantization VARCHAR(32) DEFAULT NULL, capabilities JSONB DEFAULT NULL, max_context_length INT DEFAULT NULL, max_output_tokens INT DEFAULT NULL, supports_sampling BOOLEAN DEFAULT NULL, digest VARCHAR(80) DEFAULT NULL, endpoint_fingerprint VARCHAR(64) NOT NULL, provider_data JSON DEFAULT NULL, synced_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, last_error TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_neuron_provider_model_api_configuration_name ON neuron_provider_model (api_configuration_id, name)');
        $this->addSql('CREATE INDEX idx_neuron_provider_model_api_configuration ON neuron_provider_model (api_configuration_id)');
        $this->addSql("COMMENT ON COLUMN neuron_provider_model.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_provider_model.api_configuration_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN neuron_provider_model.synced_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN neuron_provider_model.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN neuron_provider_model.updated_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql('ALTER TABLE neuron_provider_model ADD CONSTRAINT FK_5659D465316D888C FOREIGN KEY (api_configuration_id) REFERENCES dmstr_api_configuration (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE neuron_provider_model');
    }
}
