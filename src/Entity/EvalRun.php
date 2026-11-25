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
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\RunComparison;
use Dmstr\NeuronAiBundle\Evaluation\EvalRunStatus;
use Dmstr\NeuronAiBundle\OpenApi\OpenApiTag;
use Dmstr\NeuronAiBundle\State\EvalRunCancelProcessor;
use Dmstr\NeuronAiBundle\State\EvalRunCompareProvider;
use Dmstr\NeuronAiBundle\State\EvalRunStartProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One batch execution of the evaluation test set: every active
 * {@see EvalQuestion} runs as the first turn of a new {@see Conversation}
 * on the published {@see AgentVersion} `agentVersion` (with
 * `retrievalOnly` only its retrieval is measured), and each executed
 * question becomes an {@see EvalResult} of this run.
 *
 * - ownerSubject: user identifier of whoever started the run; the eval
 *   conversations (`origin` = `eval`) are owned by the same subject;
 * - status: {@see EvalRunStatus}; `cancelRequestedAt` asks the batch job
 *   to stop after the current question;
 * - jobId: id of the `Job` of dmstr/symfony-job-queue-bundle that runs the
 *   batch (no foreign key, like `Collection::reindexJobId`);
 * - questionsTotal, tokensTotal, interactionsTotal: aggregates the batch
 *   job maintains (question turns only; the judge's tokens are on each
 *   Evaluation); questionsTotal is a progress figure, see the property;
 *   verdictCounts: results per verdict of their latest
 *   evaluation (see the property);
 * - mode: `retrieval` (each question's retrieval is measured) or
 *   `agent` (no measurement); null for runs started before the mode
 *   existed;
 * - retrievalOnly: set at start (implies mode `retrieval`); the
 *   batch measures each question's retrieval without chat call and
 *   without conversation, so the results have no answer, tokens or
 *   interactions and cannot be judged;
 * - recall, mrr, questionsMeasured, questionsNegative,
 *   questionsUnmeasured, questionsRetrievalFailed: the retrieval
 *   aggregates over the stored results, maintained by
 *   {@see \Dmstr\NeuronAiBundle\Evaluation\RunAggregates}; null in mode
 *   `agent` or while nothing was aggregated yet.
 *
 * The version is referenced with ON DELETE RESTRICT (versions are never
 * deleted anyway); results are removed with the run (cascade).
 *
 * Operations: `GET /eval_runs` (filters `status`, `agentVersion`,
 * `ownerSubject`) and `GET /eval_runs/{id}` for `neuronai:eval:read`;
 * `POST /eval_runs/start` ({@see EvalRunStartProcessor}, job type
 * {@see self::JOB_TYPE_START} run by
 * {@see \Dmstr\NeuronAiBundle\JobProcessor\EvalRunJobProcessor}) and
 * `POST /eval_runs/{id}/cancel` ({@see EvalRunCancelProcessor}) for
 * `neuronai:eval:write`; `GET /eval_runs/{id}/compare/{baselineId}`
 * ({@see EvalRunCompareProvider}, an unstored
 * {@see RunComparison}) for `neuronai:eval:compare`
 * only: `neuronai:eval:read` reads runs and results but not the comparison.
 */
#[ORM\Entity]
#[ORM\Table(name: 'neuron_eval_run')]
#[ORM\Index(name: 'idx_neuron_eval_run_status', columns: ['status'])]
#[ORM\Index(name: 'idx_neuron_eval_run_owner_subject', columns: ['owner_subject'])]
#[ORM\Index(name: 'idx_neuron_eval_run_agent_version', columns: ['agent_version_id'])]
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
            uriTemplate: '/eval_runs/start',
            status: 202,
            description: 'Starts a new evaluation run on the chosen agent version: every active question is asked in turn, each in its own conversation, and a result is recorded per question. The run is processed in the background (progress in **Job**), and its results appear under **EvalResult**. Optionally only the knowledge search is measured, without asking the agent.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::AGENTS],
                responses: [
                    '202' => new OpenApiResponse(description: 'The run was created with status `pending` and its batch job was queued; the body is the run (as `GET /eval_runs/{id}`) with `jobId`.'),
                ],
                summary: 'Starts an evaluation run of every active question on an agent version.',
                description: 'Creates an evaluation run on `agentVersion` (IRI `/api/agent_versions/<uuid>` or full UUID), owned by the caller, and queues a job of type `eval_run_start`. The job runs every active question, ordered by creation time and key, as the first turn of a new evaluation conversation (`origin` `eval`, owned by the caller, pinned to the version) and records one evaluation result per question; a question whose turn fails is recorded as a `failed` result and the run continues. The optional `mode` selects the metric set: `retrieval` repeats the turn\'s retrieval search after each question and stores recall hit and first relevant rank per result and recall and MRR per run, `agent` measures nothing; when it is omitted, it is derived from the version (`retrieval` when it has knowledge collections, otherwise `agent`), and the run always stores it. With `retrievalOnly` `true` (stored as `retrievalOnly`, default `false`) no question runs as a turn and no conversation is created: per question only the retrieval search runs and the result holds the retrieval measurement, without tokens and interactions and without anything to judge; it implies `mode` `retrieval`. Instead of `agentVersion` the body may name `agent` (IRI `/api/agents/<uuid>`, full UUID or slug) with `candidate: true`: the agent\'s current candidate is validated like a publish (422 at `candidate/<field>`), frozen as a preview version (reused while the candidate is unchanged) and the run is created on that preview; a preview may also be given directly as `agentVersion`, since the current-version rule applies to published versions only, and both ways in one body are rejected with 422. Rejected with 422, before a run or job exists, for an unknown version or agent, a disabled agent, a published version that is not the agent\'s current version, when `mode` is `retrieval` or `retrievalOnly` is `true` and the version has no knowledge collections, for `retrievalOnly` together with `mode` `agent`, or when no active question exists; 400 for malformed JSON, 422 for a body that does not match the schema.',
            ),
            normalizationContext: ['groups' => [self::GROUP_READ]],
            security: "is_granted('neuronai:eval:write')",
            input: false,
            output: self::class,
            read: false,
            deserialize: false,
            validate: false,
            name: self::OPERATION_START,
            processor: EvalRunStartProcessor::class,
        ),
        new Post(
            uriTemplate: '/eval_runs/{id}/cancel',
            status: 200,
            description: 'Asks a pending or running evaluation run to stop. The run ends as cancelled after the current question; the remaining questions get no result.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::AGENTS],
                responses: [
                    '200' => new OpenApiResponse(description: 'The run with `cancelRequestedAt` set; its status changes when the batch job notices the request.'),
                ],
                summary: 'Asks a pending or running evaluation run to stop.',
                description: 'Sets `cancelRequestedAt`; the batch job checks it before each question and ends the run as `cancelled` after the current question, leaving the remaining questions without results. Requesting it again keeps the first time. No body; the response is the run. Rejected with 422 when the run has already ended (`completed`, `cancelled` or `failed`).',
            ),
            normalizationContext: ['groups' => [self::GROUP_READ]],
            security: "is_granted('neuronai:eval:write')",
            input: false,
            read: true,
            deserialize: false,
            validate: false,
            name: self::OPERATION_CANCEL,
            processor: EvalRunCancelProcessor::class,
            throwOnNotFound: true,
        ),
        // Stays after the plain Get: the first item Get of the resource is
        // the one API Platform uses for the IRI of a run.
        new Get(
            uriTemplate: '/eval_runs/{id}/compare/{baselineId}',
            description: 'Compares this run with an earlier, completed run (the baseline) question by question: which verdicts and ratings got better or worse, which questions newly failed, changed or are missing. Questions that got worse are marked as regressions. Nothing is stored.',
            uriVariables: [
                EvalRunCompareProvider::URI_VARIABLE_CANDIDATE => new Link(fromClass: self::class, identifiers: ['id']),
                EvalRunCompareProvider::URI_VARIABLE_BASELINE => new Link(fromClass: self::class, identifiers: ['id']),
            ],
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::AGENTS],
                responses: [
                    '200' => new OpenApiResponse(description: 'The comparison of the candidate run `{id}` with the baseline run `{baselineId}`; computed on request, nothing is stored.'),
                    '403' => new OpenApiResponse(description: 'The caller lacks the permission `neuronai:eval:compare` (also a caller who may only read runs and results, and a capability-token holder).'),
                    '404' => new OpenApiResponse(description: 'One of the two runs does not exist.'),
                    '422' => new OpenApiResponse(description: 'Both ids name the same run, or one of the runs is not `completed`.'),
                ],
                summary: 'Compares an evaluation run (candidate) with an earlier one (baseline) per question.',
                description: 'Matches the results of the candidate run `{id}` and the baseline run `{baselineId}` by question key and classifies every key, always as the candidate relative to the baseline. `baseline` and `candidate` name both runs with agent, agent version, version number, `startedAt` and `finishedAt`, so the result can be attributed; runs on different agents or with different question sets may be compared. Per question: `presence` (`both`, `only_in_baseline`, `only_in_candidate`); `verdictChange` (`worse`, `equal`, `better`) by the verdict order `pass`, `mostly_pass`, `mostly_fail`, `fail`, where only the latest evaluation of a result counts and only once it is `completed` (none, pending or failed is unrated, `verdictChange` null); `newlyFailed` (the candidate\'s result failed, the baseline\'s did not); `newlyUnrated` (rated in the baseline, unrated in the candidate); `questionChanged` (question text or expected answer differ by the results\' question fingerprints; for results recorded before the fingerprint existed, true when the first user messages of the two result conversations differ, else null for unknown); `humanRating` (with the setting `evaluation.human_rating_comparison` the configured field as `worse`, `equal`, `better` or `unrated`, without it `changed` or `unchanged` with both ratings). A question is a `regression` for a worse verdict, a worse human rating, a newly failed result or a question missing in the candidate (`regressionReasons`); pending, failed or missing evaluations, values that cannot be ranked and a changed question are never a regression on their own. Improvements are listed in `improvementReasons` and not flagged. `summary` counts the classifications. 404 for an unknown run, 422 for the same run twice or a run that is not `completed`.',
            ),
            // No serialization groups: the output DTO is serialized as a
            // whole (the resource's `eval_run:read` group would hide it).
            normalizationContext: [],
            security: "is_granted('neuronai:eval:compare')",
            output: RunComparison::class,
            name: self::OPERATION_COMPARE,
            provider: EvalRunCompareProvider::class,
        ),
    ],
    description: <<<'MD'
        An evaluation run asks every active evaluation question to one published agent version and records the outcome per question. It shows progress, totals of tokens and tool calls, how many answers the judge rated as pass or fail, and, where the agent uses knowledge, how well the knowledge search found the expected content. A search-only run measures just the knowledge search, without asking the agent.

        You start a run for an agent's current version and can stop it while it is running. Comparing two completed runs shows per question what got better or worse, for example after changing the agent's instructions.

        Related: a run tests an **AgentVersion** with the **EvalQuestion** set and records an **EvalResult** per question; each one is a **Conversation** unless the run is search-only. The run itself is processed as a **Job**.
        MD,
    normalizationContext: ['groups' => [self::GROUP_READ]],
    order: ['createdAt' => 'DESC'],
    paginationEnabled: true,
    paginationItemsPerPage: 30,
    openapi: new OpenApiOperation(tags: [OpenApiTag::AGENTS]),
)]
#[ApiFilter(SearchFilter::class, properties: ['status' => 'exact', 'agentVersion' => 'exact', 'ownerSubject' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'])]
class EvalRun
{
    public const string GROUP_READ = 'eval_run:read';

    /** `POST /eval_runs/start`; its body schema is `EvalRun/start.input.json`. */
    public const string OPERATION_START = 'eval_run_start';

    /** `POST /eval_runs/{id}/cancel`; no body. */
    public const string OPERATION_CANCEL = 'eval_run_cancel';

    /** `GET /eval_runs/{id}/compare/{baselineId}`: candidate `{id}` against baseline `{baselineId}`. */
    public const string OPERATION_COMPARE = 'eval_run_compare';

    /** Job type of the batch, run by {@see \Dmstr\NeuronAiBundle\JobProcessor\EvalRunJobProcessor}. */
    public const string JOB_TYPE_START = 'eval_run_start';

    public const int MAX_OWNER_SUBJECT_LENGTH = 190;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[Groups([self::GROUP_READ])]
    private Uuid $id;

    /** The published version the questions run on; serialized as IRI. */
    #[ORM\ManyToOne(targetEntity: AgentVersion::class)]
    #[ORM\JoinColumn(name: 'agent_version_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[Groups([self::GROUP_READ])]
    private ?AgentVersion $agentVersion = null;

    /** User identifier of whoever started the run; owns the eval conversations. */
    #[ORM\Column(type: Types::STRING, length: self::MAX_OWNER_SUBJECT_LENGTH)]
    #[Assert\NotBlank]
    #[Assert\Length(max: self::MAX_OWNER_SUBJECT_LENGTH)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private string $ownerSubject = '';

    /** One of {@see EvalRunStatus::ALL}. */
    #[ORM\Column(type: Types::STRING, length: 20, options: ['default' => EvalRunStatus::PENDING])]
    #[Assert\Choice(choices: EvalRunStatus::ALL)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private string $status = EvalRunStatus::PENDING;

    /**
     * `retrieval`: every question's retrieval is measured against its
     * markers; `agent`: no measurement. Null for runs started
     * before the mode existed.
     */
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    #[Groups([self::GROUP_READ])]
    private ?string $mode = null;

    /**
     * Retrieval-only run: each question's retrieval is measured
     * without chat call and without conversation; implies mode
     * `retrieval`. Set by the start only.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private bool $retrievalOnly = false;

    /** Id of the job queue job that runs the batch (no foreign key). */
    #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?string $jobId = null;

    /** Set by the cancel operation; the batch job stops after the current question. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?\DateTimeImmutable $cancelRequestedAt = null;

    /**
     * Questions of the run, a progress figure: the start stores the number
     * of active questions; the batch job sets it again when
     * it takes its question list, to the number of active questions plus
     * the questions already answered in this run that are no longer
     * active, so the progress stays truthful on a redelivery and when
     * questions were archived or activated between start and job. The
     * retrieval denominators (`questionsMeasured` and the others) come
     * from the stored results, not from this value.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private int $questionsTotal = 0;

    /** Input plus output tokens of all results (question turns; judge tokens are on each Evaluation). */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private int $tokensTotal = 0;

    /** Tool calls of all results. */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private int $interactionsTotal = 0;

    /**
     * Number of results per verdict whose latest evaluation is `completed`:
     * an object with the four keys `pass`, `mostly_pass`, `mostly_fail`,
     * `fail` (all 0 once the batch completed), maintained by
     * {@see \Dmstr\NeuronAiBundle\Evaluation\RunAggregates}; null while
     * the run has neither completed nor any evaluated result.
     *
     * @var array<string, int>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?array $verdictCounts = null;

    /**
     * Share of the `measured` results whose pool contains a relevant chunk
     * (hits / questionsMeasured); null when nothing was measured, in mode
     * `agent` or before the first aggregation.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?float $recall = null;

    /**
     * Mean reciprocal rank over the `measured` results (Σ 1 /
     * firstRelevantRank / questionsMeasured, a miss counts 0); null like
     * `recall`.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?float $mrr = null;

    /** Results with retrieval status `measured` (the denominator of recall and MRR). */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?int $questionsMeasured = null;

    /** Results with retrieval status `negative` (not in recall or MRR). */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?int $questionsNegative = null;

    /** Results with retrieval status `unmeasured`, i.e. questions without markers (not in recall or MRR). */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?int $questionsUnmeasured = null;

    /** Results whose repeated search failed (retrieval status `failed`; not in recall or MRR). */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?int $questionsRetrievalFailed = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private ?\DateTimeImmutable $finishedAt = null;

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
     * @throws \LogicException when the version was not set yet (a new run only)
     */
    public function getAgentVersion(): AgentVersion
    {
        return $this->agentVersion ?? throw new \LogicException('The agent version of the evaluation run is not set.');
    }

    public function setAgentVersion(AgentVersion $agentVersion): self
    {
        $this->agentVersion = $agentVersion;

        return $this;
    }

    public function getOwnerSubject(): string
    {
        return $this->ownerSubject;
    }

    public function setOwnerSubject(string $ownerSubject): self
    {
        $this->ownerSubject = $ownerSubject;

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
     * Whether the run has not ended yet (pending or running); only such a
     * run can be cancelled.
     */
    public function isActive(): bool
    {
        return EvalRunStatus::PENDING === $this->status || EvalRunStatus::RUNNING === $this->status;
    }

    public function getMode(): ?string
    {
        return $this->mode;
    }

    public function setMode(?string $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function isRetrievalOnly(): bool
    {
        return $this->retrievalOnly;
    }

    public function setRetrievalOnly(bool $retrievalOnly): self
    {
        $this->retrievalOnly = $retrievalOnly;

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

    public function getCancelRequestedAt(): ?\DateTimeImmutable
    {
        return $this->cancelRequestedAt;
    }

    public function setCancelRequestedAt(?\DateTimeImmutable $cancelRequestedAt): self
    {
        $this->cancelRequestedAt = $cancelRequestedAt;

        return $this;
    }

    /**
     * Whether cancelling was requested (the flag the batch job checks).
     */
    public function isCancelRequested(): bool
    {
        return null !== $this->cancelRequestedAt;
    }

    /**
     * Ends the run with $status (`completed`, `cancelled` or `failed`) at
     * $at.
     */
    public function finish(string $status, \DateTimeImmutable $at): self
    {
        if (EvalRunStatus::PENDING === $status || EvalRunStatus::RUNNING === $status || !\in_array($status, EvalRunStatus::ALL, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a final status of an evaluation run.', $status));
        }

        $this->status = $status;
        $this->finishedAt = $at;

        return $this;
    }

    public function getQuestionsTotal(): int
    {
        return $this->questionsTotal;
    }

    public function setQuestionsTotal(int $questionsTotal): self
    {
        $this->questionsTotal = $questionsTotal;

        return $this;
    }

    public function getTokensTotal(): int
    {
        return $this->tokensTotal;
    }

    public function setTokensTotal(int $tokensTotal): self
    {
        $this->tokensTotal = $tokensTotal;

        return $this;
    }

    public function getInteractionsTotal(): int
    {
        return $this->interactionsTotal;
    }

    public function setInteractionsTotal(int $interactionsTotal): self
    {
        $this->interactionsTotal = $interactionsTotal;

        return $this;
    }

    /**
     * @return array<string, int>|null
     */
    public function getVerdictCounts(): ?array
    {
        return $this->verdictCounts;
    }

    /**
     * @param array<string, int>|null $verdictCounts
     */
    public function setVerdictCounts(?array $verdictCounts): self
    {
        $this->verdictCounts = $verdictCounts;

        return $this;
    }

    public function getRecall(): ?float
    {
        return $this->recall;
    }

    public function setRecall(?float $recall): self
    {
        $this->recall = $recall;

        return $this;
    }

    public function getMrr(): ?float
    {
        return $this->mrr;
    }

    public function setMrr(?float $mrr): self
    {
        $this->mrr = $mrr;

        return $this;
    }

    public function getQuestionsMeasured(): ?int
    {
        return $this->questionsMeasured;
    }

    public function setQuestionsMeasured(?int $questionsMeasured): self
    {
        $this->questionsMeasured = $questionsMeasured;

        return $this;
    }

    public function getQuestionsNegative(): ?int
    {
        return $this->questionsNegative;
    }

    public function setQuestionsNegative(?int $questionsNegative): self
    {
        $this->questionsNegative = $questionsNegative;

        return $this;
    }

    public function getQuestionsUnmeasured(): ?int
    {
        return $this->questionsUnmeasured;
    }

    public function setQuestionsUnmeasured(?int $questionsUnmeasured): self
    {
        $this->questionsUnmeasured = $questionsUnmeasured;

        return $this;
    }

    public function getQuestionsRetrievalFailed(): ?int
    {
        return $this->questionsRetrievalFailed;
    }

    public function setQuestionsRetrievalFailed(?int $questionsRetrievalFailed): self
    {
        $this->questionsRetrievalFailed = $questionsRetrievalFailed;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

// - revised 2026-10-13
