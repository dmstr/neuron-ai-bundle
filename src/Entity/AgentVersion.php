<?php

// file generated with AI assistance: Claude Code - 2026-10-02 21:49:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use Dmstr\NeuronAiBundle\Agent\AgentVersionKind;
use Dmstr\NeuronAiBundle\OpenApi\OpenApiTag;
use Dmstr\OpenApiJsonSchema\Attribute\JsonSchema;
use Dmstr\OpenApiJsonSchema\Interface\JsonSchemaProviderInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * An immutable version of an {@see Agent}: the agent's candidate at
 * publish time with all references resolved (the model as the full UUID
 * of its catalogue entry, collections and MCP connections as full UUIDs),
 * in the shape of `Entity/Agent/candidate.json`
 * ({@see \Dmstr\NeuronAiBundle\Agent\AgentConfiguration}).
 *
 * Immutability is enforced by construction, not by the framework: all
 * fields are set in the constructor, there is no setter, the API offers
 * only GET operations and the foreign key to the agent has no cascade.
 * Schema migrations may still rewrite the stored configuration once, as
 * `Version20261008180000` did for the model reference.
 * Published versions are created by
 * {@see \Dmstr\NeuronAiBundle\Agent\AgentPublisher} with the next
 * `versionNumber` of the agent (unique per agent among the published
 * versions, partial unique index `uniq_neuron_agent_version_agent_number`).
 *
 * `kind` ({@see AgentVersionKind}) tells published versions from previews.
 * A preview is the frozen candidate for a test chat or an evaluation
 * before publishing, created by
 * {@see \Dmstr\NeuronAiBundle\Agent\AgentPreviewFactory}: `versionNumber`
 * 0, `candidateHash` the sha256 of its configuration (unique per agent
 * among the previews, partial unique index
 * `uniq_neuron_agent_version_agent_hash`), so an unchanged candidate reuses
 * its preview. A preview is never the current version
 * ({@see Agent::setCurrentVersion()}), no rollback or replay target, hidden
 * from the collection unless `?kind=preview` is given
 * ({@see \Dmstr\NeuronAiBundle\Doctrine\PublishedVersionExtension}),
 * readable as an item, and removed by the purge after the retention. The
 * constructor enforces number and hash per kind, so the two invariants
 * cannot be broken by a caller.
 *
 * References may become stale after publishing (a collection or tool is
 * removed later); that is expected and reported when a turn uses the
 * version.
 */
#[ORM\Entity]
#[ORM\Table(name: 'neuron_agent_version')]
// Partial indexes: the `where` text is what PostgreSQL returns for the
// predicate (pg_get_expr), so the schema tool compares equal strings and
// reports no diff; the migration Version20261013210000 creates them.
#[ORM\UniqueConstraint(name: 'uniq_neuron_agent_version_agent_number', columns: ['agent_id', 'version_number'], options: ['where' => self::WHERE_PUBLISHED])]
#[ORM\UniqueConstraint(name: 'uniq_neuron_agent_version_agent_hash', columns: ['agent_id', 'candidate_hash'], options: ['where' => self::WHERE_PREVIEW])]
#[ORM\Index(name: 'idx_neuron_agent_version_agent', columns: ['agent_id'])]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
    ],
    description: <<<'MD'
        A version is a published, unchangeable snapshot of an agent's configuration: instructions, model, knowledge collections and tools as they were when the agent was published.

        Versions are created only by publishing an agent and cannot be edited or deleted here; this list is read-only. Chats use the agent's current version, and an earlier version can be made current again from the agent (rollback). If a collection or tool is removed after publishing, the version still refers to it, and this is reported when the version is used.

        A preview (`kind: preview`, `versionNumber` 0) is the frozen candidate for a test chat or an evaluation before publishing. It is not published, never becomes the current version and cannot be rolled back to. The list shows only published versions unless `?kind=preview` is given; a preview stays readable by its id until the purge removes it after the retention period.

        Related: every version belongs to an **Agent**. Its model comes from **ProviderModel** through a provider connection (**ApiConfiguration**), its knowledge from **Collection**, its tools from **McpTool**. Evaluation runs (**EvalRun**) test a specific version.
        MD,
    normalizationContext: ['groups' => [self::GROUP_READ]],
    security: "is_granted('neuronai:agent:read')",
    order: ['versionNumber' => 'ASC'],
    paginationEnabled: true,
    paginationItemsPerPage: 30,
    openapi: new OpenApiOperation(tags: [OpenApiTag::AGENTS]),
)]
#[ApiFilter(SearchFilter::class, properties: ['agent' => 'exact', 'kind' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['versionNumber'])]
class AgentVersion implements JsonSchemaProviderInterface
{
    public const string GROUP_READ = 'agent_version:read';

    public const int MAX_PUBLISHED_BY_LENGTH = 190;

    /** Length of `candidateHash`: a sha256 in hex. */
    public const int CANDIDATE_HASH_LENGTH = 64;

    /** Predicates of the partial unique indexes, as PostgreSQL renders them. */
    public const string WHERE_PUBLISHED = "((kind)::text = 'published'::text)";
    public const string WHERE_PREVIEW = "((kind)::text = 'preview'::text)";

    /** Description of `configuration` in the OpenAPI schema. */
    public const string CONFIGURATION_DESCRIPTION = 'The frozen configuration of this version, the one conversations and agent chats run on; read-only. Same shape as the agent candidate with every key present; the references (model, collections, MCP connections) are stored as full UUIDs; the model entry names the provider connection.';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[Groups([self::GROUP_READ])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(name: 'agent_id', referencedColumnName: 'id', nullable: false)]
    #[Groups([self::GROUP_READ])]
    private Agent $agent;

    /**
     * 1 for the first published version of the agent, then consecutive;
     * 0 for every preview.
     */
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups([self::GROUP_READ])]
    private int $versionNumber;

    /**
     * The frozen configuration, all keys of `Entity/Agent/candidate.json`
     * present.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    #[JsonSchema]
    #[Groups([self::GROUP_READ])]
    private array $configuration;

    /** When the version was published; for a preview, when it was created. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[Groups([self::GROUP_READ])]
    private \DateTimeImmutable $publishedAt;

    /**
     * User identifier of the token that published the version; for a
     * preview, of the token that created it.
     */
    #[ORM\Column(type: Types::STRING, length: self::MAX_PUBLISHED_BY_LENGTH)]
    #[Groups([self::GROUP_READ])]
    private string $publishedBy;

    /** `published` or `preview` (see the class comment). */
    #[ORM\Column(type: Types::STRING, length: 16, enumType: AgentVersionKind::class, options: ['default' => 'published'])]
    #[Groups([self::GROUP_READ])]
    private AgentVersionKind $kind;

    /**
     * sha256 (hex) of the configuration of a preview
     * ({@see \Dmstr\NeuronAiBundle\Agent\AgentPreviewFactory::hash()}); null for
     * a published version.
     */
    #[ORM\Column(type: Types::STRING, length: self::CANDIDATE_HASH_LENGTH, nullable: true)]
    #[Groups([self::GROUP_READ])]
    private ?string $candidateHash;

    /**
     * A published version has `versionNumber` >= 1 and no hash, a preview
     * `versionNumber` 0 and the hash of its configuration; anything else is
     * refused, so the partial unique indexes always see consistent rows.
     *
     * @param array<string, mixed> $configuration
     * @param string               $publishedBy   user identifier of the publishing (preview: creating) token
     * @param \DateTimeImmutable|null $publishedAt publish (preview: creation) time, now when null
     * @param string|null          $candidateHash sha256 hex of the configuration, previews only
     *
     * @throws \InvalidArgumentException when number or hash do not fit the kind
     */
    public function __construct(
        Agent $agent,
        int $versionNumber,
        array $configuration,
        string $publishedBy,
        ?\DateTimeImmutable $publishedAt = null,
        AgentVersionKind $kind = AgentVersionKind::PUBLISHED,
        ?string $candidateHash = null,
    ) {
        if (AgentVersionKind::PREVIEW === $kind) {
            if (0 !== $versionNumber) {
                throw new \InvalidArgumentException(\sprintf('A preview has version number 0, %d given.', $versionNumber));
            }
            if (null === $candidateHash || 1 !== preg_match('/^[0-9a-f]{64}$/', $candidateHash)) {
                throw new \InvalidArgumentException('A preview needs the sha256 hex hash of its configuration.');
            }
        } else {
            if ($versionNumber < 1) {
                throw new \InvalidArgumentException(\sprintf('A published version has a version number of at least 1, %d given.', $versionNumber));
            }
            if (null !== $candidateHash) {
                throw new \InvalidArgumentException('A published version has no candidate hash.');
            }
        }

        $this->id = Uuid::v4();
        $this->kind = $kind;
        $this->candidateHash = $candidateHash;
        $this->agent = $agent;
        $this->versionNumber = $versionNumber;
        $this->configuration = $configuration;
        $this->publishedBy = $publishedBy;
        $this->publishedAt = $publishedAt ?? new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAgent(): Agent
    {
        return $this->agent;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    public function getPublishedAt(): \DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getPublishedBy(): string
    {
        return $this->publishedBy;
    }

    public function getKind(): AgentVersionKind
    {
        return $this->kind;
    }

    public function isPreview(): bool
    {
        return AgentVersionKind::PREVIEW === $this->kind;
    }

    public function getCandidateHash(): ?string
    {
        return $this->candidateHash;
    }

    /**
     * Short human-readable name: `v<n>` for a published version,
     * `preview <first 8 hex digits of the hash>` for a preview (all
     * previews have number 0, so the number alone does not tell them apart).
     */
    public function label(): string
    {
        return $this->isPreview()
            ? 'preview '.substr((string) $this->candidateHash, 0, 8)
            : 'v'.$this->versionNumber;
    }

    /**
     * Schema of the `configuration` field for the OpenAPI description: the
     * candidate schema, read-only, with references stored as full UUIDs.
     *
     * @return array<string, mixed>|null
     */
    public static function getJsonSchema(string $fieldName): ?array
    {
        if ('configuration' !== $fieldName) {
            return null;
        }

        return ['description' => self::CONFIGURATION_DESCRIPTION] + Agent::candidateSchema();
    }
}

// - revised 2026-10-13
