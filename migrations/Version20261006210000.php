<?php

// file generated with AI assistance: Claude Code - 2026-10-06 21:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Moves the top-level `temperature` and `maxTokens` of every agent
 * candidate (`neuron_agent.candidate`) into `modelOptions`: the
 * candidate schema no longer has the two keys, so a candidate keeping them
 * could neither be saved nor published. A null value is dropped; a value
 * already set in `modelOptions` wins. Published versions
 * (`neuron_agent_version.configuration`) are not touched: they keep the
 * keys, which {@see \Dmstr\NeuronAiBundle\Agent\AgentConfiguration::fromArray()}
 * maps at every turn.
 *
 * down() moves the two options back to the top level (null when unset) and
 * drops `modelOptions` with every other option in it. The key checks use
 * `jsonb_exists*()` instead of the `?` operators, which a SQL parser could
 * take for placeholders.
 */
final class Version20261006210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move temperature and maxTokens of agent candidates into modelOptions (of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE neuron_agent
            SET candidate = (
                (candidate::jsonb - 'temperature' - 'maxTokens')
                || jsonb_build_object(
                    'modelOptions',
                    jsonb_strip_nulls(jsonb_build_object(
                        'temperature', candidate::jsonb -> 'temperature',
                        'maxTokens', candidate::jsonb -> 'maxTokens'
                    ))
                    || COALESCE(candidate::jsonb -> 'modelOptions', '{}'::jsonb)
                )
            )::json
            WHERE jsonb_exists_any(candidate::jsonb, ARRAY['temperature', 'maxTokens'])
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE neuron_agent
            SET candidate = (
                (candidate::jsonb - 'modelOptions')
                || jsonb_build_object(
                    'temperature', candidate::jsonb -> 'modelOptions' -> 'temperature',
                    'maxTokens', candidate::jsonb -> 'modelOptions' -> 'maxTokens'
                )
            )::json
            WHERE jsonb_exists(candidate::jsonb, 'modelOptions')
            SQL);
    }
}
