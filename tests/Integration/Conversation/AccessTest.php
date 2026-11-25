<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:34:45 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Conversation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Ownership matrix of conversations and messages: anonymous requests get 401, owners
 * see and use their own conversations only, a second user (`X-Test-User`)
 * gets 403 on foreign items, 404 on the foreign message sub-resource and
 * empty lists, editor and developer are treated like any user, and only
 * `ROLE_ADMIN` (holder of the "any" permissions through
 * ROLE_CONVERSATION_ADMIN) reads and deletes every conversation. `hydra:operation` advertises only what each identity may do.
 *
 * Relies on the `X-Test-User` header of
 * {@see TestHeaderAuthenticator} and the firewall and access-control rows
 * for `/api/(conversations|messages)`. Needs the migrated test database;
 * runs in CI. Fixtures are written through the entity manager, owned by
 * identifiers with the prefix `test-conv-`, and removed before and after
 * each test (messages cascade).
 *
 * The static helpers are shared by the other database-bound conversation
 * tests (PayloadPermissionTest, DeletionAuditTest).
 */
#[Group('database')]
final class AccessTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    public const string PREFIX = 'test-conv-';
    public const string USER_A = self::PREFIX.'user-a';
    public const string USER_B = self::PREFIX.'user-b';
    /** Editor and developer at once: every right below admin, no foreign conversations. */
    public const string STAFF = self::PREFIX.'staff';
    public const string ADMIN = self::PREFIX.'admin';

    /** Identifier => role list of the test identities. */
    private const array ROLES = [
        self::USER_A => 'ROLE_USER',
        self::USER_B => 'ROLE_USER',
        self::STAFF => 'ROLE_EDITOR,ROLE_DEVELOPER',
        self::ADMIN => 'ROLE_ADMIN',
    ];

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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function anonymousRequests(): iterable
    {
        $id = '0199a0b1-0000-7000-8000-000000000001';

        yield 'list conversations' => ['GET', '/api/conversations'];
        yield 'create' => ['POST', '/api/conversations'];
        yield 'item' => ['GET', '/api/conversations/'.$id];
        yield 'delete' => ['DELETE', '/api/conversations/'.$id];
        yield 'turn' => ['POST', '/api/conversations/'.$id.'/messages'];
        yield 'sub-resource' => ['GET', '/api/conversations/'.$id.'/messages'];
        yield 'list messages' => ['GET', '/api/messages'];
        yield 'message item' => ['GET', '/api/messages/'.$id];
    }

    #[DataProvider('anonymousRequests')]
    public function testAnonymousIsUnauthorized(string $method, string $uri): void
    {
        $options = ['headers' => ['Accept' => 'application/ld+json', TestHeaderAuthenticator::HEADER_AUTH => 'anonymous']];
        if ('POST' === $method) {
            $options['headers']['Content-Type'] = 'application/ld+json';
            $options['json'] = ['content' => 'Hallo'];
        }

        static::createClient()->request($method, $uri, $options);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(0, self::conversationCount());
    }

    public function testCreatedConversationBelongsToTheCaller(): void
    {
        $response = self::request('POST', '/api/conversations', self::USER_A, self::createBody(['title' => 'Eigene']));

        self::assertResponseStatusCodeSame(201);
        $data = $response->toArray();
        self::assertSame(self::USER_A, $data['ownerSubject']);
        self::assertSame('active', $data['status']);
        self::assertArrayNotHasKey('nextSequence', $data);
    }

    public function testOwnerCannotCreateForSomebodyElse(): void
    {
        $response = self::request('POST', '/api/conversations', self::USER_A, self::createBody(['title' => 'Fremd', 'ownerSubject' => self::USER_B]));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(self::USER_A, $response->toArray()['ownerSubject']);
    }

    public function testListsShowOwnConversationsOnly(): void
    {
        $a = self::conversation(self::USER_A);
        self::message($a, 1);
        $b = self::conversation(self::USER_B);
        self::message($b, 1);
        $admin = self::conversation(self::STAFF);

        self::assertSame([(string) $a->getId()], self::memberIds(self::request('GET', '/api/conversations', self::USER_A)));
        self::assertSame([(string) $b->getId()], self::memberIds(self::request('GET', '/api/conversations', self::USER_B)));
        self::assertSame([(string) $admin->getId()], self::memberIds(self::request('GET', '/api/conversations', self::STAFF)));

        // The owner filter cannot widen the list.
        self::assertSame([], self::memberIds(self::request('GET', '/api/conversations?ownerSubject='.self::USER_A, self::USER_B)));

        $all = self::memberIds(self::request('GET', '/api/conversations', self::ADMIN));
        sort($all);
        $expected = [(string) $a->getId(), (string) $b->getId(), (string) $admin->getId()];
        sort($expected);
        self::assertSame($expected, $all);
    }

    public function testMessageListShowsOwnMessagesOnly(): void
    {
        $a = self::conversation(self::USER_A);
        $first = self::message($a, 1);
        $second = self::message($a, 2, Message::SPEAKER_ASSISTANT);
        $b = self::conversation(self::USER_B);
        $foreign = self::message($b, 1);

        $byConversation = '/api/messages?conversation='.urlencode(self::iri($a));

        self::assertSame([(string) $first->getId(), (string) $second->getId()], self::memberIds(self::request('GET', $byConversation, self::USER_A)));
        self::assertSame([], self::memberIds(self::request('GET', $byConversation, self::USER_B)));
        self::assertSame([], self::memberIds(self::request('GET', $byConversation, self::STAFF)));
        self::assertSame([(string) $first->getId(), (string) $second->getId()], self::memberIds(self::request('GET', $byConversation, self::ADMIN)));

        self::assertSame([(string) $foreign->getId()], self::memberIds(self::request('GET', '/api/messages', self::USER_B)));
    }

    public function testSubResourceListsTheMessagesOfAReadableConversation(): void
    {
        $a = self::conversation(self::USER_A);
        $first = self::message($a, 1);
        $second = self::message($a, 2, Message::SPEAKER_ASSISTANT);
        $uri = self::iri($a).'/messages';

        $expected = [(string) $first->getId(), (string) $second->getId()];
        self::assertSame($expected, self::memberIds(self::request('GET', $uri, self::USER_A)));
        self::assertSame($expected, self::memberIds(self::request('GET', $uri, self::ADMIN)));

        foreach ([self::USER_B, self::STAFF] as $outsider) {
            self::request('GET', $uri, $outsider);
            self::assertResponseStatusCodeSame(404, $outsider);
        }

        self::request('GET', '/api/conversations/0199a0b1-0000-7000-8000-00000000dead/messages', self::USER_A);
        self::assertResponseStatusCodeSame(404);
    }

    public function testForeignItemsAreForbidden(): void
    {
        $a = self::conversation(self::USER_A);
        $message = self::message($a, 1);

        foreach ([self::USER_B, self::STAFF] as $outsider) {
            self::request('GET', self::iri($a), $outsider);
            self::assertResponseStatusCodeSame(403, $outsider.' item');

            self::request('GET', '/api/messages/'.$message->getId(), $outsider);
            self::assertResponseStatusCodeSame(403, $outsider.' message');

            self::request('POST', self::iri($a).'/messages', $outsider, ['content' => 'Hallo']);
            self::assertResponseStatusCodeSame(403, $outsider.' turn');

            self::request('DELETE', self::iri($a), $outsider);
            self::assertResponseStatusCodeSame(403, $outsider.' delete');
        }

        // Nothing was appended or removed.
        self::assertSame(1, self::conversationCount());
        self::assertSame(1, self::messageCount($a));
    }

    public function testOwnerReadsAndTurnsOwnConversation(): void
    {
        $a = self::conversation(self::USER_A);

        $item = self::request('GET', self::iri($a), self::USER_A);
        self::assertResponseIsSuccessful();
        $data = $item->toArray();
        self::assertSame(self::USER_A, $data['ownerSubject']);
        self::assertFalse($data['running']);

        $client = static::createClient();
        $client->disableReboot();
        self::providerFactory()->queueAnswer('Hallo zurück');
        $turn = self::send($client, 'POST', self::iri($a).'/messages', self::USER_A, ['content' => 'Hallo']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Hallo zurück', $turn->toArray()['content']);
    }

    public function testMasterReadsAndDeletesForeignConversationsButCannotTurn(): void
    {
        $a = self::conversation(self::USER_A);
        $message = self::message($a, 1);

        self::request('GET', self::iri($a), self::ADMIN);
        self::assertResponseIsSuccessful();

        self::request('GET', '/api/messages/'.$message->getId(), self::ADMIN);
        self::assertResponseIsSuccessful();

        self::request('POST', self::iri($a).'/messages', self::ADMIN, ['content' => 'Hallo']);
        self::assertResponseStatusCodeSame(403);

        self::request('DELETE', self::iri($a), self::ADMIN);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, self::conversationCount());
    }

    public function testPartialIdentifiersNeverRevealForeignConversations(): void
    {
        $a = self::conversation(self::USER_A);
        self::message($a, 1);
        $partial = substr((string) $a->getId(), 0, 8);

        foreach ([self::USER_B, self::STAFF] as $outsider) {
            foreach ([
                ['GET', '/api/conversations/'.$partial],
                ['DELETE', '/api/conversations/'.$partial],
                ['GET', '/api/conversations/'.$partial.'/messages'],
            ] as [$method, $uri]) {
                $response = self::request($method, $uri, $outsider);
                self::assertContains($response->getStatusCode(), [403, 404], $outsider.' '.$method.' '.$uri);
            }
        }

        self::assertSame(1, self::conversationCount());
    }

    /**
     * NOTE: API Platform converts `{id}` and `{conversationId}` to a Uuid
     * before any provider runs (UuidUriVariableTransformer); a partial id
     * fails that conversion and answers 404 "Invalid uri variables." for
     * everybody, so the partial-UUID item provider of api-platform-utils and
     * the partial lookup of ConversationMessagesProvider are not reached
     * over HTTP. This pins the current, fail-closed behaviour; once the
     * platform resolves partial ids on these routes, the owner must get 200
     * here and the test above keeps outsiders at 403/404.
     */
    public function testPartialIdentifierOfTheOwnerIsNotResolvedOnItemRoutes(): void
    {
        $a = self::conversation(self::USER_A);
        $partial = substr((string) $a->getId(), 0, 8);

        self::request('GET', '/api/conversations/'.$partial, self::USER_A);
        self::assertResponseStatusCodeSame(404);

        self::request('GET', '/api/conversations/'.$partial.'/messages', self::USER_A);
        self::assertResponseStatusCodeSame(404);
    }

    public function testHydraOperationsFollowThePermissions(): void
    {
        $a = self::conversation(self::USER_A);
        $turn = self::iri($a).'/messages';
        $item = self::iri($a);

        $owner = self::operations(self::request('GET', $item, self::USER_A));
        self::assertContains('POST '.$turn, $owner);
        self::assertContains('DELETE '.$item, $owner);
        self::assertContains('GET '.$item, $owner);

        $master = self::operations(self::request('GET', $item, self::ADMIN));
        self::assertNotContains('POST '.$turn, $master);
        self::assertContains('DELETE '.$item, $master);

        $list = self::operations(self::request('GET', '/api/conversations', self::USER_A));
        self::assertContains('POST /api/conversations', $list);
    }

    /**
     * The Hydra collection responses add the operations of the resource;
     * the filter evaluates the item expressions with a null object for a
     * non-owner. That must neither fail nor reveal a foreign conversation.
     */
    public function testHydraCollectionsAnswerNonOwnersWithoutAnError(): void
    {
        $a = self::conversation(self::USER_A);
        $message = self::message($a, 1);
        $own = self::conversation(self::USER_B);
        $item = self::iri($a);

        foreach ([self::USER_B, self::STAFF, self::ADMIN] as $caller) {
            $list = self::request('GET', '/api/conversations', $caller);
            self::assertResponseStatusCodeSame(200, $caller.' conversations');
            $operations = self::operations($list);
            self::assertNotContains('POST '.$item.'/messages', $operations, $caller);

            self::request('GET', '/api/messages', $caller);
            self::assertResponseStatusCodeSame(200, $caller.' messages');
        }

        // The own conversation of a non-owner is the only one in its list.
        self::assertSame([(string) $own->getId()], self::memberIds(self::request('GET', '/api/conversations', self::USER_B)));
        self::assertNotContains((string) $message->getId(), self::memberIds(self::request('GET', '/api/messages', self::USER_B)));
    }

    // --- Shared helpers ----------------------------------------------------

    /**
     * Headers of a test identity: its roles and its user identifier.
     *
     * @return array<string, string>
     */
    public static function identity(string $identifier): array
    {
        return [
            TestHeaderAuthenticator::HEADER_ROLES => self::ROLES[$identifier] ?? 'ROLE_USER',
            TestHeaderAuthenticator::HEADER_USER => $identifier,
        ];
    }

    /**
     * One request with a fresh client.
     *
     * @param array<string, mixed>|null $json body of POST requests
     */
    public static function request(string $method, string $uri, string $identifier, ?array $json = null): ResponseInterface
    {
        return self::send(static::createClient(), $method, $uri, $identifier, $json);
    }

    /**
     * @param array<string, mixed>|null $json body of POST requests
     */
    public static function send(Client $client, string $method, string $uri, string $identifier, ?array $json = null): ResponseInterface
    {
        $options = ['headers' => ['Accept' => 'application/ld+json'] + self::identity($identifier)];
        if (null !== $json) {
            $options['headers']['Content-Type'] = 'application/ld+json';
            $options['json'] = $json;
        }

        return $client->request($method, $uri, $options);
    }

    /**
     * The body of `POST /api/conversations` without an agent: $keys plus the
     * IRI of the catalogue entry of the fake's model on the `anthropic`
     * fixture connection (both created when missing).
     *
     * @param array<string, mixed> $keys
     *
     * @return array<string, mixed>
     */
    public static function createBody(array $keys = []): array
    {
        return $keys + ['model' => ConversationConnections::modelIri()];
    }

    /**
     * A conversation written directly through the entity manager, on the
     * `anthropic` fixture connection.
     */
    public static function conversation(string $owner): Conversation
    {
        $conversation = (new Conversation())
            ->setOwnerSubject($owner)
            ->setProviderModel(ConversationConnections::model())
            ->setRetrievalDepth(4);
        self::entityManager()->persist($conversation);
        self::entityManager()->flush();

        return $conversation;
    }

    /**
     * A complete text message with a provider payload, written directly
     * through the entity manager; advances the conversation's counter.
     */
    public static function message(Conversation $conversation, int $sequence, string $role = Message::SPEAKER_USER): Message
    {
        $entityManager = self::entityManager();
        $managed = $entityManager->contains($conversation) ? $conversation : $entityManager->find(Conversation::class, $conversation->getId());
        self::assertInstanceOf(Conversation::class, $managed);

        $message = (new Message())
            ->setConversation($managed)
            ->setSequence($sequence)
            ->setRole($role)
            ->setKind(MessageKind::TEXT)
            ->setStatus(MessageStatus::COMPLETE)
            ->setContent('Nachricht '.$sequence)
            ->setProviderPayload(['role' => $role, 'content' => [['type' => 'text', 'text' => 'Nachricht '.$sequence]]]);
        $managed->setNextSequence($sequence + 1);
        $entityManager->persist($message);
        $entityManager->flush();

        return $message;
    }

    public static function iri(Conversation $conversation): string
    {
        return '/api/conversations/'.$conversation->getId();
    }

    /**
     * Ids of the members of a Hydra collection response.
     *
     * @return list<string>
     */
    public static function memberIds(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode());

        return array_values(array_map(static fn (array $member): string => (string) $member['id'], $response->toArray()['hydra:member'] ?? $response->toArray()['member'] ?? []));
    }

    /**
     * `METHOD @id` of every advertised Hydra operation.
     *
     * @return list<string>
     */
    public static function operations(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode());

        return array_values(array_map(
            static fn (array $operation): string => $operation['hydra:method'].' '.$operation['@id'],
            $response->toArray()['hydra:operation'] ?? [],
        ));
    }

    public static function providerFactory(): FakeProviderFactory
    {
        $factory = static::getContainer()->get(ProviderFactory::class);
        self::assertInstanceOf(FakeProviderFactory::class, $factory);

        return $factory;
    }

    public static function conversationCount(): int
    {
        return (int) self::connection()->fetchOne('SELECT COUNT(*) FROM neuron_conversation WHERE owner_subject LIKE :prefix', ['prefix' => self::PREFIX.'%']);
    }

    public static function messageCount(Conversation $conversation): int
    {
        return (int) self::connection()->fetchOne('SELECT COUNT(*) FROM neuron_message WHERE conversation_id = :id', ['id' => (string) $conversation->getId()]);
    }

    /**
     * Removes the test conversations (messages cascade) and their audit
     * entries.
     */
    public static function cleanUp(): void
    {
        static::bootKernel();
        $connection = self::connection();
        $connection->executeStatement('DELETE FROM neuron_conversation WHERE owner_subject LIKE :prefix', ['prefix' => self::PREFIX.'%']);
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
