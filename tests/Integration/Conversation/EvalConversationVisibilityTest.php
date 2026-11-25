<?php

// file generated with AI assistance: Claude Code - 2026-10-03 18:38:08 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Conversation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Visibility of evaluation conversations (`origin` = `eval`):
 * the ordinary lists hide them, `?origin=eval` lists them (own ones for a
 * plain user, all of them for a holder of `neuronai:conversation:read_eval`), a
 * reviewer reads a foreign evaluation conversation and its messages but no
 * foreign ordinary conversation, and `origin` cannot be set through
 * `POST /conversations`. Replay conversations (`origin` = `replay`, stage
 * 11a) follow the same rules with `?origin=replay`.
 *
 * Identities: the owner is `test-user` (the identifier of
 * {@see TestHeaderAuthenticator} when `X-Test-Roles` is sent without
 * `X-Test-User`), the second owner and the reviewer use `X-Test-User`. The
 * reviewer sends `ROLE_USER,ROLE_EVAL_REVIEWER`: `X-Test-Roles` replaces
 * the roles and the list operations require `ROLE_USER`, which the
 * evaluation roles do not imply. `neuronai:conversation:read_eval` comes from
 * {@see \Dmstr\NeuronAiBundle\Tests\Kernel\TestRoles}.
 *
 * Needs the migrated test database; runs in CI. Fixtures are written
 * through the entity manager; the conversations of `test-user` carry the
 * title prefix {@see self::TITLE_PREFIX}, the other owners the identifier
 * prefix {@see self::PREFIX}, and both are removed before and after each
 * test (messages cascade).
 */
#[Group('database')]
final class EvalConversationVisibilityTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const string PREFIX = 'test-eval-vis-';
    private const string TITLE_PREFIX = 'eval-visibility-';

    private const string OWNER = TestHeaderAuthenticator::USER_IDENTIFIER;
    private const string OTHER = self::PREFIX.'other';
    private const string REVIEWER = self::PREFIX.'reviewer';

    private const string ROLES_USER = 'ROLE_USER';
    private const string ROLES_REVIEWER = 'ROLE_USER,ROLE_EVAL_REVIEWER';

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        self::cleanUp();
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        parent::tearDown();
    }

    public function testOrdinaryListHidesEvalConversations(): void
    {
        $ordinary = self::conversation(self::OWNER, null);
        $eval = self::conversation(self::OWNER, Conversation::ORIGIN_EVAL);

        $ids = self::ids(self::get('/api/conversations', self::ROLES_USER));

        self::assertContains((string) $ordinary->getId(), $ids);
        self::assertNotContains((string) $eval->getId(), $ids);
    }

    public function testOwnerFilteringOnEvalSeesOwnEvalConversationsOnly(): void
    {
        $ordinary = self::conversation(self::OWNER, null);
        $own = self::conversation(self::OWNER, Conversation::ORIGIN_EVAL);
        $foreign = self::conversation(self::OTHER, Conversation::ORIGIN_EVAL);

        $response = self::get('/api/conversations?origin=eval', self::ROLES_USER);
        $ids = self::ids($response);

        self::assertContains((string) $own->getId(), $ids);
        self::assertNotContains((string) $foreign->getId(), $ids);
        self::assertNotContains((string) $ordinary->getId(), $ids);
        foreach (self::members($response) as $member) {
            self::assertSame(self::OWNER, $member['ownerSubject']);
            self::assertSame(Conversation::ORIGIN_EVAL, $member['origin']);
        }
    }

    public function testPlainUserOfAForeignRunSeesOnlyOwnEvalConversations(): void
    {
        $theirs = self::conversation(self::OWNER, Conversation::ORIGIN_EVAL);
        $own = self::conversation(self::OTHER, Conversation::ORIGIN_EVAL);

        self::assertSame([(string) $own->getId()], self::ids(self::get('/api/conversations?origin=eval', self::ROLES_USER, self::OTHER)));
        self::assertNotContains((string) $theirs->getId(), self::ids(self::get('/api/conversations?origin=eval', self::ROLES_USER, self::OTHER)));

        // Without the filter not even the own evaluation conversation is listed.
        self::assertSame([], self::ids(self::get('/api/conversations', self::ROLES_USER, self::OTHER)));
    }

    public function testReviewerListsEveryEvalConversation(): void
    {
        $ordinary = self::conversation(self::OWNER, null);
        $first = self::conversation(self::OWNER, Conversation::ORIGIN_EVAL);
        $second = self::conversation(self::OTHER, Conversation::ORIGIN_EVAL);

        $response = self::get('/api/conversations?origin=eval', self::ROLES_REVIEWER, self::REVIEWER);
        $ids = self::ids($response);

        self::assertContains((string) $first->getId(), $ids);
        self::assertContains((string) $second->getId(), $ids);
        self::assertNotContains((string) $ordinary->getId(), $ids);
        foreach (self::members($response) as $member) {
            self::assertSame(Conversation::ORIGIN_EVAL, $member['origin']);
        }

        // The ordinary list of the reviewer stays restricted to own conversations.
        self::assertSame([], self::ids(self::get('/api/conversations', self::ROLES_REVIEWER, self::REVIEWER)));
    }

    public function testReviewerReadsAForeignEvalConversationAndItsMessages(): void
    {
        $eval = self::conversation(self::OTHER, Conversation::ORIGIN_EVAL);
        $message = AccessTest::message($eval, 1);

        $item = self::get('/api/conversations/'.$eval->getId(), self::ROLES_REVIEWER, self::REVIEWER);
        self::assertSame(200, $item->getStatusCode());
        self::assertSame(Conversation::ORIGIN_EVAL, $item->toArray()['origin']);

        $messages = self::get('/api/conversations/'.$eval->getId().'/messages', self::ROLES_REVIEWER, self::REVIEWER);
        self::assertSame([(string) $message->getId()], self::ids($messages));

        // The flat message list follows the same origin rule.
        self::assertContains((string) $message->getId(), self::ids(self::get('/api/messages?origin=eval', self::ROLES_REVIEWER, self::REVIEWER)));
        self::assertNotContains((string) $message->getId(), self::ids(self::get('/api/messages', self::ROLES_REVIEWER, self::REVIEWER)));
    }

    public function testReviewerCannotTurnInAForeignEvalConversation(): void
    {
        $eval = self::conversation(self::OTHER, Conversation::ORIGIN_EVAL);

        $response = static::createClient()->request('POST', '/api/conversations/'.$eval->getId().'/messages', [
            'headers' => self::headers(self::ROLES_REVIEWER, self::REVIEWER) + ['Content-Type' => 'application/ld+json'],
            'json' => ['content' => 'Hello'],
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testReviewerIsForbiddenOnAForeignOrdinaryConversation(): void
    {
        $ordinary = self::conversation(self::OTHER, null);
        AccessTest::message($ordinary, 1);

        self::assertSame(403, self::get('/api/conversations/'.$ordinary->getId(), self::ROLES_REVIEWER, self::REVIEWER)->getStatusCode());
        self::assertSame(404, self::get('/api/conversations/'.$ordinary->getId().'/messages', self::ROLES_REVIEWER, self::REVIEWER)->getStatusCode());
    }

    public function testPlainUserIsForbiddenOnAForeignEvalConversation(): void
    {
        $eval = self::conversation(self::OTHER, Conversation::ORIGIN_EVAL);

        self::assertSame(403, self::get('/api/conversations/'.$eval->getId(), self::ROLES_USER)->getStatusCode());
        self::assertSame(404, self::get('/api/conversations/'.$eval->getId().'/messages', self::ROLES_USER)->getStatusCode());
    }

    public function testOrdinaryListHidesReplayConversations(): void
    {
        $ordinary = self::conversation(self::OWNER, null);
        $replay = self::conversation(self::OWNER, Conversation::ORIGIN_REPLAY);

        $ids = self::ids(self::get('/api/conversations', self::ROLES_USER));

        self::assertContains((string) $ordinary->getId(), $ids);
        self::assertNotContains((string) $replay->getId(), $ids);
    }

    public function testPlainUserFilteringOnReplaySeesOwnReplayConversationsOnly(): void
    {
        $own = self::conversation(self::OWNER, Conversation::ORIGIN_REPLAY);
        $foreign = self::conversation(self::OTHER, Conversation::ORIGIN_REPLAY);
        $eval = self::conversation(self::OWNER, Conversation::ORIGIN_EVAL);

        $ids = self::ids(self::get('/api/conversations?origin=replay', self::ROLES_USER));

        self::assertContains((string) $own->getId(), $ids);
        self::assertNotContains((string) $foreign->getId(), $ids);
        self::assertNotContains((string) $eval->getId(), $ids);
    }

    public function testReviewerListsEveryReplayConversationButNoEvalConversation(): void
    {
        $first = self::conversation(self::OWNER, Conversation::ORIGIN_REPLAY);
        $second = self::conversation(self::OTHER, Conversation::ORIGIN_REPLAY);
        $eval = self::conversation(self::OTHER, Conversation::ORIGIN_EVAL);

        $response = self::get('/api/conversations?origin=replay', self::ROLES_REVIEWER, self::REVIEWER);
        $ids = self::ids($response);

        self::assertContains((string) $first->getId(), $ids);
        self::assertContains((string) $second->getId(), $ids);
        self::assertNotContains((string) $eval->getId(), $ids);
        foreach (self::members($response) as $member) {
            self::assertSame(Conversation::ORIGIN_REPLAY, $member['origin']);
        }
    }

    public function testReviewerReadsAForeignReplayConversationAndItsMessages(): void
    {
        $replay = self::conversation(self::OTHER, Conversation::ORIGIN_REPLAY);
        $message = AccessTest::message($replay, 1);

        $item = self::get('/api/conversations/'.$replay->getId(), self::ROLES_REVIEWER, self::REVIEWER);
        self::assertSame(200, $item->getStatusCode());
        self::assertSame(Conversation::ORIGIN_REPLAY, $item->toArray()['origin']);

        $messages = self::get('/api/conversations/'.$replay->getId().'/messages', self::ROLES_REVIEWER, self::REVIEWER);
        self::assertSame([(string) $message->getId()], self::ids($messages));

        self::assertContains((string) $message->getId(), self::ids(self::get('/api/messages?origin=replay', self::ROLES_REVIEWER, self::REVIEWER)));
        self::assertNotContains((string) $message->getId(), self::ids(self::get('/api/messages', self::ROLES_REVIEWER, self::REVIEWER)));
    }

    public function testPlainUserIsForbiddenOnAForeignReplayConversation(): void
    {
        $replay = self::conversation(self::OTHER, Conversation::ORIGIN_REPLAY);

        self::assertSame(403, self::get('/api/conversations/'.$replay->getId(), self::ROLES_USER)->getStatusCode());
        self::assertSame(404, self::get('/api/conversations/'.$replay->getId().'/messages', self::ROLES_USER)->getStatusCode());
        self::assertNotContains((string) $replay->getId(), self::ids(self::get('/api/conversations?origin=replay', self::ROLES_USER)));
    }

    public function testOriginCannotBeSetThroughTheApi(): void
    {
        $body = AccessTest::createBody(['title' => self::TITLE_PREFIX.'posted', 'origin' => Conversation::ORIGIN_EVAL]);
        $response = static::createClient()->request('POST', '/api/conversations', [
            'headers' => self::headers(self::ROLES_USER) + ['Content-Type' => 'application/ld+json'],
            'json' => $body,
        ]);

        $status = $response->getStatusCode();
        if (201 === $status) {
            // Ignored: `origin` is not in the write group.
            $id = (string) $response->toArray()['id'];
            self::assertNull($response->toArray()['origin'] ?? null);
            self::assertNull(self::connection()->fetchOne('SELECT origin FROM neuron_conversation WHERE id = :id', ['id' => $id]));

            return;
        }

        // Rejected: nothing was stored.
        self::assertContains($status, [400, 422]);
        self::assertSame(0, (int) self::connection()->fetchOne(
            'SELECT COUNT(*) FROM neuron_conversation WHERE owner_subject = :owner AND title = :title',
            ['owner' => self::OWNER, 'title' => self::TITLE_PREFIX.'posted'],
        ));
    }

    // --- Helpers -----------------------------------------------------------

    /**
     * A conversation written directly through the entity manager.
     */
    private static function conversation(string $owner, ?string $origin): Conversation
    {
        $conversation = (new Conversation())
            ->setOwnerSubject($owner)
            ->setTitle(self::TITLE_PREFIX.($origin ?? 'ordinary'))
            ->setOrigin($origin)
            ->setProviderModel(ConversationConnections::model())
            ->setRetrievalDepth(4);
        self::entityManager()->persist($conversation);
        self::entityManager()->flush();

        return $conversation;
    }

    /**
     * @return array<string, string>
     */
    private static function headers(string $roles, ?string $identifier = null): array
    {
        $headers = [
            'Accept' => 'application/ld+json',
            TestHeaderAuthenticator::HEADER_ROLES => $roles,
        ];
        if (null !== $identifier) {
            $headers[TestHeaderAuthenticator::HEADER_USER] = $identifier;
        }

        return $headers;
    }

    private static function get(string $uri, string $roles, ?string $identifier = null): ResponseInterface
    {
        return static::createClient()->request('GET', $uri, ['headers' => self::headers($roles, $identifier)]);
    }

    /**
     * @return list<string>
     */
    private static function ids(ResponseInterface $response): array
    {
        return AccessTest::memberIds($response);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function members(ResponseInterface $response): array
    {
        $data = $response->toArray();

        return array_values($data['hydra:member'] ?? $data['member'] ?? []);
    }

    /**
     * Removes the fixtures (messages cascade) and the conversation audit
     * entries, like {@see AccessTest::cleanUp()}.
     */
    private static function cleanUp(): void
    {
        static::bootKernel();
        $connection = self::connection();
        $connection->executeStatement(
            'DELETE FROM neuron_conversation WHERE owner_subject LIKE :prefix OR (owner_subject = :owner AND title LIKE :title)',
            ['prefix' => self::PREFIX.'%', 'owner' => self::OWNER, 'title' => self::TITLE_PREFIX.'%'],
        );
        $connection->executeStatement('DELETE FROM dmstr_log_entries WHERE object_class = :class', ['class' => Conversation::class]);
        static::ensureKernelShutdown();
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
