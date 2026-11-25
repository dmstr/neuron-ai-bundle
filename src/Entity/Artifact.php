<?php

// file generated with AI assistance: Claude Code - 2026-10-16 08:40:52 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Ledger row of one file an agent wrote with the artifact tools:
 * owner, path relative to the owner's artifact root, size and the
 * conversations that created and last changed it.
 *
 * - ownerSubject: the conversation owner (Keycloak `sub`), also the name of
 *   the user root `<artifacts.root>/<ownerSubject>`;
 * - path: relative to the user root, `/` as separator, in the spelling of
 *   the last write; a conversation's own files lie below
 *   `<conversationId>/`, files of earlier conversations may be edited;
 * - pathLower: unique per owner, so two names differing only in case are
 *   refused before a case-insensitive share (CIFS) merges them;
 * - sizeBytes: the size after the last write, the base of the user quota;
 * - conversation: the creating conversation, SET NULL when it is deleted
 *   (the file outlives it);
 * - createdBy, updatedBy: the ids of the creating and the last writing
 *   conversation, kept after a conversation is deleted.
 *
 * Rows are written by {@see \Dmstr\NeuronAiBundle\Artifact\DoctrineArtifactLedger}
 * with plain DBAL during the turn; the entity is the schema and the read
 * model. Not an API resource.
 */
#[ORM\Entity]
#[ORM\Table(name: 'neuron_artifact')]
#[ORM\UniqueConstraint(name: 'uniq_neuron_artifact_owner_path_lower', columns: ['owner_subject', 'path_lower'])]
#[ORM\Index(name: 'idx_neuron_artifact_conversation', columns: ['conversation_id'])]
class Artifact
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $ownerSubject;

    #[ORM\Column(type: Types::STRING, length: 1024)]
    private string $path;

    #[ORM\Column(type: Types::STRING, length: 1024)]
    private string $pathLower;

    #[ORM\Column(type: Types::INTEGER)]
    private int $sizeBytes;

    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(name: 'conversation_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Conversation $conversation;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $createdBy;

    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $updatedBy;

    public function __construct(string $ownerSubject, string $path, int $sizeBytes, ?Conversation $conversation, string $conversationId)
    {
        $this->id = Uuid::v4();
        $this->ownerSubject = $ownerSubject;
        $this->path = $path;
        $this->pathLower = mb_strtolower($path, 'UTF-8');
        $this->sizeBytes = $sizeBytes;
        $this->conversation = $conversation;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->createdBy = $conversationId;
        $this->updatedBy = $conversationId;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwnerSubject(): string
    {
        return $this->ownerSubject;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getPathLower(): string
    {
        return $this->pathLower;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function getConversation(): ?Conversation
    {
        return $this->conversation;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCreatedBy(): string
    {
        return $this->createdBy;
    }

    public function getUpdatedBy(): string
    {
        return $this->updatedBy;
    }

    /**
     * A later write of the same file: new size and spelling, new updater.
     */
    public function recordWrite(string $path, int $sizeBytes, string $conversationId): void
    {
        $this->path = $path;
        $this->pathLower = mb_strtolower($path, 'UTF-8');
        $this->sizeBytes = $sizeBytes;
        $this->updatedBy = $conversationId;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
