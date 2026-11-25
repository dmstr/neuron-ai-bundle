<?php

// file generated with AI assistance: Claude Code - 2026-10-02 21:49:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use Dmstr\NeuronAiBundle\Chat\AgentChatResult;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Filter\CollectionSearchFilter;
use Dmstr\NeuronAiBundle\OpenApi\OpenApiTag;
use Dmstr\NeuronAiBundle\Provider\Model\ModelCapability;
use Dmstr\NeuronAiBundle\State\AgentChatProcessor;
use Dmstr\NeuronAiBundle\State\AgentWriteProcessor;
use Dmstr\NeuronAiBundle\Validator\ValidAgentCandidate;
use Dmstr\OpenApiJsonSchema\Attribute\JsonSchema;
use Dmstr\OpenApiJsonSchema\Interface\JsonSchemaProviderInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An agent: a stable record with an editable candidate and immutable published
 * versions ({@see AgentVersion}).
 *
 * - candidate: the configuration being edited, the candidate for the next
 *   version (formerly named `draft`), in the shape of
 *   `Entity/Agent/candidate.json` (including the pre-answer
 *   `rules` and their output `templates`, whose reserved slots `frame`,
 *   `limitReason`, `turnLimitReason` and `forcedCloseFallback` are filled
 *   by the bundle). POST and PATCH check only its structure
 *   ({@see ValidAgentCandidate}), so a candidate may point to collections, MCP
 *   connections or tools that do not exist yet. PATCH replaces the candidate as
 *   a whole; clients send the complete candidate.
 * - currentVersion: the version conversations of this agent use; set by
 *   publish and rollback only, together with the read-only
 *   currentVersionNumber ({@see self::setCurrentVersion()}).
 * - enabled: the creation gate for conversations created with this agent
 *   (`POST /conversations` with `agent` answers 422 for a
 *   disabled agent); a conversation created earlier keeps running when the
 *   agent is disabled later. `POST /agents/{id}/chat`
 *   honours it as well (422 for a disabled agent).
 *
 * Operations (`neuronai:agent:read` reads, `neuronai:agent:write` writes; no PUT, no
 * DELETE, an agent is deactivated with `enabled`):
 *  - `GET /agents` (filters `slug`, `enabled`), `GET /agents/{id}`;
 *  - `POST /agents`, `PATCH /agents/{id}`;
 *  - `POST /agents/{id}/publish` (`agent_publish`): validates the candidate
 *    with all references and freezes it as the next version, which becomes
 *    the current one; answers with the version;
 *  - `POST /agents/{id}/rollback` (`agent_rollback`): makes an earlier
 *    version of this agent current again; the body is validated against
 *    `Entity/Agent/rollback.input.json`; answers with the agent;
 *  - `POST /agents/{id}/chat` (`agent_chat`, `neuronai:agent:chat`): one stateless
 *    turn on the current version ({@see AgentChatProcessor}); the body
 *    (`Entity/Agent/chat.input.json`) carries `messages`, `variables`,
 *    `language` and `maxToolCalls`, everything else comes from the version;
 *    the version's pre-answer rules are evaluated on the last user message
 *    first, and a rule that answers or refuses returns its rendered text
 *    without retrieval or provider call; the version's output templates
 *    apply (`frame` next to the unframed answer, the forced-close
 *    fallback); answers with an {@see AgentChatResult}. There is no `ask`
 *    action.
 *
 * Creation, publish and rollback are audited (Gedmo loggable on
 * `currentVersionNumber` and `enabled`; the candidate is not versioned,
 * otherwise every candidate edit would end up in the log). The association
 * `currentVersion` is deliberately not versioned: Gedmo would log the
 * identifier of the version, a Uuid object, whose serialized form contains
 * NUL bytes that the `data` text column of `dmstr_log_entries` cannot hold,
 * which makes the whole audit trail of the agent unreadable.
 */
#[ORM\Entity]
#[ORM\Table(name: 'neuron_agent')]
#[ORM\UniqueConstraint(name: 'uniq_neuron_agent_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_neuron_agent_current_version', columns: ['current_version_id'])]
#[ORM\HasLifecycleCallbacks]
#[Gedmo\Loggable]
#[UniqueEntity(fields: ['slug'])]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(
            status: 201,
            security: "is_granted('neuronai:agent:write')",
            processor: AgentWriteProcessor::class,
        ),
        new Patch(
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::AGENTS],
                summary: 'Updates name, slug, enabled flag or candidate of the agent.',
                description: 'Merge patch of `name`, `slug`, `enabled` and `candidate`. The `candidate` is replaced as a whole, it is not merged key by key: send the complete candidate. Only the structure of the candidate is checked (422 for an unknown key or a value of the wrong type or range); references are checked when the candidate is published.',
            ),
            security: "is_granted('neuronai:agent:write')",
            processor: AgentWriteProcessor::class,
        ),
        new Post(
            uriTemplate: '/agents/{id}/publish',
            status: 201,
            description: 'Publishes the candidate as the next version of the agent. Everything it refers to is checked first: the model (with its provider connection), collections and MCP tools must exist and be active. The new version becomes the current one, and new chats use it from now on.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::AGENTS],
                summary: 'Publishes the candidate of the agent as its next version.',
                description: 'Validates the candidate with all references and freezes it as the next immutable version, which becomes the current version of the agent. `model` is required (there is no default): the reference to an entry of the model catalogue (IRI or UUID) is resolved and stored as the full UUID of the entry like the collections and MCP connections; the entry names the provider connection (`ollama` or `anthropic`), and the model is checked live against it. No body. 422 with the offending `candidate/<field>` as `propertyPath` for an invalid value or a dangling reference (model missing, unknown, ambiguous, no longer available, of an inactive connection or of a connection of another type, known to lack `completion`, not offered by the connection, or not verifiable because the provider is unreachable at `candidate/model`; collection, MCP connection or tool, schema, language); 409 when a concurrent publish took the same version number.',
            ),
            normalizationContext: ['groups' => [AgentVersion::GROUP_READ]],
            security: "is_granted('neuronai:agent:write')",
            input: false,
            output: AgentVersion::class,
            read: true,
            deserialize: false,
            validate: false,
            name: self::OPERATION_PUBLISH,
            processor: \Dmstr\NeuronAiBundle\State\AgentPublishProcessor::class,
            throwOnNotFound: true,
        ),
        new Post(
            uriTemplate: '/agents/{id}/rollback',
            status: 200,
            description: 'Makes an earlier version of this agent the current one again, for example to undo a publish. No version is changed; the versions to choose from are listed under **AgentVersion**.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::AGENTS],
                summary: 'Makes an earlier version the current version of the agent.',
                description: 'Sets `currentVersion` to the given version of this agent; no version is changed. The body names the version as IRI, full or partial UUID. 422 for a version of another agent, 404 for an unknown version. The response is the agent.',
            ),
            security: "is_granted('neuronai:agent:write')",
            input: false,
            read: true,
            deserialize: false,
            validate: false,
            name: self::OPERATION_ROLLBACK,
            processor: \Dmstr\NeuronAiBundle\State\AgentRollbackProcessor::class,
            throwOnNotFound: true,
        ),
        new Post(
            uriTemplate: '/agents/{id}/chat',
            status: 200,
            description: 'Sends messages to the agent and returns its answer, based on the current published version, or with `target: candidate` on a preview of its current candidate. Nothing is stored: the client sends the previous messages along with each turn. A chat with stored history is a **Conversation**.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::AGENTS],
                summary: 'Runs one stateless chat turn on the current version of the agent.',
                description: 'Answers the last user message of `messages` (the earlier entries are the history) with the current published version of the agent: instructions of the effective language with the placeholders filled, provider connection, model, collections, retrieval depth, hybrid retrieval (`hybrid`, `hybridWeight`), the version\'s MCP tools with their terminal flags, response schema, sampling and the tool-call bound. The version\'s artifact tools (`artifactTools`) are not offered here, also not with `target: candidate`: a stateless chat has no conversation and therefore no working folder, so they run only in conversations created with the agent. Nothing is stored. The body may also carry `variables` (placeholder values, validated against the version\'s `startSchema`), `language` (one of the version\'s `languages`, default `defaultLanguage`) and `maxToolCalls` (may only lower the bound). 400 for malformed JSON. 422 for a key the version fixes (`instructions`, `connection`, `model`, `collections`, `mcpConnections`, `retrievalDepth`, `hybrid`, `hybridWeight`, `contextMaxChunks`, `contextMaxChars`, `contextExpansionMaxChars`, `contextInstructions`, `responseSchema`, `terminalTools`, `maxUserTurns`, `rules`, `templates`, `injectionGuard`, `answerabilityCheck`, `answerabilityVotes`, `followUps`, `artifactTools`), a body that violates the schema, a disabled agent, an agent without a published version, an unsupported language, an undeclared, reserved, invalid or missing variable (`variables/<name>`), and a reference of the version that no longer resolves (`configuration/model` for a catalogue entry that no longer exists, a provider connection that was deactivated, or a version that names no model entry because it was published before model references and could not be migrated, `configuration/collections/<i>`, `configuration/mcpTools/<i>/connection`, `configuration/mcpTools/<i>/name`). Before retrieval and the provider call, the version\'s pre-answer rules are evaluated in order on the `content` of the last user message: the first matching rule decides; a rule with outcome `answer` or `refuse` ends the call with 200 without retrieval or provider call, the rendered template text (effective language, else the default language) as `answer`, `sources` `[]`, `usage` null and `rule` `{index, name, match, outcome, template}`; without a deciding rule `rule` is null. Then, before retrieval, the version\'s answer gates run: with `injectionGuard` on, the version\'s model checks the last user message for a prompt-injection attempt; a refusal ends the call with 200 without retrieval or answer call, the version\'s `injectionRefusal` text (effective language, else the default language) as `answer`, `sources` `[]`, `frame` and `rule` null and `gate` `{gate, kind, template}` (`{"gate": "injection", "kind": "injection_refusal", "template": "injectionRefusal"}`); the gate\'s reason is never returned. Otherwise `gate` is null; a gate that fails (provider error, unparsable verdict) lets the call continue. With `answerabilityCheck` on and collections in the version, the answerability check runs after retrieval and before the answer (`answerabilityVotes` calls, a strict majority of unanswerable votes refuses); a refusal returns the `unanswerableRefusal` text as `answer`, `gate` `{"gate": "answerability", "kind": "unanswerable_refusal", "template": "unanswerableRefusal"}` and the retrieved chunks in `sources`. With `followUps` on, a complete text answer (no rule, gate refusal, response schema, terminal tool, forced-close fallback or empty answer) is followed by one call on the version\'s model that suggests at most 3 follow-up questions in the effective language, returned as `followUps`: the list, `[]` when the call failed or produced nothing, null when the version has `followUps` off or the answer is not eligible; its tokens are listed as `usage.gates.followUps`. `usage` sums the provider calls of the call including the gate calls and, whenever a gate ran, lists the gate share as `usage.gates.<gate>` `{input_tokens, output_tokens}`. The response names the version as `agentVersion` and the effective `language`. With `target` `candidate` (requires `neuronai:agent:chat_candidate`, else 403) the turn runs on a preview of the agent\'s current candidate instead of the published version: the candidate is validated like a publish (422 at `candidate/<field>`), frozen as a preview version and reused while it is unchanged; `agentVersion` then names the preview and `agentVersionKind` is `preview`. With a `frame` template of the version, `frame` is `{prefix, suffix}` (the template rendered and split at its `{{answer}}`) for a text answer, including a forced and a fallback answer, and null for schema, terminal-tool and rule answers; `answer` stays unframed, clients show `prefix + answer + suffix`. A forced close that fails or answers with an empty text is answered with the version\'s `forcedCloseFallback` template, when it has one, as `answer` with `forced` true.',
            ),
            // No serialization groups: the output DTO is serialized as a
            // whole (the resource's `agent:read` group would hide it).
            normalizationContext: [],
            security: "is_granted('neuronai:agent:chat')",
            input: false,
            output: AgentChatResult::class,
            read: true,
            deserialize: false,
            validate: false,
            name: self::OPERATION_CHAT,
            processor: AgentChatProcessor::class,
            throwOnNotFound: true,
        ),
    ],
    description: <<<'MD'
        An agent is an assistant that answers questions: it combines instructions, a language model and, optionally, knowledge collections and MCP tools.

        You edit the agent's **candidate**. Publishing freezes the candidate as a new version, and chats always use the current version. An earlier version can be made current again (rollback). A disabled agent accepts no new conversations.

        Related: the model comes from **ProviderModel** through a provider connection (**ApiConfiguration**), knowledge from **Collection**, tools from **McpTool**. Published versions are listed under **AgentVersion**, stored chats under **Conversation**.
        MD,
    normalizationContext: ['groups' => [self::GROUP_READ]],
    denormalizationContext: ['groups' => [self::GROUP_WRITE]],
    security: "is_granted('neuronai:agent:read')",
    order: ['name' => 'ASC'],
    paginationEnabled: true,
    paginationItemsPerPage: 30,
    openapi: new OpenApiOperation(tags: [OpenApiTag::AGENTS]),
)]
#[ApiFilter(SearchFilter::class, properties: ['slug' => 'exact', 'currentVersion' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['enabled'])]
class Agent implements JsonSchemaProviderInterface
{
    public const string OPERATION_PUBLISH = 'agent_publish';
    public const string OPERATION_ROLLBACK = 'agent_rollback';
    public const string OPERATION_CHAT = 'agent_chat';

    public const string GROUP_READ = 'agent:read';
    public const string GROUP_WRITE = 'agent:write';

    public const int MAX_NAME_LENGTH = 190;
    public const string SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

    /** JSON schema of the candidate and of a version's configuration. */
    public const string CANDIDATE_SCHEMA_FILE = __DIR__.'/Agent/candidate.json';

    /** @var array<string, mixed>|null the decoded candidate schema, read once */
    private static ?array $candidateSchema = null;

    /** @var array<string, mixed>|null property defaults of the candidate schema, read once */
    private static ?array $candidateDefaults = null;

    /**
     * Starting values of a NEW agent's candidate and of the admin form, for
     * keys whose neutral schema default is not what most agents want. They
     * hold for every provider (no `temperature`, which a model may refuse,
     * and no answer gate, which needs a refusal template to publish).
     * {@see candidateDefaults()} stays as it is: a stored version without
     * a key takes it from there, so changing it would change old versions.
     */
    public const array NEW_AGENT_DEFAULTS = [
        'hybrid' => true,
        'hybridWeight' => 0.3,
        'modelOptions' => ['maxTokens' => 2048],
    ];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[Groups([self::GROUP_READ])]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: self::MAX_NAME_LENGTH)]
    #[Assert\NotBlank]
    #[Assert\Length(max: self::MAX_NAME_LENGTH)]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private string $name;

    /** Machine name, e.g. "support-de": lowercase letters, digits and dashes. */
    #[ORM\Column(type: Types::STRING, length: self::MAX_NAME_LENGTH)]
    #[Assert\NotBlank]
    #[Assert\Length(max: self::MAX_NAME_LENGTH)]
    #[Assert\Regex(pattern: self::SLUG_PATTERN, message: 'The slug may only contain lowercase letters, digits and dashes and must not start with a dash.')]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private string $slug;

    /**
     * Creation gate for conversations created with this agent (422 when
     * false); existing conversations are not affected.
     * `POST /agents/{id}/chat` answers 422 for a disabled agent as well.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    #[Gedmo\Versioned]
    private bool $enabled = true;

    /** The version conversations use; set by publish and rollback only. */
    #[ORM\ManyToOne(targetEntity: AgentVersion::class)]
    #[ORM\JoinColumn(name: 'current_version_id', referencedColumnName: 'id', nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?AgentVersion $currentVersion = null;

    /**
     * Number of the current version; derived from currentVersion by
     * setCurrentVersion(), so the two cannot drift. Audited in place of the
     * association (see the class comment).
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    #[Gedmo\Versioned]
    private ?int $currentVersionNumber = null;

    /**
     * Editable configuration in the shape of `Entity/Agent/candidate.json`;
     * replaced as a whole by PATCH.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    #[ApiProperty(description: 'Editable configuration (schema `Entity/Agent/candidate.json`). PATCH replaces it as a whole; references are checked on publish.')]
    #[JsonSchema]
    #[ValidAgentCandidate]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private array $candidate;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[Groups([self::GROUP_READ])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[Groups([self::GROUP_READ])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->candidate = array_replace(self::candidateDefaults(), self::NEW_AGENT_DEFAULTS);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * The property defaults of `Entity/Agent/candidate.json`, all keys present.
     *
     * @return array<string, mixed>
     */
    public static function candidateDefaults(): array
    {
        if (null === self::$candidateDefaults) {
            $defaults = [];
            foreach (self::candidateSchema()['properties'] as $key => $property) {
                $defaults[$key] = $property['default'];
            }
            self::$candidateDefaults = $defaults;
        }

        return self::$candidateDefaults;
    }

    /**
     * The decoded `Entity/Agent/candidate.json`, with the keywords of the
     * model picker on `model` (the available completion models of active
     * connections, {@see ProviderModel::modelPicker()}).
     *
     * @return array{properties: array<string, array<string, mixed>>, description: string}&array<string, mixed>
     */
    public static function candidateSchema(): array
    {
        if (null === self::$candidateSchema) {
            /** @var array{properties: array<string, array<string, mixed>>, description: string}&array<string, mixed> $schema */
            $schema = json_decode((string) file_get_contents(self::CANDIDATE_SCHEMA_FILE), true, 512, \JSON_THROW_ON_ERROR);
            // The model picker of the admin form; built in PHP so the URL
            // and the keywords the type-ahead needs stay the ones of
            // ProviderModel::modelPicker().
            $schema['properties']['model'] = [...$schema['properties']['model'], ...ProviderModel::modelPicker(ModelCapability::COMPLETION)];
            // The knowledge collections picker, same type-ahead as the model.
            $schema['properties']['collections']['items'] += [
                'format' => 'iri-reference',
                'x-collection' => '/api/admin/collections',
                'x-label-property' => 'name',
                'x-search-property' => CollectionSearchFilter::PARAMETER,
            ];
            self::$candidateSchema = $schema;
        }

        return self::$candidateSchema;
    }

    /**
     * Schema of the `candidate` field for the OpenAPI description
     * ({@see \Dmstr\OpenApiJsonSchema\OpenApi\JsonFieldSchemaDecorator}), so
     * clients and the admin form see every key with its type, title and
     * description instead of an untyped map.
     *
     * @return array<string, mixed>|null
     */
    public static function getJsonSchema(string $fieldName): ?array
    {
        if ('candidate' !== $fieldName) {
            return null;
        }

        // The form starts a new agent with the recommended values; the
        // stored default of candidateSchema() stays the neutral one.
        $schema = self::candidateSchema();
        foreach (self::NEW_AGENT_DEFAULTS as $key => $default) {
            $schema['properties'][$key]['default'] = $default;
        }

        return $schema;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getCurrentVersion(): ?AgentVersion
    {
        return $this->currentVersion;
    }

    /**
     * Sets the current version and its number; the only place that writes
     * either of them.
     *
     * @throws \LogicException for a preview version, which never becomes current
     */
    public function setCurrentVersion(?AgentVersion $currentVersion): self
    {
        if (null !== $currentVersion && $currentVersion->isPreview()) {
            throw new \LogicException(\sprintf('Version %s is a preview and cannot become the current version.', $currentVersion->getId()->toRfc4122()));
        }

        $this->currentVersion = $currentVersion;
        $this->currentVersionNumber = $currentVersion?->getVersionNumber();

        return $this;
    }

    public function getCurrentVersionNumber(): ?int
    {
        return $this->currentVersionNumber;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCandidate(): array
    {
        return $this->candidate;
    }

    /**
     * @param array<string, mixed> $candidate
     */
    public function setCandidate(array $candidate): self
    {
        $this->candidate = $candidate;

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
}

// - revised 2026-10-07
// - revised 2026-10-09
// - revised 2026-10-13
// - revised 2026-10-13
// - revised 2026-10-16
