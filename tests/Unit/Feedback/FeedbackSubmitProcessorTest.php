<?php

// file generated with AI assistance: Claude Code - 2026-10-03 21:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Feedback;

use ApiPlatform\Metadata\Post;
use ApiPlatform\Symfony\Validator\Validator;
use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Feedback;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Evaluation\EvaluationSettingsInterface;
use Dmstr\NeuronAiBundle\Feedback\FeedbackRateLimiter;
use Dmstr\NeuronAiBundle\Security\ConversationSecurity;
use Dmstr\NeuronAiBundle\Security\Permissions;
use Dmstr\NeuronAiBundle\State\FeedbackSubmitProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Validator\Validation;

/**
 * The checks of `feedback_submit` in their order, kernel-free:
 * missing conversation (422) before the permission, the permission (source
 * `reviewer` or `enduser`, else 403) before the limit, the limit (0: 403
 * naming the setting; used up: 429) before the validation of the entity
 * (empty feedback, message of another conversation: 422). Authorization is
 * a stub that grants the listed attributes; the validator is the real
 * Symfony validator with attribute mapping.
 */
final class FeedbackSubmitProcessorTest extends TestCase
{
    private const string USER = 'user-1';

    private Conversation $conversation;

    /** @var list<array{0: string, 1: mixed}> attribute checks the stub saw */
    private array $checks = [];

    private int $flushes = 0;

    private FeedbackRateLimiter $limiter;

    protected function setUp(): void
    {
        $this->conversation = new Conversation()->setOwnerSubject('owner-1');
        $this->limiter = new FeedbackRateLimiter(new CacheStorage(new ArrayAdapter()));
    }

    public function testOwnerSubmitsAsEndUser(): void
    {
        $feedback = $this->processor([ConversationSecurity::TURN])->process($this->feedback('up'), new Post());

        self::assertSame('enduser', $feedback->getSource());
        self::assertSame(self::USER, $feedback->getSubject());
        self::assertSame('2026-10-03 12:00:00', $feedback->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertSame(1, $this->flushes);
    }

    public function testReviewerWhoMayReadSubmitsAsReviewer(): void
    {
        $feedback = $this->processor([Permissions::FEEDBACK_SUBMIT_REVIEWER, ConversationSecurity::READ, ConversationSecurity::TURN])->process($this->feedback('down'), new Post());

        self::assertSame('reviewer', $feedback->getSource());
    }

    public function testReviewerRoleWithoutReadFallsBackToTheOwnerCheck(): void
    {
        $feedback = $this->processor([Permissions::FEEDBACK_SUBMIT_REVIEWER, ConversationSecurity::TURN])->process($this->feedback('up'), new Post());

        self::assertSame('enduser', $feedback->getSource());
    }

    public function testReadWithoutReviewerRoleIsNoReviewerFeedback(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->processor([ConversationSecurity::READ])->process($this->feedback('up'), new Post());
    }

    public function testDeniedSubmissionDoesNotCount(): void
    {
        try {
            $this->processor([], 1)->process($this->feedback('up'), new Post());
            self::fail('A caller without permission was accepted.');
        } catch (AccessDeniedException) {
        }

        $this->processor([ConversationSecurity::TURN], 1)->process($this->feedback('up'), new Post());
        self::assertSame(1, $this->flushes);
    }

    public function testMissingConversationIsCheckedBeforeThePermission(): void
    {
        try {
            $this->processor([])->process(new Feedback()->setRating('up'), new Post());
            self::fail('A feedback without conversation was accepted.');
        } catch (ValidationException $e) {
            self::assertSame('conversation', $e->getConstraintViolationList()->get(0)->getPropertyPath());
        }
        self::assertSame([], $this->checks);
    }

    public function testLimitZeroForbidsNamingTheSettingBeforeTheValidation(): void
    {
        try {
            $this->processor([ConversationSecurity::TURN], 0)->process($this->feedback(null), new Post());
            self::fail('Feedback was accepted with the limit 0.');
        } catch (AccessDeniedHttpException $e) {
            self::assertSame(403, $e->getStatusCode());
            self::assertStringContainsString(FeedbackSubmitProcessor::SETTING, $e->getMessage());
        }
        self::assertSame(0, $this->flushes);
    }

    public function testEveryPermittedSubmissionCountsAlsoAnInvalidOne(): void
    {
        $processor = $this->processor([ConversationSecurity::TURN], 2);
        $processor->process($this->feedback('up'), new Post());
        try {
            $processor->process($this->feedback(null), new Post());
            self::fail('An empty feedback was accepted.');
        } catch (ValidationException) {
        }

        try {
            $processor->process($this->feedback('up'), new Post());
            self::fail('The third submission was accepted at the limit 2.');
        } catch (TooManyRequestsHttpException $e) {
            self::assertArrayHasKey('Retry-After', $e->getHeaders());
        }
        self::assertSame(1, $this->flushes);
    }

    public function testWithoutSettingsTheBundleDefaultApplies(): void
    {
        $processor = $this->processor([ConversationSecurity::TURN], null);
        for ($i = 0; $i < FeedbackSubmitProcessor::DEFAULT_LIMIT; ++$i) {
            $processor->process($this->feedback('up'), new Post());
        }

        $this->expectException(TooManyRequestsHttpException::class);
        $processor->process($this->feedback('up'), new Post());
    }

    public function testEmptyFeedbackIsUnprocessable(): void
    {
        $this->expectException(ValidationException::class);

        $this->processor([ConversationSecurity::TURN])->process($this->feedback(null)->setReason(' ')->setComment(''), new Post());
    }

    public function testInvalidRatingIsUnprocessable(): void
    {
        try {
            $this->processor([ConversationSecurity::TURN])->process($this->feedback('sideways'), new Post());
            self::fail('An invalid rating was accepted.');
        } catch (ValidationException $e) {
            self::assertSame('rating', $e->getConstraintViolationList()->get(0)->getPropertyPath());
        }
    }

    public function testMessageOfAnotherConversationIsUnprocessable(): void
    {
        $other = new Conversation()->setOwnerSubject('owner-1');
        $feedback = $this->feedback('up')->setMessage(new Message()->setConversation($other));

        try {
            $this->processor([ConversationSecurity::TURN])->process($feedback, new Post());
            self::fail('A message of another conversation was accepted.');
        } catch (ValidationException $e) {
            self::assertSame('message', $e->getConstraintViolationList()->get(0)->getPropertyPath());
        }
        self::assertSame(0, $this->flushes);
    }

    public function testMessageOfTheConversationIsAccepted(): void
    {
        $feedback = $this->feedback(null)->setComment('This answer.')->setMessage(new Message()->setConversation($this->conversation));

        $this->processor([ConversationSecurity::TURN])->process($feedback, new Post());

        self::assertSame(1, $this->flushes);
    }

    private function feedback(?string $rating): Feedback
    {
        return new Feedback()->setConversation($this->conversation)->setRating($rating);
    }

    /**
     * @param list<string> $granted attributes the stub grants (roles and permissions)
     * @param int|null     $limit   the setting; null: no settings service
     */
    private function processor(array $granted, ?int $limit = 30): FeedbackSubmitProcessor
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser(self::USER, null, ['ROLE_USER']), 'api', ['ROLE_USER']));

        $authorizationChecker = new class($granted, $this->checks) implements AuthorizationCheckerInterface {
            /**
             * @param list<string>                     $granted
             * @param list<array{0: string, 1: mixed}> $checks
             */
            public function __construct(private readonly array $granted, private array &$checks)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                $this->checks[] = [(string) $attribute, $subject];

                return \in_array((string) $attribute, $this->granted, true);
            }
        };

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        $settings = null;
        if (null !== $limit) {
            $settings = $this->createStub(EvaluationSettingsInterface::class);
            $settings->method('feedbackPerSubjectAndHour')->willReturn($limit);
        }

        return new FeedbackSubmitProcessor(
            $entityManager,
            $tokenStorage,
            $authorizationChecker,
            new Validator(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()),
            $this->limiter,
            new MockClock('2026-10-03 12:00:00'),
            $settings,
        );
    }
}
