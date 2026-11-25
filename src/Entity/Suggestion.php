<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:23:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use Dmstr\NeuronAiBundle\Evaluation\SuggestionPriority;
use Dmstr\NeuronAiBundle\Evaluation\SuggestionStatus;
use Dmstr\NeuronAiBundle\Evaluation\SuggestionType;
use Dmstr\NeuronAiBundle\OpenApi\OpenApiTag;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One improvement the judge proposes in an {@see Evaluation},
 * e.g. a missing document (`type` `data`) or an instruction of the agent
 * version (`type` `prompt`).
 *
 * - type, category, description, action, priority: the judge's suggestion
 *   as `verdict.schema.json` describes it; written by the judge job only;
 * - status: processing state ({@see SuggestionStatus}), `open` initially;
 * - assignee: free-text name or user identifier of whoever takes care of
 *   it (no foreign key).
 *
 * Suggestions are removed with their evaluation (cascade).
 *
 * Operations: `GET /suggestions` (filters `evaluation`, `type`, `status`,
 * `assignee`, `priority`) and `GET /suggestions/{id}` for `neuronai:eval:read`;
 * `PATCH /suggestions/{id}` for `neuronai:eval:write`, which changes `status`
 * and `assignee` only. Like the read-only fields of the other resources of
 * the bundle, every other property of the PATCH body is ignored (not part
 * of the write group), not rejected.
 */
#[ORM\Entity]
#[ORM\Table(name: 'neuron_suggestion')]
#[ORM\Index(name: 'idx_neuron_suggestion_evaluation', columns: ['evaluation_id'])]
#[ORM\Index(name: 'idx_neuron_suggestion_status', columns: ['status'])]
#[ORM\Index(name: 'idx_neuron_suggestion_assignee', columns: ['assignee'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('neuronai:eval:read')",
        ),
        new Get(
            security: "is_granted('neuronai:eval:read')",
        ),
        new Patch(
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::AGENTS],
                summary: 'Updates the processing status and the assignee of a suggestion.',
                description: 'JSON merge patch (`application/merge-patch+json`) of `status` (one of `open`, `fixed`, `wontfix`) and `assignee` (at most 255 characters, null to unassign). Every other property of the body (`type`, `category`, `description`, `action`, `priority`, `evaluation`, timestamps) is ignored and keeps its value; the judge\'s suggestion itself cannot be changed. Rejected with 422 for another status or a longer assignee.',
            ),
            security: "is_granted('neuronai:eval:write')",
        ),
    ],
    description: <<<'MD'
        A suggestion is an improvement the judge proposes when it evaluates an answer, for example a document that is missing from the knowledge (type data) or an instruction of the agent that should change (type prompt). Its content comes from the judge and cannot be edited.

        You work through suggestions here: set the status (open, fixed or won't fix) and an assignee who takes care of it. Filter, for example, for all open suggestions of high priority.

        Related: each suggestion belongs to an **Evaluation**, the judge's assessment of one **EvalResult** of an **EvalRun**.
        MD,
    normalizationContext: ['groups' => [self::GROUP_READ]],
    denormalizationContext: ['groups' => [self::GROUP_WRITE]],
    order: ['createdAt' => 'ASC'],
    paginationEnabled: true,
    paginationItemsPerPage: 50,
    openapi: new OpenApiOperation(tags: [OpenApiTag::AGENTS]),
)]
#[ApiFilter(SearchFilter::class, properties: ['evaluation' => 'exact', 'type' => 'exact', 'status' => 'exact', 'assignee' => 'exact', 'priority' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'])]
class Suggestion
{
    public const string GROUP_READ = 'suggestion:read';
    public const string GROUP_WRITE = 'suggestion:write';

    public const int MAX_CATEGORY_LENGTH = 100;
    public const int MAX_ASSIGNEE_LENGTH = 255;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[Groups([self::GROUP_READ])]
    private Uuid $id;

    /** The evaluation that proposed it; serialized as IRI. */
    #[ORM\ManyToOne(targetEntity: Evaluation::class, inversedBy: 'suggestions')]
    #[ORM\JoinColumn(name: 'evaluation_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?Evaluation $evaluation = null;

    /** One of {@see SuggestionType::ALL}. */
    #[ORM\Column(type: Types::STRING, length: 20)]
    #[Assert\Choice(choices: SuggestionType::ALL)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private string $type = SuggestionType::DATA;

    /** The judge's free-text category, e.g. `missing-document`. */
    #[ORM\Column(type: Types::STRING, length: self::MAX_CATEGORY_LENGTH)]
    #[Assert\Length(max: self::MAX_CATEGORY_LENGTH)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private string $category = '';

    /** What is wrong or missing. */
    #[ORM\Column(type: Types::TEXT)]
    #[ApiProperty(writable: false, openapiContext: ['x-format' => 'textarea', 'x-grid' => ['columnsMd' => 12, 'newRow' => true]])]
    #[Groups([self::GROUP_READ])]
    private string $description = '';

    /** What should be done about it. */
    #[ORM\Column(type: Types::TEXT)]
    #[ApiProperty(writable: false, openapiContext: ['x-format' => 'textarea', 'x-grid' => ['columnsMd' => 12, 'newRow' => true]])]
    #[Groups([self::GROUP_READ])]
    private string $action = '';

    /** One of {@see SuggestionPriority::ALL}. */
    #[ORM\Column(type: Types::STRING, length: 20)]
    #[Assert\Choice(choices: SuggestionPriority::ALL)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private string $priority = SuggestionPriority::MEDIUM;

    /** One of {@see SuggestionStatus::ALL}; writable by `neuronai:eval:write`. */
    #[ORM\Column(type: Types::STRING, length: 20, options: ['default' => SuggestionStatus::OPEN])]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: SuggestionStatus::ALL)]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private string $status = SuggestionStatus::OPEN;

    /** Who takes care of it (free text, no foreign key); writable by `neuronai:eval:write`. */
    #[ORM\Column(type: Types::STRING, length: self::MAX_ASSIGNEE_LENGTH, nullable: true)]
    #[Assert\Length(max: self::MAX_ASSIGNEE_LENGTH)]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private ?string $assignee = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[Groups([self::GROUP_READ])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[Groups([self::GROUP_READ])]
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

    /**
     * @throws \LogicException when the evaluation was not set yet (a new suggestion only)
     */
    public function getEvaluation(): Evaluation
    {
        return $this->evaluation ?? throw new \LogicException('The evaluation of the suggestion is not set.');
    }

    public function setEvaluation(Evaluation $evaluation): self
    {
        $this->evaluation = $evaluation;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): self
    {
        $this->action = $action;

        return $this;
    }

    public function getPriority(): string
    {
        return $this->priority;
    }

    public function setPriority(string $priority): self
    {
        $this->priority = $priority;

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

    public function getAssignee(): ?string
    {
        return $this->assignee;
    }

    public function setAssignee(?string $assignee): self
    {
        $this->assignee = $assignee;

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
