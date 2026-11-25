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
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use Dmstr\NeuronAiBundle\Evaluation\EvaluationStatus;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Evaluation\Verdict;
use Dmstr\NeuronAiBundle\OpenApi\OpenApiTag;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection as DoctrineCollection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One judgement of an {@see EvalResult} by the judge agent: the
 * judge compared the actual answer of the result's conversation with the
 * question's expected answer and returned a {@see Verdict}, its reasoning
 * and improvement {@see Suggestion}s.
 *
 * - evalResult: the judged result; the evaluation is removed with it
 *   (cascade). A result may be evaluated several times; its `evaluation`
 *   points to the latest one;
 * - status: {@see EvaluationStatus}, `errorMessage` when failed (judge not
 *   runnable, `schema_errors` or a verdict-schema violation);
 * - verdict, reasoning: the judge's answer, set when completed;
 * - judgeAgentVersion: the published judge version that answered; set to
 *   null if that version is ever deleted;
 * - model, tokensInput, tokensOutput, durationMs: measurements of the
 *   judge call; `model` is the name the judge was called with
 *   (serialized as `modelName`), providerModel the judge version's model
 *   catalogue entry ({@see ProviderModel}, serialized as its IRI in
 *   `model`; foreign key ON DELETE SET NULL, so the name stays when the
 *   entry goes with its connection);
 * - jobId: id of the `Job` of dmstr/symfony-job-queue-bundle that runs the
 *   evaluation (no foreign key, like `EvalRun::jobId`);
 * - suggestions: read-only inverse side; suggestions are written by the
 *   judge and removed with the evaluation (cascade in the database).
 *
 * Every field is written by the judge job; the API is read-only.
 *
 * Operations: `GET /evaluations` (filters `evalResult`, `status`,
 * `verdict`, order `createdAt`) and `GET /evaluations/{id}` for
 * `neuronai:eval:read`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'neuron_evaluation')]
#[ORM\Index(name: 'idx_neuron_evaluation_eval_result', columns: ['eval_result_id'])]
#[ORM\Index(name: 'idx_neuron_evaluation_status', columns: ['status'])]
#[ORM\Index(name: 'idx_neuron_evaluation_judge_agent_version', columns: ['judge_agent_version_id'])]
#[ORM\Index(name: 'idx_neuron_evaluation_provider_model', columns: ['provider_model_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('neuronai:eval:read')",
        ),
        new Get(
            security: "is_granted('neuronai:eval:read')",
        ),
    ],
    description: <<<'MD'
        An evaluation is the AI judge's assessment of one evaluation result: the judge compares the agent's actual answer with the expected answer and returns a verdict (from pass to fail), its reasoning and suggestions for improvement.

        Evaluations are written by the judge and are read-only. A result can be evaluated several times; the latest evaluation is the one shown on the result.

        Related: an evaluation judges an **EvalResult**; the judge is itself an agent, answering with one of its versions (**AgentVersion**). The improvement proposals are listed under **Suggestion**, the background work as **Job**.
        MD,
    normalizationContext: ['groups' => [self::GROUP_READ]],
    order: ['createdAt' => 'DESC'],
    paginationEnabled: true,
    paginationItemsPerPage: 50,
    openapi: new OpenApiOperation(tags: [OpenApiTag::AGENTS]),
)]
#[ApiFilter(SearchFilter::class, properties: ['evalResult' => 'exact', 'judgeAgentVersion' => 'exact', 'status' => 'exact', 'verdict' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'])]
class Evaluation
{
    public const string GROUP_READ = 'evaluation:read';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[Groups([self::GROUP_READ])]
    private Uuid $id;

    /** The judged result; serialized as IRI. */
    #[ORM\ManyToOne(targetEntity: EvalResult::class)]
    #[ORM\JoinColumn(name: 'eval_result_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Groups([self::GROUP_READ])]
    private ?EvalResult $evalResult = null;

    /** One of {@see EvaluationStatus::ALL}. */
    #[ORM\Column(type: Types::STRING, length: 20, options: ['default' => EvaluationStatus::PENDING])]
    #[Assert\Choice(choices: EvaluationStatus::ALL)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private string $status = EvaluationStatus::PENDING;

    /** One of {@see Verdict::ALL}; null until the judge answered. */
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    #[Assert\Choice(choices: Verdict::ALL)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?string $verdict = null;

    /** The judge's explanation of the verdict. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[ApiProperty(writable: false, openapiContext: ['x-format' => 'textarea', 'x-grid' => ['columnsMd' => 12, 'newRow' => true]])]
    #[Groups([self::GROUP_READ])]
    private ?string $reasoning = null;

    /** The judge version that answered; null once deleted. Serialized as IRI. */
    #[ORM\ManyToOne(targetEntity: AgentVersion::class)]
    #[ORM\JoinColumn(name: 'judge_agent_version_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?AgentVersion $judgeAgentVersion = null;

    /** Name of the model the judge call used; serialized as `modelName`. */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[ApiProperty(
        description: 'The name of the model the judge call used, as the provider lists it. It stays when the catalogue entry `model` is gone.',
        writable: false,
        example: 'claude-haiku-4-5-20251001',
    )]
    #[SerializedName('modelName')]
    #[Groups([self::GROUP_READ])]
    private ?string $model = null;

    /**
     * The model catalogue entry of the judge call (the judge version's
     * `model`); serialized as its IRI in `model`
     * ({@see getModelReference()}). Set to null by the database when the
     * entry is deleted (with its provider connection).
     */
    #[ORM\ManyToOne(targetEntity: ProviderModel::class)]
    #[ORM\JoinColumn(name: 'provider_model_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[ApiProperty(readable: false, writable: false)]
    private ?ProviderModel $providerModel = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?int $tokensInput = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?int $tokensOutput = null;

    /** Wall-clock duration of the judge call in milliseconds. */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?int $durationMs = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[ApiProperty(writable: false, openapiContext: ['x-format' => 'textarea', 'x-grid' => ['columnsMd' => 12, 'newRow' => true]])]
    #[Groups([self::GROUP_READ])]
    private ?string $errorMessage = null;

    /** Id of the job queue job that runs the evaluation (no foreign key). */
    #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?string $jobId = null;

    /**
     * The judge's suggestions; read-only inverse side, serialized as IRIs.
     *
     * @var DoctrineCollection<int, Suggestion>
     */
    #[ORM\OneToMany(targetEntity: Suggestion::class, mappedBy: 'evaluation')]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private DoctrineCollection $suggestions;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[Groups([self::GROUP_READ])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[Groups([self::GROUP_READ])]
    private \DateTimeImmutable $updatedAt;

    /** Set when the evaluation ended (`completed` or `failed`). */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->suggestions = new ArrayCollection();
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
     * @throws \LogicException when the result was not set yet (a new evaluation only)
     */
    public function getEvalResult(): EvalResult
    {
        return $this->evalResult ?? throw new \LogicException('The result of the evaluation is not set.');
    }

    public function setEvalResult(EvalResult $evalResult): self
    {
        $this->evalResult = $evalResult;

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

    public function getVerdict(): ?string
    {
        return $this->verdict;
    }

    public function setVerdict(?string $verdict): self
    {
        $this->verdict = $verdict;

        return $this;
    }

    public function getReasoning(): ?string
    {
        return $this->reasoning;
    }

    public function setReasoning(?string $reasoning): self
    {
        $this->reasoning = $reasoning;

        return $this;
    }

    public function getJudgeAgentVersion(): ?AgentVersion
    {
        return $this->judgeAgentVersion;
    }

    public function setJudgeAgentVersion(?AgentVersion $judgeAgentVersion): self
    {
        $this->judgeAgentVersion = $judgeAgentVersion;

        return $this;
    }

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
     * The catalogue entry of the judge call; null without one.
     */
    public function getProviderModel(): ?ProviderModel
    {
        return $this->providerModel;
    }

    /**
     * Sets the catalogue entry only; the model name is set on its own,
     * because the evaluation keeps it when the entry is gone.
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
        description: 'The model the judge call used: the IRI of its entry in the model catalogue (`/api/refs/provider_models/<uuid>`). Null before the judge answered and when the entry is gone (deleted with its provider connection) or was never known; `modelName` keeps the name.',
        writable: false,
        example: '/api/refs/provider_models/0199a1b2-0000-4000-8000-000000000000',
    )]
    #[SerializedName('model')]
    #[Groups([self::GROUP_READ])]
    public function getModelReference(): ?string
    {
        return $this->providerModel?->iri();
    }

    public function getTokensInput(): ?int
    {
        return $this->tokensInput;
    }

    public function setTokensInput(?int $tokensInput): self
    {
        $this->tokensInput = $tokensInput;

        return $this;
    }

    public function getTokensOutput(): ?int
    {
        return $this->tokensOutput;
    }

    public function setTokensOutput(?int $tokensOutput): self
    {
        $this->tokensOutput = $tokensOutput;

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

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

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

    /**
     * The suggestions as loaded from the database; a suggestion created in
     * the same unit of work appears here only after a reload (the owning
     * side is {@see Suggestion::setEvaluation()}).
     *
     * @return DoctrineCollection<int, Suggestion>
     */
    public function getSuggestions(): DoctrineCollection
    {
        return $this->suggestions;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): self
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }
}

// - revised 2026-10-07
