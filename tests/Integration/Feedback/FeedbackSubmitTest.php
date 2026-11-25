<?php

// file generated with AI assistance: Claude Code - 2026-10-03 21:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Feedback;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Anonymous\CapabilityToken;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Feedback;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Feedback\FeedbackSource;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryEvaluationSettings;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Permission matrix, rate limit and validation of `POST /api/feedback`
 * (`feedback_submit`) and the read access of `GET /api/feedback`.
 *
 * Submission: the owner (`ROLE_USER`) and the holder of the capability
 * token of the conversation submit with `source` `enduser`; a holder of
 * `ROLE_EVAL_REVIEWER` (directly or through `ROLE_ADMIN`) who may read the
 * conversation submits with `source` `reviewer` (an evaluation conversation
 * of anybody, or an own one); a reviewer on a foreign ordinary conversation
 * may not read it and gets 403, as do a foreign `ROLE_USER` and a token
 * holder on another conversation. `source` and `subject` in the body are
 * ignored. A message of another conversation, an empty feedback, an
 * invalid rating and a missing conversation answer 422. The limit
 * `evaluation.feedback_per_subject_and_hour` at 2 refuses the third
 * submission with 429 and a numeric `Retry-After`; at 0 every submission
 * answers 403 naming the setting.
 *
 * Reading: `ROLE_EVAL_REVIEWER`, `ROLE_EVAL_ADMIN` and `ROLE_ADMIN` list
 * and read feedback (filter `conversation`); `ROLE_USER` and token holders
 * get 403; the filter `conversation.agentVersion.agent` lists the feedback
 * of one agent, and every item names its `agent`.
 *
 * Review: `PATCH /api/feedback/{id}` changes `status` and `reviewNote` for
 * the evaluation roles and ignores `rating`; `ROLE_USER` and token holders
 * get 403, another status 422. There is no DELETE.
 *
 * Test identities as documented in the test kernel's security configuration: `X-Test-Roles` replaces
 * the roles, `X-Test-User` the identifier (every test uses fresh
 * identifiers, so the rate-limit counters of earlier runs never apply);
 * capability tokens are sent as `Authorization: Bearer anon_…` with
 * `X-Test-Auth: anonymous`. Conversations are written through the entity
 * manager (owner prefix {@see self::PREFIX}, anonymous ones tracked by id);
 * the `evaluation` settings row is removed before and after every test, so
 * the limit starts at the default (30). Needs the migrated test database
 * (CI only).
 */
#[Group('database')]
final class FeedbackSubmitTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    public const string PREFIX = 'test-feedback-';
    public const string SETTING = 'evaluation.feedback_per_subject_and_hour';

    private const string ROLES_USER = 'ROLE_USER';
    private const string ROLES_REVIEWER = 'ROLE_USER,ROLE_EVAL_REVIEWER';
    private const string ROLES_EVAL_ADMIN = 'ROLE_USER,ROLE_EVAL_ADMIN';
    private const string ROLES_ADMIN = 'ROLE_ADMIN';

    /** @var list<string> ids of the anonymous conversations of the test */
    private static array $anonymousIds = [];

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        self::cleanUp();
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        parent::tearDown();
    }

    // --- submission: who may ---------------------------------------------

    public function testOwnerSubmitsAsEndUserAndBodySourceIsIgnored(): void
    {
        $owner = self::identity('owner');
        $conversation = self::conversation($owner);

        $response = self::submit([
            'conversation' => self::iri($conversation),
            'rating' => 'up',
            'reason' => 'correct',
            'comment' => 'Exactly what I needed.',
            'source' => 'reviewer',
            'subject' => 'somebody-else',
        ], self::asUser(self::ROLES_USER, $owner));

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        $body = $response->toArray();
        self::assertSame('Feedback', $body['@type']);
        self::assertStringStartsWith('/api/feedback/', (string) $body['@id']);
        self::assertSame(self::iri($conversation), $body['conversation']);
        self::assertSame('up', $body['rating']);
        self::assertSame('correct', $body['reason']);
        self::assertSame('Exactly what I needed.', $body['comment']);
        self::assertSame('enduser', $body['source']);
        self::assertSame($owner, $body['subject']);
        self::assertNotEmpty($body['createdAt']);
        self::assertArrayNotHasKey('message', array_filter($body, static fn (mixed $value): bool => null !== $value));

        $rows = self::rows($conversation);
        self::assertCount(1, $rows);
        self::assertSame('enduser', $rows[0]['source']);
        self::assertSame($owner, $rows[0]['subject']);
        self::assertNull($rows[0]['message_id']);
    }

    public function testOwnerSubmitsOnOneMessage(): void
    {
        $owner = self::identity('owner');
        $conversation = self::conversation($owner);
        $message = self::message($conversation);

        $response = self::submit([
            'conversation' => self::iri($conversation),
            'message' => self::messageIri($message),
            'rating' => 'down',
        ], self::asUser(self::ROLES_USER, $owner));

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        self::assertSame(self::messageIri($message), $response->toArray()['message']);
        self::assertSame((string) $message->getId(), self::rows($conversation)[0]['message_id']);
    }

    public function testCapabilityTokenHolderSubmitsAsEndUser(): void
    {
        $token = self::token();
        $conversation = self::anonymous($token);

        $response = self::submit([
            'conversation' => self::iri($conversation),
            'rating' => 'up',
            'source' => 'reviewer',
        ], self::asToken($token));

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        $body = $response->toArray();
        self::assertSame('enduser', $body['source']);
        self::assertSame($conversation->getOwnerSubject(), $body['subject']);
        self::assertSame('enduser', self::rows($conversation)[0]['source']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function reviewers(): iterable
    {
        yield 'ROLE_EVAL_REVIEWER' => [self::ROLES_REVIEWER];
        yield 'ROLE_ADMIN through the map' => [self::ROLES_ADMIN];
    }

    #[DataProvider('reviewers')]
    public function testReviewerSubmitsAsReviewerOnAForeignEvalConversation(string $roles): void
    {
        $conversation = self::conversation(self::identity('starter'), Conversation::ORIGIN_EVAL);
        $reviewer = self::identity('reviewer');

        $response = self::submit([
            'conversation' => self::iri($conversation),
            'rating' => 'down',
            'reason' => 'missing source',
        ], self::asUser($roles, $reviewer));

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        self::assertSame('reviewer', $response->toArray()['source']);
        self::assertSame($reviewer, $response->toArray()['subject']);
        self::assertSame('reviewer', self::rows($conversation)[0]['source']);
    }

    public function testReviewerOnAnOwnOrdinaryConversationSubmitsAsReviewer(): void
    {
        $reviewer = self::identity('reviewer');
        $conversation = self::conversation($reviewer);

        $response = self::submit(['conversation' => self::iri($conversation), 'comment' => 'Own conversation.'], self::asUser(self::ROLES_REVIEWER, $reviewer));

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        self::assertSame('reviewer', $response->toArray()['source']);
    }

    /**
     * `neuronai:conversation:read_own` grants reviewers evaluation conversations only
     * (plus their own); a foreign ordinary conversation stays closed.
     */
    public function testReviewerOnAForeignOrdinaryConversationIsForbidden(): void
    {
        $conversation = self::conversation(self::identity('owner'));

        $response = self::submit(['conversation' => self::iri($conversation), 'rating' => 'up'], self::asUser(self::ROLES_REVIEWER, self::identity('reviewer')));

        self::assertSame(403, $response->getStatusCode(), $response->getContent(false));
        self::assertCount(0, self::rows($conversation));
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function foreignConversations(): iterable
    {
        yield 'ordinary' => [null];
        yield 'evaluation' => [Conversation::ORIGIN_EVAL];
    }

    #[DataProvider('foreignConversations')]
    public function testForeignUserIsForbidden(?string $origin): void
    {
        $conversation = self::conversation(self::identity('owner'), $origin);

        $response = self::submit(['conversation' => self::iri($conversation), 'rating' => 'up'], self::asUser(self::ROLES_USER, self::identity('foreign')));

        self::assertSame(403, $response->getStatusCode(), $response->getContent(false));
        self::assertCount(0, self::rows($conversation));
    }

    public function testEvalAdminWithoutReviewerRoleIsForbiddenOnAForeignEvalConversation(): void
    {
        $conversation = self::conversation(self::identity('starter'), Conversation::ORIGIN_EVAL);

        $response = self::submit(['conversation' => self::iri($conversation), 'rating' => 'up'], self::asUser(self::ROLES_EVAL_ADMIN, self::identity('eval-admin')));

        self::assertSame(403, $response->getStatusCode(), $response->getContent(false));
        self::assertCount(0, self::rows($conversation));
    }

    public function testTokenHolderOnAnotherConversationIsForbidden(): void
    {
        $token = self::token();
        self::anonymous($token);
        $otherAnonymous = self::anonymous(self::token());
        $ordinary = self::conversation(self::identity('owner'));

        foreach ([$otherAnonymous, $ordinary] as $conversation) {
            $response = self::submit(['conversation' => self::iri($conversation), 'rating' => 'up'], self::asToken($token));

            self::assertSame(403, $response->getStatusCode(), $response->getContent(false));
            self::assertCount(0, self::rows($conversation));
        }
    }

    public function testRequestWithoutCredentialsIsUnauthorized(): void
    {
        $conversation = self::conversation(self::identity('owner'));

        $response = self::submit(['conversation' => self::iri($conversation), 'rating' => 'up'], [TestHeaderAuthenticator::HEADER_AUTH => 'anonymous']);

        self::assertSame(401, $response->getStatusCode(), $response->getContent(false));
        self::assertCount(0, self::rows($conversation));
    }

    // --- submission: validation -------------------------------------------

    public function testMessageOfAnotherConversationIsUnprocessable(): void
    {
        $owner = self::identity('owner');
        $conversation = self::conversation($owner);
        $other = self::conversation($owner);
        $foreignMessage = self::message($other);

        $response = self::submit([
            'conversation' => self::iri($conversation),
            'message' => self::messageIri($foreignMessage),
            'rating' => 'up',
        ], self::asUser(self::ROLES_USER, $owner));

        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertContains('message', self::violationPaths($response));
        self::assertCount(0, self::rows($conversation));
        self::assertCount(0, self::rows($other));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function emptyFeedback(): iterable
    {
        yield 'nothing but the conversation' => [[]];
        yield 'nulls' => [['rating' => null, 'reason' => null, 'comment' => null]];
        yield 'blank texts' => [['reason' => '  ', 'comment' => '']];
        yield 'only the ignored source' => [['source' => 'enduser']];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[DataProvider('emptyFeedback')]
    public function testFeedbackWithoutRatingReasonAndCommentIsUnprocessable(array $fields): void
    {
        $owner = self::identity('owner');
        $conversation = self::conversation($owner);

        $response = self::submit(['conversation' => self::iri($conversation)] + $fields, self::asUser(self::ROLES_USER, $owner));

        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertNotSame([], self::violationPaths($response));
        self::assertCount(0, self::rows($conversation));
    }

    public function testInvalidRatingIsUnprocessable(): void
    {
        $owner = self::identity('owner');
        $conversation = self::conversation($owner);

        $response = self::submit(['conversation' => self::iri($conversation), 'rating' => 'sideways'], self::asUser(self::ROLES_USER, $owner));

        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertContains('rating', self::violationPaths($response));
        self::assertCount(0, self::rows($conversation));
    }

    public function testTooLongReasonIsUnprocessable(): void
    {
        $owner = self::identity('owner');
        $conversation = self::conversation($owner);

        $response = self::submit(['conversation' => self::iri($conversation), 'reason' => str_repeat('x', Feedback::MAX_REASON_LENGTH + 1)], self::asUser(self::ROLES_USER, $owner));

        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertContains('reason', self::violationPaths($response));
    }

    public function testMissingConversationIsUnprocessable(): void
    {
        $response = self::submit(['rating' => 'up'], self::asUser(self::ROLES_USER, self::identity('owner')));

        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertContains('conversation', self::violationPaths($response));
    }

    /**
     * An IRI that resolves to nothing is refused by API Platform's
     * denormalization before the processor runs (a client error, no row).
     */
    public function testUnknownConversationIsRefused(): void
    {
        $response = self::submit(['conversation' => '/api/conversations/00000000-0000-4000-8000-000000000000', 'rating' => 'up'], self::asUser(self::ROLES_USER, self::identity('owner')));

        self::assertContains($response->getStatusCode(), [400, 404, 422], $response->getContent(false));
    }

    // --- submission: limit ------------------------------------------------

    public function testThirdSubmissionAtLimitTwoIsTooManyRequests(): void
    {
        self::configureLimit(2);
        $owner = self::identity('owner');
        $conversation = self::conversation($owner);
        $body = ['conversation' => self::iri($conversation), 'rating' => 'up'];

        for ($i = 1; $i <= 2; ++$i) {
            $response = self::submit($body, self::asUser(self::ROLES_USER, $owner));
            self::assertSame(201, $response->getStatusCode(), 'submission '.$i.': '.$response->getContent(false));
        }

        $refused = self::submit($body, self::asUser(self::ROLES_USER, $owner));
        self::assertSame(429, $refused->getStatusCode(), $refused->getContent(false));
        $retryAfter = $refused->getHeaders(false)['retry-after'][0] ?? null;
        self::assertNotNull($retryAfter);
        self::assertMatchesRegularExpression('/^\d+$/', $retryAfter);
        self::assertGreaterThanOrEqual(1, (int) $retryAfter);
        self::assertCount(2, self::rows($conversation));

        // Another subject has its own count.
        $token = self::token();
        $anonymous = self::anonymous($token);
        $response = self::submit(['conversation' => self::iri($anonymous), 'rating' => 'up'], self::asToken($token));
        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
    }

    public function testCapabilityTokenHolderIsLimitedToo(): void
    {
        self::configureLimit(2);
        $token = self::token();
        $conversation = self::anonymous($token);
        $body = ['conversation' => self::iri($conversation), 'comment' => 'Helpful.'];

        self::submit($body, self::asToken($token));
        self::submit($body, self::asToken($token));
        $refused = self::submit($body, self::asToken($token));

        self::assertSame(429, $refused->getStatusCode(), $refused->getContent(false));
        self::assertCount(2, self::rows($conversation));
    }

    public function testLimitZeroDisablesFeedbackNamingTheSetting(): void
    {
        self::configureLimit(0);
        $owner = self::identity('owner');
        $conversation = self::conversation($owner);

        $response = self::submit(['conversation' => self::iri($conversation), 'rating' => 'up'], self::asUser(self::ROLES_USER, $owner));

        self::assertSame(403, $response->getStatusCode(), $response->getContent(false));
        self::assertStringContainsString(self::SETTING, (string) ($response->toArray(false)['detail'] ?? ''));
        self::assertCount(0, self::rows($conversation));
    }

    // --- reading ----------------------------------------------------------

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function readers(): iterable
    {
        yield 'ROLE_EVAL_REVIEWER' => [self::ROLES_REVIEWER];
        yield 'ROLE_EVAL_ADMIN' => [self::ROLES_EVAL_ADMIN];
        yield 'ROLE_ADMIN through the map' => [self::ROLES_ADMIN];
    }

    #[DataProvider('readers')]
    public function testEvalRolesListAndReadFeedback(string $roles): void
    {
        $conversation = self::conversation(self::identity('owner'));
        $feedback = self::feedback($conversation, FeedbackSource::ENDUSER, 'down');
        $reader = self::asUser($roles, self::identity('reader'));

        $list = self::request('GET', '/api/feedback?conversation='.urlencode(self::iri($conversation)), $reader);
        self::assertSame(200, $list->getStatusCode(), $list->getContent(false));
        $data = $list->toArray();
        $members = array_values($data['hydra:member'] ?? $data['member'] ?? []);
        self::assertSame(['/api/feedback/'.$feedback->getId()], array_column($members, '@id'));

        $filtered = self::request('GET', '/api/feedback?conversation='.urlencode(self::iri($conversation)).'&source=reviewer', $reader);
        self::assertSame(200, $filtered->getStatusCode());
        $data = $filtered->toArray();
        self::assertSame([], array_values($data['hydra:member'] ?? $data['member'] ?? []));

        $item = self::request('GET', '/api/feedback/'.$feedback->getId(), $reader);
        self::assertSame(200, $item->getStatusCode(), $item->getContent(false));
        self::assertSame('down', $item->toArray()['rating']);
        self::assertSame('enduser', $item->toArray()['source']);
    }

    public function testUserAndTokenHolderCannotReadFeedback(): void
    {
        $token = self::token();
        $conversation = self::anonymous($token);
        $feedback = self::feedback($conversation, FeedbackSource::ENDUSER, 'up');

        foreach ([self::asUser(self::ROLES_USER, self::identity('user')), self::asToken($token)] as $headers) {
            self::assertSame(403, self::request('GET', '/api/feedback', $headers)->getStatusCode());
            self::assertSame(403, self::request('GET', '/api/feedback/'.$feedback->getId(), $headers)->getStatusCode());
        }
    }

    public function testAgentFilterListsTheFeedbackOfOneAgent(): void
    {
        $agent = self::agent('filter');
        $withAgent = self::conversation(self::identity('owner'))->setAgentVersion($agent->getCurrentVersion());
        self::entityManager()->flush();
        $feedback = self::feedback($withAgent, FeedbackSource::ENDUSER, 'down');
        self::feedback(self::conversation(self::identity('owner')), FeedbackSource::ENDUSER, 'down');
        $reader = self::asUser(self::ROLES_REVIEWER, self::identity('reader'));

        $agentIri = '/api/agents/'.$agent->getId();
        $list = self::request('GET', '/api/feedback?'.http_build_query(['conversation.agentVersion.agent' => $agentIri]), $reader);
        self::assertSame(200, $list->getStatusCode(), $list->getContent(false));
        $data = $list->toArray();
        $members = array_values($data['hydra:member'] ?? $data['member'] ?? []);
        self::assertSame(['/api/feedback/'.$feedback->getId()], array_column($members, '@id'));
        self::assertSame($agentIri, $members[0]['agent']);
        self::assertSame('open', $members[0]['status']);
    }

    #[DataProvider('readers')]
    public function testEvalRolesReviewFeedbackButCannotChangeIt(string $roles): void
    {
        $conversation = self::conversation(self::identity('owner'));
        $feedback = self::feedback($conversation, FeedbackSource::ENDUSER, 'up');

        $patch = self::patch($feedback, ['rating' => 'down', 'status' => 'fixed', 'reviewNote' => 'Prompt rule added.'], self::asUser($roles, self::identity('reviewer')));
        self::assertSame(200, $patch->getStatusCode(), $patch->getContent(false));
        self::assertSame('fixed', $patch->toArray()['status']);

        $row = self::rows($conversation)[0];
        self::assertSame('up', $row['rating']);
        self::assertSame('fixed', $row['status']);
        self::assertSame('Prompt rule added.', $row['review_note']);
    }

    public function testInvalidStatusIsUnprocessable(): void
    {
        $feedback = self::feedback(self::conversation(self::identity('owner')), FeedbackSource::ENDUSER, 'down');

        $patch = self::patch($feedback, ['status' => 'done'], self::asUser(self::ROLES_ADMIN, self::identity('admin')));
        self::assertSame(422, $patch->getStatusCode(), $patch->getContent(false));
        self::assertSame(['status'], self::violationPaths($patch));
    }

    public function testUserAndTokenHolderCannotReviewAndNobodyDeletes(): void
    {
        $token = self::token();
        $conversation = self::anonymous($token);
        $feedback = self::feedback($conversation, FeedbackSource::ENDUSER, 'down');

        foreach ([self::asUser(self::ROLES_USER, self::identity('user')), self::asToken($token)] as $headers) {
            self::assertSame(403, self::patch($feedback, ['status' => 'fixed'], $headers)->getStatusCode());
        }
        self::assertSame(405, self::request('DELETE', '/api/feedback/'.$feedback->getId(), self::asUser(self::ROLES_ADMIN, self::identity('admin')))->getStatusCode());
        self::assertSame('open', self::rows($conversation)[0]['status']);
    }

    // --- helpers: identities and requests ---------------------------------

    /**
     * A fresh user identifier (own rate-limit count per test and run).
     */
    public static function identity(string $name): string
    {
        return self::PREFIX.$name.'-'.bin2hex(random_bytes(4));
    }

    /**
     * A fresh capability token (`anon_` + 32 random URL-safe characters).
     */
    public static function token(): string
    {
        return 'anon_'.rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    /**
     * @return array<string, string>
     */
    public static function asUser(string $roles, string $identifier): array
    {
        return [
            TestHeaderAuthenticator::HEADER_ROLES => $roles,
            TestHeaderAuthenticator::HEADER_USER => $identifier,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function asToken(string $token): array
    {
        return [
            TestHeaderAuthenticator::HEADER_AUTH => 'anonymous',
            'Authorization' => 'Bearer '.$token,
        ];
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public static function submit(array $body, array $headers): ResponseInterface
    {
        return static::createClient()->request('POST', '/api/feedback', [
            'headers' => ['Accept' => 'application/ld+json', 'Content-Type' => 'application/ld+json'] + $headers,
            'json' => $body,
        ]);
    }

    /**
     * @param array<string, string> $headers
     */
    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private static function patch(Feedback $feedback, array $body, array $headers): ResponseInterface
    {
        return static::createClient()->request('PATCH', '/api/feedback/'.$feedback->getId(), [
            'headers' => ['Accept' => 'application/ld+json', 'Content-Type' => 'application/merge-patch+json'] + $headers,
            'json' => $body,
        ]);
    }

    private static function request(string $method, string $uri, array $headers): ResponseInterface
    {
        return static::createClient()->request($method, $uri, ['headers' => ['Accept' => 'application/ld+json'] + $headers]);
    }

    /**
     * @return list<string>
     */
    private static function violationPaths(ResponseInterface $response): array
    {
        return array_map('strval', array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'));
    }

    /**
     * Sets `evaluation.feedback_per_subject_and_hour` as the default test
     * identity (`test-admin`, ROLE_ADMIN).
     */
    private static function configureLimit(int $limit): void
    {
        InMemoryEvaluationSettings::configure(['feedback_per_subject_and_hour' => $limit]);
    }

    // --- helpers: fixtures ------------------------------------------------

    public static function iri(Conversation $conversation): string
    {
        return '/api/conversations/'.$conversation->getId();
    }

    public static function messageIri(Message $message): string
    {
        return '/api/messages/'.$message->getId();
    }

    /**
     * An ordinary (origin null) or evaluation conversation of $owner.
     */
    private static function conversation(string $owner, ?string $origin = null): Conversation
    {
        $conversation = new Conversation()
            ->setOwnerSubject($owner)
            ->setTitle(self::PREFIX.($origin ?? 'ordinary'))
            ->setOrigin($origin)
            ->setProviderModel(ConversationConnections::model())
            ->setRetrievalDepth(4)
            ->setNextSequence(2);
        self::entityManager()->persist($conversation);
        self::entityManager()->flush();

        return $conversation;
    }

    /**
     * An anonymous conversation whose capability token is $token.
     */
    private static function anonymous(string $token): Conversation
    {
        $conversation = new Conversation();
        $conversation
            ->setOwnerSubject(Conversation::ANONYMOUS_OWNER_PREFIX.$conversation->getId()->toRfc4122())
            ->setAnonymous(true)
            ->setTokenHash(CapabilityToken::hash($token))
            ->setAddressHash(hash('sha256', 'feedback'))
            ->setProviderModel(ConversationConnections::model())
            ->setRetrievalDepth(4);
        self::entityManager()->persist($conversation);
        self::entityManager()->flush();
        self::$anonymousIds[] = (string) $conversation->getId();

        return $conversation;
    }

    /**
     * The assistant answer (sequence 1) of the conversation.
     */
    private static function message(Conversation $conversation): Message
    {
        $message = new Message()
            ->setConversation($conversation)
            ->setSequence(1)
            ->setRole(Message::SPEAKER_ASSISTANT)
            ->setKind(MessageKind::TEXT)
            ->setStatus(MessageStatus::COMPLETE)
            ->setContent('Every 500 operating hours.');
        self::entityManager()->persist($message);
        self::entityManager()->flush();

        return $message;
    }

    /**
     * An enabled agent with a published version 1 (slug prefix of
     * {@see AgentFixtures}, removed by its cleanUp()).
     */
    private static function agent(string $slug): Agent
    {
        $entityManager = self::entityManager();
        $agent = new Agent()->setName('Feedback '.$slug)->setSlug(AgentFixtures::PREFIX.'feedback-'.$slug)->setEnabled(true);
        $entityManager->persist($agent);
        $entityManager->flush();
        $version = new AgentVersion($agent, 1, ConversationConnections::withConnection(Agent::candidateDefaults()), 'test-admin');
        $entityManager->persist($version);
        $agent->setCurrentVersion($version);
        $entityManager->flush();

        return $agent;
    }

    private static function feedback(Conversation $conversation, FeedbackSource $source, string $rating): Feedback
    {
        $feedback = new Feedback()
            ->setConversation($conversation)
            ->setRating($rating)
            ->setSource($source)
            ->setSubject((string) $conversation->getOwnerSubject());
        self::entityManager()->persist($feedback);
        self::entityManager()->flush();

        return $feedback;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(Conversation $conversation): array
    {
        return self::connection()->fetchAllAssociative(
            'SELECT * FROM neuron_feedback WHERE conversation_id = :id ORDER BY created_at',
            ['id' => (string) $conversation->getId()],
        );
    }

    /**
     * The test conversations (feedback and messages cascade), their audit
     * entries and the `evaluation` settings row with its audit entries.
     */
    private static function cleanUp(): void
    {
        static::bootKernel();
        $connection = self::connection();
        $ids = $connection->fetchFirstColumn('SELECT id FROM neuron_conversation WHERE owner_subject LIKE :prefix', ['prefix' => self::PREFIX.'%']);
        $ids = array_values(array_unique([...array_map('strval', $ids), ...self::$anonymousIds]));
        if ([] !== $ids) {
            $connection->executeStatement('DELETE FROM neuron_conversation WHERE id IN (:ids)', ['ids' => $ids], ['ids' => ArrayParameterType::STRING]);
            $connection->executeStatement('DELETE FROM dmstr_log_entries WHERE object_class = :class AND object_id IN (:ids)', ['class' => Conversation::class, 'ids' => $ids], ['ids' => ArrayParameterType::STRING]);
        }
        self::$anonymousIds = [];
        static::ensureKernelShutdown();

        AgentFixtures::cleanUp();
    }

    private static function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    private static function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');

        return $connection;
    }
}
