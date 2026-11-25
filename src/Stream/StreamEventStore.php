<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:12 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Stream;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;

/**
 * Storage of stream events for resumption (`neuron_stream_event`).
 *
 * Every row has a database-generated, monotonic id, which is the SSE `id`
 * a client resumes from. Writes go through DBAL in autocommit mode, never
 * through the entity manager and never inside an ORM transaction, so a
 * failed event write cannot close the entity manager or roll back message
 * records; the caller logs it and goes on (events only serve resumption).
 *
 * The mapping {@see \Dmstr\NeuronAiBundle\Entity\StreamEventRecord} exists
 * for the schema only.
 */
final class StreamEventStore
{
    private const string TABLE = 'neuron_stream_event';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Inserts one row and returns its id.
     *
     * @param array<string, mixed> $payload
     */
    public function append(Uuid $conversationId, Uuid $messageId, string $type, array $payload): int
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO '.self::TABLE.' (conversation_id, message_id, type, payload, created_at) VALUES (:conversation, :message, :type, :payload, :created) RETURNING id',
            [
                'conversation' => $conversationId->toRfc4122(),
                'message' => $messageId->toRfc4122(),
                'type' => $type,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created' => new \DateTimeImmutable(),
            ],
            ['created' => Types::DATETIMETZ_IMMUTABLE],
        );

        if (false === $id) {
            throw new \RuntimeException('The stream event was not stored.');
        }

        return (int) $id;
    }

    /**
     * All rows of the conversation with an id greater than $afterId.
     *
     * Payloads are decoded as arrays; an empty `input` of a `tool_call`
     * event is restored as an empty object, as it was sent.
     *
     * @return list<StreamEvent> ordered by id, with ids
     */
    public function after(Uuid $conversationId, int $afterId = 0): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, type, payload FROM '.self::TABLE.' WHERE conversation_id = :conversation AND id > :after ORDER BY id ASC',
            ['conversation' => $conversationId->toRfc4122(), 'after' => $afterId],
            ['after' => ParameterType::INTEGER],
        );

        $events = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
            $payload = \is_array($payload) ? $payload : [];
            if (StreamEvent::TOOL_CALL === $row['type'] && [] === ($payload['input'] ?? null)) {
                $payload['input'] = new \stdClass();
            }
            $events[] = new StreamEvent((string) $row['type'], $payload, (int) $row['id']);
        }

        return $events;
    }

    public function hasEvents(Uuid $conversationId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM '.self::TABLE.' WHERE conversation_id = :conversation LIMIT 1',
            ['conversation' => $conversationId->toRfc4122()],
        );
    }

    /**
     * Whether a `done` or `error` event of the conversation is stored.
     */
    public function hasTerminal(Uuid $conversationId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM '.self::TABLE.' WHERE conversation_id = :conversation AND type IN (:done, :error) LIMIT 1',
            ['conversation' => $conversationId->toRfc4122(), 'done' => StreamEvent::DONE, 'error' => StreamEvent::ERROR],
        );
    }

    public function deleteFor(Uuid $conversationId): void
    {
        $this->connection->executeStatement(
            'DELETE FROM '.self::TABLE.' WHERE conversation_id = :conversation',
            ['conversation' => $conversationId->toRfc4122()],
        );
    }
}
