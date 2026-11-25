<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Entity\Refs;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Filter\CapabilityFilter;
use Dmstr\NeuronAiBundle\Filter\ModelSearchFilter;
use Dmstr\NeuronAiBundle\OpenApi\OpenApiTag;
use Dmstr\NeuronAiBundle\Provider\EndpointFingerprint;
use Dmstr\NeuronAiBundle\Provider\Model\ModelCapability;
use Dmstr\NeuronAiBundle\Provider\Model\ModelOptionsSchemaOutput;
use Dmstr\NeuronAiBundle\Provider\ProviderType;
use Dmstr\NeuronAiBundle\State\ProviderModelOptionsSchemaProvider;
use Dmstr\NeuronAiBundle\State\ProviderModelScanProcessor;
use Dmstr\NeuronAiBundle\State\ProviderModelSyncProcessor;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * REF entity mirroring the models a provider connection (`ollama` or
 * `anthropic`) offers, with what each model can do: family, parameter size,
 * quantization, capabilities, trained context length and output limit,
 * and for Ollama the Modelfile parameters with the runtime default context
 * length ({@see getDefaultContextLength()}).
 *
 * Rows are written only by the catalogue scan and sync
 * ({@see \Dmstr\NeuronAiBundle\Provider\ProviderModelScanner}); the API is
 * read-only apart from the two actions:
 *  - `POST /refs/provider_models/scan` queues a job (type
 *    `provider_model_scan`) that mirrors all active provider connections or
 *    the one in the body: new models are created, changed ones updated,
 *    vanished ones marked `available = false`;
 *  - `POST /refs/provider_models/{id}/sync` describes one row afresh; a
 *    model the connection no longer offers keeps its row, marked
 *    `available = false`, and gets `lastError`.
 *
 * Rows are never deleted by scan or sync, so references to a catalogue
 * entry (agents, conversations, collections, records) stay valid; a model
 * that is offered again gets its old row back with `available = true`.
 *
 * `GET /refs/provider_models/{id}/options_schema` returns the JSON schema of
 * the agent `modelOptions` the model accepts
 * ({@see \Dmstr\NeuronAiBundle\Provider\ModelOptionsSchema}).
 *
 * `capabilities` has three states: null = unknown (never described, or the
 * endpoint does not report capabilities), a list without `tools` = known to
 * lack tools, a list with `tools` = known to support tools. The row is a
 * snapshot: nothing rescans automatically except the scan job queued when a
 * provider connection is created, activated or pointed to another URL
 * through the API. A row described from another endpoint than the
 * connection's current one is `stale` ({@see EndpointFingerprint}).
 *
 * Rows of a connection are deleted with the connection (FK ON DELETE
 * CASCADE). Rows for one connection:
 *
 *     $entityManager->getRepository(ProviderModel::class)
 *         ->findBy(['apiConfiguration' => $connection], ['name' => 'ASC']);
 */
#[ORM\Entity]
#[ORM\Table(name: 'neuron_provider_model')]
#[ORM\UniqueConstraint(name: 'uniq_neuron_provider_model_api_configuration_name', columns: ['api_configuration_id', 'name'])]
#[ORM\Index(name: 'idx_neuron_provider_model_api_configuration', columns: ['api_configuration_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(),
        // Stays after the plain Get: the first item Get of the resource is
        // the one API Platform uses for the IRI of a row.
        new Get(
            uriTemplate: '/provider_models/{id}/options_schema',
            description: 'Returns the model options an agent may set for this model, such as temperature, maximum tokens or thinking, with the limits of the model. The agent form loads it after a model is chosen.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::CORE],
                responses: [
                    '200' => new OpenApiResponse(description: 'The schema of the model options of the catalogue model `{id}`.'),
                    '404' => new OpenApiResponse(description: 'No catalogue entry with this id.'),
                ],
                summary: 'Returns the JSON schema of the agent model options a catalogue model accepts.',
                description: 'The `schema` (JSON Schema 2020-12) describes the `modelOptions` an agent on this model may set, as the agent publish checks them: the options of the provider type (Ollama `temperature`, `topP`, `topK`, `maxTokens`, `repeatPenalty`, `seed`, `numCtx`, `think`; Anthropic `temperature`, `topP`, `topK`, `maxTokens`, `effort`, `thinkingDisplay`), sampling only for a model that accepts it (`supportsSampling`), `numCtx` up to `maxContextLength`, Anthropic `maxTokens` up to `maxOutputTokens`, the effort levels the Models API reports, and the thinking options only with the capability `thinking`. A `stale` entry gives the options of the provider without the limits of the model. A form loads this schema after the model is chosen, because the static schema of the candidate cannot depend on the chosen model.',
            ),
            // No serialization groups: the output DTO is serialized as a whole.
            normalizationContext: [],
            output: ModelOptionsSchemaOutput::class,
            name: self::OPERATION_OPTIONS_SCHEMA,
            provider: ProviderModelOptionsSchemaProvider::class,
        ),
        new GetCollection(),
        new Post(
            uriTemplate: '/provider_models/scan',
            status: 201,
            description: 'Brings the model catalogue up to date with all active provider connections, or with one chosen connection: new models are added, changed ones updated and models that are no longer offered marked as unavailable; entries are never deleted, so agents and conversations that use a model keep their reference. The scan runs in the background; progress is shown in **Job**. If the same scan is already waiting or running, that one is shown instead of starting another.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::CORE],
                summary: 'Queues a scan of the provider model catalogue.',
                description: 'Creates a job of type `provider_model_scan` that mirrors the models of all active `ollama` and `anthropic` connections, or of the connection given as `apiConfiguration`. The body is validated before the job is created; while an identical scan is pending or running, that job is returned instead of a new one.',
            ),
            security: "is_granted('neuronai:provider_model:write')",
            input: false,
            output: Job::class,
            read: false,
            deserialize: false,
            validate: false,
            name: self::OPERATION_SCAN,
            processor: ProviderModelScanProcessor::class,
        ),
        new Post(
            uriTemplate: '/provider_models/{id}/sync',
            status: 200,
            description: 'Describes this model afresh from its provider connection: capabilities, context length and listing details. If the connection no longer offers the model, the entry is marked as unavailable; if the connection cannot be reached, the entry stays as it is and shows the error. Not possible while a scan of the same connection is running.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::CORE],
                summary: 'Describes one catalogue entry afresh from its provider connection.',
                description: 'Refreshes capabilities, context length and the listing fields of the model. A model the connection no longer offers keeps its entry, gets `available` false and `lastError` (still 200); a failing provider sets `lastError` only. 409 while a scan of the same connection is running.',
            ),
            security: "is_granted('neuronai:provider_model:write')",
            input: false,
            deserialize: false,
            validate: false,
            name: self::OPERATION_SYNC,
            processor: ProviderModelSyncProcessor::class,
            throwOnNotFound: true,
        ),
    ],
    description: <<<'MD'
        The model catalogue lists the language models that the provider connections (Ollama, Anthropic) offer, together with what each model can do: size, context length, output limit and capabilities such as tool use or thinking. Agents choose their model from this list.

        The catalogue is a snapshot and is not edited by hand: a scan brings it up to date, and a single entry can be refreshed on its own. A scan also starts on its own when a provider connection is created, activated or pointed to another server. An entry that was described from a different server than the connection's current one is marked as stale.

        Related: each model belongs to a provider connection in **ApiConfiguration**. Models are used by an **Agent**; a scan runs as a **Job**.
        MD,
    routePrefix: '/refs',
    security: "is_granted('neuronai:provider_model:read')",
    paginationEnabled: true,
    paginationItemsPerPage: 30,
    openapi: new OpenApiOperation(tags: [OpenApiTag::CORE]),
)]
#[ApiFilter(SearchFilter::class, properties: ['apiConfiguration' => 'exact', 'name' => 'exact', 'family' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['available', 'apiConfiguration.active'])]
#[ApiFilter(CapabilityFilter::class)]
#[ApiFilter(ModelSearchFilter::class)]
class ProviderModel
{
    public const string OPERATION_SCAN = 'provider_model_scan';
    public const string OPERATION_SYNC = 'provider_model_sync';
    public const string OPERATION_OPTIONS_SCHEMA = 'provider_model_options_schema';

    /** Job type of the asynchronous scan (dmstr_job.type is VARCHAR(50)). */
    public const string JOB_TYPE = 'provider_model_scan';

    public const int MAX_NAME_LENGTH = 190;

    /**
     * Connection types whose models the catalogue mirrors. The scan, the
     * connection picker of the scan form (`x-collection`, see
     * {@see connectionCollectionUrl()}) and the OpenAPI request body all
     * read this list.
     *
     * @var list<string>
     */
    public const array CONNECTION_TYPES = ProviderType::CHAT_TYPES;

    /** @var list<string> the capability vocabulary, see {@see ModelCapability} */
    public const array CAPABILITIES = ModelCapability::ALL;

    /**
     * Collection the scan form offers its connections from: the active
     * connections of {@see CONNECTION_TYPES}.
     */
    public static function connectionCollectionUrl(): string
    {
        $types = array_map(static fn (string $type): string => 'type[]='.rawurlencode($type), self::CONNECTION_TYPES);

        return '/api/admin/api_configurations?'.implode('&', $types).'&active=true';
    }

    /**
     * The catalogue's collection path; an entry's IRI is this path, `/` and
     * its UUID ({@see iri()}).
     */
    public const string COLLECTION_PATH = '/api/refs/provider_models';

    /**
     * Collection a model picker (`x-collection`) offers its models from:
     * the available entries of active connections with $capability, e.g.
     * `completion` for chat models and `embedding` for collections. The
     * scan leaves the rows of an inactive connection alone, so they keep
     * `available = true`; `apiConfiguration.active=true` drops them, as
     * the resolver rejects them (reason `inactive`). Entries with unknown
     * capabilities do not match the capability filter
     * ({@see CapabilityFilter}), so a never-described model is not offered
     * there, although a reference to it is accepted
     * ({@see \Dmstr\NeuronAiBundle\Provider\ProviderModelResolver}).
     *
     * @param string $capability one of {@see CAPABILITIES}
     *
     * @throws \InvalidArgumentException for an unknown capability
     */
    public static function modelCollectionUrl(string $capability): string
    {
        ModelCapability::assertKnown($capability);

        return self::MODEL_COLLECTION_PREFIX.rawurlencode($capability);
    }

    /** {@see modelCollectionUrl()} up to the capability. */
    private const string MODEL_COLLECTION_PREFIX = self::COLLECTION_PATH.'?available=true&apiConfiguration.active=true&'.CapabilityFilter::PARAMETER.'[]=';

    /**
     * {@see modelCollectionUrl()} for chat models (`completion`), as a
     * constant for attribute metadata (`x-collection` of an `ApiProperty`).
     */
    public const string CHAT_MODEL_COLLECTION_URL = self::MODEL_COLLECTION_PREFIX.ModelCapability::COMPLETION;

    /**
     * {@see modelCollectionUrl()} for embedding models (`embedding`), the
     * picker of a collection's `embeddingModel` and of the reindex target,
     * as a constant for attribute metadata.
     */
    public const string EMBEDDING_MODEL_COLLECTION_URL = self::MODEL_COLLECTION_PREFIX.ModelCapability::EMBEDDING;

    /**
     * Schema keywords shared by every model picker. The admin form's
     * type-ahead (`EditorIriReference` of dmstr/jedison-extender-bundle)
     * resolves only on `format: iri-reference` together with an
     * `x-collection`; an `x-collection` alone renders a plain text input.
     * It shows the name and searches with {@see ModelSearchFilter}, so a
     * typed name and a pasted UUID both find the entry.
     */
    private const array PICKER = ['format' => 'iri-reference', 'x-label-property' => 'name', 'x-search-property' => ModelSearchFilter::PARAMETER];

    /**
     * Schema keywords of a model picker for $capability: {@see PICKER} plus
     * the `x-collection` of {@see modelCollectionUrl()}, for schemas built
     * in PHP.
     *
     * @return array<string, string>
     *
     * @throws \InvalidArgumentException for an unknown capability
     */
    public static function modelPicker(string $capability): array
    {
        return [...self::PICKER, 'x-collection' => self::modelCollectionUrl($capability)];
    }

    /** {@see modelPicker()} for chat models, as a constant for attribute metadata (`openapiContext`). */
    public const array CHAT_MODEL_PICKER = [...self::PICKER, 'x-collection' => self::CHAT_MODEL_COLLECTION_URL];

    /** {@see modelPicker()} for embedding models, as a constant for attribute metadata (`openapiContext`). */
    public const array EMBEDDING_MODEL_PICKER = [...self::PICKER, 'x-collection' => self::EMBEDDING_MODEL_COLLECTION_URL];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    /** The `ollama` or `anthropic` connection that offers the model. */
    #[ORM\ManyToOne(targetEntity: ApiConfiguration::class)]
    #[ORM\JoinColumn(name: 'api_configuration_id', nullable: false, onDelete: 'CASCADE')]
    private ApiConfiguration $apiConfiguration;

    /** Model name as the provider lists it (Ollama `llama3.1:8b`, Anthropic model id), unique per connection. */
    #[ORM\Column(type: Types::STRING, length: self::MAX_NAME_LENGTH)]
    private string $name;

    /** Human-readable name (Anthropic `display_name`); null for Ollama. */
    #[ORM\Column(type: Types::STRING, length: 190, nullable: true)]
    private ?string $displayName = null;

    /** Model family (Ollama `details.family`, Anthropic `line`). */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $family = null;

    /** Parameter size as the provider reports it (Ollama `7.6B`); null for Anthropic. */
    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $parameterSize = null;

    /** Quantization level (Ollama `Q4_K_M`); null for Anthropic. */
    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $quantization = null;

    /**
     * Capabilities of the model (`completion`, `tools`, `vision`, `thinking`,
     * `embedding`, ...), sorted. Null = unknown.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $capabilities = null;

    /** Trained maximum context length in tokens (not Ollama's runtime `num_ctx`). */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $maxContextLength = null;

    /** Maximum output tokens (Anthropic `max_tokens`); null for Ollama. */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $maxOutputTokens = null;

    /**
     * Whether the model accepts the sampling parameters `temperature`,
     * `top_p` and `top_k` (Ollama always; Anthropic from the bundle's rule
     * table of model families). Null = unknown.
     */
    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $supportsSampling = null;

    /** Ollama digest of the model; drives the incremental rescan. Null for Anthropic. */
    #[ORM\Column(type: Types::STRING, length: 80, nullable: true)]
    private ?string $digest = null;

    /** sha256 of the connection's base URL at describe time, see {@see EndpointFingerprint}. */
    #[ORM\Column(type: Types::STRING, length: 64)]
    #[ApiProperty(readable: false, writable: false)]
    private string $endpointFingerprint;

    /**
     * Provider-specific extras that are not filterable. Ollama: `families`,
     * `format`, `size`, `modified_at`, `parameter_count`, `embedding_length`,
     * `remote_model` and `remote_host` (cloud models), `system` (Modelfile
     * system prompt), `projector_info` (scalar keys), `parameters` (the
     * Modelfile parameters of `/show`, parsed: `num_ctx`, `stop`,
     * `temperature`, …; [] when the Modelfile sets none, missing when the
     * model was never described by `/show`) and `model_info` (the scalar
     * keys of `/show` `model_info`). Anthropic: `capabilities` object,
     * `created_at`.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $providerData = null;

    /**
     * Whether the connection offered the model at the last scan or sync.
     * False marks a model that is no longer offered; the row is kept so
     * references to it stay valid, and becomes available again when the
     * model is offered again.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    #[ApiProperty(description: 'Whether the provider connection offered the model at the last scan or sync. A model that is no longer offered keeps its entry with `false`, so agents and conversations that use it keep their reference; it becomes `true` again when the model is offered again.', writable: false)]
    private bool $available = true;

    /** Last successful describe; null for a row that was never described. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    /** Error of the last scan or sync of this entry; null after a successful one. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[ApiProperty(openapiContext: ['x-format' => 'textarea', 'x-grid' => ['columnsMd' => 12, 'newRow' => true]])]
    private ?string $lastError = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(ApiConfiguration $apiConfiguration, string $name)
    {
        $this->id = Uuid::v4();
        $this->apiConfiguration = $apiConfiguration;
        $this->name = $name;
        $this->endpointFingerprint = EndpointFingerprint::of($apiConfiguration);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
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
     * The entry's IRI (`/api/refs/provider_models/<uuid>`), the form a model
     * reference takes in API bodies and stored configurations.
     */
    public function iri(): string
    {
        return self::COLLECTION_PATH.'/'.$this->id->toRfc4122();
    }

    public function getApiConfiguration(): ApiConfiguration
    {
        return $this->apiConfiguration;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): self
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getFamily(): ?string
    {
        return $this->family;
    }

    public function setFamily(?string $family): self
    {
        $this->family = $family;

        return $this;
    }

    public function getParameterSize(): ?string
    {
        return $this->parameterSize;
    }

    public function setParameterSize(?string $parameterSize): self
    {
        $this->parameterSize = $parameterSize;

        return $this;
    }

    public function getQuantization(): ?string
    {
        return $this->quantization;
    }

    public function setQuantization(?string $quantization): self
    {
        $this->quantization = $quantization;

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getCapabilities(): ?array
    {
        return $this->capabilities;
    }

    /**
     * Unknown values are dropped, the list is sorted; null = unknown.
     *
     * @param list<string>|null $capabilities
     */
    public function setCapabilities(?array $capabilities): self
    {
        $this->capabilities = ModelCapability::normalize($capabilities);

        return $this;
    }

    /**
     * Whether the model is known to have the capability (false when the
     * capabilities are unknown).
     */
    public function hasCapability(string $capability): bool
    {
        return null !== $this->capabilities && \in_array($capability, $this->capabilities, true);
    }

    public function getMaxContextLength(): ?int
    {
        return $this->maxContextLength;
    }

    public function setMaxContextLength(?int $maxContextLength): self
    {
        $this->maxContextLength = $maxContextLength;

        return $this;
    }

    public function getMaxOutputTokens(): ?int
    {
        return $this->maxOutputTokens;
    }

    public function setMaxOutputTokens(?int $maxOutputTokens): self
    {
        $this->maxOutputTokens = $maxOutputTokens;

        return $this;
    }

    public function getSupportsSampling(): ?bool
    {
        return $this->supportsSampling;
    }

    public function setSupportsSampling(?bool $supportsSampling): self
    {
        $this->supportsSampling = $supportsSampling;

        return $this;
    }

    public function getDigest(): ?string
    {
        return $this->digest;
    }

    public function setDigest(?string $digest): self
    {
        $this->digest = $digest;

        return $this;
    }

    public function getEndpointFingerprint(): string
    {
        return $this->endpointFingerprint;
    }

    public function setEndpointFingerprint(string $endpointFingerprint): self
    {
        $this->endpointFingerprint = $endpointFingerprint;

        return $this;
    }

    /**
     * Whether the row was described from another endpoint than the one the
     * connection points to now (its URL changed since); a rescan is due.
     */
    #[ApiProperty(description: 'Whether the entry was described from another endpoint than the connection points to now (its URL changed); a rescan is due. A stale entry does not count for the capability check.')]
    public function isStale(): bool
    {
        return $this->endpointFingerprint !== EndpointFingerprint::of($this->apiConfiguration);
    }

    /**
     * The Modelfile parameters of an Ollama model (`providerData.parameters`);
     * [] when the Modelfile sets none, null when unknown (never described by
     * `/show`, or not an Ollama model).
     *
     * @return array<string, mixed>|null
     */
    public function modelParameters(): ?array
    {
        $parameters = $this->providerData['parameters'] ?? null;

        return \is_array($parameters) ? $parameters : null;
    }

    /**
     * The context length Ollama uses for a request without `num_ctx`, when
     * the Modelfile sets one; null = not set there (the server default
     * applies, which the API does not report) or unknown.
     */
    #[ApiProperty(description: 'Context length in tokens that the server uses for this model when an agent sets no `numCtx` (Ollama Modelfile parameter `num_ctx`). Null when the Modelfile does not set it, then the server default applies, or when the model was not described yet.')]
    public function getDefaultContextLength(): ?int
    {
        $numCtx = $this->modelParameters()['num_ctx'] ?? null;

        return \is_int($numCtx) && $numCtx > 0 ? $numCtx : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getProviderData(): ?array
    {
        return $this->providerData;
    }

    /**
     * @param array<string, mixed>|null $providerData
     */
    public function setProviderData(?array $providerData): self
    {
        $this->providerData = [] === $providerData ? null : $providerData;

        return $this;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function setAvailable(bool $available): self
    {
        $this->available = $available;

        return $this;
    }

    public function getSyncedAt(): ?\DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function setSyncedAt(?\DateTimeImmutable $syncedAt): self
    {
        $this->syncedAt = $syncedAt;

        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): self
    {
        $this->lastError = $lastError;

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
// - revised 2026-10-08
// - revised 2026-10-08
// - revised 2026-10-08
// - revised 2026-10-09
