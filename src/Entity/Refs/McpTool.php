<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:41:04 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Entity\Refs;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\OpenApi\OpenApiTag;
use Dmstr\NeuronAiBundle\State\McpToolScanProcessor;
use Dmstr\NeuronAiBundle\State\McpToolSyncProcessor;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * REF entity mirroring the tools an MCP server (an `mcp` connection, see
 * {@see \Dmstr\NeuronAiBundle\Mcp\McpExtension}) announces via `tools/list`.
 *
 * Rows are written only by the catalogue scan and sync
 * ({@see \Dmstr\NeuronAiBundle\Mcp\McpToolScanner}); the API is read-only
 * apart from the two actions:
 *  - `POST /refs/mcp_tools/scan` queues a job (type `mcp_tool_scan`) that
 *    mirrors all active connections or the one in the body: new tools are
 *    created, changed titles, descriptions, input schemas, annotations and
 *    `_meta` updated, vanished tools removed;
 *  - `POST /refs/mcp_tools/{id}/sync` refreshes one row synchronously; a tool
 *    the server no longer lists keeps its row and gets `lastError` (removal
 *    is the scan's job).
 *
 * Rows of a connection are deleted with the connection (FK ON DELETE CASCADE).
 * Rows for one connection:
 *
 *     $entityManager->getRepository(McpTool::class)
 *         ->findBy(['apiConfiguration' => $connection], ['name' => 'ASC']);
 */
#[ORM\Entity]
#[ORM\Table(name: 'neuron_mcp_tool')]
#[ORM\UniqueConstraint(name: 'uniq_neuron_mcp_tool_api_configuration_name', columns: ['api_configuration_id', 'name'])]
#[ORM\Index(name: 'idx_neuron_mcp_tool_api_configuration', columns: ['api_configuration_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(),
        new Post(
            uriTemplate: '/mcp_tools/scan',
            status: 201,
            description: 'Brings the tool catalogue up to date with all active MCP connections, or with one chosen connection: new tools are added, changed ones updated and tools that are no longer offered removed. The scan runs in the background; progress is shown in **Job**.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::CORE],
                summary: 'Queues a scan of the MCP tool catalogue.',
                description: 'Creates a job of type `mcp_tool_scan` that mirrors the tools of all active `mcp` connections, or of the connection given as `apiConfiguration`. The body is validated before the job is created.',
            ),
            security: "is_granted('neuronai:mcp_tool:write')",
            input: false,
            output: Job::class,
            read: false,
            deserialize: false,
            validate: false,
            name: self::OPERATION_SCAN,
            processor: McpToolScanProcessor::class,
        ),
        new Post(
            uriTemplate: '/mcp_tools/{id}/sync',
            status: 200,
            description: 'Refreshes the description and input of this tool from its MCP server right away. If the server no longer offers the tool, the entry stays and shows the error; removing it is left to the scan.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::CORE],
                summary: 'Refreshes one catalogue entry from its MCP server.',
                description: 'Updates description and input schema of the tool. A tool the server no longer lists keeps its entry and gets `lastError`; removal is done by the scan.',
            ),
            security: "is_granted('neuronai:mcp_tool:write')",
            input: false,
            deserialize: false,
            validate: false,
            name: self::OPERATION_SYNC,
            processor: McpToolSyncProcessor::class,
            throwOnNotFound: true,
        ),
    ],
    description: <<<'MD'
        The MCP tool catalogue lists the tools that the connected MCP servers offer, with their descriptions and input. Agents choose their tools from this list.

        The catalogue is a copy of what the servers announce and is not edited by hand: a scan brings it up to date, and a single entry can be refreshed on its own. Entries of a connection disappear when the connection is deleted.

        Related: each tool belongs to an MCP connection in **ApiConfiguration**. Tools are used by an **Agent**; a scan runs as a **Job**.
        MD,
    routePrefix: '/refs',
    security: "is_granted('neuronai:mcp_tool:read')",
    paginationEnabled: true,
    paginationItemsPerPage: 30,
    openapi: new OpenApiOperation(tags: [OpenApiTag::CORE]),
)]
#[ApiFilter(SearchFilter::class, properties: ['apiConfiguration' => 'exact', 'name' => 'exact'])]
class McpTool
{
    public const string OPERATION_SCAN = 'mcp_tool_scan';
    public const string OPERATION_SYNC = 'mcp_tool_sync';

    /** Job type of the asynchronous scan (dmstr_job.type is VARCHAR(50)). */
    public const string JOB_TYPE = 'mcp_tool_scan';

    public const int MAX_NAME_LENGTH = 190;

    /** Longer titles are cut by the scanner. */
    public const int MAX_TITLE_LENGTH = 255;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    /** The `mcp` connection that announces the tool. */
    #[ORM\ManyToOne(targetEntity: ApiConfiguration::class)]
    #[ORM\JoinColumn(name: 'api_configuration_id', nullable: false, onDelete: 'CASCADE')]
    private ApiConfiguration $apiConfiguration;

    /** Tool name as announced by the server, unique per connection. */
    #[ORM\Column(type: Types::STRING, length: self::MAX_NAME_LENGTH)]
    private string $name;

    /** Human-readable display name as announced by the server (MCP `title`), if any. */
    #[ORM\Column(type: Types::STRING, length: self::MAX_TITLE_LENGTH, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[ApiProperty(openapiContext: ['x-format' => 'textarea', 'x-grid' => ['columnsMd' => 12, 'newRow' => true]])]
    private ?string $description = null;

    /**
     * JSON schema of the tool arguments as announced by the server
     * (`type: object`, `properties`, `required`).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $inputSchema = null;

    /**
     * Behaviour hints as announced by the server (MCP `annotations`, e.g.
     * `readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`,
     * `title`); null when the server sends none or an empty object. Hints
     * only: they come from the server and are not a security guarantee.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $annotations = null;

    /**
     * Server-specific metadata of the tool (MCP `_meta`); null when the
     * server sends none or an empty object.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $meta = null;

    /** Last time the entry was confirmed by the server (scan or sync). */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $syncedAt;

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
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->syncedAt = $this->createdAt;
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

    public function getApiConfiguration(): ApiConfiguration
    {
        return $this->apiConfiguration;
    }

    public function getName(): string
    {
        return $this->name;
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
     * @return array<string, mixed>|null
     */
    public function getInputSchema(): ?array
    {
        return $this->inputSchema;
    }

    /**
     * @param array<string, mixed>|null $inputSchema
     */
    public function setInputSchema(?array $inputSchema): self
    {
        $this->inputSchema = $inputSchema;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAnnotations(): ?array
    {
        return $this->annotations;
    }

    /**
     * @param array<string, mixed>|null $annotations
     */
    public function setAnnotations(?array $annotations): self
    {
        $this->annotations = $annotations;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMeta(): ?array
    {
        return $this->meta;
    }

    /**
     * @param array<string, mixed>|null $meta
     */
    public function setMeta(?array $meta): self
    {
        $this->meta = $meta;

        return $this;
    }

    public function getSyncedAt(): \DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function setSyncedAt(\DateTimeImmutable $syncedAt): self
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

// - revised 2026-10-09 06:42:28 UTC
