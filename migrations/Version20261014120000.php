<?php

// file generated with AI assistance: Claude Code - 2026-10-14 12:30:38 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Converts `neuron_message.provider_payload` from the neuron-ai 3.x to the
 * 4.x serialization (neuron 4.x guide 34). The bundle reads only the 4.x
 * shape (`HistoryReconstructor` replays a `message` only when it holds
 * `__meta`), so without this migration the turns stored before the upgrade
 * would no longer be replayed into the next turn.
 *
 * - `message` (the Neuron serialization of the step's response, or of the
 *   user message): the message fields `__id`, `role`, `content`, `usage`,
 *   `type` and `tools` stay at the top level, every other key (the
 *   metadata: `stop_reason`, `citations`, ...) moves under `__meta`, which is
 *   `[]` when there is none, as 4.x writes it. The entries of `tools` lose
 *   `parameters` and get `deferred: false` and `approval`, `approvalReason`,
 *   `rejectReason` as null.
 * - `tool` of a `tool_call` record (the serialized call): the same change
 *   of the entry; `result` stays as stored (a 3.x MCP result is the JSON
 *   string of its content list, which 4.x reads as a plain string).
 *
 * Only rows in the 3.x shape are touched (a `message` without `__meta`, a
 * `tool` with `parameters`; key presence is tested with `->` instead of the
 * `?` operator, which DBAL would take for a placeholder), so the migration
 * can run on a database that
 * already holds 4.x rows. Rows are never deleted: conversations are
 * referenced by stream events, replays, feedback and evaluation results.
 * The json columns are rewritten through jsonb, which normalizes the key
 * order of the converted objects.
 *
 * down() converts the 4.x shape back: `__meta` is spread to the top level,
 * the four 4.x keys of the tool entries are dropped and `parameters` comes
 * back as `[]` (its 3.x value, the provider options of the tool, is not
 * stored anywhere), and a `tool.result` that is a content-block array (a
 * 4.x `ToolOutput`) becomes the JSON string of that array.
 */
final class Version20261014120000 extends AbstractMigration
{
    /** The keys of a serialized message that are not metadata. */
    private const string MESSAGE_FIELDS = "('__id', 'role', 'content', 'usage', 'type', 'tools')";

    /** The keys a 4.x tool entry has and a 3.x one has not. */
    private const string TOOL_ENTRY_KEYS = "jsonb_build_object('deferred', false, 'approval', NULL, 'approvalReason', NULL, 'rejectReason', NULL)";

    public function getDescription(): string
    {
        return 'Convert neuron_message.provider_payload to the neuron-ai 4.x message serialization (metadata under __meta, 4.x tool entries)';
    }

    public function up(Schema $schema): void
    {
        $toolEntries = \sprintf(
            "(SELECT COALESCE(jsonb_agg((t.value - 'parameters') || %s ORDER BY t.ord), '[]'::jsonb) FROM jsonb_array_elements(e.value) WITH ORDINALITY AS t(value, ord))",
            self::TOOL_ENTRY_KEYS,
        );
        $message = \sprintf(
            "(SELECT COALESCE(jsonb_object_agg(e.key, CASE WHEN e.key = 'tools' AND jsonb_typeof(e.value) = 'array' THEN %s ELSE e.value END) FILTER (WHERE e.key IN %s), '{}'::jsonb)"
            ." || jsonb_build_object('__meta', COALESCE(jsonb_object_agg(e.key, e.value) FILTER (WHERE e.key NOT IN %s), '[]'::jsonb))"
            ." FROM jsonb_each(provider_payload::jsonb -> 'message') AS e)",
            $toolEntries,
            self::MESSAGE_FIELDS,
            self::MESSAGE_FIELDS,
        );

        $this->addSql(\sprintf(
            "UPDATE neuron_message SET provider_payload = jsonb_set(provider_payload::jsonb, '{message}', %s)::json"
            ." WHERE jsonb_typeof(provider_payload::jsonb -> 'message') = 'object' AND provider_payload::jsonb -> 'message' -> '__meta' IS NULL",
            $message,
        ));
        $this->addSql(\sprintf(
            "UPDATE neuron_message SET provider_payload = jsonb_set(provider_payload::jsonb, '{tool}', ((provider_payload::jsonb -> 'tool') - 'parameters') || %s)::json"
            ." WHERE provider_payload::jsonb ->> 'part' = 'tool_call' AND jsonb_typeof(provider_payload::jsonb -> 'tool') = 'object' AND provider_payload::jsonb -> 'tool' -> 'parameters' IS NOT NULL",
            self::TOOL_ENTRY_KEYS,
        ));
    }

    public function down(Schema $schema): void
    {
        $toolEntry = "((%1\$s - 'deferred' - 'approval' - 'approvalReason' - 'rejectReason' - 'result') || jsonb_build_object('parameters', '[]'::jsonb)"
            ." || CASE WHEN %1\$s -> 'result' IS NULL THEN '{}'::jsonb"
            ." WHEN jsonb_typeof(%1\$s -> 'result') IN ('array', 'object') THEN jsonb_build_object('result', to_jsonb((%1\$s -> 'result')::text))"
            ." ELSE jsonb_build_object('result', %1\$s -> 'result') END)";
        $message = \sprintf(
            "(((provider_payload::jsonb -> 'message') - '__meta')"
            ." || CASE WHEN jsonb_typeof(provider_payload::jsonb -> 'message' -> '__meta') = 'object' THEN provider_payload::jsonb -> 'message' -> '__meta' ELSE '{}'::jsonb END)"
            ." || CASE WHEN jsonb_typeof(provider_payload::jsonb -> 'message' -> 'tools') = 'array'"
            ." THEN jsonb_build_object('tools', (SELECT COALESCE(jsonb_agg(%s ORDER BY t.ord), '[]'::jsonb) FROM jsonb_array_elements(provider_payload::jsonb -> 'message' -> 'tools') WITH ORDINALITY AS t(value, ord)))"
            ." ELSE '{}'::jsonb END",
            \sprintf($toolEntry, 't.value'),
        );

        $this->addSql(\sprintf(
            "UPDATE neuron_message SET provider_payload = jsonb_set(provider_payload::jsonb, '{message}', %s)::json"
            ." WHERE jsonb_typeof(provider_payload::jsonb -> 'message') = 'object' AND provider_payload::jsonb -> 'message' -> '__meta' IS NOT NULL",
            $message,
        ));
        $this->addSql(\sprintf(
            "UPDATE neuron_message SET provider_payload = jsonb_set(provider_payload::jsonb, '{tool}', %s)::json"
            ." WHERE provider_payload::jsonb ->> 'part' = 'tool_call' AND jsonb_typeof(provider_payload::jsonb -> 'tool') = 'object' AND provider_payload::jsonb -> 'tool' -> 'deferred' IS NOT NULL",
            \sprintf($toolEntry, "(provider_payload::jsonb -> 'tool')"),
        ));
    }
}
