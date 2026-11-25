<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use Dmstr\NeuronAiBundle\OpenApi\OpenApiTag;
use Dmstr\NeuronAiBundle\Replay\ConversationReplayStatus;
use Dmstr\NeuronAiBundle\Security\ConversationSecurity;
use Dmstr\NeuronAiBundle\State\ConversationReplayCancelProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * One conversation replay (operation and job type
 * `conversation_replay`): the user messages of a stored source
 * {@see Conversation} are replayed, in their order, as the turns of a new
 * hidden conversation (`origin` = `replay`) pinned to the target
 * {@see AgentVersion}. Not to be confused with the history replay of a
 * turn (the stored records of a conversation sent to the model again, see
 * {@see \Dmstr\NeuronAiBundle\Conversation\HistoryReconstructor}).
 *
 * - id: the id of the replay conversation (`conversation`); the record is
 *   addressed through it (`/conversations/{id}/replay_run`), so both share
 *   one UUID;
 * - conversation: the replay conversation, created together with this
 *   record by the trigger request and owned by `ownerSubject`; deleting it
 *   deletes the record (ON DELETE CASCADE);
 * - sourceConversation: the replayed conversation; set to null by the
 *   database when it is deleted (ON DELETE SET NULL, because the anonymous
 *   purge deletes with DQL and no ORM cascade runs);
 * - targetVersion: the agent version the replay runs on (ON DELETE
 *   RESTRICT; versions are never deleted anyway);
 * - sourceMessageIds: the ids of the source's user messages in sequence
 *   order, frozen by the trigger request (never serialized);
 *   `turnsTotal` is their number, `turnsReplayed` the user messages the
 *   replay conversation holds;
 * - overrides: `variables` and `language` exactly as the request gave
 *   them (only the keys given); `language` the effective language. Not
 *   audited: the entity is not loggable, and variables are personal data;
 * - status: {@see ConversationReplayStatus}; `cancelRequestedAt` is the
 *   replay's own cancel flag, read from the database before each turn
 *   (the conversation's `cancelRequestedAt` is cleared by every turn and
 *   acts on streamed turns only);
 * - jobId: id of the job of dmstr/symfony-job-queue-bundle (no foreign key).
 *
 * Operations: `POST /conversations/{id}/replay` (on the {@see Conversation}
 * resource, `{id}` = the source; {@see \Dmstr\NeuronAiBundle\State\ConversationReplayProcessor})
 * for `neuronai:eval:write`; `GET /conversations/{id}/replay_run` (`{id}` = the
 * replay conversation) for readers of the replay conversation
 * (`neuronai:conversation:read_own`: the owner, holders of `neuronai:conversation:read_eval` or
 * `neuronai:conversation:read_any`); `POST /conversations/{id}/replay_run/cancel`
 * ({@see ConversationReplayCancelProcessor}) for `neuronai:eval:write`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'neuron_conversation_replay')]
#[ORM\UniqueConstraint(name: 'uniq_neuron_conversation_replay_conversation', columns: ['conversation_id'])]
#[ORM\Index(name: 'idx_neuron_conversation_replay_source', columns: ['source_conversation_id'])]
#[ORM\Index(name: 'idx_neuron_conversation_replay_target_version', columns: ['target_version_id'])]
#[ORM\Index(name: 'idx_neuron_conversation_replay_owner_subject', columns: ['owner_subject'])]
#[ORM\Index(name: 'idx_neuron_conversation_replay_status', columns: ['status'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'ConversationReplay',
    operations: [
        new Get(
            uriTemplate: '/conversations/{id}/replay_run',
            description: 'Shows the replay that produced this replay conversation: its source, target version, status and progress.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::AGENTS],
                summary: 'Returns the replay that produced a replay conversation.',
                description: '`{id}` is the replay conversation (`origin` `replay`), not the source. Readable by everybody who may read the replay conversation: its owner (the evaluation admin who triggered the replay), holders of `neuronai:conversation:read_eval` (as mapped by the application) and of `neuronai:conversation:read_any`; 403 otherwise, 404 for a conversation that is no replay conversation. `overrides` holds the request\'s `variables` and `language` as given; the variables are personal data.',
            ),
            security: ConversationSecurity::READ_PARENT,
        ),
        new Post(
            uriTemplate: '/conversations/{id}/replay_run/cancel',
            status: 200,
            description: 'Stops a pending or running replay: the turn in progress is completed, the remaining messages are not replayed, and the replay ends as cancelled. A replay that has already ended cannot be cancelled.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::AGENTS],
                responses: [
                    '200' => new OpenApiResponse(description: 'The replay with `cancelRequestedAt` set; its status changes when the replay job notices the request.'),
                ],
                summary: 'Asks a pending or running conversation replay to stop.',
                description: '`{id}` is the replay conversation. Sets `cancelRequestedAt`; the replay job reads it before each turn and ends the replay as `cancelled`, so a running turn completes first and the remaining source messages are not replayed. Requesting it again keeps the first time. No body; the response is the replay. Rejected with 422 when the replay has already ended (`completed`, `partial`, `cancelled` or `failed`); holders of `neuronai:eval:write` only.',
            ),
            normalizationContext: ['groups' => [self::GROUP_READ]],
            security: "is_granted('neuronai:eval:write')",
            input: false,
            read: true,
            deserialize: false,
            validate: false,
            name: self::OPERATION_CANCEL,
            processor: ConversationReplayCancelProcessor::class,
            throwOnNotFound: true,
        ),
    ],
    description: <<<'MD'
        A conversation replay sends the user messages of an existing conversation, in their original order, to a chosen agent version again. The answers are collected in a new, hidden replay conversation, so you can compare how another version answers the same questions.

        A replay runs in the background and ends as completed, partial (the replay conversation was closed early, for example by a turn limit), cancelled or failed. While it runs, no manual messages can be sent to the replay conversation. A replay is looked up through its replay conversation.

        Related: the source and the replay conversation are **Conversation**s, the target is an **AgentVersion**. A replay is started with the replay action of **Conversation** and runs as a **Job**.
        MD,
    normalizationContext: ['groups' => [self::GROUP_READ]],
    openapi: new OpenApiOperation(tags: [OpenApiTag::AGENTS]),
)]
class ConversationReplay
{
    public const string GROUP_READ = 'conversation_replay:read';

    /**
     * Job type of the replay, run by
     * {@see \Dmstr\NeuronAiBundle\JobProcessor\ConversationReplayJobProcessor};
     * equal to the trigger operation's name {@see Conversation::OPERATION_REPLAY}.
     */
    public const string JOB_TYPE = 'conversation_replay';

    /** `POST /conversations/{id}/replay_run/cancel`; no body. */
    public const string OPERATION_CANCEL = 'conversation_replay_cancel';

    public const int MAX_OWNER_SUBJECT_LENGTH = 190;

    /** The id of the replay conversation. */
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[Groups([self::GROUP_READ])]
    private Uuid $id;

    /** The replay conversation (`origin` `replay`); serialized as IRI. */
    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(name: 'conversation_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[ApiProperty(description: 'The replay conversation (`origin` `replay`, owned by the triggering user) whose turns are the replayed source messages.')]
    #[Groups([self::GROUP_READ])]
    private Conversation $conversation;

    /** The replayed conversation; null once it was deleted. */
    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(name: 'source_conversation_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[ApiProperty(description: 'The replayed conversation; null once it was deleted (the replay stays).')]
    #[Groups([self::GROUP_READ])]
    private ?Conversation $sourceConversation = null;

    /** The agent version the replay runs on; serialized as IRI. */
    #[ORM\ManyToOne(targetEntity: AgentVersion::class)]
    #[ORM\JoinColumn(name: 'target_version_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[ApiProperty(description: 'The agent version the replay conversation is pinned to; any published version, not only the current one.')]
    #[Groups([self::GROUP_READ])]
    private AgentVersion $targetVersion;

    /** User identifier of whoever triggered the replay; owns the replay conversation. */
    #[ORM\Column(type: Types::STRING, length: self::MAX_OWNER_SUBJECT_LENGTH)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private string $ownerSubject;

    /** One of {@see ConversationReplayStatus::ALL}. */
    #[ORM\Column(type: Types::STRING, length: 20, options: ['default' => ConversationReplayStatus::PENDING])]
    #[ApiProperty(
        description: '`pending`, `running`, `completed` (every source user message was replayed), `partial` (the replay conversation closed first, e.g. at the target version\'s user-turn limit), `cancelled` or `failed` (see `errorMessage`).',
        writable: false,
    )]
    #[Groups([self::GROUP_READ])]
    private string $status = ConversationReplayStatus::PENDING;

    /** The effective language of the replay conversation. */
    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    #[ApiProperty(description: 'The effective language of the replay conversation: the requested one, else the source\'s language when the target version supports it, else the target version\'s `defaultLanguage`.', writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?string $language = null;

    /**
     * `variables` and `language` exactly as the trigger request gave them
     * (only the keys given); null when the request gave neither. Personal
     * data; never audited.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[ApiProperty(description: '`variables` and `language` exactly as the trigger request gave them (only the keys given), null when it gave neither. `variables` replaced the source\'s start parameters as a whole. Never audited.', writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?array $overrides = null;

    /**
     * Ids of the source's user messages in sequence order, frozen by the
     * trigger request; never serialized.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    #[ApiProperty(readable: false, writable: false)]
    private array $sourceMessageIds = [];

    /** Id of the job queue job that runs the replay (no foreign key). */
    #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?string $jobId = null;

    /** Set by the cancel operation; the job stops before the next turn. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?\DateTimeImmutable $cancelRequestedAt = null;

    /** User messages the replay conversation holds. */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private int $turnsReplayed = 0;

    /** Source user messages to replay. */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private int $turnsTotal = 0;

    /** Why a `failed` replay stopped. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[ApiProperty(writable: false, openapiContext: ['x-format' => 'textarea', 'x-grid' => ['columnsMd' => 12, 'newRow' => true]])]
    #[Groups([self::GROUP_READ])]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[Groups([self::GROUP_READ])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[Groups([self::GROUP_READ])]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param Conversation $conversation the new replay conversation; the record takes its id
     */
    public function __construct(Conversation $conversation, AgentVersion $targetVersion, string $ownerSubject)
    {
        $this->id = $conversation->getId();
        $this->conversation = $conversation;
        $this->targetVersion = $targetVersion;
        $this->ownerSubject = $ownerSubject;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getSourceConversation(): ?Conversation
    {
        return $this->sourceConversation;
    }

    public function setSourceConversation(?Conversation $sourceConversation): self
    {
        $this->sourceConversation = $sourceConversation;

        return $this;
    }

    public function getTargetVersion(): AgentVersion
    {
        return $this->targetVersion;
    }

    public function getOwnerSubject(): string
    {
        return $this->ownerSubject;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        if (!\in_array($status, ConversationReplayStatus::ALL, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a status of a conversation replay.', $status));
        }
        $this->status = $status;

        return $this;
    }

    /**
     * Whether the replay has not ended yet (pending or running); only such
     * a replay can be cancelled, and only then a manual turn is refused.
     */
    public function isActive(): bool
    {
        return ConversationReplayStatus::isActive($this->status);
    }

    /**
     * Ends the replay with a final $status at $at; `failed` keeps $error.
     */
    public function finish(string $status, \DateTimeImmutable $at, ?string $error = null): self
    {
        if (ConversationReplayStatus::isActive($status) || !\in_array($status, ConversationReplayStatus::ALL, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a final status of a conversation replay.', $status));
        }

        $this->status = $status;
        $this->finishedAt = $at;
        $this->errorMessage = $error;

        return $this;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(?string $language): self
    {
        $this->language = $language;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOverrides(): ?array
    {
        return $this->overrides;
    }

    /**
     * @param array<string, mixed>|null $overrides
     */
    public function setOverrides(?array $overrides): self
    {
        $this->overrides = [] === $overrides ? null : $overrides;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getSourceMessageIds(): array
    {
        return $this->sourceMessageIds;
    }

    /**
     * Freezes the source's user messages and sets `turnsTotal`.
     *
     * @param list<string> $sourceMessageIds in sequence order
     */
    public function setSourceMessageIds(array $sourceMessageIds): self
    {
        $this->sourceMessageIds = array_values($sourceMessageIds);
        $this->turnsTotal = \count($this->sourceMessageIds);

        return $this;
    }

    public function getJobId(): ?string
    {
        return $this->jobId;
    }

    public function setJobId(?string $jobId): self
    {
        $this->jobId = $jobId;

        return $this;
    }

    public function getCancelRequestedAt(): ?\DateTimeImmutable
    {
        return $this->cancelRequestedAt;
    }

    public function setCancelRequestedAt(?\DateTimeImmutable $cancelRequestedAt): self
    {
        $this->cancelRequestedAt = $cancelRequestedAt;

        return $this;
    }

    public function isCancelRequested(): bool
    {
        return null !== $this->cancelRequestedAt;
    }

    public function getTurnsReplayed(): int
    {
        return $this->turnsReplayed;
    }

    public function setTurnsReplayed(int $turnsReplayed): self
    {
        $this->turnsReplayed = $turnsReplayed;

        return $this;
    }

    public function getTurnsTotal(): int
    {
        return $this->turnsTotal;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
