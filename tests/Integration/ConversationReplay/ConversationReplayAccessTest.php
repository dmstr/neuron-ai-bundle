<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\ConversationReplay;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Anonymous\CapabilityToken;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\ConversationReplay;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Access matrix of the conversation replay: only
 * `ROLE_EVAL_ADMIN` triggers and cancels a replay; `ROLE_USER`,
 * `ROLE_EVAL_REVIEWER` and a capability-token holder (even on its own
 * anonymous conversation) get 403 and nothing is stored or changed. A
 * reviewer reads a foreign replay conversation, its messages, the
 * `?origin=replay` list and the replay record; a plain user sees neither
 * the list entry nor the item nor the record. The paths stay on the `api`
 * firewall without a change of the test kernel's security configuration, and the OpenAPI document
 * contains the three operations and the `origin` description naming
 * `replay`.
 *
 * Fixtures are written through the entity manager: the replay record and
 * its conversation directly (no job runs here), agents with the prefix of
 * {@see ConversationReplayTest::AGENT_SLUG}, sources with the title prefix
 * {@see ConversationReplayTest::PREFIX}; {@see ConversationReplayTest::cleanUp()}
 * removes them. Needs the migrated test database (CI only), except the
 * firewall and OpenAPI checks, which need the kernel only.
 */
#[Group('database')]
final class ConversationReplayAccessTest extends ApiTestCase
{
    protected function setUp(): void
    {
        // Counts or scans all rows: start from an empty database.
        TestDatabase::migrate(static::bootKernel());
        TestDatabase::purge(TestDatabase::connection(static::$kernel));
    }

    protected static ?bool $alwaysBootKernel = true;

    private const string TOKEN = 'anon_UmVwbGF5LWFjY2Vzcy1tYXRyaXgtdG9rZW4';
    private const string OWNER = ConversationReplayTest::PREFIX.'admin';
    private const string REVIEWER = ConversationReplayTest::PREFIX.'reviewer';
    private const string USER = ConversationReplayTest::PREFIX.'user';

    private const string ROLES_ADMIN = 'ROLE_USER,ROLE_EVAL_ADMIN';
    private const string ROLES_REVIEWER = 'ROLE_USER,ROLE_EVAL_REVIEWER';
    private const string ROLES_USER = 'ROLE_USER';

    private bool $usesDatabase = false;

    protected function tearDown(): void
    {
        if ($this->usesDatabase) {
            ConversationReplayTest::cleanUp();
        }
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function replayPaths(): iterable
    {
        yield 'trigger' => ['/api/conversations/x/replay'];
        yield 'record' => ['/api/conversations/x/replay_run'];
        yield 'cancel' => ['/api/conversations/x/replay_run/cancel'];
    }

    #[DataProvider('replayPaths')]
    public function testReplayPathsAreOnTheApiFirewall(string $path): void
    {
        $map = static::getContainer()->get('security.firewall.map');
        self::assertInstanceOf(FirewallMap::class, $map);

        self::assertSame('api', $map->getFirewallConfig(Request::create($path))?->getName());
    }

    public function testOpenApiDocumentsTheOperationsAndTheReplayOrigin(): void
    {
        $response = static::createClient()->request('GET', '/api/docs.jsonopenapi', ['headers' => ['Accept' => 'application/vnd.openapi+json']]);
        self::assertResponseIsSuccessful();
        $openApi = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(Conversation::OPERATION_REPLAY, $openApi['paths']['/api/conversations/{id}/replay']['post']['operationId'] ?? null);
        self::assertArrayHasKey('requestBody', $openApi['paths']['/api/conversations/{id}/replay']['post']);
        self::assertArrayHasKey('get', $openApi['paths']['/api/conversations/{id}/replay_run'] ?? []);
        self::assertSame(ConversationReplay::OPERATION_CANCEL, $openApi['paths']['/api/conversations/{id}/replay_run/cancel']['post']['operationId'] ?? null);

        $descriptions = [];
        foreach ($openApi['components']['schemas'] as $name => $schema) {
            if (str_starts_with((string) $name, 'Conversation') && isset($schema['properties']['origin']['description'])) {
                $descriptions[] = $schema['properties']['origin']['description'];
            }
        }
        self::assertNotEmpty($descriptions);
        foreach ($descriptions as $description) {
            self::assertStringContainsString('`replay`', $description);
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function nonAdmins(): iterable
    {
        yield 'user' => [self::ROLES_USER];
        yield 'reviewer' => [self::ROLES_REVIEWER];
        yield 'agent admin' => ['ROLE_USER,ROLE_AGENT_ADMIN'];
    }

    #[DataProvider('nonAdmins')]
    public function testOnlyEvalAdminsTriggerAndCancel(string $roles): void
    {
        [$source, $version, $replay] = $this->fixtures();
        $replays = self::replayCount();

        $trigger = self::send('POST', '/api/conversations/'.$source->getId().'/replay', $roles, self::USER, ['targetVersion' => (string) $version->getId()]);
        self::assertSame(403, $trigger->getStatusCode(), $trigger->getContent(false));
        self::assertSame($replays, self::replayCount());

        $cancel = self::send('POST', '/api/conversations/'.$replay->getId().'/replay_run/cancel', $roles, self::USER);
        self::assertSame(403, $cancel->getStatusCode(), $cancel->getContent(false));
        self::assertNull(self::connection()->fetchOne('SELECT cancel_requested_at FROM neuron_conversation_replay WHERE id = :id', ['id' => (string) $replay->getId()]));

        // The admin may.
        $admin = self::send('POST', '/api/conversations/'.$replay->getId().'/replay_run/cancel', self::ROLES_ADMIN, self::OWNER);
        self::assertSame(200, $admin->getStatusCode(), $admin->getContent(false));
    }

    public function testCapabilityTokenHoldersGet403EvenOnTheirOwnConversation(): void
    {
        [, $version, $replay] = $this->fixtures();
        $anonymous = $this->anonymousConversation();
        $replays = self::replayCount();

        $headers = [
            'Accept' => 'application/ld+json',
            'Content-Type' => 'application/ld+json',
            TestHeaderAuthenticator::HEADER_AUTH => 'anonymous',
            'Authorization' => 'Bearer '.self::TOKEN,
        ];
        $trigger = static::createClient()->request('POST', '/api/conversations/'.$anonymous->getId().'/replay', [
            'headers' => $headers,
            'body' => json_encode(['targetVersion' => (string) $version->getId()], \JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(403, $trigger->getStatusCode(), $trigger->getContent(false));
        self::assertSame($replays, self::replayCount());

        $cancel = static::createClient()->request('POST', '/api/conversations/'.$replay->getId().'/replay_run/cancel', ['headers' => $headers]);
        self::assertSame(403, $cancel->getStatusCode(), $cancel->getContent(false));

        $read = static::createClient()->request('GET', '/api/conversations/'.$replay->getId().'/replay_run', ['headers' => $headers]);
        self::assertSame(403, $read->getStatusCode(), $read->getContent(false));
    }

    public function testReviewerReadsTheReplayConversationItsMessagesAndItsRecord(): void
    {
        [, , $replay] = $this->fixtures();
        $id = (string) $replay->getId();

        $item = self::send('GET', '/api/conversations/'.$id, self::ROLES_REVIEWER, self::REVIEWER);
        self::assertSame(200, $item->getStatusCode(), $item->getContent(false));
        self::assertSame(Conversation::ORIGIN_REPLAY, $item->toArray()['origin']);

        $messages = self::send('GET', '/api/conversations/'.$id.'/messages', self::ROLES_REVIEWER, self::REVIEWER);
        self::assertSame(200, $messages->getStatusCode(), $messages->getContent(false));
        self::assertCount(1, $messages->toArray()['member'] ?? $messages->toArray()['hydra:member'] ?? []);

        self::assertContains($id, self::ids(self::send('GET', '/api/conversations?origin=replay', self::ROLES_REVIEWER, self::REVIEWER)));
        self::assertNotContains($id, self::ids(self::send('GET', '/api/conversations', self::ROLES_REVIEWER, self::REVIEWER)));

        $record = self::send('GET', '/api/conversations/'.$id.'/replay_run', self::ROLES_REVIEWER, self::REVIEWER);
        self::assertSame(200, $record->getStatusCode(), $record->getContent(false));
        self::assertSame('/api/conversations/'.$id, $record->toArray()['conversation']);
        self::assertSame(self::OWNER, $record->toArray()['ownerSubject']);
    }

    public function testPlainUserSeesNeitherListEntryNorItemNorRecord(): void
    {
        [, , $replay] = $this->fixtures();
        $id = (string) $replay->getId();

        self::assertSame(403, self::send('GET', '/api/conversations/'.$id, self::ROLES_USER, self::USER)->getStatusCode());
        self::assertSame(404, self::send('GET', '/api/conversations/'.$id.'/messages', self::ROLES_USER, self::USER)->getStatusCode());
        self::assertNotContains($id, self::ids(self::send('GET', '/api/conversations?origin=replay', self::ROLES_USER, self::USER)));
        self::assertNotContains($id, self::ids(self::send('GET', '/api/conversations', self::ROLES_USER, self::USER)));
        self::assertSame(403, self::send('GET', '/api/conversations/'.$id.'/replay_run', self::ROLES_USER, self::USER)->getStatusCode());

        // A conversation that is no replay conversation has no record.
        self::assertSame(404, self::send('GET', '/api/conversations/00000000-0000-4000-8000-000000000000/replay_run', self::ROLES_ADMIN, self::OWNER)->getStatusCode());
    }

    // --- fixtures -------------------------------------------------------

    /**
     * A source with one user message, a published version, and a pending
     * replay of the source owned by self::OWNER with one replayed message.
     *
     * @return array{0: Conversation, 1: AgentVersion, 2: ConversationReplay}
     */
    private function fixtures(): array
    {
        $this->usesDatabase = true;
        ConversationReplayTest::cleanUp();
        static::bootKernel();
        $entityManager = self::entityManager();

        $agent = new Agent()->setName('Replay access')->setSlug(ConversationReplayTest::AGENT_SLUG.'access')->setEnabled(true);
        $entityManager->persist($agent);
        $entityManager->flush();
        $version = new AgentVersion($agent, 1, ConversationConnections::withConnection(Agent::candidateDefaults()), 'test-admin');
        $entityManager->persist($version);
        $agent->setCurrentVersion($version);
        $entityManager->flush();

        $source = self::conversation(ConversationReplayTest::PREFIX.'source-owner', null);
        $sourceMessage = self::message($source, 1, 'Replay question one');

        $conversation = self::conversation(self::OWNER, Conversation::ORIGIN_REPLAY)->setAgentVersion($version);
        $entityManager->flush();
        self::message($conversation, 1, 'Replay question one');

        $replay = new ConversationReplay($conversation, $version, self::OWNER)
            ->setSourceConversation($source)
            ->setSourceMessageIds([(string) $sourceMessage->getId()])
            ->setLanguage('de')
            ->setTurnsReplayed(1);
        $entityManager->persist($replay);
        $entityManager->flush();

        return [$source, $version, $replay];
    }

    private function anonymousConversation(): Conversation
    {
        $conversation = new Conversation();
        $conversation
            ->setOwnerSubject($conversation->anonymousOwnerSubject())
            ->setAnonymous(true)
            ->setTokenHash(CapabilityToken::hash(self::TOKEN))
            ->setAddressHash(hash('sha256', 'replay-access'))
            ->setTitle(ConversationReplayTest::PREFIX.'anonymous')
            ->setProviderModel(ConversationConnections::model())
            ->setRetrievalDepth(4);
        self::entityManager()->persist($conversation);
        self::entityManager()->flush();
        self::message($conversation, 1, 'Anonymous question');

        return $conversation;
    }

    private static function conversation(string $owner, ?string $origin): Conversation
    {
        $conversation = new Conversation()
            ->setOwnerSubject($owner)
            ->setOrigin($origin)
            ->setTitle(ConversationReplayTest::PREFIX.($origin ?? 'source'))
            ->setProviderModel(ConversationConnections::model())
            ->setRetrievalDepth(4);
        self::entityManager()->persist($conversation);
        self::entityManager()->flush();

        return $conversation;
    }

    private static function message(Conversation $conversation, int $sequence, string $content): Message
    {
        $message = new Message()
            ->setConversation($conversation)
            ->setSequence($sequence)
            ->setRole(Message::SPEAKER_USER)
            ->setKind(MessageKind::TEXT)
            ->setStatus(MessageStatus::COMPLETE)
            ->setContent($content);
        $conversation->setNextSequence($sequence + 1);
        self::entityManager()->persist($message);
        self::entityManager()->flush();

        return $message;
    }

    // --- requests -------------------------------------------------------

    /**
     * @param array<string, mixed>|null $json
     */
    private static function send(string $method, string $uri, string $roles, string $user, ?array $json = null): ResponseInterface
    {
        $options = ['headers' => [
            'Accept' => 'application/ld+json',
            TestHeaderAuthenticator::HEADER_ROLES => $roles,
            TestHeaderAuthenticator::HEADER_USER => $user,
        ]];
        if ('POST' === $method) {
            $options['headers']['Content-Type'] = 'application/ld+json';
            if (null !== $json) {
                $options['body'] = json_encode($json, \JSON_THROW_ON_ERROR);
            }
        }

        return static::createClient()->request($method, $uri, $options);
    }

    /**
     * @return list<string>
     */
    private static function ids(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        $data = $response->toArray();

        return array_values(array_map(static fn (array $member): string => (string) $member['id'], $data['member'] ?? $data['hydra:member'] ?? []));
    }

    private static function replayCount(): int
    {
        return (int) self::connection()->fetchOne('SELECT COUNT(*) FROM neuron_conversation_replay');
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
