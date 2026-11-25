<?php

// file generated with AI assistance: Claude Code - 2026-10-13 23:10:57 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Preview versions: `neuron_agent_version.kind` (`published` or `preview`,
 * every existing row is published through the default) and
 * `candidate_hash` (sha256 hex of a preview's configuration, null for
 * published versions).
 *
 * The plain unique index on `(agent_id, version_number)` becomes partial
 * (published versions only, all previews have number 0), and a second
 * partial unique index makes `(agent_id, candidate_hash)` unique among the
 * previews. The predicates are written as PostgreSQL renders them
 * (pg_get_expr), which is the `where` option of the ORM mapping, so the
 * schema tool sees no diff.
 *
 * down() fails while previews exist (the plain unique index would collide
 * on number 0 when an agent has two previews); delete them first.
 */
final class Version20261013210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add neuron_agent_version.kind and candidate_hash, partial unique indexes per kind (preview versions of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE neuron_agent_version ADD kind VARCHAR(16) DEFAULT 'published' NOT NULL");
        $this->addSql('ALTER TABLE neuron_agent_version ADD candidate_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('DROP INDEX uniq_neuron_agent_version_agent_number');
        $this->addSql("CREATE UNIQUE INDEX uniq_neuron_agent_version_agent_number ON neuron_agent_version (agent_id, version_number) WHERE ((kind)::text = 'published'::text)");
        $this->addSql("CREATE UNIQUE INDEX uniq_neuron_agent_version_agent_hash ON neuron_agent_version (agent_id, candidate_hash) WHERE ((kind)::text = 'preview'::text)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_neuron_agent_version_agent_hash');
        $this->addSql('DROP INDEX uniq_neuron_agent_version_agent_number');
        $this->addSql('CREATE UNIQUE INDEX uniq_neuron_agent_version_agent_number ON neuron_agent_version (agent_id, version_number)');
        $this->addSql('ALTER TABLE neuron_agent_version DROP candidate_hash');
        $this->addSql('ALTER TABLE neuron_agent_version DROP kind');
    }
}
