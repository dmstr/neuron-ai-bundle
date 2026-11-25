<?php

// file generated with AI assistance: Claude Code - 2026-10-03 21:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use ApiPlatform\Validator\ValidatorInterface;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Feedback;
use Dmstr\NeuronAiBundle\Evaluation\EvaluationSettingsInterface;
use Dmstr\NeuronAiBundle\Feedback\FeedbackRateLimiter;
use Dmstr\NeuronAiBundle\Feedback\FeedbackSource;
use Dmstr\NeuronAiBundle\Security\ConversationSecurity;
use Dmstr\NeuronAiBundle\Security\Permissions;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * `POST /feedback` (`feedback_submit`): stores feedback on a conversation
 * or one of its messages.
 *
 * API Platform has deserialized the body (`conversation`, `message`,
 * `rating`, `reason`, `comment`; an unknown IRI is refused by API Platform
 * itself, `source`, `subject` and every other property are ignored), and
 * the firewall has authenticated the caller (JWT or capability token).
 * The operation does not validate on its own; this processor checks, in
 * this order:
 *  1. `conversation` is given (422 on `conversation` otherwise);
 *  2. permission: a holder of `neuronai:feedback:submit_reviewer` who
 *     is granted `neuronai:conversation:read_own` on the conversation submits as
 *     `reviewer`; otherwise whoever is granted `neuronai:conversation:turn_own` (the
 *     owner, or the capability token of exactly this conversation) submits
 *     as `enduser`; everybody else gets 403;
 *  3. limit: {@see EvaluationSettingsInterface::feedbackPerSubjectAndHour()}
 *     (bundle default {@see self::DEFAULT_LIMIT} without settings); 0
 *     answers 403 naming the setting {@see self::SETTING}; a used-up limit
 *     answers 429 with `Retry-After` ({@see FeedbackRateLimiter}, key = the
 *     caller's user identifier); every permitted submission counts, also
 *     one refused by the next step;
 *  4. validation of the entity: at least one of `rating`, `reason`,
 *     `comment`; `rating` `up` or `down`; `reason` at most 255 characters;
 *     `message` belongs to `conversation` (422 with violations).
 *
 * Then `source`, `subject` (the caller's user identifier; for a capability
 * token the conversation's `ownerSubject`) and `createdAt` are set and the
 * feedback is stored (201).
 *
 * @implements ProcessorInterface<Feedback, Feedback>
 */
final class FeedbackSubmitProcessor implements ProcessorInterface
{
    public const string SETTING = 'evaluation.feedback_per_subject_and_hour';

    /** Submissions per subject and hour without an application setting. */
    public const int DEFAULT_LIMIT = 30;


    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly ValidatorInterface $validator,
        private readonly FeedbackRateLimiter $rateLimiter,
        private readonly ClockInterface $clock,
        private readonly ?EvaluationSettingsInterface $settings = null,
    ) {
    }

    /**
     * @throws ValidationException          without a conversation, or for an invalid feedback
     * @throws AccessDeniedException        when the caller may not give feedback on the conversation
     * @throws AccessDeniedHttpException    when feedback is disabled (limit 0)
     * @throws TooManyRequestsHttpException when the caller's limit is used up
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Feedback
    {
        if (!$data instanceof Feedback) {
            throw new BadRequestHttpException('The request body is not a feedback.');
        }

        $conversation = $data->getConversation();
        if (null === $conversation) {
            $violations = new ConstraintViolationList([
                new ConstraintViolation('The conversation is required.', null, [], $data, 'conversation', null),
            ]);

            throw new ValidationException($violations);
        }

        $subject = $this->subject();
        $source = $this->source($conversation);

        $limit = $this->settings?->feedbackPerSubjectAndHour() ?? self::DEFAULT_LIMIT;
        if ($limit < 1) {
            throw new AccessDeniedHttpException(\sprintf('Feedback is disabled (setting "%s" is 0).', self::SETTING));
        }
        $this->rateLimiter->consume($subject, $limit);

        $this->validator->validate($data);

        $data
            ->setSource($source)
            ->setSubject($subject)
            ->setCreatedAt($this->clock->now());
        $this->entityManager->persist($data);
        $this->entityManager->flush();

        return $data;
    }

    /**
     * @throws AccessDeniedException when the caller may not give feedback on the conversation
     */
    private function source(Conversation $conversation): FeedbackSource
    {
        if ($this->authorizationChecker->isGranted(Permissions::FEEDBACK_SUBMIT_REVIEWER)
            && $this->authorizationChecker->isGranted(new Expression(ConversationSecurity::READ), $conversation)) {
            return FeedbackSource::REVIEWER;
        }

        if ($this->authorizationChecker->isGranted(new Expression(ConversationSecurity::TURN), $conversation)) {
            return FeedbackSource::ENDUSER;
        }

        throw new AccessDeniedException('You may not give feedback on this conversation.');
    }

    /**
     * @throws AccessDeniedException without an authenticated user
     */
    private function subject(): string
    {
        $token = $this->tokenStorage->getToken();
        $identifier = null !== $token?->getUser() ? $token->getUserIdentifier() : '';
        if ('' === $identifier) {
            throw new AccessDeniedException('Giving feedback requires an authenticated user.');
        }

        return $identifier;
    }
}
