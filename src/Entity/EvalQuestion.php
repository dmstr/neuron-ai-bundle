<?php

// file generated with AI assistance: Claude Code - 2026-10-03 18:38:08 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use Dmstr\NeuronAiBundle\Evaluation\EvalQuestionStatus;
use Dmstr\NeuronAiBundle\OpenApi\OpenApiTag;
use Dmstr\NeuronAiBundle\Validator\EvalQuestionRetrieval;
use Dmstr\NeuronAiBundle\Validator\EvalQuestionRetrievalRules;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One question of the maintained evaluation test set: the user
 * message of the first turn (`question`) and the answer a reviewer expects
 * (`expectedAnswer`). An {@see EvalRun} executes every active question
 * against a published {@see AgentVersion} and records an {@see EvalResult}
 * per question.
 *
 * - key: the stable identifier an import file uses to upsert the question
 *   (unique, lowercase letters, digits, `.`, `_` and `-`);
 * - requestData: structured start data for agents with a start form (a
 *   JSON object or null); an evaluation run and the single execution pass
 *   it as the `variables` of the evaluation conversation, checked against
 *   the pinned version's `startSchema` (see
 *   {@see \Dmstr\NeuronAiBundle\Evaluation\QuestionVariables});
 * - status: `active` questions take part in new runs, `archived` ones are
 *   kept for the results that reference them ({@see EvalQuestionStatus});
 * - markers, negative: expected retrieval of the question:
 *   case-insensitive substrings searched in the content of the retrieved
 *   chunks, or a question that should find nothing; maintained by the
 *   import ({@see \Dmstr\NeuronAiBundle\Evaluation\EvalQuestionImporter})
 *   or through the API, both with the rules of
 *   {@see EvalQuestionRetrievalRules} ({@see EvalQuestionRetrieval}).
 *   `negative: true` with markers answers 422 on `negative`, or on
 *   `markers` when a PATCH adds markers to a question that is already
 *   negative; `markers: null` clears them.
 *
 * Operations: `neuronai:eval:read` reads (`GET /eval_questions`, filters `status`
 * and `key`), `neuronai:eval:write` creates, edits and deletes. A question with
 * results cannot be deleted (the foreign key of `neuron_eval_result`
 * restricts); archive it instead.
 */
#[ORM\Entity]
#[ORM\Table(name: 'neuron_eval_question')]
#[ORM\UniqueConstraint(name: 'uniq_neuron_eval_question_key', columns: ['key'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['key'])]
#[EvalQuestionRetrieval]
#[ApiResource(
    shortName: 'EvalQuestion',
    operations: [
        new GetCollection(
            security: "is_granted('neuronai:eval:read')",
        ),
        new Get(
            security: "is_granted('neuronai:eval:read')",
        ),
        // Type errors (e.g. `"negative": "yes"`) answer 422 with a
        // violation on the property instead of 400.
        new Post(
            status: 201,
            security: "is_granted('neuronai:eval:write')",
            collectDenormalizationErrors: true,
        ),
        new Patch(
            security: "is_granted('neuronai:eval:write')",
            collectDenormalizationErrors: true,
        ),
        new Delete(
            security: "is_granted('neuronai:eval:write')",
        ),
    ],
    description: <<<'MD'
        An evaluation question is one entry of the test set that checks how well an agent answers: the question a user would ask and the answer a reviewer expects. Optionally it also states what the knowledge search should find (search terms in the retrieved text), or that it should find nothing.

        Active questions take part in every new evaluation run; archive a question to leave it out while keeping the results that refer to it. A question that already has results cannot be deleted, only archived. For agents with a start form, a question can carry the start data the conversation begins with.

        Related: an **EvalRun** asks every active question to an **AgentVersion** and records one **EvalResult** per question.
        MD,
    normalizationContext: ['groups' => [self::GROUP_READ]],
    denormalizationContext: ['groups' => [self::GROUP_WRITE]],
    order: ['key' => 'ASC'],
    paginationEnabled: true,
    paginationItemsPerPage: 30,
    openapi: new OpenApiOperation(tags: [OpenApiTag::AGENTS]),
)]
#[ApiFilter(SearchFilter::class, properties: ['status' => 'exact', 'key' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'key'])]
class EvalQuestion
{
    public const string GROUP_READ = 'eval_question:read';
    public const string GROUP_WRITE = 'eval_question:write';

    /**
     * `POST /api/eval_questions/{id}/execute` (a plain Symfony route,
     * {@see \Dmstr\NeuronAiBundle\Controller\EvalQuestionExecuteController});
     * its body schema is `EvalQuestion/execute.input.json`.
     */
    public const string OPERATION_EXECUTE = 'eval_question_execute';

    public const int MAX_KEY_LENGTH = 100;
    public const string KEY_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,99}$/';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[Groups([self::GROUP_READ])]
    private Uuid $id;

    /** Stable identifier of the question in import files, e.g. `support.opening-hours`. */
    #[ORM\Column(type: Types::STRING, length: self::MAX_KEY_LENGTH)]
    #[Assert\NotBlank]
    #[Assert\Length(max: self::MAX_KEY_LENGTH)]
    #[Assert\Regex(pattern: self::KEY_PATTERN, message: 'The key may only contain lowercase letters, digits, dots, underscores and dashes and must start with a letter or digit.')]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private string $key = '';

    /** The user message of the first turn. */
    #[ORM\Column(type: Types::TEXT)]
    #[ApiProperty(openapiContext: ['x-format' => 'textarea', 'x-grid' => ['columnsMd' => 12, 'newRow' => true]])]
    #[Assert\NotBlank]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private string $question = '';

    /**
     * Structured start data for agents with a start form.
     *
     * @var array<array-key, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private ?array $requestData = null;

    /** The answer a reviewer expects; compared with the actual answer. */
    #[ORM\Column(type: Types::TEXT)]
    #[ApiProperty(openapiContext: ['x-format' => 'textarea', 'x-grid' => ['columnsMd' => 12, 'newRow' => true]])]
    #[Assert\NotBlank]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private string $expectedAnswer = '';

    /** One of {@see EvalQuestionStatus::ALL}. */
    #[ORM\Column(type: Types::STRING, length: 20, options: ['default' => EvalQuestionStatus::ACTIVE])]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: EvalQuestionStatus::ALL)]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private string $status = EvalQuestionStatus::ACTIVE;

    /**
     * Expected retrieval markers: case-insensitive substrings
     * searched in the content of the retrieved chunks; the first chunk
     * whose content contains any marker is the hit. Null for a question
     * without markers (not measured). Otherwise a non-empty list of at
     * most 50 unique strings of at most 200 characters, trimmed when
     * stored; written by the import and through the API.
     *
     * Rules: {@see EvalQuestionRetrievalRules::markers()}; trimming:
     * {@see self::setMarkers()}. The value type stays `mixed` so that a
     * non-string entry reaches the validator and gets the import's message
     * instead of a type error.
     *
     * @var array<array-key, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private ?array $markers = null;

    /**
     * A question that should find nothing in the collections,
     * for example one outside the knowledge base; it has no markers and is
     * only counted, not measured. Written by the import (a missing value
     * there means false; null on questions imported before the field existed means
     * the same) and through the API (a boolean; a string or number answers
     * 422).
     */
    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private ?bool $negative = null;

    /**
     * `negative` as loaded from the database (not mapped, not serialised):
     * tells {@see EvalQuestionRetrieval} whether a conflict with markers
     * comes from new markers on a negative question.
     */
    private bool $loadedNegative = false;

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

    #[ORM\PostLoad]
    public function onPostLoad(): void
    {
        $this->loadedNegative = true === $this->negative;
    }

    /**
     * Whether the question was negative when it was loaded from the
     * database (false for a new question).
     */
    public function wasLoadedNegative(): bool
    {
        return $this->loadedNegative;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): self
    {
        $this->key = $key;

        return $this;
    }

    public function getQuestion(): string
    {
        return $this->question;
    }

    public function setQuestion(string $question): self
    {
        $this->question = $question;

        return $this;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function getRequestData(): ?array
    {
        return $this->requestData;
    }

    /**
     * @param array<array-key, mixed>|null $requestData
     */
    public function setRequestData(?array $requestData): self
    {
        $this->requestData = $requestData;

        return $this;
    }

    public function getExpectedAnswer(): string
    {
        return $this->expectedAnswer;
    }

    public function setExpectedAnswer(string $expectedAnswer): self
    {
        $this->expectedAnswer = $expectedAnswer;

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
     * Whether the question takes part in new runs.
     */
    public function isActive(): bool
    {
        return EvalQuestionStatus::ACTIVE === $this->status;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function getMarkers(): ?array
    {
        return $this->markers;
    }

    /**
     * Stores the markers with every string entry trimmed
     * ({@see EvalQuestionRetrievalRules::trimMarkers()}); validation
     * happens in {@see EvalQuestionRetrieval}.
     *
     * @param array<array-key, mixed>|null $markers
     */
    public function setMarkers(?array $markers): self
    {
        $this->markers = EvalQuestionRetrievalRules::trimMarkers($markers);

        return $this;
    }

    public function isNegative(): ?bool
    {
        return $this->negative;
    }

    public function setNegative(?bool $negative): self
    {
        $this->negative = $negative;

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
