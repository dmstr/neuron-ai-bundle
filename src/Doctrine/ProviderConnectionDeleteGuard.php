<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Doctrine;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Agent\AgentVersionKind;
use Dmstr\NeuronAiBundle\Provider\ProviderType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Refuses to delete a provider connection (`ollama`, `anthropic`) that is
 * still referenced; references are never set to NULL.
 *
 * Doctrine entity listener `preRemove` on the vendor ApiConfiguration
 * entity, so it applies to `DELETE /api/admin/api_configurations/{id}`
 * (API Platform's Doctrine remove processor: HTTP 409) and to every other
 * path through the EntityManager (console, application code). Other
 * connection types pass untouched. To retire a referenced connection,
 * deactivate it (`active: false`); references to it then fail as stale.
 *
 * Every kind of reference is one private method returning human-readable
 * descriptions, combined in {@see self::references()}:
 *  - agent versions: `configuration->>'model'` holds the id of a model
 *    catalogue entry of the connection (`neuron_provider_model`, which the
 *    delete would cascade away), or, for a version the migration
 *    `Version20261008180000` could not rewrite, `configuration->>'connection'`
 *    still holds the connection id;
 *  - conversations: `neuron_conversation.provider_model_id` (NOT NULL) is
 *    a model catalogue entry of the connection (which the delete would
 *    cascade away against the key of the conversation), or
 *    `provider_connection_id` is the connection (the same connection as
 *    the entry's; kept as a second match); also anonymous, evaluation and
 *    replay conversations;
 *  - collections: `neuron_collection.embedding_model_id` (NOT NULL) or
 *    `pending_embedding_model_id` is a model catalogue entry of the
 *    connection (current model or reindex target, which the delete would
 *    cascade away against the keys of the collection), or
 *    `embedding_connection_id` or `pending_embedding_connection_id` is the
 *    connection (a pending target queued without an entry).
 * Replays hang on a conversation and a version and are covered by them.
 *
 * The listener runs in `preRemove`, before Doctrine deletes the connection
 * and the database cascades its entries, so a referenced connection always
 * answers 409 and never reaches the foreign keys of conversations and
 * collections, which have no cascade and, being NOT NULL, could not be set
 * to null either.
 */
final class ProviderConnectionDeleteGuard
{
    /** References named in the message; the rest is counted. */
    private const int MAX_LISTED = 10;

    public function preRemove(ApiConfiguration $connection, PreRemoveEventArgs $args): void
    {
        if (!ProviderType::isProvider($connection)) {
            return;
        }

        $references = $this->references($connection, $args->getObjectManager()->getConnection());
        if ([] === $references) {
            return;
        }

        $listed = \array_slice($references, 0, self::MAX_LISTED);
        $more = \count($references) - \count($listed);

        throw new ConflictHttpException(\sprintf(
            'Provider connection "%s" is still referenced by %s%s. Deactivate it (active: false) instead, or remove the references first.',
            $connection->getName(),
            implode('; ', $listed),
            $more > 0 ? \sprintf('; and %d more', $more) : '',
        ));
    }

    /**
     * Descriptions of everything that references the connection, in the
     * order agent versions, conversations, collections.
     *
     * @return list<string>
     */
    public function references(ApiConfiguration $connection, Connection $database): array
    {
        $id = $connection->getId()->toRfc4122();

        return [
            ...$this->agentVersionReferences($id, $database),
            ...$this->conversationReferences($id, $database),
            ...$this->collectionReferences($id, $database),
        ];
    }

    /**
     * Agent versions whose frozen configuration references a
     * model catalogue entry of the connection (`model` holds the entry's
     * full UUID, as stored by the publish), or names the connection itself
     * (`connection`, a version stored before model references). Previews
     * block as well (they hold a resolved configuration like a published
     * version) and are named as previews, since their number is always 0.
     *
     * @return list<string>
     */
    private function agentVersionReferences(string $id, Connection $database): array
    {
        $rows = $database->fetchAllAssociative(
            <<<'SQL'
                SELECT a.slug, v.version_number, v.kind, v.id
                FROM neuron_agent_version v
                JOIN neuron_agent a ON a.id = v.agent_id
                WHERE v.configuration->>'model' IN (
                        SELECT pm.id::text FROM neuron_provider_model pm WHERE pm.api_configuration_id = :id
                    )
                    OR v.configuration->>'connection' = :id
                ORDER BY a.slug, v.version_number
                SQL,
            ['id' => $id],
        );

        return array_map(
            static fn (array $row): string => AgentVersionKind::PREVIEW->value === $row['kind']
                ? \sprintf('agent preview of "%s" (/api/agent_versions/%s)', $row['slug'], $row['id'])
                : \sprintf('agent version %d of "%s" (/api/agent_versions/%s)', $row['version_number'], $row['slug'], $row['id']),
            $rows,
        );
    }

    /**
     * Conversations on the connection (any owner and origin), through their
     * model catalogue entry or directly: the
     * oldest by IRI, at most {@see self::MAX_LISTED} entries: with more
     * conversations the last entry counts the rest (`<n> more
     * conversations`).
     *
     * @return list<string>
     */
    private function conversationReferences(string $id, Connection $database): array
    {
        // Conversations can be many (anonymous ones): list a few, count the rest.
        $rows = $database->fetchAllAssociative(
            <<<'SQL'
                SELECT c.id, COUNT(*) OVER () AS total
                FROM neuron_conversation c
                WHERE c.provider_model_id IN (
                        SELECT pm.id FROM neuron_provider_model pm WHERE pm.api_configuration_id = :id
                    )
                    OR c.provider_connection_id = :id
                ORDER BY c.created_at, c.id
                LIMIT :limit
                SQL,
            ['id' => $id, 'limit' => self::MAX_LISTED],
            ['limit' => ParameterType::INTEGER],
        );
        if ([] === $rows) {
            return [];
        }

        // Titles and owners are personal data: name the conversations by IRI only.
        $references = array_map(
            static fn (array $row): string => \sprintf('conversation /api/conversations/%s', $row['id']),
            $rows,
        );
        $total = (int) $rows[0]['total'];
        if ($total > \count($references)) {
            // Keep the count within the listed entries of the message.
            $references = \array_slice($references, 0, self::MAX_LISTED - 1);
            $more = $total - \count($references);
            $references[] = \sprintf('%d more conversations', $more);
        }

        return $references;
    }

    /**
     * Collections embedding with the connection, through their catalogue
     * entry or directly, as the current model or as the target of a
     * pending reindex: by IRI and name (collection
     * names are machine names, no personal data), at most
     * {@see self::MAX_LISTED} entries; with more collections the last entry
     * counts the rest (`<n> more collections`).
     *
     * @return list<string>
     */
    private function collectionReferences(string $id, Connection $database): array
    {
        $rows = $database->fetchAllAssociative(
            <<<'SQL'
                WITH entries AS (
                    SELECT pm.id FROM neuron_provider_model pm WHERE pm.api_configuration_id = :id
                )
                SELECT c.id, c.name,
                    (c.embedding_connection_id = :id OR c.embedding_model_id IN (SELECT id FROM entries)) AS is_current,
                    COUNT(*) OVER () AS total
                FROM neuron_collection c
                WHERE c.embedding_connection_id = :id
                    OR c.pending_embedding_connection_id = :id
                    OR c.embedding_model_id IN (SELECT id FROM entries)
                    OR c.pending_embedding_model_id IN (SELECT id FROM entries)
                ORDER BY c.name, c.id
                LIMIT :limit
                SQL,
            ['id' => $id, 'limit' => self::MAX_LISTED],
            ['limit' => ParameterType::INTEGER],
        );
        if ([] === $rows) {
            return [];
        }

        $references = array_map(
            static fn (array $row): string => \sprintf(
                '%s "%s" (/api/admin/collections/%s)',
                \in_array($row['is_current'], [true, 1, '1', 't', 'true'], true) ? 'collection' : 'pending reindex of collection',
                $row['name'],
                $row['id'],
            ),
            $rows,
        );
        $total = (int) $rows[0]['total'];
        if ($total > \count($references)) {
            // Keep the count within the listed entries of the message.
            $references = \array_slice($references, 0, self::MAX_LISTED - 1);
            $more = $total - \count($references);
            $references[] = \sprintf('%d more collections', $more);
        }

        return $references;
    }
}

// - revised 2026-10-13
