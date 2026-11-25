<?php

// file generated with AI assistance: Claude Code - 2026-10-07 19:32:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Removes `num_ctx` from the configuration of `ollama` connections. The
 * context length is a model setting now (agent `modelOptions.numCtx`, else
 * the Modelfile `num_ctx`, else the server default), and
 * ollama.schema.json no longer has the key; with `additionalProperties:
 * false` a connection that still carried it could not be saved again.
 *
 * Agents without `modelOptions.numCtx` on such a connection run with the
 * model's default context length from now on; set `numCtx` on them to keep
 * the former value. Not reversible: the removed values are not kept.
 *
 * `jsonb_exists()` instead of the `?` operator, which DBAL would read as a
 * positional parameter.
 */
final class Version20261007193253 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove num_ctx from ollama provider connections (dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE dmstr_api_configuration SET config_json = (config_json::jsonb - 'num_ctx')::json WHERE type = 'ollama' AND jsonb_exists(config_json::jsonb, 'num_ctx')");
    }

    public function down(Schema $schema): void
    {
        // The removed values are gone; an empty down keeps the chain usable.
    }
}
