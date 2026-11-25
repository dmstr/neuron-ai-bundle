<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:21:48 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousConversationResult;
use Dmstr\NeuronAiBundle\Conversation\ConversationStatus;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\OpenApi\OpenApiTag;
use Dmstr\NeuronAiBundle\Provider\ProviderType;
use Dmstr\NeuronAiBundle\Security\ConversationSecurity;
use Dmstr\NeuronAiBundle\State\AnonymousConversationCreateProcessor;
use Dmstr\NeuronAiBundle\State\ConversationCancelProcessor;
use Dmstr\NeuronAiBundle\State\ConversationCreateProcessor;
use Dmstr\NeuronAiBundle\State\ConversationReplayProcessor;
use Dmstr\NeuronAiBundle\State\ConversationRunningProvider;
use Dmstr\NeuronAiBundle\State\ConversationTurnProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A persisted chat conversation of one owner (the Keycloak subject, i.e. the
 * user identifier of the token). Its configuration is fixed at creation:
 * the model (`providerModel`, an entry of the model catalogue
 * {@see ProviderModel}, given as the reference `model` and checked live
 * against its connection at creation) and with it the provider connection
 * (`providerConnection`, an active `ollama` or `anthropic` connection, the
 * entry's connection) and the model name (`modelName`, the entry's name, the
 * model the provider is called with), the
 * knowledge collections and MCP connections (stored as UUID lists,
 * validated at creation and at each turn), the retrieval depth and the
 * maximum number of user turns (`maxUserTurns`, null = unlimited).
 * `provider` is read-only: the type of the connection, kept as a column
 * for the representation and run comparisons. A conversation whose
 * connection was deactivated is stale: its next turn answers 422.
 * Conversations stored before provider connections (no
 * `providerConnection`) cannot get a catalogue entry; the migration of the
 * model reference refuses to run until they are deleted.
 *
 * A conversation created with an agent (write-only `agent`, an "agent
 * conversation") pins the agent's current published version
 * (`agentVersion`) and takes everything from it: provider connection,
 * model, collections, retrieval depth and the MCP connections of its tools are
 * copied into the columns for the representation, `maxUserTurns` is
 * resolved from it, and every turn reads instructions, response schema,
 * tools and limits from the pinned version again. The caller may only add
 * `title`, `maxUserTurns` (lower only), `language` (validated against the
 * version's languages, default its `defaultLanguage`) and `variables` (the
 * start parameters, validated against the version's `startSchema` at
 * creation and substituted into the instructions at every turn; item GET
 * only, never in lists or the turn response, never audited).
 *
 * Every message of the conversation is a {@see Message} record with a
 * gap-free `sequence`; `nextSequence` is the counter the turn reads under a
 * pessimistic lock and increments in the same transaction as each insert.
 * It is never exposed.
 *
 * A running turn is not stored: the item GET exposes the read-only
 * `running` flag, derived from the conversation's advisory lock by
 * {@see ConversationRunningProvider}.
 *
 * Anonymous conversations (`anonymous` true) belong to no account: the
 * owner is `anon:<id>`, which is also the user identifier of the principal
 * a capability token authenticates as, so the ownership rules bind a token
 * to exactly its conversation. Only the SHA-256 hash of the token is stored
 * (`tokenHash`, never serialized) and the keyed hash of the creating client
 * address (`addressHash`, group `conversation:admin` only). They are
 * created with the configuration the operator released, count their turns
 * (`turnCount`) and tokens (`tokensUsed`, input plus output) and are closed
 * (`status` `closed`, `closedAt`, `closeReason`) when a limit or the
 * retention is reached.
 *
 * Every conversation counts its accepted user turns (`turnCount`). With
 * `maxUserTurns` the last allowed turn is answered under a forced close and
 * closes the conversation (`closeReason` `user_turn_limit`); every further
 * turn answers 422 with that code.
 *
 * Operations (permission attributes from {@see \Dmstr\NeuronAiBundle\Security\Permissions}):
 *  - `GET /conversations`: own conversations, all with `neuronai:conversation:read_any`
 *    (restricted by a query extension, not by the security expression);
 *    filter `anonymous=true|false`;
 *  - `POST /conversations`: owner, defaults and references are filled and
 *    validated by {@see ConversationCreateProcessor};
 *  - `POST /conversations/anonymous` (`conversation_anonymous`): public
 *    creation of an anonymous conversation, returns it with its capability
 *    token ({@see AnonymousConversationCreateProcessor}). Like the stateless
 *    chat, this is a deliberate exception to the "collection action → Job"
 *    guard rail: it is a short synchronous insert whose answer (the token)
 *    the caller needs immediately;
 *  - `GET /conversations/{id}`, `DELETE /conversations/{id}`;
 *  - `POST /conversations/{id}/messages` (`conversation_turn`): appends a
 *    user message, runs one turn and returns the final assistant message;
 *    the body is validated against `Conversation/turn.input.json`;
 *  - `POST /conversations/{id}/cancel` (`conversation_cancel`): asks the
 *    running turn to stop by setting `cancelRequestedAt` (never exposed),
 *    a no-op without a running turn; answers with `running`
 *    ({@see ConversationCancelProcessor});
 *  - `POST /conversations/{id}/replay` (`conversation_replay`,
 *    `neuronai:eval:write`): replays the conversation's user messages on an
 *    agent version as the turns of a new replay conversation and answers
 *    202 with the {@see ConversationReplay} record
 *    ({@see ConversationReplayProcessor}); the body is validated against
 *    `Conversation/replay.input.json`.
 *
 * Evaluation conversations (`origin` = `eval`) are created by an
 * {@see EvalRun} on the run's agent version and owned by whoever started
 * the run. They are hidden from the ordinary lists unless the request
 * filters on `origin` ({@see \Dmstr\NeuronAiBundle\Doctrine\OwnedConversationExtension}),
 * and holders of `neuronai:conversation:read_eval` read them regardless of the owner
 * ({@see \Dmstr\NeuronAiBundle\Security\ConversationVoter}). `origin` is set
 * by the bundle only, never through the API.
 *
 * Replay conversations (`origin` = `replay`) are created by a
 * {@see ConversationReplay} and owned by whoever triggered it; they are
 * hidden and readable exactly like evaluation conversations
 * ({@see self::EVALUATION_ORIGINS}). While the replay is pending or
 * running, manual turns are refused
 * ({@see \Dmstr\NeuronAiBundle\Replay\ConversationReplayGuard}).
 *
 * Creation and removal are audited (Gedmo loggable), and so are changes of
 * `status`, `closedAt` and `closeReason` (a close is an `update` entry);
 * messages are removed by the database cascade.
 */
#[ORM\Entity]
#[ORM\Table(name: 'neuron_conversation')]
#[ORM\Index(name: 'idx_neuron_conversation_owner_subject', columns: ['owner_subject'])]
#[ORM\Index(name: 'idx_neuron_conversation_status', columns: ['status'])]
#[ORM\Index(name: 'idx_neuron_conversation_anonymous_closed_at', columns: ['anonymous', 'closed_at'])]
#[ORM\Index(name: 'idx_neuron_conversation_origin', columns: ['origin'])]
#[ORM\UniqueConstraint(name: 'uniq_neuron_conversation_token_hash', columns: ['token_hash'])]
#[ORM\HasLifecycleCallbacks]
#[Gedmo\Loggable]
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('neuronai:conversation:list')",
        ),
        new Post(
            status: 201,
            security: "is_granted('neuronai:conversation:create')",
            processor: ConversationCreateProcessor::class,
        ),
        new Post(
            uriTemplate: '/conversations/anonymous',
            status: 201,
            description: 'Creates a conversation for a visitor without an account, with the setup the operator released for anonymous use: a fixed configuration or the current version of a released agent. The answer contains an access token that is shown only once and gives access to exactly this conversation. Anonymous conversations are limited in turns, tokens and lifetime and are closed when a limit is reached.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::CHAT],
                summary: 'Creates an anonymous conversation (public).',
                description: 'Public endpoint without credentials. Creates a conversation with the provider connection, model, collections and MCP connections the operator released for anonymous visitors, or, when the operator released an agent, with the agent\'s current published version (instructions, provider connection, model, collections, MCP tools including terminal tools, limits, pre-answer rules and their templates, the output templates `frame`, `limitReason`, `turnLimitReason` and `forcedCloseFallback`; default language, no start parameters), which the conversation is pinned to; the body must be `{}`, any configuration in it is rejected with 422. Returns the conversation and its capability token `anon_…`, which is shown only in this response: send it as `Authorization: Bearer <token>` to read the conversation and its messages, run turns and delete it. Limits: creations per client address and hour (429 with `Retry-After`, 403 when anonymous creation is disabled), turns and tokens per conversation and a retention period, after which the conversation is closed (turns answer 422 with a `code`). 503 while the release does not resolve, also for a released agent that is unknown, disabled, unpublished or refers to something deleted.',
            ),
            normalizationContext: ['groups' => [AnonymousConversationResult::GROUP_READ, self::GROUP_READ]],
            input: false,
            output: AnonymousConversationResult::class,
            read: false,
            deserialize: false,
            validate: false,
            name: self::OPERATION_ANONYMOUS,
            processor: AnonymousConversationCreateProcessor::class,
        ),
        new Get(
            normalizationContext: ['groups' => [self::GROUP_READ, self::GROUP_ITEM]],
            security: ConversationSecurity::READ,
            provider: ConversationRunningProvider::class,
        ),
        new Delete(
            security: ConversationSecurity::DELETE,
        ),
        new Post(
            uriTemplate: '/conversations/{id}/messages',
            status: 201,
            description: 'Sends a user message and runs one turn: the model answers with the conversation\'s history, knowledge collections and tools, and the final answer is returned. Every message of the turn, including tool calls, is stored and listed under **Message**. A closed conversation, or one that has reached its limits, accepts no further turns.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::CHAT],
                summary: 'Runs one turn of the conversation.',
                description: 'Appends the user message `content`, runs the conversation\'s provider connection and model with the history of its complete turns, the retrieved context of its collections and the tools of its MCP connections, and returns the final assistant message (kind `text`, or `schema` with `responseSchema`). Every message of the turn, including reasoning and tool calls, is stored as its own record. A failed provider call or a passed deadline still answers 201 with `status: "error"`. 409 while another turn of the conversation runs; 422 for an invalid body, a collection or MCP connection that no longer exists, or a provider connection that is inactive or missing (a conversation stored before provider connections); 422 with a `code` (`conversation_closed`, `turn_limit`, `token_limit`, `retention_expired`, `user_turn_limit`) when the conversation accepts no further turn. Anonymous conversations reject `responseSchema` (422) and are closed when they reach their turn or token limit. The last allowed user turn of a conversation with `maxUserTurns` is answered under a forced close from its first inference (a `limit` record, then the answer with `closeReason` `user_turn_limit`, or `terminal_tool` when a terminal tool answered) and closes the conversation; every further turn answers 422 with code `user_turn_limit`. With `maxToolCalls` (at least 1; omitted: the configured default, unbounded by default) the model may make that many tool calls; then a `limit` record is stored and the model is forced to answer, and that answer carries `closeReason` `forced_bound`. A conversation created with an agent runs every turn on its pinned agent version, with the stored `variables` and `language`: instructions, provider connection, model, response schema, collections, retrieval depth, the version\'s MCP tools and terminal tools, sampling and the tool-call bound come from the version, and later publishes, rollbacks or disabling the agent do not change it; `maxToolCalls` can only lower the bound of the agent version or the settings default, `responseSchema` and `terminalTools` answer 422 (fixed by the agent version), and a reference of the version that no longer resolves answers 422 with its path. The version\'s pre-answer rules are evaluated on `content` before history, retrieval and any provider call: a rule with outcome `answer` or `refuse` stores the user message and returns one message of kind `rule_answer` or `rule_refusal` (the rendered template text, `closeReason` equal to the kind, `sources` `[]`, no usage) without a model call; the turn counts with no tokens, also as the last allowed turn (then without a `limit` record), and a refusal is never replayed to the model. The version\'s output templates apply: with a `frame` template the final message of kind `text` and status `complete` (not `terminal_tool`; normal, forced and fallback answers) carries `frame` `{prefix, suffix}` while its `content` stays unframed; `limitReason` and `turnLimitReason` replace the English text of the `limit` record; with `forcedCloseFallback` a forced close that fails or answers with an empty text returns a `complete` `text` message with the fallback (`closeReason` `forced_bound` or `user_turn_limit`, no `error` record), which is not replayed to the model. With `terminalTools` (names of offered MCP tools) a call of one of them ends the turn: the tool runs, no further model call follows, and its result becomes the answer (`structured` when it is JSON or matches `responseSchema`) with `closeReason` `terminal_tool`; at the tool-call bound the first of them is forced where the provider supports it. A terminal tool name that is not an offered tool, a duplicate or an empty name is rejected with 422 before the provider is called.',
            ),
            normalizationContext: ['groups' => [Message::GROUP_READ]],
            security: ConversationSecurity::TURN,
            input: false,
            output: Message::class,
            read: true,
            deserialize: false,
            validate: false,
            name: self::OPERATION_TURN,
            processor: ConversationTurnProcessor::class,
            throwOnNotFound: true,
        ),
        new Post(
            uriTemplate: '/conversations/{id}/replay',
            status: 202,
            description: 'Replays the user messages of this conversation on a chosen agent version, as the turns of a new conversation, for example to compare the answers of another version. The replay runs in the background; its progress is shown in **ConversationReplay** and **Job**. Tools run live again, so MCP tools that change data act a second time.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::CHAT],
                responses: [
                    '202' => new OpenApiResponse(description: 'The replay conversation was created and the replay job queued; the body is the replay record (as `GET /conversations/{id}/replay_run` of the replay conversation) with status `pending` and `jobId`.'),
                ],
                summary: 'Replays the conversation on an agent version (asynchronous).',
                description: 'Replays the user messages of this conversation (the source; any owner, also anonymous and evaluation conversations, and conversations created without an agent), in their sequence order and including those of aborted, failed and rule-refused turns, as the turns of a new conversation pinned to `targetVersion` (IRI `/api/agent_versions/<uuid>` or full UUID; any published version of an enabled agent, also an older one). Not to be confused with the history replay of a turn. The replay conversation is created by this request: owned by the caller, `origin` `replay` (hidden from the lists unless `?origin=replay`, readable by holders of `neuronai:conversation:read_eval`), with `variables` (replacing the source\'s as a whole; omitted: the source\'s) and `language` (omitted: the source\'s when the target supports it, else the target\'s default) validated against the target version. A job of type `conversation_replay` then runs one turn per source message with the target version\'s instructions, rules, templates, limits and tools; tools run live, so write-capable MCP tools act again. The replay ends `completed`, `partial` when the replay conversation closes first (e.g. the target version\'s user-turn limit), `cancelled` or `failed`; manual turns on the replay conversation answer 422 until then. Rejected with 422, before anything is stored, for a source without user messages, an unknown version, a disabled agent, invalid variables (`variables/<name>`, also for source variables the target does not declare), an unsupported language or a stale reference of the version; 400 for malformed JSON, 422 for a body that does not match the schema; 403 without `neuronai:eval:write`.',
            ),
            normalizationContext: ['groups' => [ConversationReplay::GROUP_READ]],
            security: "is_granted('neuronai:eval:write')",
            input: false,
            output: ConversationReplay::class,
            read: true,
            deserialize: false,
            validate: false,
            name: self::OPERATION_REPLAY,
            processor: ConversationReplayProcessor::class,
            throwOnNotFound: true,
        ),
        new Post(
            uriTemplate: '/conversations/{id}/cancel',
            status: 200,
            description: 'Asks the turn that is currently running to stop; it ends with the partial answer kept. Without a running turn, nothing changes.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::CHAT],
                summary: 'Cancels the running turn of the conversation.',
                description: 'Asks the running (streaming) turn to stop: it ends at its next check with an `aborted` final message that keeps the partial text, and the stream ends with `done` and `status: "aborted"`. Without a running turn the request changes nothing. No body; the response is the conversation with `running`.',
            ),
            normalizationContext: ['groups' => [self::GROUP_READ, self::GROUP_ITEM]],
            security: ConversationSecurity::TURN,
            input: false,
            read: true,
            deserialize: false,
            validate: false,
            name: self::OPERATION_CANCEL,
            processor: ConversationCancelProcessor::class,
            throwOnNotFound: true,
        ),
    ],
    description: <<<'MD'
        A conversation is a stored chat with history: each user message is answered with the conversation's model, its knowledge collections and MCP tools, and every message is kept. Its setup is fixed when it is created, either directly (a model from the model catalogue, collections, tools) or by choosing an agent. An agent conversation stays on the agent version that was current at that time, so later publishes or rollbacks do not change it.

        A conversation can be limited in the number of user turns and is closed when a limit is reached. Anonymous conversations belong to no account and are accessed with a token instead. Conversations created by evaluation runs or replays are hidden from the normal list unless you filter by their origin.

        Related: the messages are listed under **Message**, ratings and comments under **Feedback**. A conversation runs on an **AgentVersion** of an **Agent**, or directly on a model from **ProviderModel** (of a provider connection in **ApiConfiguration**) with **Collection**s as knowledge. Replays of a conversation are tracked under **ConversationReplay**.
        MD,
    normalizationContext: ['groups' => [self::GROUP_READ]],
    denormalizationContext: ['groups' => [self::GROUP_WRITE]],
    order: ['createdAt' => 'DESC'],
    paginationEnabled: true,
    paginationItemsPerPage: 30,
    openapi: new OpenApiOperation(tags: [OpenApiTag::CHAT]),
)]
#[ApiFilter(SearchFilter::class, properties: ['ownerSubject' => 'exact', 'status' => 'exact', 'origin' => 'exact', 'agentVersion' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['anonymous'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'])]
class Conversation
{
    public const string OPERATION_TURN = 'conversation_turn';
    public const string OPERATION_CANCEL = 'conversation_cancel';
    public const string OPERATION_ANONYMOUS = 'conversation_anonymous';
    /** `POST /conversations/{id}/replay`; its body schema is `Conversation/replay.input.json`. */
    public const string OPERATION_REPLAY = 'conversation_replay';

    public const string GROUP_READ = 'conversation:read';
    public const string GROUP_WRITE = 'conversation:write';
    public const string GROUP_ITEM = 'conversation:item';
    /** Added for holders of `neuronai:conversation:read_any` (address hash). */
    public const string GROUP_ADMIN = 'conversation:admin';

    /** Owner prefix of anonymous conversations: `anon:<id>`. */
    public const string ANONYMOUS_OWNER_PREFIX = 'anon:';

    public const string CLOSE_REASON_TURN_LIMIT = 'turn_limit';
    public const string CLOSE_REASON_TOKEN_LIMIT = 'token_limit';
    public const string CLOSE_REASON_RETENTION = 'retention';
    /**
     * Closed because its last allowed user turn ran, or the turn gate found
     * `turnCount >= maxUserTurns`.
     */
    public const string CLOSE_REASON_USER_TURN_LIMIT = 'user_turn_limit';

    /** `origin` of a conversation created by an evaluation run. */
    public const string ORIGIN_EVAL = 'eval';

    /** `origin` of a conversation created by a conversation replay. */
    public const string ORIGIN_REPLAY = 'replay';

    /**
     * Origins `neuronai:conversation:read_eval` covers: holders read these
     * conversations of every owner and list them with `?origin=<origin>`.
     */
    public const array EVALUATION_ORIGINS = [self::ORIGIN_EVAL, self::ORIGIN_REPLAY];

    public const int MAX_TITLE_LENGTH = 190;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[Groups([self::GROUP_READ])]
    private Uuid $id;

    /** User identifier (Keycloak subject) of the owner; set by the create processor. */
    #[ORM\Column(type: Types::STRING, length: 190)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?string $ownerSubject = null;

    /** Set from the first user message on the first turn when empty. */
    #[ORM\Column(type: Types::STRING, length: self::MAX_TITLE_LENGTH, nullable: true)]
    #[Assert\Length(max: self::MAX_TITLE_LENGTH)]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private ?string $title = null;

    /** One of {@see ConversationStatus::all()}. */
    #[ORM\Column(type: Types::STRING, length: 20)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    #[Gedmo\Versioned]
    private string $status = ConversationStatus::ACTIVE;

    /**
     * Chat provider type (`anthropic`, `ollama`): derived from the provider
     * connection ({@see ProviderType::of()}) by {@see setProviderConnection()},
     * never written through the API.
     */
    #[ORM\Column(type: Types::STRING, length: 20)]
    #[ApiProperty(
        description: 'Chat provider type (`ollama`, `anthropic`), derived from the provider connection. Read-only.',
        writable: false,
    )]
    #[Groups([self::GROUP_READ])]
    private ?string $provider = null;

    /**
     * The model name, passed to the provider connection as is: the name of
     * {@see self::$providerModel} (copied at creation, also from an agent
     * version), kept as a column for the turns, the representation and run
     * comparisons. Read-only, serialized as `modelName`.
     */
    #[ORM\Column(type: Types::STRING, length: 190)]
    #[Assert\Length(max: 190)]
    #[ApiProperty(
        description: 'The name of the model the turns run on, as the provider lists it (e.g. `llama3.2:latest` or `claude-haiku-4-5-20251001`): the name of the catalogue entry `model`. Read-only.',
        writable: false,
    )]
    #[SerializedName('modelName')]
    #[Groups([self::GROUP_READ])]
    private ?string $model = null;

    /**
     * The model catalogue entry the turns run on; it names the provider
     * connection ({@see self::$providerConnection}) and the model name
     * ({@see self::$model}). Set at creation from `model` (or from the
     * agent version); every stored conversation has one (column NOT NULL:
     * the migration gave every conversation an entry, a placeholder where
     * the catalogue had none, and refuses to run while a conversation
     * cannot get one). The foreign key has no cascade: deleting the
     * connection, which would delete its entries, is refused (409) by
     * {@see \Dmstr\NeuronAiBundle\Doctrine\ProviderConnectionDeleteGuard};
     * the key is the backstop. Serialized as its IRI in `model`. Not
     * audited.
     */
    #[ORM\ManyToOne(targetEntity: ProviderModel::class)]
    #[ORM\JoinColumn(name: 'provider_model_id', referencedColumnName: 'id', nullable: false)]
    #[ApiProperty(readable: false, writable: false)]
    private ?ProviderModel $providerModel = null;

    /**
     * The model as a string reference (not persisted), serialized as
     * `model`. On input: IRI `/api/refs/provider_models/<uuid>` or full or
     * partial UUID of a catalogue entry, resolved by
     * {@see ConversationCreateProcessor}. On output: the IRI of
     * {@see self::$providerModel}.
     */
    #[ApiProperty(
        description: 'The model the turns run on: an entry of the model catalogue (`/api/refs/provider_models`), which names the provider connection. On input: its IRI (`/api/refs/provider_models/<uuid>`) or its full or partial UUID; required without `agent`. 422 at `model` when it is missing, unknown, ambiguous, no longer available, of an inactive connection, known to lack the capability `completion`, not offered by the connection according to the live check, or cannot be verified because the provider is unreachable. A model name is no reference. On output: the IRI of the entry. With `agent` it comes from the agent version and answers 422 when given.',
        example: '/api/refs/provider_models/0199a1b2-0000-4000-8000-000000000000',
        openapiContext: ProviderModel::CHAT_MODEL_PICKER,
    )]
    #[SerializedName('model')]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private ?string $modelReference = null;

    /**
     * The provider connection (`ollama` or `anthropic`) the turns run on:
     * the connection of {@see self::$providerModel}, set with it; null only
     * for a new conversation before the entry was set (the column stays
     * nullable from before provider connections, the entry's NOT NULL
     * column makes it set for every stored conversation). The foreign key has no cascade: deleting a
     * referenced connection is refused (409) by
     * {@see \Dmstr\NeuronAiBundle\Doctrine\ProviderConnectionDeleteGuard},
     * the key is the backstop. Never serialized as such (the vendor entity
     * is readable by administrators only); the representation shows the
     * connection's name as `connection`. Not audited.
     */
    #[ORM\ManyToOne(targetEntity: ApiConfiguration::class)]
    #[ORM\JoinColumn(name: 'provider_connection_id', referencedColumnName: 'id', nullable: true)]
    #[ApiProperty(readable: false, writable: false)]
    private ?ApiConfiguration $providerConnection = null;

    /**
     * Knowledge collection ids (UUID strings). On input any reference the
     * create processor resolves; stored as full UUIDs.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\All([new Assert\Type('string'), new Assert\NotBlank()])]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private array $collections = [];

    /**
     * `mcp` connection ids (UUID strings). On input any reference
     * McpConnectionResolver accepts (IRI, full or partial UUID, name);
     * stored as full UUIDs.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\All([new Assert\Type('string'), new Assert\NotBlank()])]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private array $mcpConnections = [];

    /** Chunks retrieved per turn; null on input means `store.top_k`. */
    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\Positive]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private ?int $retrievalDepth = null;

    /**
     * The agent version the conversation is held with; serialized as IRI.
     * Set at creation from the agent given as `agent` (its current published
     * version); later publishes or rollbacks of the agent do not change it.
     * The column keeps its name `agent_version`. Not audited (a Gedmo
     * `Versioned` on this association breaks the audit log).
     */
    #[ORM\ManyToOne(targetEntity: AgentVersion::class)]
    #[ORM\JoinColumn(name: 'agent_version', referencedColumnName: 'id', nullable: true)]
    #[ApiProperty(
        description: 'The agent version the conversation is held with: set at creation from the agent given as `agent` (its current published version); later publishes or rollbacks of the agent do not change it.',
        writable: false,
    )]
    #[Groups([self::GROUP_READ])]
    private ?AgentVersion $agentVersion = null;

    /**
     * Write-only reference to the agent the conversation is created with
     * (not persisted): IRI `/api/agents/<uuid>`, full UUID or slug, resolved
     * in this order by {@see ConversationCreateProcessor}; partial UUIDs are
     * not accepted. The conversation pins the agent's current published
     * version (`agentVersion`) and takes provider connection, model,
     * collections, retrieval depth, hybrid retrieval, MCP tools, instructions, response
     * schema and limits from it; the body keys the version fixes answer 422,
     * unknown, disabled or unpublished agents too.
     */
    #[ApiProperty(
        description: 'Write-only reference to the agent the conversation is created with: IRI `/api/agents/<uuid>`, full UUID or slug (resolved in this order; partial UUIDs are not accepted). The conversation pins the agent\'s current published version (`agentVersion`) and takes provider connection, model, collections, retrieval depth, hybrid retrieval (`hybrid`, `hybridWeight`), MCP tools, instructions, response schema, `maxUserTurns` and the tool-call bound from it. With `agent` only `title`, `maxUserTurns` (lower only), `language` and `variables` may be given; `instructions`, `connection`, `provider`, `model`, `collections`, `mcpConnections`, `retrievalDepth`, `hybrid`, `hybridWeight`, `responseSchema`, `terminalTools`, `rules` and `templates` answer 422, and so do unknown, disabled or unpublished agents.',
        readable: false,
    )]
    #[Groups([self::GROUP_WRITE])]
    private ?string $agent = null;

    /**
     * The maximum number of user turns the conversation accepts; null =
     * unlimited. Fixed at creation (there is no PATCH). The last allowed
     * turn is answered under a forced close and closes the conversation
     * with reason `user_turn_limit`. For an agent conversation the request
     * value may only lower the limit of the version or the settings default
     * (clamped silently; the representation shows the effective limit).
     */
    #[ORM\Column(name: 'max_user_turns', type: Types::INTEGER, nullable: true)]
    #[Assert\Positive]
    #[ApiProperty(description: 'The maximum number of user turns the conversation accepts; null = unlimited. Fixed at creation. The last allowed turn is answered under a forced close and closes the conversation with `closeReason` `user_turn_limit`. With `agent`, the value may only lower the limit of the agent version or the settings default (clamped silently; the representation shows the effective limit).')]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private ?int $maxUserTurns = null;

    /**
     * Language tag, e.g. `de` or `en-GB`. With `agent`: validated against
     * the version's `languages` (422 otherwise); omitted means the version's
     * `defaultLanguage`, and the effective tag is stored. Without an agent
     * it is stored as given and has no effect.
     */
    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    #[Assert\Length(max: 16)]
    #[ApiProperty(description: 'Language tag, e.g. `de` or `en-GB`. With `agent`: must be one of the agent version\'s `languages` (422 otherwise); omitted means the version\'s `defaultLanguage`, and the effective tag is stored. The turns use the instructions of this language. Without an agent it is stored as given and has no effect.')]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private ?string $language = null;

    /**
     * Start parameters of an agent conversation (name => scalar value),
     * only with `agent`: validated against the version's `startSchema` at
     * creation and substituted into the instructions at every turn; `[]`
     * when none were given, null without an agent. Shown on the item GET
     * only (owner and `neuronai:conversation:read_any` holders, like message
     * content), never in lists or the turn response. Personal data, so not
     * `Gedmo\Versioned` (never audited).
     *
     * The type stays loose (`array-key`, `mixed`) so the serializer accepts
     * any JSON object or list and the create processor answers a wrong
     * shape with 422 instead of a type error (400).
     *
     * @var array<array-key, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[ApiProperty(description: 'Start parameters of an agent conversation, a JSON object of scalar values; only together with `agent` (422 otherwise). Validated against the agent version\'s `startSchema` at creation (422 for an undeclared, missing or non-scalar variable, a reserved name or a placeholder of the instructions left unfilled) and substituted into the instructions at every turn. Readable on the item GET by the owner and by holders of `neuronai:conversation:read_any`, like message content; never in lists or the turn response, never in the audit log.')]
    #[Groups([self::GROUP_ITEM, self::GROUP_WRITE])]
    private ?array $variables = null;

    /**
     * Where the conversation comes from when it was not created by a user
     * through the API: `eval` ({@see self::ORIGIN_EVAL}) for a conversation
     * of an evaluation run, `replay` ({@see self::ORIGIN_REPLAY}) for the
     * conversation of a conversation replay; null for every ordinary
     * conversation. Set by the bundle only, never writable through the API.
     * Lists hide non-null origins unless the request filters on `origin`.
     */
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    #[ApiProperty(
        description: 'Where the conversation comes from: `eval` for a conversation of an evaluation run, `replay` for the conversation a conversation replay created (`POST /conversations/{id}/replay`; its record is `GET /conversations/{id}/replay_run`), null for an ordinary conversation. Read-only. Lists hide conversations with an origin unless the request filters on it (`?origin=eval`, `?origin=replay`).',
        writable: false,
    )]
    #[Groups([self::GROUP_READ])]
    private ?string $origin = null;

    /** Created through the public anonymous endpoint, owned by `anon:<id>`. */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private bool $anonymous = false;

    /**
     * SHA-256 hex of the capability token of an anonymous conversation;
     * never exposed.
     */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    #[ApiProperty(readable: false, writable: false)]
    private ?string $tokenHash = null;

    /**
     * Keyed hash (HMAC-SHA256) of the client address that created an
     * anonymous conversation; only for holders of `neuronai:conversation:read_any`.
     */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_ADMIN])]
    private ?string $addressHash = null;

    /**
     * Accepted user turns (one per user message), including turns that
     * ended `error` or `aborted`; compared with `maxUserTurns`.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private int $turnCount = 0;

    /** Tokens used by all turns: input plus output tokens of every provider response. */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private int $tokensUsed = 0;

    /** When the conversation was closed; null while it is not closed. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    #[Gedmo\Versioned]
    private ?\DateTimeImmutable $closedAt = null;

    /** Why it was closed: `turn_limit`, `token_limit`, `retention` or `user_turn_limit`. */
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    #[Gedmo\Versioned]
    private ?string $closeReason = null;

    /** Sequence the next message gets; never exposed. */
    #[ORM\Column(type: Types::INTEGER)]
    #[ApiProperty(readable: false, writable: false)]
    private int $nextSequence = 1;

    /**
     * Set by the cancel operation while a turn runs; polled by the
     * streaming turn and cleared when the next turn takes the lock. Never
     * exposed.
     */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    #[ApiProperty(readable: false, writable: false)]
    private ?\DateTimeImmutable $cancelRequestedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[Groups([self::GROUP_READ])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[Groups([self::GROUP_READ])]
    private \DateTimeImmutable $updatedAt;

    /**
     * Whether a turn currently runs (not persisted); set on the item GET only
     * by {@see ConversationRunningProvider}, null elsewhere.
     */
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_ITEM])]
    private ?bool $running = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
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

    /**
     * Null only for a new conversation before the create processor set it.
     */
    public function getOwnerSubject(): ?string
    {
        return $this->ownerSubject;
    }

    public function setOwnerSubject(string $ownerSubject): self
    {
        $this->ownerSubject = $ownerSubject;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

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

    /**
     * The turn gate: only an active conversation accepts turns (archived
     * and closed ones are read-only).
     */
    public function acceptsTurns(): bool
    {
        return ConversationStatus::ACTIVE === $this->status;
    }

    /**
     * The provider type; null only for a new conversation before the create
     * processor set the connection.
     */
    public function getProvider(): ?string
    {
        return $this->provider;
    }

    /**
     * Sets the stored provider type directly. Prefer
     * {@see setProviderConnection()}, which derives it; kept for fixtures of
     * conversations stored before provider connections.
     */
    public function setProvider(?string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    /**
     * The provider connection the turns run on; null only for a new
     * conversation before the create processor set it.
     */
    public function getProviderConnection(): ?ApiConfiguration
    {
        return $this->providerConnection;
    }

    /**
     * Sets the provider connection and, for a non-null connection, the
     * derived `provider` ({@see ProviderType::of()}). Null clears the
     * connection and keeps `provider`.
     *
     * @throws \InvalidArgumentException for a connection that is no provider connection
     */
    public function setProviderConnection(?ApiConfiguration $connection): static
    {
        if (null !== $connection) {
            $this->provider = ProviderType::of($connection);
        }
        $this->providerConnection = $connection;

        return $this;
    }

    /**
     * The name of the provider connection, null without one. Read-only,
     * serialized as `connection`.
     */
    #[ApiProperty(
        description: 'The name of the provider connection the turns run on: the connection of the catalogue entry `model`. Read-only. A request that sends `connection` is rejected with 422: the model reference names the connection.',
        writable: false,
        example: 'ollama-local',
    )]
    #[Groups([self::GROUP_READ])]
    public function getConnection(): ?string
    {
        return $this->providerConnection?->getName();
    }

    /**
     * The model name; null only for a new conversation before the create
     * processor set it.
     */
    public function getModel(): ?string
    {
        return $this->model;
    }

    /**
     * Sets the stored model name directly. Prefer {@see setProviderModel()},
     * which takes it from the catalogue entry; kept for fixtures that
     * change the name of a conversation with an entry.
     */
    public function setModel(?string $model): self
    {
        $this->model = $model;

        return $this;
    }

    /**
     * The model catalogue entry; null only for a new conversation before
     * the create processor (or the agent version pinner) set it. A stored
     * conversation always has one.
     */
    public function getProviderModel(): ?ProviderModel
    {
        return $this->providerModel;
    }

    /**
     * Sets the model catalogue entry and with it the provider connection
     * (and `provider`, its type, {@see setProviderConnection()}) and the
     * model name. There is no way to clear it: the column is NOT NULL.
     *
     * @throws \InvalidArgumentException for an entry whose connection is no provider connection
     */
    public function setProviderModel(ProviderModel $providerModel): static
    {
        $this->setProviderConnection($providerModel->getApiConfiguration());
        $this->model = $providerModel->getName();
        $this->providerModel = $providerModel;

        return $this;
    }

    /**
     * The reference given on input until the create processor resolved it,
     * afterwards the IRI of the catalogue entry.
     */
    public function getModelReference(): ?string
    {
        return $this->modelReference ?? $this->providerModel?->iri();
    }

    /**
     * The input reference; the create processor resolves it and clears it
     * with null.
     */
    public function setModelReference(?string $modelReference): self
    {
        $this->modelReference = $modelReference;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getCollections(): array
    {
        return $this->collections;
    }

    /**
     * @param list<string> $collections
     */
    public function setCollections(array $collections): self
    {
        $this->collections = array_values($collections);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getMcpConnections(): array
    {
        return $this->mcpConnections;
    }

    /**
     * @param list<string> $mcpConnections
     */
    public function setMcpConnections(array $mcpConnections): self
    {
        $this->mcpConnections = array_values($mcpConnections);

        return $this;
    }

    /**
     * Null only for a new conversation before the create processor filled the default.
     */
    public function getRetrievalDepth(): ?int
    {
        return $this->retrievalDepth;
    }

    public function setRetrievalDepth(?int $retrievalDepth): self
    {
        $this->retrievalDepth = $retrievalDepth;

        return $this;
    }

    public function getAgentVersion(): ?AgentVersion
    {
        return $this->agentVersion;
    }

    public function setAgentVersion(?AgentVersion $agentVersion): self
    {
        $this->agentVersion = $agentVersion;

        return $this;
    }

    public function getAgent(): ?string
    {
        return $this->agent;
    }

    public function setAgent(?string $agent): self
    {
        $this->agent = $agent;

        return $this;
    }

    /**
     * Null = unlimited.
     */
    public function getMaxUserTurns(): ?int
    {
        return $this->maxUserTurns;
    }

    public function setMaxUserTurns(?int $maxUserTurns): self
    {
        $this->maxUserTurns = $maxUserTurns;

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
     * @return array<array-key, mixed>|null
     */
    public function getVariables(): ?array
    {
        return $this->variables;
    }

    /**
     * @param array<array-key, mixed>|null $variables
     */
    public function setVariables(?array $variables): self
    {
        $this->variables = $variables;

        return $this;
    }

    /**
     * `eval` for a conversation of an evaluation run, `replay` for the
     * conversation of a conversation replay, null otherwise.
     */
    public function getOrigin(): ?string
    {
        return $this->origin;
    }

    /**
     * Whether this is an evaluation or replay conversation, which the
     * holders of `neuronai:conversation:read_eval` may read
     * ({@see self::EVALUATION_ORIGINS}); used by {@see ConversationSecurity}.
     */
    public function isEvaluationOrigin(): bool
    {
        return \in_array($this->origin, self::EVALUATION_ORIGINS, true);
    }

    public function setOrigin(?string $origin): self
    {
        $this->origin = $origin;

        return $this;
    }

    /**
     * Owner subject of the anonymous conversation with the given id.
     */
    public static function anonymousOwnerSubjectFor(Uuid $id): string
    {
        return self::ANONYMOUS_OWNER_PREFIX.$id->toRfc4122();
    }

    public function anonymousOwnerSubject(): string
    {
        return self::anonymousOwnerSubjectFor($this->id);
    }

    public function isAnonymous(): bool
    {
        return $this->anonymous;
    }

    public function setAnonymous(bool $anonymous): self
    {
        $this->anonymous = $anonymous;

        return $this;
    }

    public function getTokenHash(): ?string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(?string $tokenHash): self
    {
        $this->tokenHash = $tokenHash;

        return $this;
    }

    public function getAddressHash(): ?string
    {
        return $this->addressHash;
    }

    public function setAddressHash(?string $addressHash): self
    {
        $this->addressHash = $addressHash;

        return $this;
    }

    public function getTurnCount(): int
    {
        return $this->turnCount;
    }

    public function setTurnCount(int $turnCount): self
    {
        $this->turnCount = $turnCount;

        return $this;
    }

    public function getTokensUsed(): int
    {
        return $this->tokensUsed;
    }

    public function setTokensUsed(int $tokensUsed): self
    {
        $this->tokensUsed = $tokensUsed;

        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeImmutable $closedAt): self
    {
        $this->closedAt = $closedAt;

        return $this;
    }

    /**
     * One of the `CLOSE_REASON_*` constants; null while not closed.
     */
    public function getCloseReason(): ?string
    {
        return $this->closeReason;
    }

    public function setCloseReason(?string $closeReason): self
    {
        $this->closeReason = $closeReason;

        return $this;
    }

    public function getNextSequence(): int
    {
        return $this->nextSequence;
    }

    public function setNextSequence(int $nextSequence): self
    {
        $this->nextSequence = $nextSequence;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isRunning(): ?bool
    {
        return $this->running;
    }

    public function setRunning(bool $running): self
    {
        $this->running = $running;

        return $this;
    }
}

// - revised 2026-10-07
// - revised 2026-10-09
