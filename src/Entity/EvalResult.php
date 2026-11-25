<?php

// file generated with AI assistance: Claude Code - 2026-10-03 18:38:08 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\RequestBody as OpenApiRequestBody;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultSource;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultStatus;
use Dmstr\NeuronAiBundle\Evaluation\RetrievalMeasurement;
use Dmstr\NeuronAiBundle\Evaluation\RetrievalStatus;
use Dmstr\NeuronAiBundle\OpenApi\OpenApiTag;
use Dmstr\NeuronAiBundle\State\EvalResultEvaluateProcessor;
use Dmstr\NeuronAiBundle\State\EvalResultRateProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The outcome of one executed {@see EvalQuestion}: the question
 * ran as the first turn of a real {@see Conversation} (`origin` = `eval`)
 * on the run's agent version, and this record keeps the measurements of
 * that turn. The answer itself is the conversation's final assistant
 * message; it is not copied here. A result of a retrieval-only run
 * ({@see EvalRun::isRetrievalOnly()}) has no conversation and
 * no turn: it keeps only the retrieval measurement and the search time.
 *
 * - evalRun: the batch run (`source` = `batch`), or null for a single
 *   execution outside a run (`source` = `single`);
 * - conversation: set to null when the conversation is deleted, the result
 *   and its measurements stay; always null in a retrieval-only run;
 * - status: {@see EvalResultStatus}, `errorMessage` when failed;
 * - durationMs, tokensInput, tokensOutput, interactions (tool calls of the
 *   turn): measurements of the turn; in a retrieval-only run `durationMs`
 *   is the time of the retrieval search and the other three stay null;
 * - evaluation: the latest judge {@see Evaluation} of this result;
 *   set to null when that evaluation is deleted. Earlier evaluations
 *   stay reachable through `GET /evaluations?evalResult=…`;
 * - humanRating, humanRatedBy, humanRatedAt: the reviewer's rating (validated
 *   against the `human_rating_schema` setting), who rated and when; written
 *   by the rate operation, read-only here;
 * - questionFingerprint: sha256 hex digest of the question text, a NUL
 *   byte and the expected answer when the result was recorded
 *   ({@see self::fingerprintOf()}); the import updates a
 *   question in place, so the run comparison detects a changed question
 *   by this value. Null for results recorded before fingerprints existed;
 * - retrievalStatus, recallHit, firstRelevantRank: the retrieval
 *   measurement ({@see RetrievalMeasurement}, see the
 *   properties).
 *
 * The unique index on (`eval_run_id`, `eval_question_id`) is the
 * idempotence guarantee of the batch job: a redelivered job skips the
 * questions that already have a result in its run. PostgreSQL treats NULLs
 * as distinct, so single executions are not constrained.
 *
 * Operations: `GET /eval_results` (filters `evalRun`, `evalQuestion`,
 * `status`, `source`) and `GET /eval_results/{id}` for `neuronai:eval:read`;
 * `POST /eval_results/{id}/evaluate` ({@see EvalResultEvaluateProcessor},
 * job type `eval_result_evaluate`) for `neuronai:eval:write`;
 * `POST /eval_results/{id}/rate` ({@see EvalResultRateProcessor}) for
 * `neuronai:eval:rate`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'neuron_eval_result')]
#[ORM\UniqueConstraint(name: 'uniq_neuron_eval_result_run_question', columns: ['eval_run_id', 'eval_question_id'])]
#[ORM\Index(name: 'idx_neuron_eval_result_run', columns: ['eval_run_id'])]
#[ORM\Index(name: 'idx_neuron_eval_result_question', columns: ['eval_question_id'])]
#[ORM\Index(name: 'idx_neuron_eval_result_conversation', columns: ['conversation_id'])]
#[ORM\Index(name: 'idx_neuron_eval_result_evaluation', columns: ['evaluation_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('neuronai:eval:read')",
        ),
        new Get(
            security: "is_granted('neuronai:eval:read')",
        ),
        new Post(
            uriTemplate: '/eval_results/{id}/evaluate',
            status: 202,
            description: 'Asks the AI judge to compare the answer of this result with the expected answer. The judge works in the background (progress in **Job**) and stores its verdict, reasoning and improvement suggestions as a new **Evaluation**, which becomes the latest evaluation of the result. Only completed results whose conversation still exists can be evaluated.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::AGENTS],
                responses: [
                    '202' => new OpenApiResponse(description: 'A new evaluation with status `pending` was stored as the result\'s `evaluation` and its judge job was queued; the body is the evaluation (as `GET /evaluations/{id}`) with `jobId`.'),
                ],
                summary: 'Asks the AI judge to evaluate an evaluation result.',
                description: 'Creates a pending evaluation of the result, makes it the result\'s latest `evaluation` and queues a job of type `eval_result_evaluate`. The job runs the current published version of the agent named by the setting `evaluation.judge_agent` with the question, the expected answer and the transcript of the result\'s conversation, and validates the answer against the verdict schema of the bundle (`verdict` `pass`, `mostly_pass`, `mostly_fail` or `fail`, `reasoning`, `suggestions` of type `data` or `prompt`), whatever response schema the judge version declares. A valid answer completes the evaluation with verdict, reasoning, judge version, model, tokens and duration and stores one suggestion per entry; an invalid answer or a failing judge call ends it `failed` with `errorMessage` and no suggestions, and the result itself stays unchanged. No body. Rejected with 422, before an evaluation or job exists, when the result is not `completed` or its conversation was deleted, and when the setting is empty or names an unknown or disabled agent or one without a published version.',
            ),
            normalizationContext: ['groups' => [Evaluation::GROUP_READ]],
            security: "is_granted('neuronai:eval:write')",
            input: false,
            output: Evaluation::class,
            read: true,
            deserialize: false,
            validate: false,
            name: self::OPERATION_EVALUATE,
            processor: EvalResultEvaluateProcessor::class,
            throwOnNotFound: true,
        ),
        new Post(
            uriTemplate: '/eval_results/{id}/rate',
            status: 200,
            description: 'Stores your own rating of this result. The form of the rating is defined by the operator\'s rating schema; a new rating replaces the previous one and records who rated and when.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::AGENTS],
                responses: [
                    '200' => new OpenApiResponse(description: 'The rating was stored as the result\'s `humanRating` with `humanRatedBy` and `humanRatedAt`; the body is the result.'),
                    '400' => new OpenApiResponse(description: 'The body is not valid JSON or not a JSON object.'),
                    '422' => new OpenApiResponse(description: 'The setting `evaluation.human_rating_schema` is empty or not a valid JSON Schema (the detail names the setting), or the rating does not match it (one violation per error, `propertyPath` = JSON pointer of the offending value).'),
                ],
                summary: 'Stores a reviewer\'s rating of an evaluation result.',
                description: 'The body is the rating document itself, a JSON object that must match the JSON Schema of the setting `evaluation.human_rating_schema` (default draft 2020-12), which the operator defines. A valid rating is stored as the result\'s `humanRating` together with the reviewer\'s user identifier (`humanRatedBy`) and the time (`humanRatedAt`); a new rating replaces the previous one. Rejected with 422 when the setting is empty or not a valid JSON Schema, and when the rating does not match it: one violation per validation error, with the JSON pointer of the offending value as `propertyPath` (an unexpected property under `additionalProperties: false` is reported at its own pointer, e.g. `/extra`).',
                requestBody: new OpenApiRequestBody(
                    description: 'The rating document; its shape is defined by the setting `evaluation.human_rating_schema`.',
                    content: new \ArrayObject(['application/ld+json' => ['schema' => ['type' => 'object']], 'application/json' => ['schema' => ['type' => 'object']]]),
                    required: true,
                ),
            ),
            security: "is_granted('neuronai:eval:rate')",
            input: false,
            read: true,
            deserialize: false,
            validate: false,
            name: self::OPERATION_RATE,
            processor: EvalResultRateProcessor::class,
            throwOnNotFound: true,
        ),
    ],
    description: <<<'MD'
        An evaluation result is the outcome of asking one evaluation question to an agent version: whether it succeeded, how long it took, how many tokens and tool calls it needed, and how well the knowledge search found the expected content. The answer itself is the last message of the result's conversation. A result of a search-only run measures only the knowledge search and has no answer.

        Results are recorded by evaluation runs and are read-only. Here you can ask the AI judge to evaluate a result, and reviewers can add their own rating.

        Related: a result belongs to an **EvalRun** and answers an **EvalQuestion**; the answer is in its **Conversation**. The judge's verdicts are listed under **Evaluation**, its improvement proposals under **Suggestion**.
        MD,
    normalizationContext: ['groups' => [self::GROUP_READ]],
    order: ['createdAt' => 'ASC'],
    paginationEnabled: true,
    paginationItemsPerPage: 50,
    openapi: new OpenApiOperation(tags: [OpenApiTag::AGENTS]),
)]
#[ApiFilter(SearchFilter::class, properties: ['evalRun' => 'exact', 'evalQuestion' => 'exact', 'conversation' => 'exact', 'evaluation' => 'exact', 'status' => 'exact', 'source' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'])]
class EvalResult
{
    public const string GROUP_READ = 'eval_result:read';

    /** Operation name and job type of `POST /eval_results/{id}/evaluate`. */
    public const string OPERATION_EVALUATE = 'eval_result_evaluate';

    /** Operation name of `POST /eval_results/{id}/rate`. */
    public const string OPERATION_RATE = 'eval_result_rate';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[Groups([self::GROUP_READ])]
    private Uuid $id;

    /** The batch run; null for a single execution. Serialized as IRI. */
    #[ORM\ManyToOne(targetEntity: EvalRun::class)]
    #[ORM\JoinColumn(name: 'eval_run_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    #[Groups([self::GROUP_READ])]
    private ?EvalRun $evalRun = null;

    /** The executed question; serialized as IRI. */
    #[ORM\ManyToOne(targetEntity: EvalQuestion::class)]
    #[ORM\JoinColumn(name: 'eval_question_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[Groups([self::GROUP_READ])]
    private ?EvalQuestion $evalQuestion = null;

    /** The conversation the question ran in; null once it was deleted and in a retrieval-only run. Serialized as IRI. */
    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(name: 'conversation_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups([self::GROUP_READ])]
    private ?Conversation $conversation = null;

    /** The latest judge evaluation; null before the first one. Serialized as IRI. */
    #[ORM\ManyToOne(targetEntity: Evaluation::class)]
    #[ORM\JoinColumn(name: 'evaluation_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?Evaluation $evaluation = null;

    /** One of {@see EvalResultSource::ALL}. */
    #[ORM\Column(type: Types::STRING, length: 20)]
    #[Assert\Choice(choices: EvalResultSource::ALL)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private string $source = EvalResultSource::BATCH;

    /** One of {@see EvalResultStatus::ALL}. */
    #[ORM\Column(type: Types::STRING, length: 20, options: ['default' => EvalResultStatus::PENDING])]
    #[Assert\Choice(choices: EvalResultStatus::ALL)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private string $status = EvalResultStatus::PENDING;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[ApiProperty(writable: false, openapiContext: ['x-format' => 'textarea', 'x-grid' => ['columnsMd' => 12, 'newRow' => true]])]
    #[Groups([self::GROUP_READ])]
    private ?string $errorMessage = null;

    /** Wall-clock duration of the turn in milliseconds (of the retrieval search in a retrieval-only run). */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?int $durationMs = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?int $tokensInput = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?int $tokensOutput = null;

    /** Tool calls of the turn. */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?int $interactions = null;

    /**
     * The reviewer's rating, valid against the `human_rating_schema`
     * setting; written by the rate operation.
     *
     * @var array<array-key, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?array $humanRating = null;

    /** User identifier of the reviewer who rated last. */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?string $humanRatedBy = null;

    /** When the reviewer rated last. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?\DateTimeImmutable $humanRatedAt = null;

    /**
     * sha256 hex digest of question text, NUL and expected answer at the
     * time the result was recorded; null for results recorded before
     * fingerprints existed.
     */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?string $questionFingerprint = null;

    /**
     * One of {@see RetrievalStatus::ALL}: how the question's retrieval was
     * measured. Null for runs in mode `agent`, for single executions and
     * for results written before retrieval measurement existed.
     */
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    #[Assert\Choice(choices: RetrievalStatus::ALL)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?string $retrievalStatus = null;

    /**
     * Whether the uncapped retrieval pool contains a chunk whose content
     * matches a marker of the question. Set only when `retrievalStatus` is
     * `measured`, null otherwise.
     */
    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?bool $recallHit = null;

    /**
     * 1-based rank of the first pool chunk whose content matches a marker.
     * Set only when `retrievalStatus` is `measured`; null on a miss and for
     * every other status.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?int $firstRelevantRank = null;

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

    public function getEvalRun(): ?EvalRun
    {
        return $this->evalRun;
    }

    public function setEvalRun(?EvalRun $evalRun): self
    {
        $this->evalRun = $evalRun;

        return $this;
    }

    /**
     * @throws \LogicException when the question was not set yet (a new result only)
     */
    public function getEvalQuestion(): EvalQuestion
    {
        return $this->evalQuestion ?? throw new \LogicException('The question of the evaluation result is not set.');
    }

    public function setEvalQuestion(EvalQuestion $evalQuestion): self
    {
        $this->evalQuestion = $evalQuestion;

        return $this;
    }

    public function getConversation(): ?Conversation
    {
        return $this->conversation;
    }

    public function setConversation(?Conversation $conversation): self
    {
        $this->conversation = $conversation;

        return $this;
    }

    public function getEvaluation(): ?Evaluation
    {
        return $this->evaluation;
    }

    public function setEvaluation(?Evaluation $evaluation): self
    {
        $this->evaluation = $evaluation;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

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

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

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

    public function getInteractions(): ?int
    {
        return $this->interactions;
    }

    public function setInteractions(?int $interactions): self
    {
        $this->interactions = $interactions;

        return $this;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function getHumanRating(): ?array
    {
        return $this->humanRating;
    }

    /**
     * @param array<array-key, mixed>|null $humanRating
     */
    public function setHumanRating(?array $humanRating): self
    {
        $this->humanRating = $humanRating;

        return $this;
    }

    public function getHumanRatedBy(): ?string
    {
        return $this->humanRatedBy;
    }

    public function setHumanRatedBy(?string $humanRatedBy): self
    {
        $this->humanRatedBy = $humanRatedBy;

        return $this;
    }

    public function getHumanRatedAt(): ?\DateTimeImmutable
    {
        return $this->humanRatedAt;
    }

    public function setHumanRatedAt(?\DateTimeImmutable $humanRatedAt): self
    {
        $this->humanRatedAt = $humanRatedAt;

        return $this;
    }

    public function getQuestionFingerprint(): ?string
    {
        return $this->questionFingerprint;
    }

    public function setQuestionFingerprint(?string $questionFingerprint): self
    {
        $this->questionFingerprint = $questionFingerprint;

        return $this;
    }

    public function getRetrievalStatus(): ?string
    {
        return $this->retrievalStatus;
    }

    public function setRetrievalStatus(?string $retrievalStatus): self
    {
        $this->retrievalStatus = $retrievalStatus;

        return $this;
    }

    /**
     * The fingerprint of $question as it is now: sha256 hex digest of the
     * question text, a NUL byte and the expected answer.
     */
    public static function fingerprintOf(EvalQuestion $question): string
    {
        return hash('sha256', $question->getQuestion()."\0".$question->getExpectedAnswer());
    }

    /**
     * Stores a retrieval measurement: `retrievalStatus`, `recallHit` and
     * `firstRelevantRank` all at once.
     */
    public function applyRetrieval(RetrievalMeasurement $measurement): self
    {
        $this->retrievalStatus = $measurement->status;
        $this->recallHit = $measurement->recallHit;
        $this->firstRelevantRank = $measurement->firstRelevantRank;

        return $this;
    }

    public function getRecallHit(): ?bool
    {
        return $this->recallHit;
    }

    public function setRecallHit(?bool $recallHit): self
    {
        $this->recallHit = $recallHit;

        return $this;
    }

    public function getFirstRelevantRank(): ?int
    {
        return $this->firstRelevantRank;
    }

    public function setFirstRelevantRank(?int $firstRelevantRank): self
    {
        $this->firstRelevantRank = $firstRelevantRank;

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
