<?php

// file generated with AI assistance: Claude Code - 2026-10-03 21:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use Dmstr\NeuronAiBundle\Evaluation\SuggestionStatus;
use Dmstr\NeuronAiBundle\Feedback\FeedbackRating;
use Dmstr\NeuronAiBundle\Feedback\FeedbackSource;
use Dmstr\NeuronAiBundle\OpenApi\OpenApiTag;
use Dmstr\NeuronAiBundle\State\FeedbackSubmitProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Feedback on a conversation or on one of its messages:
 * a rating (`up`/`down`), a reason and a free comment, at least one of
 * them, given by an end user or a reviewer.
 *
 * - conversation: the conversation the feedback is about (required);
 *   feedback is removed with its conversation (cascade);
 * - message: optionally one message of that conversation; a message of
 *   another conversation is rejected (422 on `message`); deleting the
 *   message keeps the feedback with `message` null;
 * - rating: one of {@see FeedbackRating} or null;
 * - reason: short free text (at most 255 characters) or null;
 * - comment: free text or null;
 * - source: {@see FeedbackSource}, derived on submission, never taken from
 *   the body (a `source` in the body is ignored);
 * - subject: user identifier of the submitter; for a capability token the
 *   conversation's `ownerSubject` (`anon:<uuid>`);
 * - agent: the agent of the conversation's agent version, or null (read
 *   only, derived);
 * - status, reviewNote: the review state ({@see SuggestionStatus}, `open`
 *   initially) and a note of whoever handles the feedback, e.g. what was
 *   changed in the agent; the only fields a PATCH changes.
 *
 * Operations: `POST /feedback` (`feedback_submit`,
 * {@see FeedbackSubmitProcessor}: permission, rate limit, validation) for
 * every authenticated principal, capability-token holders included;
 * `GET /feedback` (filters `conversation`, `message`, `source`, `rating`,
 * `status`, `conversation.agentVersion.agent`) and `GET /feedback/{id}`
 * for holders of `neuronai:feedback:read`; `PATCH /feedback/{id}`
 * (`feedback_review`) of `status` and `reviewNote` for holders of
 * `neuronai:feedback:review`. The feedback itself (rating, reason,
 * comment) cannot be changed, and there is no DELETE.
 */
#[ORM\Entity]
#[ORM\Table(name: 'neuron_feedback')]
#[ORM\Index(name: 'idx_neuron_feedback_conversation', columns: ['conversation_id'])]
#[ORM\Index(name: 'idx_neuron_feedback_message', columns: ['message_id'])]
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/feedback',
            security: self::SECURITY_READ,
        ),
        new Post(
            uriTemplate: '/feedback',
            status: 201,
            description: 'Submits feedback on a conversation or on one of its answers; at least a rating, a reason or a comment is needed. Whether it counts as end-user or reviewer feedback is determined from who submits it, and the number of submissions per person and hour is limited.',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::CHAT],
                summary: 'Submits feedback on a conversation or one of its messages.',
                description: 'Body `{"conversation": "<IRI>", "message": "<IRI>", "rating": "up"|"down", "reason": "…", "comment": "…"}`; `message`, `rating`, `reason` and `comment` are optional, but at least one of `rating`, `reason` and `comment` is required (422). `message` must belong to `conversation` (422 on `message`). A holder of `neuronai:feedback:submit_reviewer` who may read the conversation submits with `source` `reviewer`; otherwise the owner of the conversation or the holder of its capability token (`Authorization: Bearer anon_…`) submits with `source` `enduser`; everybody else gets 403. `source` and `subject` are derived and ignored in the body. Submissions per subject and hour are limited by the setting `evaluation.feedback_per_subject_and_hour` (default 30): 429 with `Retry-After` when used up, 403 when the setting is 0 (feedback disabled).',
            ),
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            validate: false,
            name: self::OPERATION_SUBMIT,
            processor: FeedbackSubmitProcessor::class,
        ),
        new Get(
            uriTemplate: '/feedback/{id}',
            security: self::SECURITY_READ,
        ),
        new Patch(
            uriTemplate: '/feedback/{id}',
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::CHAT],
                summary: 'Updates the review status and the review note of a feedback.',
                description: 'JSON merge patch (`application/merge-patch+json`) of `status` (one of `open`, `fixed`, `wontfix`) and `reviewNote` (free text, null to clear). Every other property of the body (`rating`, `reason`, `comment`, `conversation`, `message`, `source`, `subject`, `createdAt`) is ignored and keeps its value; the feedback itself cannot be changed. Rejected with 422 for another status.',
            ),
            denormalizationContext: ['groups' => [self::GROUP_REVIEW]],
            security: "is_granted('neuronai:feedback:review')",
            name: self::OPERATION_REVIEW,
        ),
    ],
    description: <<<'MD'
        Feedback is a rating (up or down), a short reason or a comment on a conversation or on one of its answers. End users give feedback on their own conversations; reviewers give feedback on any conversation they may read, and their entries are marked as reviewer feedback.

        The feedback itself cannot be edited or deleted. Whoever handles it sets its status (open, fixed or won't fix) and a note, for example what was changed in the agent. Filter by agent, conversation, source, rating or status, for example for the open feedback rated down of one agent.

        Related: feedback belongs to a **Conversation** and optionally to one **Message** of it, and is removed together with the conversation.
        MD,
    normalizationContext: ['groups' => [self::GROUP_READ]],
    denormalizationContext: ['groups' => [self::GROUP_WRITE]],
    order: ['createdAt' => 'DESC'],
    paginationEnabled: true,
    paginationItemsPerPage: 50,
    openapi: new OpenApiOperation(tags: [OpenApiTag::CHAT]),
)]
#[ApiFilter(SearchFilter::class, properties: ['conversation' => 'exact', 'message' => 'exact', 'source' => 'exact', 'rating' => 'exact', 'status' => 'exact', 'conversation.agentVersion.agent' => 'exact'])]
class Feedback
{
    public const string GROUP_READ = 'feedback:read';
    public const string GROUP_WRITE = 'feedback:write';
    public const string GROUP_REVIEW = 'feedback:review';

    public const string OPERATION_SUBMIT = 'feedback_submit';
    public const string OPERATION_REVIEW = 'feedback_review';

    /**
     * Reading feedback: the evaluation roles only. Role checks do not look
     * at the object, so the Hydra operation filter's `null` object is
     * harmless.
     */
    public const string SECURITY_READ = "is_granted('neuronai:feedback:read')";

    public const int MAX_REASON_LENGTH = 255;
    public const int MAX_SUBJECT_LENGTH = 255;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[Groups([self::GROUP_READ])]
    private Uuid $id;

    /** The conversation the feedback is about; IRI in and out. */
    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(name: 'conversation_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'The conversation is required.')]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private ?Conversation $conversation = null;

    /** Optionally one message of the conversation; IRI in and out. */
    #[ORM\ManyToOne(targetEntity: Message::class)]
    #[ORM\JoinColumn(name: 'message_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private ?Message $message = null;

    /** Thumbs `up` or `down` ({@see FeedbackRating}), or null. */
    #[ORM\Column(type: Types::STRING, length: 10, nullable: true)]
    #[Assert\Choice(callback: [FeedbackRating::class, 'values'], message: 'The rating must be "up" or "down".')]
    #[ApiProperty(jsonSchemaContext: ['enum' => ['up', 'down', null]])]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private ?string $rating = null;

    /** Short reason, at most 255 characters, or null; blank is stored as null. */
    #[ORM\Column(type: Types::STRING, length: self::MAX_REASON_LENGTH, nullable: true)]
    #[Assert\Length(max: self::MAX_REASON_LENGTH)]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private ?string $reason = null;

    /** Free comment, or null; blank is stored as null. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[ApiProperty(openapiContext: ['x-format' => 'textarea', 'x-grid' => ['columnsMd' => 12, 'newRow' => true]])]
    #[Groups([self::GROUP_READ, self::GROUP_WRITE])]
    private ?string $comment = null;

    /** `enduser` or `reviewer` ({@see FeedbackSource}); derived on submission, ignored in the body. */
    #[ORM\Column(type: Types::STRING, length: 20)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private string $source = FeedbackSource::ENDUSER->value;

    /** User identifier of the submitter (capability token: the conversation's `ownerSubject`); derived, ignored in the body. */
    #[ORM\Column(type: Types::STRING, length: self::MAX_SUBJECT_LENGTH)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private string $subject = '';

    /** One of {@see SuggestionStatus::ALL}, `open` on submission; changed by a review only. */
    #[ORM\Column(type: Types::STRING, length: 20, options: ['default' => SuggestionStatus::OPEN])]
    #[Assert\Choice(choices: SuggestionStatus::ALL, message: 'The status must be "open", "fixed" or "wontfix".')]
    #[ApiProperty(jsonSchemaContext: ['enum' => SuggestionStatus::ALL])]
    #[Groups([self::GROUP_READ, self::GROUP_REVIEW])]
    private string $status = SuggestionStatus::OPEN;

    /** Note of the review, or null; blank is stored as null. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[ApiProperty(openapiContext: ['x-format' => 'textarea', 'x-grid' => ['columnsMd' => 12, 'newRow' => true]])]
    #[Groups([self::GROUP_READ, self::GROUP_REVIEW])]
    private ?string $reviewNote = null;

    /** Time of the submission. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * At least one of rating, reason and comment; a blank text does not
     * count.
     */
    #[Assert\Callback]
    public function validateContent(ExecutionContextInterface $context): void
    {
        if (null === $this->rating && null === $this->reason && null === $this->comment) {
            $context->buildViolation('At least one of "rating", "reason" and "comment" is required.')
                ->addViolation();
        }
    }

    /**
     * The message, when given, belongs to the conversation.
     */
    #[Assert\Callback]
    public function validateMessage(ExecutionContextInterface $context): void
    {
        if (null === $this->message || null === $this->conversation) {
            return;
        }

        if (!$this->message->getConversation()->getId()->equals($this->conversation->getId())) {
            $context->buildViolation('The message does not belong to the conversation.')
                ->atPath('message')
                ->addViolation();
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function setMessage(?Message $message): self
    {
        $this->message = $message;

        return $this;
    }

    public function getRating(): ?string
    {
        return $this->rating;
    }

    /**
     * Stored as given; the validator rejects anything but `up` and `down`.
     */
    public function setRating(?string $rating): self
    {
        $this->rating = $rating;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * A blank reason is stored as null.
     */
    public function setReason(?string $reason): self
    {
        $this->reason = self::nullIfBlank($reason);

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * A blank comment is stored as null.
     */
    public function setComment(?string $comment): self
    {
        $this->comment = self::nullIfBlank($comment);

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(FeedbackSource $source): self
    {
        $this->source = $source->value;

        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): self
    {
        $this->subject = $subject;

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

    public function getReviewNote(): ?string
    {
        return $this->reviewNote;
    }

    /**
     * A blank note is stored as null.
     */
    public function setReviewNote(?string $reviewNote): self
    {
        $this->reviewNote = self::nullIfBlank($reviewNote);

        return $this;
    }

    /** The agent of the conversation's agent version; null for a conversation without agent. IRI out. */
    #[ApiProperty(writable: false)]
    #[Groups([self::GROUP_READ])]
    public function getAgent(): ?Agent
    {
        return $this->conversation?->getAgentVersion()?->getAgent();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    private static function nullIfBlank(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : $value;
    }
}

// - revised 2026-10-07
// - revised 2026-11-20
