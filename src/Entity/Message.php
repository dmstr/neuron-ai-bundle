<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:21:48 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\OpenApi\OpenApiTag;
use Dmstr\NeuronAiBundle\Security\ConversationSecurity;
use Dmstr\NeuronAiBundle\State\ConversationMessagesProvider;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * One message record of a {@see Conversation}: user input, an assistant
 * answer, a reasoning block, one tool call with its result, the note that
 * the turn's tool-call bound or the conversation's user-turn limit was
 * reached (kind `limit`), or the fixed answer or refusal of a pre-answer
 * rule of the agent version (kinds `rule_answer` and `rule_refusal`, no
 * model call). Records are written only by the turn; the API is read-only.
 *
 * - sequence: gap-free position within the conversation (unique per
 *   conversation); aborted and error records keep theirs;
 * - step: index of the provider response within the turn; groups the
 *   reasoning, text and parallel tool-call records of one response;
 * - role: `user` or `assistant`; kind: {@see MessageKind}; status:
 *   {@see MessageStatus};
 * - provider, model: the provider type and the model name of an assistant
 *   record (the name the provider was called with, serialized as
 *   `modelName`); providerModel: the model catalogue entry the record ran
 *   on ({@see ProviderModel}, serialized as its IRI in `model`; foreign
 *   key ON DELETE SET NULL, so the record keeps provider, name and
 *   connection id when the entry goes with its connection; null on user
 *   records, on records whose turn had no entry and on records stored
 *   before the model reference that the migration could not match);
 *   connectionId: the id of the provider connection it ran on (UUID, no
 *   foreign key: the record keeps it after the connection is gone; null
 *   on user records and on records stored before provider connections).
 *   connectionId is not serialized;
 * - usage: token usage of an assistant response (`input_tokens`,
 *   `output_tokens`, `cached_input_tokens`, `reasoning_tokens`);
 * - sources: the context chunks actually sent with the turn;
 * - tool*: name, call id, input, result, error flag of a tool call;
 * - closeReason: why the answer was closed early; `forced_bound` when the
 *   tool-call bound forced the step to answer, `user_turn_limit` for the
 *   forced answer of the last allowed user turn
 *   ({@see \Dmstr\NeuronAiBundle\Conversation\ForcedClose}),
 *   `terminal_tool` when a terminal tool answered
 *   ({@see \Dmstr\NeuronAiBundle\Conversation\TerminalClose}),
 *   `rule_answer` or `rule_refusal` on the record of a turn a pre-answer
 *   rule decided ({@see \Dmstr\NeuronAiBundle\Conversation\RuleClose}),
 *   null otherwise;
 * - responseSchema/structured: the schema of a schema turn and the parsed
 *   answer;
 * - frame: `{prefix, suffix}` of the agent version's `frame` template on the
 *   final record of a framed turn only (kind `text`, status `complete`,
 *   close reason not `terminal_tool`, including forced and forced-close
 *   fallback answers); `content` stays unframed, clients show
 *   `prefix + content + suffix`. A forced close answered by the version's
 *   `forcedCloseFallback` ends in a `complete` `text` record with
 *   `closeReason` `forced_bound` or `user_turn_limit` instead of an `error`
 *   record, and the content of a `limit` record is the version's
 *   `limitReason` or `turnLimitReason` template when it has one;
 * - followUps: suggested follow-up questions (list of strings) on the final
 *   record of a turn whose version has `followUps` on and that ended in a
 *   complete text answer; null when the follow-up gate did not
 *   run (off, or a rule, gate refusal, schema, terminal-tool, fallback,
 *   error or empty answer), `[]` when it failed or produced nothing. Never
 *   replayed into the history;
 * - providerPayload: what a replay needs (Neuron message serialization plus
 *   metadata: reasoning signatures, tool ids, grouping); only in the group
 *   `message:payload`, which is added on the item GET for holders of
 *   `neuronai:conversation:read_payload`, never in lists.
 *
 * Operations:
 *  - `GET /messages/{id}`: readers of the conversation;
 *  - `GET /messages?conversation=<iri>`: own messages, all with
 *    `neuronai:conversation:read_any` (query extension);
 *  - `GET /conversations/{conversationId}/messages`: the messages of one
 *    readable conversation, 404 otherwise ({@see ConversationMessagesProvider});
 *    any authenticated principal may ask, so the capability token of an
 *    anonymous conversation reads its own messages (the provider's
 *    `neuronai:conversation:read_own` check is the guard). The flat list stays
 *    `neuronai:conversation:list`, so token holders get 403 there.
 *
 * Messages are deleted with their conversation (FK ON DELETE CASCADE).
 */
#[ORM\Entity]
#[ORM\Table(name: 'neuron_message')]
#[ORM\UniqueConstraint(name: 'uniq_neuron_message_conversation_sequence', columns: ['conversation_id', 'sequence'])]
#[ORM\Index(name: 'idx_neuron_message_conversation', columns: ['conversation_id'])]
#[ORM\Index(name: 'idx_neuron_message_status', columns: ['status'])]
#[ORM\Index(name: 'idx_neuron_message_provider_model', columns: ['provider_model_id'])]
#[ApiResource(
    operations: [
        new Get(
            security: ConversationSecurity::READ_PARENT,
        ),
        new GetCollection(
            security: "is_granted('neuronai:conversation:list')",
        ),
        new GetCollection(
            uriTemplate: '/conversations/{conversationId}/messages',
            description: 'Lists the messages of one conversation in their order. Visitors of an anonymous conversation can read its messages with their access token.',
            uriVariables: [
                'conversationId' => new Link(fromClass: Conversation::class, toProperty: 'conversation'),
            ],
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            provider: ConversationMessagesProvider::class,
        ),
    ],
    description: <<<'MD'
        A message is one entry in the history of a conversation: a user question, an answer of the assistant, a reasoning step, a tool call with its result, or a note that a limit was reached. Answers decided by a pre-answer rule of the agent appear as their own kind, without a model call.

        Messages are written only while a conversation runs and cannot be changed here. Assistant messages show the model used, the token usage and the knowledge sources that were sent with the turn; the final answer carries suggested follow-up questions when the agent has them enabled.

        Related: every message belongs to a **Conversation**; **Feedback** can refer to a single message.
        MD,
    normalizationContext: ['groups' => [self::GROUP_READ]],
    order: ['sequence' => 'ASC'],
    paginationEnabled: true,
    paginationItemsPerPage: 50,
    openapi: new OpenApiOperation(tags: [OpenApiTag::CHAT]),
)]
#[ApiFilter(SearchFilter::class, properties: ['conversation' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['sequence'])]
class Message
{
    public const string GROUP_READ = 'message:read';
    public const string GROUP_PAYLOAD = 'message:payload';

    public const string SPEAKER_USER = 'user';
    public const string SPEAKER_ASSISTANT = 'assistant';

    public const int MAX_TOOL_NAME_LENGTH = 190;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[Groups([self::GROUP_READ])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(name: 'conversation_id', nullable: false, onDelete: 'CASCADE')]
    #[Groups([self::GROUP_READ])]
    private Conversation $conversation;

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups([self::GROUP_READ])]
    private int $sequence;

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups([self::GROUP_READ])]
    private int $step = 0;

    /** `user` or `assistant`. */
    #[ORM\Column(type: Types::STRING, length: 16)]
    #[Groups([self::GROUP_READ])]
    private string $role;

    /** One of {@see MessageKind::all()}. */
    #[ORM\Column(type: Types::STRING, length: 20)]
    #[Groups([self::GROUP_READ])]
    private string $kind;

    /** One of {@see MessageStatus::all()}. */
    #[ORM\Column(type: Types::STRING, length: 16)]
    #[Groups([self::GROUP_READ])]
    private string $status;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[ApiProperty(openapiContext: ['x-format' => 'textarea', 'x-grid' => ['columnsMd' => 12, 'newRow' => true]])]
    #[Groups([self::GROUP_READ])]
    private ?string $content = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    #[Groups([self::GROUP_READ])]
    private ?string $provider = null;

    /**
     * The model name the provider was called with, kept as a record when
     * the catalogue entry is gone. Serialized as `modelName`.
     */
    #[ORM\Column(type: Types::STRING, length: 190, nullable: true)]
    #[ApiProperty(
        description: 'The name of the model that wrote an assistant message, as the provider lists it (e.g. `llama3.2:latest` or `claude-haiku-4-5-20251001`). It stays when the catalogue entry `model` is gone. Null on user messages.',
        example: 'claude-haiku-4-5-20251001',
    )]
    #[SerializedName('modelName')]
    #[Groups([self::GROUP_READ])]
    private ?string $model = null;

    /**
     * The model catalogue entry the assistant record ran on; serialized as
     * its IRI in `model` ({@see getModelReference()}). Set to null by the
     * database when the entry is deleted (with its provider connection);
     * the record keeps {@see self::$model} and {@see self::$connectionId}.
     */
    #[ORM\ManyToOne(targetEntity: ProviderModel::class)]
    #[ORM\JoinColumn(name: 'provider_model_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[ApiProperty(readable: false, writable: false)]
    private ?ProviderModel $providerModel = null;

    /**
     * Id of the provider connection the assistant record ran on; a plain
     * UUID column without foreign key, never serialized.
     */
    #[ORM\Column(name: 'connection_id', type: 'uuid', nullable: true)]
    private ?Uuid $connectionId = null;

    /**
     * @var array<string, int|null>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups([self::GROUP_READ])]
    private ?array $usage = null;

    /**
     * @var list<array<string, mixed>>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups([self::GROUP_READ])]
    private ?array $sources = null;

    #[ORM\Column(type: Types::STRING, length: self::MAX_TOOL_NAME_LENGTH, nullable: true)]
    #[Groups([self::GROUP_READ])]
    private ?string $toolName = null;

    #[ORM\Column(type: Types::STRING, length: 190, nullable: true)]
    #[Groups([self::GROUP_READ])]
    private ?string $toolCallId = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups([self::GROUP_READ])]
    private ?array $toolInput = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[ApiProperty(openapiContext: ['x-format' => 'textarea', 'x-grid' => ['columnsMd' => 12, 'newRow' => true]])]
    #[Groups([self::GROUP_READ])]
    private ?string $toolResult = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    #[Groups([self::GROUP_READ])]
    private bool $toolError = false;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups([self::GROUP_READ])]
    private ?int $durationMs = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[ApiProperty(openapiContext: ['x-format' => 'textarea', 'x-grid' => ['columnsMd' => 12, 'newRow' => true]])]
    #[Groups([self::GROUP_READ])]
    private ?string $error = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    #[Groups([self::GROUP_READ])]
    private ?string $closeReason = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups([self::GROUP_READ])]
    private ?array $responseSchema = null;

    /**
     * Parsed answer of a schema turn (any JSON value).
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups([self::GROUP_READ])]
    private mixed $structured = null;

    /**
     * The rendered `frame` template of the agent version, split at its
     * `{{answer}}`, on the final record of a framed turn only (kind `text`,
     * status `complete`, close reason not `terminal_tool`); `content` stays
     * unframed. Clients show `prefix + content + suffix`.
     *
     * @var array{prefix: string, suffix: string}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups([self::GROUP_READ])]
    private ?array $frame = null;

    /**
     * Suggested follow-up questions of a complete text answer (at most 3,
     * each at most 200 characters); null when the follow-up gate did not
     * run, `[]` when it failed or produced nothing. User-facing, so in
     * `message:read` (lists and token holders too); the gate's failure
     * note stays in `providerPayload.gates.followUps`.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups([self::GROUP_READ])]
    private ?array $followUps = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups([self::GROUP_PAYLOAD])]
    private ?array $providerPayload = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[Groups([self::GROUP_READ])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function setConversation(Conversation $conversation): self
    {
        $this->conversation = $conversation;

        return $this;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function setSequence(int $sequence): self
    {
        $this->sequence = $sequence;

        return $this;
    }

    public function getStep(): int
    {
        return $this->step;
    }

    public function setStep(int $step): self
    {
        $this->step = $step;

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): self
    {
        $this->content = $content;

        return $this;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function setProvider(?string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    /**
     * The provider connection id as RFC 4122 string; null without one.
     */
    public function getConnectionId(): ?string
    {
        return $this->connectionId?->toRfc4122();
    }

    /**
     * @param string|null $connectionId a full UUID; null clears it
     *
     * @throws \InvalidArgumentException for a string that is no UUID
     */
    public function setConnectionId(?string $connectionId): self
    {
        $this->connectionId = null !== $connectionId ? Uuid::fromString($connectionId) : null;

        return $this;
    }

    /**
     * The model name; null on user records.
     */
    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): self
    {
        $this->model = $model;

        return $this;
    }

    /**
     * The model catalogue entry the record ran on; null without one.
     */
    public function getProviderModel(): ?ProviderModel
    {
        return $this->providerModel;
    }

    /**
     * Sets the catalogue entry only; provider, model name and connection
     * id are set on their own, because a record keeps them when the entry
     * is gone.
     */
    public function setProviderModel(?ProviderModel $providerModel): self
    {
        $this->providerModel = $providerModel;

        return $this;
    }

    /**
     * IRI of the catalogue entry, serialized as `model`.
     */
    #[ApiProperty(
        description: 'The model that wrote an assistant message: the IRI of its entry in the model catalogue (`/api/refs/provider_models/<uuid>`), which names the provider connection. Null on user messages and when the entry is gone (deleted with its provider connection) or was never known; `modelName` keeps the name.',
        writable: false,
        example: '/api/refs/provider_models/0199a1b2-0000-4000-8000-000000000000',
    )]
    #[SerializedName('model')]
    #[Groups([self::GROUP_READ])]
    public function getModelReference(): ?string
    {
        return $this->providerModel?->iri();
    }

    /**
     * @return array<string, int|null>|null
     */
    public function getUsage(): ?array
    {
        return $this->usage;
    }

    /**
     * @param array<string, int|null>|null $usage
     */
    public function setUsage(?array $usage): self
    {
        $this->usage = $usage;

        return $this;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function getSources(): ?array
    {
        return $this->sources;
    }

    /**
     * @param list<array<string, mixed>>|null $sources
     */
    public function setSources(?array $sources): self
    {
        $this->sources = $sources;

        return $this;
    }

    public function getToolName(): ?string
    {
        return $this->toolName;
    }

    public function setToolName(?string $toolName): self
    {
        $this->toolName = $toolName;

        return $this;
    }

    public function getToolCallId(): ?string
    {
        return $this->toolCallId;
    }

    public function setToolCallId(?string $toolCallId): self
    {
        $this->toolCallId = $toolCallId;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getToolInput(): ?array
    {
        return $this->toolInput;
    }

    /**
     * @param array<string, mixed>|null $toolInput
     */
    public function setToolInput(?array $toolInput): self
    {
        $this->toolInput = $toolInput;

        return $this;
    }

    public function getToolResult(): ?string
    {
        return $this->toolResult;
    }

    public function setToolResult(?string $toolResult): self
    {
        $this->toolResult = $toolResult;

        return $this;
    }

    public function isToolError(): bool
    {
        return $this->toolError;
    }

    public function setToolError(bool $toolError): self
    {
        $this->toolError = $toolError;

        return $this;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function setDurationMs(?int $durationMs): self
    {
        $this->durationMs = $durationMs;

        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): self
    {
        $this->error = $error;

        return $this;
    }

    public function getCloseReason(): ?string
    {
        return $this->closeReason;
    }

    public function setCloseReason(?string $closeReason): self
    {
        $this->closeReason = $closeReason;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResponseSchema(): ?array
    {
        return $this->responseSchema;
    }

    /**
     * @param array<string, mixed>|null $responseSchema
     */
    public function setResponseSchema(?array $responseSchema): self
    {
        $this->responseSchema = $responseSchema;

        return $this;
    }

    public function getStructured(): mixed
    {
        return $this->structured;
    }

    public function setStructured(mixed $structured): self
    {
        $this->structured = $structured;

        return $this;
    }

    /**
     * @return array{prefix: string, suffix: string}|null
     */
    public function getFrame(): ?array
    {
        return $this->frame;
    }

    /**
     * @param array{prefix: string, suffix: string}|null $frame
     */
    public function setFrame(?array $frame): self
    {
        $this->frame = $frame;

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getFollowUps(): ?array
    {
        return $this->followUps;
    }

    /**
     * @param list<string>|null $followUps
     */
    public function setFollowUps(?array $followUps): self
    {
        $this->followUps = $followUps;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getProviderPayload(): ?array
    {
        return $this->providerPayload;
    }

    /**
     * @param array<string, mixed>|null $providerPayload
     */
    public function setProviderPayload(?array $providerPayload): self
    {
        $this->providerPayload = $providerPayload;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
