<?php

// file generated with AI assistance: Claude Code - 2026-09-30 05:42:49 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Filter\CollectionSearchFilter;
use Dmstr\NeuronAiBundle\OpenApi\OpenApiTag;
use Dmstr\NeuronAiBundle\State\CollectionDeleteProcessor;
use Dmstr\NeuronAiBundle\State\CollectionLockProcessor;
use Dmstr\NeuronAiBundle\State\CollectionReindexCancelProcessor;
use Dmstr\NeuronAiBundle\State\CollectionReindexProcessor;
use Dmstr\NeuronAiBundle\State\CollectionUnlockProcessor;
use Dmstr\NeuronAiBundle\Validator\ValidEmbeddingModel;
use Dmstr\NeuronPgvectorStore\PgVectorStore;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Knowledge collection: a named set of documents that share one embedding
 * space. Documents and chunks reference a collection by its id
 * (chunk.collection_id, chunk metadata "collection_id"), never by its
 * name.
 *
 * - embeddingModel: the embedding model as an entry of the model catalogue
 *   ({@see ProviderModel}), which names the `ollama` provider connection
 *   the vectors are computed through. Required on create: IRI or full or
 *   partial UUID of an available entry with the capability `embedding` on
 *   an active `ollama` connection whose name matches a model registered in
 *   `dmstr_neuron_ai.embeddings.models` (exactly, or with or without
 *   `:latest`). Stored as the relation `embeddingProviderModel` (column
 *   `embedding_model_id`, foreign key without cascade); the representation
 *   shows the entry's IRI. It cannot be changed directly: a model change is
 *   a reindex.
 * - embeddingModelName (PHP property `$embeddingModel`, column
 *   `embedding_model`): the registered name of the model, the vector label
 *   the collection's rows, chunk ids and the partial vector index are keyed
 *   by (e.g. `nomic-embed-text` for the entry `nomic-embed-text:latest`).
 *   Derived from the entry; read-only.
 * - embeddingConnection: the name of the entry's connection (relation
 *   `embeddingProviderConnection`, column `embedding_connection_id`, set
 *   with the entry); read-only.
 * - embeddingDimension: the model's vector dimension, always derived from
 *   the registry; a given value must match it.
 * - pendingEmbeddingModel, pendingEmbeddingModelName and
 *   pendingEmbeddingConnection: the target entry, label and connection of a
 *   running reindex. While they are set, documents are embedded for both
 *   the current and the pending label; read-only in the API.
 * - reindexJobId: id of the job queue job of the last reindex (no foreign
 *   key); read-only in the API.
 * - keywordLanguage: the PostgreSQL text search configuration of the
 *   collection's keyword (full-text) part of hybrid search, `german` or
 *   `english`; `german` for new collections. Changing it needs no reindex:
 *   the keyword indexes are per language expression, so a search simply
 *   uses the other one.
 *
 * Every stored collection has an entry (column NOT NULL) and with it a
 * connection. A collection stored before provider connections (no
 * `embeddingProviderConnection`) cannot get an entry; the migration of the
 * model reference refuses to run until it is deleted. A collection whose
 * connection was deactivated is stale: ingest, retrieval and reindex runs
 * refuse it with a clear error
 * ({@see \Dmstr\NeuronAiBundle\Embeddings\EmbeddingConnectionException}).
 *
 * Model change (reindex, {@see \Dmstr\NeuronAiBundle\Reindex\CollectionReindexer}):
 *  - `POST /admin/collections/{id}/reindex` with `{"model": "<reference>"}`
 *    sets the pending entry, label and connection and queues a job of type
 *    `collection_reindex`; the job embeds every document with the new
 *    model and switches the collection over only when all of them
 *    succeeded;
 *  - `POST /admin/collections/{id}/reindex/cancel` drops the pending model
 *    and its vectors.
 *
 * Lock (`lockedAt`, `lockedBy`): a locked collection refuses every write
 * to itself and to its content with 423 Locked
 * ({@see \Dmstr\NeuronAiBundle\Knowledge\CollectionLockedException}):
 *  - `POST /admin/collections/{id}/lock` (`neuronai:collection:lock`) locks it;
 *  - `POST /admin/collections/{id}/unlock` (`neuronai:collection:unlock`, which
 *    held separately from `neuronai:collection:lock`) unlocks it;
 *  - the collection itself (PUT, PATCH, DELETE and every other EntityManager
 *    path) is guarded by
 *    {@see \Dmstr\NeuronAiBundle\Doctrine\CollectionLockListener}, the
 *    write paths of its content call
 *    {@see \Dmstr\NeuronAiBundle\Knowledge\CollectionLockGuard}.
 * Lock and unlock are audited (Gedmo loggable on `lockedAt` and
 * `lockedBy`).
 *
 * See {@see ValidEmbeddingModel} for the validation rules.
 */
#[ORM\Entity]
#[ORM\Table(name: 'neuron_collection')]
#[ORM\UniqueConstraint(name: 'uniq_neuron_collection_name', columns: ['name'])]
#[ORM\HasLifecycleCallbacks]
#[Gedmo\Loggable]
#[UniqueEntity(fields: ['name'])]
#[ValidEmbeddingModel]
#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(),
        new Post(security: "is_granted('neuronai:collection:write')"),
        new Put(security: "is_granted('neuronai:collection:write')"),
        new Patch(security: "is_granted('neuronai:collection:write')"),
        // Deletes the content with the collection (423 locked, 409 while a
        // reindex is pending); see CollectionDeleteProcessor.
        new Delete(security: "is_granted('neuronai:collection:write')", processor: CollectionDeleteProcessor::class),
        new Post(
            uriTemplate: '/collections/{id}/reindex',
            status: 201,
            description: 'Switches the collection to another embedding model from the model catalogue, which may be on another provider connection. A background job re-embeds every document; searches keep using the current model until all documents succeeded, then the collection switches over and the old vectors are removed. Progress is shown in **Job**; a failed reindex is resumed by starting it again with the same model.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::RAG],
                summary: 'Queues a reindex of the collection with another embedding model.',
                description: 'Sets the catalogue entry `model` (IRI or full or partial UUID of a `/api/refs/provider_models` entry) as the pending embedding model; its connection becomes the pending embedding connection and its registered name the pending vector label. Creates a job of type `collection_reindex`. While the reindex is pending, new documents are embedded for both models and searches keep using the current one. The job embeds every document with the new model; only when all documents succeeded does the collection switch over and the old model\'s vectors are deleted. A failed job keeps the pending model; queueing the same model again resumes it. Rejected (422, violation at `model`) for a missing, unknown, ambiguous or unavailable entry, one of an inactive or non-`ollama` connection, one known to lack the capability `embedding`, one whose name is not registered in `dmstr_neuron_ai.embeddings.models`, the current model (also on another connection: the stored vectors are labelled by the registered name only, so a connection change needs another model), or while another model is pending. `connection` is no longer accepted (422 at `connection`): the entry names the connection.',
            ),
            security: "is_granted('neuronai:collection:write')",
            input: false,
            output: Job::class,
            read: true,
            deserialize: false,
            validate: false,
            name: self::OPERATION_REINDEX,
            processor: CollectionReindexProcessor::class,
            throwOnNotFound: true,
        ),
        new Post(
            uriTemplate: '/collections/{id}/reindex/cancel',
            status: 200,
            description: 'Cancels the pending reindex: the target model and the vectors already computed for it are dropped, and the collection keeps its current model and connection. A reindex job that is still running ends without switching over.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::RAG],
                summary: 'Cancels the pending reindex of the collection.',
                description: 'Clears the pending embedding model and connection and deletes the pending model\'s vectors; the collection keeps its current model and connection. A running reindex job ends without switching over. Rejected (422) when no reindex is pending.',
            ),
            security: "is_granted('neuronai:collection:write')",
            input: false,
            read: true,
            deserialize: false,
            validate: false,
            name: self::OPERATION_REINDEX_CANCEL,
            processor: CollectionReindexCancelProcessor::class,
            throwOnNotFound: true,
        ),
        new Post(
            uriTemplate: '/collections/{id}/lock',
            status: 200,
            description: 'Locks the collection: it can no longer be changed or deleted, and no documents or chunks can be added to, changed in or removed from it, until someone with the unlock permission unlocks it. Searches keep working.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::RAG],
                summary: 'Locks the collection against changes.',
                description: 'Sets `lockedAt` and `lockedBy` (the identifier of the current user) and returns the collection. While locked, every write to the collection or its content is refused with 423 Locked (`Collection "<name>" is locked`), also from the console and background jobs. Locking a locked collection changes nothing (200). Requires the permission `neuronai:collection:lock`; unlocking needs `neuronai:collection:unlock`.',
            ),
            security: "is_granted('neuronai:collection:lock')",
            input: false,
            read: true,
            deserialize: false,
            validate: false,
            name: self::OPERATION_LOCK,
            processor: CollectionLockProcessor::class,
            throwOnNotFound: true,
        ),
        new Post(
            uriTemplate: '/collections/{id}/unlock',
            status: 200,
            description: 'Unlocks a locked collection, so it and its content can be changed again. Needs the separate unlock permission, which administrators do not hold by default.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::RAG],
                summary: 'Unlocks the collection.',
                description: 'Clears `lockedAt` and `lockedBy` and returns the collection. Unlocking an unlocked collection changes nothing (200). Requires the permission `neuronai:collection:unlock`, which is held separately from the permission to lock.',
            ),
            security: "is_granted('neuronai:collection:unlock')",
            input: false,
            read: true,
            deserialize: false,
            validate: false,
            name: self::OPERATION_UNLOCK,
            processor: CollectionUnlockProcessor::class,
            throwOnNotFound: true,
        ),
    ],
    description: <<<'MD'
        A collection is a named set of knowledge documents that agents and conversations search to ground their answers. All documents of a collection are embedded with the same embedding model through the same provider connection; the model, and with it the connection, is chosen when the collection is created. The keyword language for the full-text part of hybrid search can be changed at any time.

        The embedding model is chosen from the model catalogue; it names the provider connection the documents are embedded through. It cannot be edited directly: switching to another model, which may be on another connection, is a reindex. It re-embeds every document in the background, searches keep using the current model meanwhile, and the collection switches over only when all documents succeeded. A collection from before provider connections cannot be used until a reindex moves it onto a model.

        Related: the embedding model is a **ProviderModel** of a provider connection in **ApiConfiguration**. **Agent** versions and **Conversation**s use collections as their knowledge. A reindex runs as a **Job**.
        MD,
    routePrefix: '/admin',
    security: "is_granted('neuronai:collection:read')",
    paginationEnabled: true,
    paginationItemsPerPage: 30,
    openapi: new OpenApiOperation(tags: [OpenApiTag::RAG]),
)]
// `embeddingProviderConnection` and `embeddingProviderModel` back the
// Collection tabs of an ApiConfiguration and of a ProviderModel in the admin
// UI (`?embeddingProviderConnection=<iri>`, `?embeddingProviderModel=<iri>`);
// without the filter API Platform ignores the parameter and lists all.
#[ApiFilter(SearchFilter::class, properties: ['name' => 'exact', 'embeddingProviderConnection' => 'exact', 'embeddingProviderModel' => 'exact'])]
#[ApiFilter(CollectionSearchFilter::class)]
class Collection
{
    public const string OPERATION_REINDEX = 'collection_reindex';
    public const string OPERATION_REINDEX_CANCEL = 'collection_reindex_cancel';
    public const string OPERATION_LOCK = 'collection_lock';
    public const string OPERATION_UNLOCK = 'collection_unlock';

    /** Job type of the asynchronous reindex (dmstr_job.type is VARCHAR(50)). */
    public const string JOB_TYPE_REINDEX = 'collection_reindex';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    /** Machine name, e.g. "docs-de": lowercase letters, digits and dashes. */
    #[ORM\Column(type: Types::STRING, length: 190)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 190)]
    #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9-]*$/', message: 'The name may only contain lowercase letters, digits and dashes and must not start with a dash.')]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[ApiProperty(openapiContext: ['x-format' => 'textarea', 'x-grid' => ['columnsMd' => 12, 'newRow' => true]])]
    private ?string $description = null;

    /**
     * The registered name of the embedding model (`dmstr_neuron_ai.embeddings.models`),
     * the vector label the collection's store rows, chunk ids and the
     * partial vector index are keyed by. Derived from the catalogue entry
     * {@see self::$embeddingProviderModel} (the registered name that
     * matches the entry's name, e.g. `nomic-embed-text` for
     * `nomic-embed-text:latest`); null only for a new collection before it
     * was resolved. Read-only, serialized as `embeddingModelName`.
     */
    #[ORM\Column(type: Types::STRING, length: 190)]
    #[Assert\Length(max: 190)]
    #[ApiProperty(
        description: 'The registered name of the embedding model (`dmstr_neuron_ai.embeddings.models`), e.g. `nomic-embed-text`: the label the collection\'s vectors are stored and searched under. It matches the name of the catalogue entry `embeddingModel` exactly or without the tag `:latest`. Read-only.',
        writable: false,
    )]
    #[SerializedName('embeddingModelName')]
    private ?string $embeddingModel = null;

    /** Vector dimension produced by the embedding model (derived from the registry). */
    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\Positive]
    private ?int $embeddingDimension = null;

    /**
     * Registered name (vector label) of the target model of a running
     * reindex, next to {@see self::$pendingEmbeddingProviderModel}; set and
     * cleared by the reindex only. Serialized as `pendingEmbeddingModelName`.
     */
    #[ORM\Column(type: Types::STRING, length: 190, nullable: true)]
    #[ApiProperty(
        description: 'Registered name (vector label) of the target model of a running reindex, next to `pendingEmbeddingModel`. Read-only.',
        writable: false,
    )]
    #[SerializedName('pendingEmbeddingModelName')]
    private ?string $pendingEmbeddingModel = null;

    /**
     * The model catalogue entry the collection's vectors are computed with;
     * it names the embedding connection ({@see self::$embeddingProviderConnection})
     * and gives the label ({@see self::$embeddingModel}). Set on create
     * from `embeddingModel`
     * ({@see \Dmstr\NeuronAiBundle\Doctrine\CollectionEmbeddingListener})
     * and by the reindex swap; null only for a new collection before it
     * was resolved. Every stored collection has one (column NOT NULL: the
     * migration gave every collection an entry, a placeholder where the
     * catalogue had none, and refuses to run while a collection cannot get
     * one). The foreign key has no cascade: deleting the connection,
     * which would delete its entries, is refused (409) by
     * {@see \Dmstr\NeuronAiBundle\Doctrine\ProviderConnectionDeleteGuard};
     * the key is the backstop. Serialized as its IRI in `embeddingModel`.
     */
    #[ORM\ManyToOne(targetEntity: ProviderModel::class)]
    #[ORM\JoinColumn(name: 'embedding_model_id', referencedColumnName: 'id', nullable: false)]
    #[ApiProperty(readable: false, writable: false)]
    private ?ProviderModel $embeddingProviderModel = null;

    /**
     * Target entry of a running reindex, next to
     * {@see self::$pendingEmbeddingModel} and
     * {@see self::$pendingEmbeddingProviderConnection}; set and cleared by
     * the reindex only, so the column is nullable. Same foreign key rules
     * as {@see self::$embeddingProviderModel}.
     */
    #[ORM\ManyToOne(targetEntity: ProviderModel::class)]
    #[ORM\JoinColumn(name: 'pending_embedding_model_id', referencedColumnName: 'id', nullable: true)]
    #[ApiProperty(readable: false, writable: false)]
    private ?ProviderModel $pendingEmbeddingProviderModel = null;

    /**
     * The embedding model as a string reference (not persisted), serialized
     * as `embeddingModel`. On input: IRI `/api/refs/provider_models/<uuid>`
     * or full or partial UUID of a catalogue entry, validated by
     * {@see ValidEmbeddingModel} and resolved before persist. On output: the
     * IRI of {@see self::$embeddingProviderModel}.
     */
    #[ApiProperty(
        description: 'The embedding model the collection\'s vectors are computed with: an entry of the model catalogue (`/api/refs/provider_models`), which names the `ollama` provider connection. On input: its IRI (`/api/refs/provider_models/<uuid>`) or its full or partial UUID; required on create, there is no default model. 422 at `embeddingModel` when it is missing, unknown, ambiguous, no longer available, of an inactive or non-`ollama` connection, known to lack the capability `embedding`, or when its name is not registered in `dmstr_neuron_ai.embeddings.models` (exactly or without `:latest`). A model name is no reference. On output: the IRI of the entry. Changing it needs a reindex (`POST /api/admin/collections/{id}/reindex`); sending back the current entry is no change.',
        example: '/api/refs/provider_models/0199a1b2-0000-4000-8000-000000000000',
        openapiContext: ProviderModel::EMBEDDING_MODEL_PICKER,
    )]
    #[SerializedName('embeddingModel')]
    private ?string $embeddingModelReference = null;

    /**
     * The `ollama` connection the collection's vectors are computed
     * through: the connection of {@see self::$embeddingProviderModel}, set
     * with it; null only for a new collection before it was resolved (the
     * column stays nullable from before provider connections, the entry's
     * NOT NULL column makes it set for every stored collection). The foreign key has no cascade: deleting a referenced
     * connection is refused (409) by
     * {@see \Dmstr\NeuronAiBundle\Doctrine\ProviderConnectionDeleteGuard},
     * the key is the backstop. Never serialized as such (the vendor entity
     * is readable by administrators only); the representation shows the
     * name as `embeddingConnection`. Changed only by the reindex swap.
     */
    #[ORM\ManyToOne(targetEntity: ApiConfiguration::class)]
    #[ORM\JoinColumn(name: 'embedding_connection_id', referencedColumnName: 'id', nullable: true)]
    #[ApiProperty(readable: false, writable: false)]
    private ?ApiConfiguration $embeddingProviderConnection = null;

    /**
     * Target connection of a running reindex, next to
     * {@see self::$pendingEmbeddingModel}; set and cleared by the reindex
     * only. Same foreign key rules as {@see self::$embeddingProviderConnection}.
     */
    #[ORM\ManyToOne(targetEntity: ApiConfiguration::class)]
    #[ORM\JoinColumn(name: 'pending_embedding_connection_id', referencedColumnName: 'id', nullable: true)]
    #[ApiProperty(readable: false, writable: false)]
    private ?ApiConfiguration $pendingEmbeddingProviderConnection = null;

    /** Job queue job id of the last reindex (no foreign key). */
    #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
    #[ApiProperty(writable: false)]
    private ?string $reindexJobId = null;

    /**
     * Language of the keyword (full-text) part of hybrid retrieval: `german` or `english`, default `german`. A change applies to the next search without a reindex.
     */
    #[ORM\Column(type: Types::STRING, length: 16)]
    #[Assert\Choice(choices: PgVectorStore::KEYWORD_LANGUAGES)]
    private string $keywordLanguage = 'german';

    /**
     * When the collection was locked; null while it is unlocked. Set and
     * cleared by lock() and unlock() only; read-only in the API. Audited.
     */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    #[ApiProperty(
        description: 'When the collection was locked (`POST /api/admin/collections/{id}/lock`); null while it is unlocked. A locked collection refuses every change to itself and its content with 423 Locked. Read-only.',
        writable: false,
    )]
    #[Gedmo\Versioned]
    private ?\DateTimeImmutable $lockedAt = null;

    /**
     * Identifier of the user who locked the collection (the Keycloak
     * subject); null while it is unlocked. Read-only in the API. Audited.
     */
    #[ORM\Column(type: Types::STRING, length: 190, nullable: true)]
    #[ApiProperty(
        description: 'Identifier of the user who locked the collection; null while it is unlocked. Read-only.',
        writable: false,
    )]
    #[Gedmo\Versioned]
    private ?string $lockedBy = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * The registered name of the embedding model, the vector label (not the
     * catalogue name, see {@see getEmbeddingProviderModel()}). Null only for
     * a new collection before its model reference was resolved.
     */
    public function getEmbeddingModel(): ?string
    {
        return $this->embeddingModel;
    }

    /**
     * Sets the vector label directly (fixtures, the reindex swap). The API
     * sets it from the catalogue entry of `embeddingModel`.
     */
    public function setEmbeddingModel(string $embeddingModel): self
    {
        $this->embeddingModel = $embeddingModel;

        return $this;
    }

    /**
     * Null only for a new collection that has not been persisted yet and was
     * created without a dimension.
     */
    public function getEmbeddingDimension(): ?int
    {
        return $this->embeddingDimension;
    }

    public function setEmbeddingDimension(int $embeddingDimension): self
    {
        $this->embeddingDimension = $embeddingDimension;

        return $this;
    }

    /**
     * Registered name (vector label) of the target model of a running
     * reindex; null without one.
     */
    public function getPendingEmbeddingModel(): ?string
    {
        return $this->pendingEmbeddingModel;
    }

    public function setPendingEmbeddingModel(?string $pendingEmbeddingModel): self
    {
        $this->pendingEmbeddingModel = $pendingEmbeddingModel;

        return $this;
    }

    /**
     * The model catalogue entry of the embedding model; null only for a new
     * collection before its reference was resolved. A stored collection
     * always has one.
     */
    public function getEmbeddingProviderModel(): ?ProviderModel
    {
        return $this->embeddingProviderModel;
    }

    /**
     * Sets the catalogue entry and with it the embedding connection (its
     * connection). There is no way to clear it: the column is NOT NULL.
     * The label ({@see setEmbeddingModel()}) is set apart: it is the
     * registered name that matches the entry, which the entity cannot look
     * up.
     */
    public function setEmbeddingProviderModel(ProviderModel $entry): self
    {
        $this->embeddingProviderConnection = $entry->getApiConfiguration();
        $this->embeddingProviderModel = $entry;

        return $this;
    }

    /**
     * Target entry of a running reindex; null without one.
     */
    public function getPendingEmbeddingProviderModel(): ?ProviderModel
    {
        return $this->pendingEmbeddingProviderModel;
    }

    /**
     * Sets the target entry of a reindex and, for a non-null entry, the
     * pending connection (its connection). Null clears the entry only.
     */
    public function setPendingEmbeddingProviderModel(?ProviderModel $entry): self
    {
        if (null !== $entry) {
            $this->pendingEmbeddingProviderConnection = $entry->getApiConfiguration();
        }
        $this->pendingEmbeddingProviderModel = $entry;

        return $this;
    }

    /**
     * The IRI of the catalogue entry; without an entry the reference given
     * on input (null without one), so a new collection shows what it was
     * given until the reference is resolved.
     */
    public function getEmbeddingModelReference(): ?string
    {
        return $this->embeddingProviderModel?->iri() ?? $this->embeddingModelReference;
    }

    /**
     * The input reference (IRI or full or partial UUID of a catalogue
     * entry); resolved before persist, and on an update only accepted when
     * it names the current entry.
     */
    public function setEmbeddingModelReference(?string $reference): self
    {
        $this->embeddingModelReference = $reference;

        return $this;
    }

    /**
     * The unresolved input reference only (null when none was given); not a
     * getter, so the serializer does not see it.
     */
    public function embeddingModelInput(): ?string
    {
        return $this->embeddingModelReference;
    }

    /**
     * The embedding connection; null only for a new collection before the
     * reference was resolved.
     */
    public function getEmbeddingProviderConnection(): ?ApiConfiguration
    {
        return $this->embeddingProviderConnection;
    }

    /**
     * Sets the embedding connection directly. Prefer
     * {@see setEmbeddingProviderModel()}, which takes it from the catalogue
     * entry; kept for fixtures.
     */
    public function setEmbeddingProviderConnection(?ApiConfiguration $connection): self
    {
        $this->embeddingProviderConnection = $connection;

        return $this;
    }

    /**
     * Target connection of a running reindex; null without one.
     */
    public function getPendingEmbeddingProviderConnection(): ?ApiConfiguration
    {
        return $this->pendingEmbeddingProviderConnection;
    }

    /**
     * Sets the pending connection directly. Prefer
     * {@see setPendingEmbeddingProviderModel()}; kept for fixtures.
     */
    public function setPendingEmbeddingProviderConnection(?ApiConfiguration $connection): self
    {
        $this->pendingEmbeddingProviderConnection = $connection;

        return $this;
    }

    /**
     * The name of the embedding connection, null without one. Read-only.
     */
    #[ApiProperty(
        description: 'The name of the `ollama` provider connection the collection\'s vectors are computed through: the connection of the catalogue entry `embeddingModel`. Read-only. It changes only with a reindex to a model of another connection.',
        writable: false,
        example: 'ollama-local',
    )]
    public function getEmbeddingConnection(): ?string
    {
        return $this->embeddingProviderConnection?->getName();
    }

    /**
     * IRI of the target entry of a running reindex (read-only), serialized
     * as `pendingEmbeddingModel`.
     */
    #[ApiProperty(
        description: 'The target model of a running reindex: the IRI of its catalogue entry (`/api/refs/provider_models/<uuid>`). Read-only.',
        writable: false,
    )]
    #[SerializedName('pendingEmbeddingModel')]
    public function getPendingEmbeddingModelReference(): ?string
    {
        return $this->pendingEmbeddingProviderModel?->iri();
    }

    /**
     * Name of the target connection of a running reindex (read-only).
     */
    #[ApiProperty(
        description: 'Name of the target connection of a running reindex: the connection of `pendingEmbeddingModel`. Read-only.',
        writable: false,
    )]
    public function getPendingEmbeddingConnection(): ?string
    {
        return $this->pendingEmbeddingProviderConnection?->getName();
    }

    public function getReindexJobId(): ?string
    {
        return $this->reindexJobId;
    }

    public function setReindexJobId(?string $reindexJobId): self
    {
        $this->reindexJobId = $reindexJobId;

        return $this;
    }

    public function getKeywordLanguage(): string
    {
        return $this->keywordLanguage;
    }

    public function setKeywordLanguage(string $language): self
    {
        $this->keywordLanguage = $language;

        return $this;
    }

    /**
     * Whether the collection is locked, as loaded into this entity. Writers
     * that may hold a stale entity (messenger workers) ask
     * {@see \Dmstr\NeuronAiBundle\Knowledge\CollectionLockGuard}, which
     * reads the database.
     */
    #[ApiProperty(
        description: 'Whether the collection is locked (`lockedAt` is set). Read-only.',
        writable: false,
    )]
    public function isLocked(): bool
    {
        return null !== $this->lockedAt;
    }

    public function getLockedAt(): ?\DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function getLockedBy(): ?string
    {
        return $this->lockedBy;
    }

    /**
     * Locks the collection for the given actor at the given time; a locked
     * collection keeps its original lock (idempotent).
     */
    public function lock(string $actor, \DateTimeImmutable $at): void
    {
        if ($this->isLocked()) {
            return;
        }
        $this->lockedAt = $at;
        $this->lockedBy = $actor;
    }

    /**
     * Unlocks the collection; an unlocked collection stays as it is.
     */
    public function unlock(): void
    {
        if (!$this->isLocked()) {
            return;
        }
        $this->lockedAt = null;
        $this->lockedBy = null;
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
// - revised 2026-10-09
// - revised 2026-10-13
// - revised 2026-10-13 20:10:00 UTC
