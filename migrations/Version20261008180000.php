<?php

// file generated with AI assistance: Claude Code - 2026-10-08 16:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Dmstr\NeuronAiBundle\Provider\ModelReferenceMigrationSql as Sql;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Turns the model of agent versions and candidates into a reference to the
 * model catalogue: `{connection, model: <name>}` becomes
 * `{model: <uuid of the neuron_provider_model entry>}`, without
 * `connection` (the entry names its connection).
 *
 *  - Published versions (`neuron_agent_version.configuration`): the stored
 *    `connection` is the full UUID of the connection. The frozen
 *    configuration is rewritten once; versions are immutable for the API
 *    only. A version whose connection no longer exists, or that has no
 *    model name (one published before provider connections, with a
 *    `provider` string), is left as it is; the turn mapper reports it as
 *    stale at `configuration/model`, as it did before at
 *    `configuration/connection`.
 *  - Candidates (`neuron_agent.candidate`): `connection` is the reference
 *    the user typed: full or partial UUID, IRI or name, matched against the
 *    `ollama` and `anthropic` connections and used only when it matches
 *    exactly one. Every candidate loses the key `connection`, which the
 *    candidate schema no longer has; one whose connection does not match
 *    keeps its model name, which the publish then rejects as no reference.
 *  - Names match with and without `:latest`; a pair without a catalogue
 *    entry gets a placeholder entry (`available` false, stale fingerprint),
 *    see {@see Sql}. Placeholders keep migrated versions running: the turn
 *    uses the entry's name and does not check `available`.
 *
 * Only the keys `model` and `connection` change: the documents are rebuilt
 * in their stored order ({@see Sql::withModel()}), so a version's
 * `responseSchema`, `startSchema` and every other value keep their key
 * order and text. jsonb serves the lookups only, never the written value,
 * because it sorts the keys of every object.
 *
 * down() writes `connection` (the entry's connection id, right before
 * `model`) and `model` (the entry's name) back for every model that is a
 * full UUID or an IRI of an entry, then deletes the placeholder entries no
 * scan took over. An untagged Ollama name comes back with `:latest`, the
 * entry's name. The key checks use `jsonb_exists()` instead of the `?`
 * operator, which a SQL parser could take for a placeholder.
 */
final class Version20261008180000 extends AbstractMigration
{
    /** Versions with a model name and an existing connection: `row_id`, `connection_id`, `name`. */
    private const string VERSION_PAIRS = <<<'SQL'
        SELECT v.id AS row_id, c.id AS connection_id, v.configuration::jsonb ->> 'model' AS name
        FROM neuron_agent_version v
        JOIN dmstr_api_configuration c ON c.id::text = lower(v.configuration::jsonb ->> 'connection')
        WHERE jsonb_typeof(v.configuration::jsonb -> 'connection') = 'string'
            AND jsonb_typeof(v.configuration::jsonb -> 'model') = 'string'
            AND v.configuration::jsonb ->> 'model' <> ''
        SQL;

    /**
     * Candidates with a string `connection` and a model name:
     * `row_id`, `reference`, `name`; matched to their connection by
     * {@see Sql::connectionPairs()}.
     */
    private const string CANDIDATE_ROWS = <<<'SQL'
        SELECT a.id AS row_id, a.candidate::jsonb ->> 'connection' AS reference, a.candidate::jsonb ->> 'model' AS name
        FROM neuron_agent a
        WHERE jsonb_typeof(a.candidate::jsonb -> 'connection') = 'string'
            AND jsonb_typeof(a.candidate::jsonb -> 'model') = 'string'
            AND a.candidate::jsonb ->> 'model' <> ''
        SQL;

    public function getDescription(): string
    {
        return 'Agent versions and candidates reference the model catalogue instead of connection and model name (of dmstr/neuron-ai-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(Sql::insertPlaceholders(self::VERSION_PAIRS));
        $candidatePairs = Sql::connectionPairs(self::CANDIDATE_ROWS);
        $this->addSql(Sql::insertPlaceholders($candidatePairs));

        $this->addSql(\sprintf(
            <<<'SQL'
                UPDATE neuron_agent_version v
                SET configuration = %s
                FROM (SELECT p.row_id, %s AS model_id FROM (%s) p) r
                WHERE v.id = r.row_id AND r.model_id IS NOT NULL
                SQL,
            Sql::withModel('v.configuration', 'to_json(r.model_id::text)'),
            Sql::entryId('p.connection_id', 'p.name'),
            self::VERSION_PAIRS,
        ));

        $this->addSql(\sprintf(
            <<<'SQL'
                UPDATE neuron_agent a
                SET candidate = %s
                FROM (SELECT p.row_id, %s AS model_id FROM (%s) p) r
                WHERE a.id = r.row_id AND r.model_id IS NOT NULL
                SQL,
            Sql::withModel('a.candidate', 'to_json(r.model_id::text)'),
            Sql::entryId('p.connection_id', 'p.name'),
            $candidatePairs,
        ));

        // The candidate schema has no `connection` any more.
        $this->addSql(\sprintf(
            <<<'SQL'
                UPDATE neuron_agent a
                SET candidate = %s
                WHERE json_typeof(a.candidate) = 'object' AND jsonb_exists(a.candidate::jsonb, 'connection')
                SQL,
            Sql::withModel('a.candidate', null),
        ));
    }

    public function down(Schema $schema): void
    {
        $restore = static fn (string $document): string => Sql::withModel($document, 'to_json(pm.name)', 'to_json(pm.api_configuration_id::text)');

        $this->addSql(\sprintf(
            <<<'SQL'
                UPDATE neuron_agent_version v
                SET configuration = %s
                FROM neuron_provider_model pm
                WHERE json_typeof(v.configuration) = 'object'
                    AND pm.id::text = lower(v.configuration::jsonb ->> 'model')
                SQL,
            $restore('v.configuration'),
        ));

        $this->addSql(\sprintf(
            <<<'SQL'
                UPDATE neuron_agent a
                SET candidate = %s
                FROM neuron_provider_model pm
                WHERE json_typeof(a.candidate) = 'object' AND (
                    pm.id::text = lower(a.candidate::jsonb ->> 'model')
                    OR '/api/refs/provider_models/' || pm.id::text = lower(a.candidate::jsonb ->> 'model')
                )
                SQL,
            $restore('a.candidate'),
        ));

        $this->addSql(Sql::deletePlaceholders());
    }
}
