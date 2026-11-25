<?php

// file generated with AI assistance: Claude Code - 2026-10-08 16:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;

/**
 * SQL building blocks (PostgreSQL) for the migrations that turn a stored
 * pair of provider connection and model name into a reference to an entry
 * of the model catalogue ({@see ProviderModel}, `neuron_provider_model`).
 * Every migration of the model reference uses them, so the name matching
 * and the placeholder rows are the same everywhere.
 *
 *  - Names match as in {@see ModelName::equivalent()}: exactly, or with
 *    and without the tag `:latest`; the exact name wins
 *    ({@see ModelCapabilityCheck::find()}).
 *  - A pair without an entry gets a placeholder entry
 *    ({@see insertPlaceholders()}): `available` false, the endpoint
 *    fingerprint {@see PLACEHOLDER_FINGERPRINT} (no sha256 digest, so the
 *    entry is stale for every endpoint), a new `gen_random_uuid()`, no
 *    capabilities. An untagged name on an `ollama` connection is stored
 *    with `:latest`, the name Ollama lists, so the next scan of the
 *    connection that finds the model takes the entry over (same UUID,
 *    `available` true again) instead of adding a second one.
 *  - A pair that gets no entry from either (no connection, an empty name,
 *    a connection type the catalogue does not hold, a name too long for an
 *    entry) is found by {@see withoutEntry()}, so a migration whose column
 *    becomes NOT NULL can refuse to run while such rows exist.
 *  - A connection reference a user typed (name, full or partial UUID, IRI)
 *    is matched by {@see connectionPairs()}, as the connection resolver
 *    would have resolved it.
 *  - A stored document (a `json` column) is rewritten with
 *    {@see withModel()}, which changes the keys `model` and `connection`
 *    only and keeps every other key and value as stored.
 *
 * The fragments are interpolated from constant SQL of the migrations, never
 * from user input.
 */
final class ModelReferenceMigrationSql
{
    /** Endpoint fingerprint of a placeholder entry; not a sha256 digest, so the entry is stale. */
    public const string PLACEHOLDER_FINGERPRINT = 'placeholder';

    /** The connection types the catalogue holds entries for. */
    private const string CATALOGUE_TYPES = "'ollama', 'anthropic'";

    private function __construct()
    {
    }

    /**
     * True when the catalogue name $entryName matches the stored name
     * $name ({@see ModelName::equivalent()}; $name must not be empty).
     */
    public static function equivalent(string $entryName, string $name): string
    {
        return \sprintf("(%1\$s = %2\$s OR %1\$s = %2\$s || ':latest' OR %1\$s || ':latest' = %2\$s)", $entryName, $name);
    }

    /**
     * A scalar subquery: the id of the catalogue entry of connection
     * $connectionId (a uuid expression) for the stored name $name (a text
     * expression), the exact name first, else an equivalent one; NULL when
     * there is none.
     */
    public static function entryId(string $connectionId, string $name): string
    {
        return \sprintf(
            '(SELECT pm.id FROM neuron_provider_model pm WHERE pm.api_configuration_id = %1$s AND %2$s ORDER BY pm.name = %3$s DESC, pm.available DESC, pm.name LIMIT 1)',
            $connectionId,
            self::equivalent('pm.name', $name),
            $name,
        );
    }

    /**
     * A condition (boolean SQL): true when the pair of connection
     * $connectionId (a uuid expression, may be NULL) and stored name $name
     * (a text expression, may be NULL) gets no catalogue entry from
     * {@see insertPlaceholders()} followed by {@see entryId()}: no
     * connection, an empty name, or no equivalent entry and no placeholder
     * (a connection type the catalogue does not hold, or a name longer than
     * {@see ProviderModel::MAX_NAME_LENGTH}). It reads the state before the
     * placeholders are inserted, so a migration can evaluate it in up()
     * before any of its statements ran.
     */
    public static function withoutEntry(string $connectionId, string $name): string
    {
        return \sprintf(
            <<<'SQL'
                (%1$s IS NULL OR %2$s IS NULL OR %2$s = ''
                    OR (NOT EXISTS (SELECT 1 FROM neuron_provider_model pm WHERE pm.api_configuration_id = %1$s AND %3$s)
                        AND NOT EXISTS (
                            SELECT 1 FROM dmstr_api_configuration ac
                            WHERE ac.id = %1$s AND ac.type IN (%4$s)
                                AND char_length(CASE WHEN ac.type = 'ollama' AND strpos(%2$s, ':') = 0 THEN %2$s || ':latest' ELSE %2$s END) <= %5$d
                        )))
                SQL,
            $connectionId,
            $name,
            self::equivalent('pm.name', $name),
            self::CATALOGUE_TYPES,
            ProviderModel::MAX_NAME_LENGTH,
        );
    }

    /**
     * A scalar subquery: the JSON object $document (an expression of type
     * `json`) without its top-level key `connection`, with the value of
     * `model` replaced by $model (a `json` expression; null keeps the
     * stored value) and, when $connection (a `json` expression) is given,
     * with `connection` set to it right before `model`.
     *
     * The object is rebuilt from `json_each()` in the stored order, and
     * every other value is the stored JSON text, so the order of keys at
     * every depth stays as it was (a version's `responseSchema` and
     * `startSchema` go to the provider and the start form in that order).
     * A round trip through `jsonb` would sort the keys of every object.
     * $document must be an object; an object that ends up without keys
     * becomes `{}`.
     */
    public static function withModel(string $document, ?string $model, ?string $connection = null): string
    {
        $value = null === $model ? 'e.value' : \sprintf("CASE WHEN e.key = 'model' THEN %s ELSE e.value END", $model);
        $connectionRow = null === $connection ? '' : \sprintf(
            "SELECT 'connection'::text, (%s)::json, 2 * e.ord - 1 WHERE e.key = 'model' UNION ALL ",
            $connection,
        );

        return \sprintf(
            <<<'SQL'
                (SELECT COALESCE(json_object_agg(k.key, k.value ORDER BY k.ord), '{}'::json)
                FROM json_each(%1$s) WITH ORDINALITY e(key, value, ord)
                CROSS JOIN LATERAL (%2$sSELECT e.key, %3$s, 2 * e.ord WHERE e.key <> 'connection') k(key, value, ord))
                SQL,
            $document,
            $connectionRow,
            $value,
        );
    }

    /**
     * A SELECT of the pairs of $rows whose connection reference matches
     * exactly one `ollama` or `anthropic` connection, with the columns
     * `row_id`, `connection_id` and `name`. $rows is a SELECT with the
     * columns `row_id`, `reference` (text: the connection reference as a
     * user typed it) and `name` (text: the model name). The reference is
     * trimmed and matches as
     * {@see Connection\ProviderConnectionResolver} resolves it: the full
     * UUID, the IRI `/api/admin/api_configurations/<uuid>`, the name, or a
     * partial UUID of at least 8 hex digits. A reference that matches no
     * connection or several is left out (its row stays unmigrated).
     */
    public static function connectionPairs(string $rows): string
    {
        return \sprintf(
            <<<'SQL'
                SELECT m.row_id, (array_agg(m.connection_id))[1] AS connection_id, m.name
                FROM (
                    SELECT DISTINCT r.row_id, c.id AS connection_id, r.name
                    FROM (%1$s) r
                    JOIN dmstr_api_configuration c ON c.type IN (%2$s) AND (
                        c.id::text = lower(btrim(r.reference))
                        OR '/api/admin/api_configurations/' || c.id::text = lower(btrim(r.reference))
                        OR c.name = btrim(r.reference)
                        OR (
                            btrim(r.reference) ~* '^[0-9a-f]{8}[0-9a-f-]{0,28}$'
                            AND c.id::text LIKE lower(btrim(r.reference)) || '%%'
                        )
                    )
                    WHERE r.reference IS NOT NULL AND r.name IS NOT NULL AND r.name <> ''
                ) m
                GROUP BY m.row_id, m.name
                HAVING COUNT(*) = 1
                SQL,
            $rows,
            self::CATALOGUE_TYPES,
        );
    }

    /**
     * INSERT of a placeholder entry for every pair of $pairs that has no
     * entry yet. $pairs is a SELECT with the columns `connection_id` (uuid
     * of an existing connection) and `name` (text); pairs with an empty
     * name, a connection that is not `ollama` or `anthropic` or a name
     * longer than {@see ProviderModel::MAX_NAME_LENGTH} are skipped (their
     * rows stay unmigrated).
     */
    public static function insertPlaceholders(string $pairs): string
    {
        $name = "CASE WHEN c.type = 'ollama' AND strpos(r.name, ':') = 0 THEN r.name || ':latest' ELSE r.name END";

        return \sprintf(
            <<<'SQL'
                INSERT INTO neuron_provider_model (id, api_configuration_id, name, endpoint_fingerprint, available, created_at, updated_at)
                SELECT gen_random_uuid(), p.connection_id, p.name, '%1$s', false, NOW(), NOW()
                FROM (
                    SELECT DISTINCT c.id AS connection_id, %2$s AS name
                    FROM (%3$s) r
                    JOIN dmstr_api_configuration c ON c.id = r.connection_id AND c.type IN (%4$s)
                    WHERE r.name IS NOT NULL AND r.name <> ''
                ) p
                WHERE char_length(p.name) <= %5$d
                    AND NOT EXISTS (
                        SELECT 1 FROM neuron_provider_model pm
                        WHERE pm.api_configuration_id = p.connection_id AND %6$s
                    )
                SQL,
            self::PLACEHOLDER_FINGERPRINT,
            $name,
            $pairs,
            self::CATALOGUE_TYPES,
            ProviderModel::MAX_NAME_LENGTH,
            self::equivalent('pm.name', 'p.name'),
        );
    }

    /**
     * DELETE of the placeholder entries no scan took over (still
     * unavailable with {@see PLACEHOLDER_FINGERPRINT}), for the down()
     * of a migration once nothing references them any more.
     */
    public static function deletePlaceholders(): string
    {
        return \sprintf("DELETE FROM neuron_provider_model WHERE endpoint_fingerprint = '%s' AND available = false", self::PLACEHOLDER_FINGERPRINT);
    }
}
