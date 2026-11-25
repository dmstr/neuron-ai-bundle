<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:12 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Mapping of `neuron_stream_event`, one row per stored event of a
 * conversation's stream (resumption via `Last-Event-ID`).
 *
 * The mapping exists for the schema only (so the ORM schema diff stays
 * clean); rows are written and read with DBAL by
 * {@see \Dmstr\NeuronAiBundle\Stream\StreamEventStore}, never through the
 * entity manager. Not an API resource. Rows are deleted by the next turn of
 * the conversation and with the conversation (ON DELETE CASCADE).
 *
 * - id: database-generated and monotonic, the SSE event id;
 * - messageId: the in-flight assistant record of the turn (no foreign key);
 * - type/payload: {@see \Dmstr\NeuronAiBundle\Stream\StreamEvent}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'neuron_stream_event')]
#[ORM\Index(name: 'idx_neuron_stream_event_conversation', columns: ['conversation_id', 'id'])]
class StreamEventRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(name: 'conversation_id', nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    #[ORM\Column(type: 'uuid')]
    private Uuid $messageId;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $type;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(Conversation $conversation, Uuid $messageId, string $type, array $payload)
    {
        $this->conversation = $conversation;
        $this->messageId = $messageId;
        $this->type = $type;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return null !== $this->id ? (int) $this->id : null;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getMessageId(): Uuid
    {
        return $this->messageId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
