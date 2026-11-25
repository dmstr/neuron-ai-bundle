<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:54:57 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Conversation\Anonymous;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\DoctrineAuditLog\Entity\LogEntry;
use Dmstr\NeuronAiBundle\Anonymous\CapabilityToken;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Tests\Integration\Conversation\AccessTest;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\AnonymousFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Access matrix of capability-token holders: the holder of the
 * token of anonymous conversation A reads A and its messages, runs a turn
 * and deletes A, and reaches nothing else: B's items answer 403 (its
 * message sub-resource 404), every list and the stateless, admin and refs
 * endpoints 403, and a missing, malformed, unknown or deleted token 401.
 * Holders of `neuronai:conversation:read_any` (`ROLE_ADMIN`) list, filter and
 * delete anonymous conversations and see the address hash; ordinary users
 * never see them. The token itself never appears in a response body or in
 * the audit log.
 *
 * Token requests send `X-Test-Auth: anonymous` (the test authenticator
 * steps aside) plus `Authorization: Bearer anon_…`. Relies on the entity
 * side of anonymous conversations (`anonymous`, `tokenHash`, `addressHash` in the group
 * `conversation:admin`, the `anonymous` filter, the sub-resource open to
 * authenticated principals) and on {@see AccessTest}'s helpers. Needs the
 * migrated test database; runs in CI.
 */
#[Group('database')]
final class TokenAccessTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const string TOKEN_A = 'anon_VG9rZW4tQS1vZi10aGUtYWNjZXNzLW1hdHJpeA';
    private const string TOKEN_B = 'anon_VG9rZW4tQi1vZi10aGUtYWNjZXNzLW1hdHJpeA';

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

    public function testHolderReadsItsConversationAndMessages(): void
    {
        $a = self::anonymous(self::TOKEN_A);
        $message = AccessTest::message($a, 1);

        $item = self::token(self::TOKEN_A, 'GET', AccessTest::iri($a));
        self::assertResponseStatusCodeSame(200);
        $data = $item->toArray();
        self::assertTrue($data['anonymous']);
        self::assertSame(self::ownerSubject($a), $data['ownerSubject']);
        self::assertArrayNotHasKey('addressHash', $data);
        self::assertArrayNotHasKey('tokenHash', $data);
        self::assertTokenAbsent($item);

        $messages = self::token(self::TOKEN_A, 'GET', AccessTest::iri($a).'/messages');
        self::assertSame([(string) $message->getId()], AccessTest::memberIds($messages));

        self::token(self::TOKEN_A, 'GET', '/api/messages/'.$message->getId());
        self::assertResponseStatusCodeSame(200);
    }

    public function testHolderRunsATurn(): void
    {
        $a = self::anonymous(self::TOKEN_A);

        $client = static::createClient();
        $client->disableReboot();
        AccessTest::providerFactory()->queueAnswer('Hallo zurück');
        $turn = self::token(self::TOKEN_A, 'POST', AccessTest::iri($a).'/messages', ['content' => 'Hallo'], $client);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Hallo zurück', $turn->toArray()['content']);
        self::assertTokenAbsent($turn);
    }

    public function testHolderDeletesItsConversationAndTheTokenDiesWithIt(): void
    {
        $a = self::anonymous(self::TOKEN_A);
        AccessTest::message($a, 1);
        AccessTest::message($a, 2, Message::SPEAKER_ASSISTANT);

        self::token(self::TOKEN_A, 'DELETE', AccessTest::iri($a));
        self::assertResponseStatusCodeSame(204);

        self::assertSame(0, self::rows($a));
        self::assertSame(0, AccessTest::messageCount($a));

        // The audit actor is the owner subject, never the token.
        $remove = self::entries($a, 'remove');
        self::assertCount(1, $remove);
        self::assertSame(self::ownerSubject($a), $remove[0]->getUsername());
        self::assertStringNotContainsString(CapabilityToken::PREFIX, (string) $remove[0]->getUsername());

        $response = self::token(self::TOKEN_A, 'GET', AccessTest::iri($a));
        self::assertResponseStatusCodeSame(401);
        self::assertTokenAbsent($response);
    }

    public function testHolderPassesTheOwnerCheckOfItsStreamAndCancelRoutes(): void
    {
        $a = self::anonymous(self::TOKEN_A);

        // Authorized by the owner attributes: nothing to resume is a 404, not a 403.
        self::token(self::TOKEN_A, 'GET', '/api/conversations/'.$a->getId().'/stream');
        self::assertResponseStatusCodeSame(404);

        $cancel = self::token(self::TOKEN_A, 'POST', '/api/conversations/'.$a->getId().'/cancel');
        self::assertNotSame(403, $cancel->getStatusCode());
    }

    /**
     * Requests of the holder of A on B; `{B}` and `{B-message}` are
     * replaced by the ids of the fixture.
     *
     * @return iterable<string, array{string, string, int}>
     */
    public static function foreignRequests(): iterable
    {
        yield 'item' => ['GET', '/api/conversations/{B}', 403];
        yield 'delete' => ['DELETE', '/api/conversations/{B}', 403];
        yield 'turn' => ['POST', '/api/conversations/{B}/messages', 403];
        yield 'sub-resource' => ['GET', '/api/conversations/{B}/messages', 404];
        yield 'message item' => ['GET', '/api/messages/{B-message}', 403];
        yield 'stream resume' => ['GET', '/api/conversations/{B}/stream', 403];
        yield 'stream turn' => ['POST', '/api/conversations/{B}/stream', 403];
        yield 'cancel' => ['POST', '/api/conversations/{B}/cancel', 403];
    }

    #[DataProvider('foreignRequests')]
    public function testTokenOfAOpensNothingOfB(string $method, string $uri, int $status): void
    {
        self::anonymous(self::TOKEN_A);
        $b = self::anonymous(self::TOKEN_B);
        $message = AccessTest::message($b, 1);

        $uri = strtr($uri, ['{B-message}' => (string) $message->getId(), '{B}' => (string) $b->getId()]);
        $response = self::token(self::TOKEN_A, $method, $uri, 'POST' === $method ? ['content' => 'Hallo'] : null);

        self::assertResponseStatusCodeSame($status);
        self::assertTokenAbsent($response);

        // Nothing was appended, removed or audited as removed.
        self::assertSame(1, self::rows($b));
        self::assertSame(1, AccessTest::messageCount($b));
        self::assertSame([], self::entries($b, 'remove'));
    }

    /**
     * Endpoints that check a permission the test kernel maps to `ROLE_USER`
     * (conversation list and create, collection, agent, eval, mcp_tool,
     * provider_model and knowledge reads, agent chat): a token principal
     * carries no role, so it holds none of these permissions and the
     * operation answers 403.
     *
     * @return iterable<string, array{string, string, array<string, mixed>|null}>
     */
    public static function userOnlyRequests(): iterable
    {
        yield 'list conversations' => ['GET', '/api/conversations', null];
        yield 'list anonymous conversations' => ['GET', '/api/conversations?anonymous=true', null];
        yield 'list messages' => ['GET', '/api/messages', null];
        yield 'create conversation' => ['POST', '/api/conversations', ['title' => 'Fremd']];
        yield 'refs' => ['GET', '/api/refs/mcp_tools', null];
        yield 'provider models' => ['GET', '/api/refs/provider_models', null];
        yield 'collections' => ['GET', '/api/admin/collections', null];
        yield 'agents' => ['GET', '/api/agents', null];
        yield 'agent versions' => ['GET', '/api/agent_versions', null];
        yield 'agent chat' => ['POST', '/api/agents/00000000-0000-4000-8000-000000000000/chat', ['messages' => [['role' => 'user', 'content' => 'Hallo']]]];
        yield 'eval questions' => ['GET', '/api/eval_questions', null];
        yield 'eval runs' => ['GET', '/api/eval_runs', null];
        yield 'eval results' => ['GET', '/api/eval_results', null];
        yield 'evaluations' => ['GET', '/api/evaluations', null];
        yield 'suggestions' => ['GET', '/api/suggestions', null];
        yield 'feedback list' => ['GET', '/api/feedback', null];
    }

    /**
     * @param array<string, mixed>|null $json
     */
    #[DataProvider('userOnlyRequests')]
    public function testHolderIsForbiddenOutsideItsConversation(string $method, string $uri, ?array $json): void
    {
        self::anonymous(self::TOKEN_A);

        $response = self::token(self::TOKEN_A, $method, $uri, $json);

        // Authenticated, but not authorised: 403, neither 2xx nor 401.
        self::assertResponseStatusCodeSame(403);
        self::assertTokenAbsent($response);
        // Nothing was created for the token principal.
        self::assertSame(1, self::anonymousCount());
    }

    /**
     * Authorization headers that must not authenticate. In the test
     * environment the api firewall is the production one plus the test
     * authenticator (firewall settings are deep-merged), so Lexik's JWT
     * authenticator is active here too: values that are not capability
     * tokens (JWT-shaped, empty suffix, dots) reach it and fail as an
     * invalid JWT, an unknown capability token fails in the capability
     * authenticator. Every case is a 401.
     *
     * @return iterable<string, array{string|null}>
     */
    public static function invalidTokens(): iterable
    {
        yield 'no token' => [null];
        yield 'empty suffix' => ['anon_'];
        yield 'dot in the value' => ['anon_with.dot'];
        yield 'unknown token' => ['anon_unknownsuffix'];
        yield 'JWT-shaped value' => ['eyJhbGciOi.xxx.yyy'];
    }

    #[DataProvider('invalidTokens')]
    public function testMissingOrInvalidTokensAreUnauthorized(?string $token): void
    {
        $a = self::anonymous(self::TOKEN_A);

        $headers = ['Accept' => 'application/ld+json', TestHeaderAuthenticator::HEADER_AUTH => 'anonymous'];
        if (null !== $token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }
        foreach (['GET', 'DELETE'] as $method) {
            $response = static::createClient()->request($method, AccessTest::iri($a), ['headers' => $headers]);

            self::assertResponseStatusCodeSame(401, $method);
            if (null !== $token) {
                self::assertStringNotContainsString($token, $response->getContent(false), $method);
            }
        }

        self::assertSame(1, self::rows($a));
    }

    public function testMasterListsFiltersAndDeletesAnonymousConversations(): void
    {
        $a = self::anonymous(self::TOKEN_A);
        $b = self::anonymous(self::TOKEN_B);
        $own = AccessTest::conversation(AccessTest::USER_A);
        $ids = static fn (Conversation ...$conversations): array => array_map(static fn (Conversation $c): string => (string) $c->getId(), $conversations);

        $anonymous = AccessTest::memberIds(AccessTest::request('GET', '/api/conversations?anonymous=true', AccessTest::ADMIN));
        self::assertEqualsCanonicalizing($ids($a, $b), $anonymous);

        $list = AccessTest::request('GET', '/api/conversations', AccessTest::ADMIN);
        $all = AccessTest::memberIds($list);
        foreach ($ids($a, $b, $own) as $id) {
            self::assertContains($id, $all);
        }
        foreach ($list->toArray()['hydra:member'] ?? $list->toArray()['member'] ?? [] as $member) {
            if ((string) $a->getId() === (string) $member['id']) {
                self::assertSame(hash('sha256', 'fixture'), $member['addressHash'] ?? null);
            }
        }

        $item = AccessTest::request('GET', AccessTest::iri($a), AccessTest::ADMIN)->toArray();
        self::assertSame(hash('sha256', 'fixture'), $item['addressHash'] ?? null);
        self::assertArrayNotHasKey('tokenHash', $item);

        AccessTest::request('DELETE', AccessTest::iri($b), AccessTest::ADMIN);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, self::rows($b));

        $remove = self::entries($b, 'remove');
        self::assertCount(1, $remove);
        self::assertSame(AccessTest::ADMIN, $remove[0]->getUsername());
    }

    public function testOrdinaryUsersNeverSeeAnonymousConversations(): void
    {
        $a = self::anonymous(self::TOKEN_A);
        $message = AccessTest::message($a, 1);
        $own = AccessTest::conversation(AccessTest::USER_A);

        self::assertSame([(string) $own->getId()], AccessTest::memberIds(AccessTest::request('GET', '/api/conversations', AccessTest::USER_A)));
        self::assertSame([], AccessTest::memberIds(AccessTest::request('GET', '/api/conversations?anonymous=true', AccessTest::USER_A)));

        $ownItem = AccessTest::request('GET', AccessTest::iri($own), AccessTest::USER_A)->toArray();
        self::assertFalse($ownItem['anonymous']);
        self::assertArrayNotHasKey('addressHash', $ownItem);

        foreach ([
            ['GET', AccessTest::iri($a), 403],
            ['GET', '/api/messages/'.$message->getId(), 403],
            ['GET', AccessTest::iri($a).'/messages', 404],
            ['DELETE', AccessTest::iri($a), 403],
        ] as [$method, $uri, $status]) {
            AccessTest::request($method, $uri, AccessTest::USER_A);
            self::assertResponseStatusCodeSame($status, $method.' '.$uri);
        }

        self::assertSame(1, self::rows($a));
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * One request as the holder of a capability token: the test
     * authenticator steps aside, the capability authenticator takes over.
     *
     * @param array<string, mixed>|null $json body of POST requests
     */
    private static function token(string $token, string $method, string $uri, ?array $json = null, ?Client $client = null): ResponseInterface
    {
        $options = ['headers' => [
            'Accept' => 'application/ld+json',
            TestHeaderAuthenticator::HEADER_AUTH => 'anonymous',
            'Authorization' => 'Bearer '.$token,
        ]];
        if (null !== $json) {
            $options['headers']['Content-Type'] = 'application/ld+json';
            $options['json'] = $json;
        }

        return ($client ?? static::createClient())->request($method, $uri, $options);
    }

    /**
     * An anonymous conversation whose capability token is $token, written
     * directly through the entity manager.
     */
    private static function anonymous(string $token): Conversation
    {
        $conversation = new Conversation();
        $conversation
            ->setOwnerSubject(self::ownerSubject($conversation))
            ->setAnonymous(true)
            ->setTokenHash(CapabilityToken::hash($token))
            ->setAddressHash(hash('sha256', 'fixture'))
            ->setProviderModel(AnonymousFixtures::releaseModel())
            ->setRetrievalDepth(4);
        self::entityManager()->persist($conversation);
        self::entityManager()->flush();

        return $conversation;
    }

    private static function ownerSubject(Conversation $conversation): string
    {
        return Conversation::ANONYMOUS_OWNER_PREFIX.$conversation->getId()->toRfc4122();
    }

    /**
     * The literal token never leaves the server after creation.
     *
     * The `trace` member of an error response is left out: Symfony's
     * ProblemNormalizer adds it only with `kernel.debug` (the test env), and
     * it carries the arguments of the test's own call stack, the request
     * headers included. Without debug the member is absent.
     */
    private static function assertTokenAbsent(ResponseInterface $response): void
    {
        $content = $response->getContent(false);
        $decoded = json_decode($content, true);
        if (\is_array($decoded) && \array_key_exists('trace', $decoded)) {
            unset($decoded['trace']);
            $content = json_encode($decoded, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }
        foreach ([self::TOKEN_A, self::TOKEN_B, CapabilityToken::hash(self::TOKEN_A), CapabilityToken::hash(self::TOKEN_B)] as $secret) {
            self::assertStringNotContainsString($secret, $content);
        }
    }

    /**
     * 1 while the conversation's row exists, 0 once it is gone.
     */
    private static function rows(Conversation $conversation): int
    {
        return (int) self::connection()->fetchOne('SELECT COUNT(*) FROM neuron_conversation WHERE id = :id', ['id' => (string) $conversation->getId()]);
    }

    private static function anonymousCount(): int
    {
        return (int) self::connection()->fetchOne('SELECT COUNT(*) FROM neuron_conversation WHERE owner_subject LIKE :prefix', ['prefix' => Conversation::ANONYMOUS_OWNER_PREFIX.'%']);
    }

    /**
     * Audit entries of a conversation with the given action.
     *
     * @return list<LogEntry>
     */
    private static function entries(Conversation $conversation, string $action): array
    {
        $entityManager = self::entityManager();
        $entityManager->clear();

        return array_values($entityManager->getRepository(LogEntry::class)->findBy(
            ['objectClass' => Conversation::class, 'objectId' => (string) $conversation->getId(), 'action' => $action],
            ['version' => 'ASC'],
        ));
    }

    /**
     * Removes the anonymous and the `test-conv-` conversations (messages
     * cascade), the conversation audit entries and, after the
     * conversations, the release connection of
     * {@see AnonymousFixtures::releaseConnection()}.
     */
    private static function cleanUp(): void
    {
        static::bootKernel();
        $connection = self::connection();
        $connection->executeStatement(
            'DELETE FROM neuron_conversation WHERE owner_subject LIKE :anonymous OR owner_subject LIKE :prefix',
            ['anonymous' => Conversation::ANONYMOUS_OWNER_PREFIX.'%', 'prefix' => AccessTest::PREFIX.'%'],
        );
        $connection->executeStatement('DELETE FROM dmstr_log_entries WHERE object_class = :class', ['class' => Conversation::class]);
        AgentFixtures::deleteProviderConnections($connection, AnonymousFixtures::RELEASE_CONNECTION);
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
