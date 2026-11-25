<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:54:19 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Conversation\Anonymous;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\NeuronAiBundle\Anonymous\CapabilityToken;
use Dmstr\NeuronAiBundle\Anonymous\ConversationLimitException;
use Dmstr\NeuronAiBundle\Conversation\ConversationStatus;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\AnonymousFixtures;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Turn limits of anonymous conversations: the turn limit, the token budget
 * (input plus output tokens) and the retention close the conversation and
 * refuse further turns with 422 and a stable `code`, without a provider
 * call; a close is audited as an `update` under `anon:<id>`; a
 * `responseSchema` is refused; conversations of users are not limited.
 * The streamed turn (`POST /conversations/{id}/stream`) passes the same
 * gate and is counted the same way. An anonymous conversation
 * created from a release with `agent` (pinned to the agent's version) is
 * closed by the turn and token limits the same way.
 *
 * The anonymous fixtures are written through the entity manager with a
 * known capability token, so the test does not depend on the creation
 * path. Turns are sent with that token (`X-Test-Auth: anonymous` plus
 * `Authorization: Bearer anon_…`) and therefore rely on the capability
 * token authenticator of the security layer. Limits are set through the
 * settings API. Agents are created through the API on the shared client
 * (prefix `test-agent-` of {@see AgentFixtures}), so the fake
 * provider keeps its queue.
 *
 * Needs the migrated test database (CI only); cleanup as in
 * {@see AnonymousFixtures}, plus the user conversations created here.
 */
#[Group('database')]
final class AnonymousLimitsTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private Client $client;

    /** @var list<string> ids of non-anonymous conversations to remove */
    private array $userConversations = [];

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        AnonymousFixtures::cleanUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        $connection = AnonymousFixtures::connection();
        foreach ($this->userConversations as $id) {
            $connection->executeStatement('DELETE FROM neuron_conversation WHERE id = :id', ['id' => $id]);
        }
        AnonymousFixtures::cleanUp();
        parent::tearDown();
    }

    public function testReachingTheTurnLimitClosesTheConversation(): void
    {
        AnonymousFixtures::configure(['max_turns_per_conversation' => 2]);
        [$id, $token] = $this->anonymousConversation();
        $this->factory()->queueAnswer('Eins')->queueAnswer('Zwei');

        foreach (['Erste Frage', 'Zweite Frage'] as $content) {
            $this->turn($token, ['content' => $content]);
            self::assertResponseStatusCodeSame(201, $content);
        }

        $row = AnonymousFixtures::row($id);
        self::assertSame(ConversationStatus::CLOSED, $row['status']);
        self::assertSame(Conversation::CLOSE_REASON_TURN_LIMIT, $row['close_reason']);
        self::assertNotNull($row['closed_at']);
        self::assertSame(2, (int) $row['turn_count']);
        $calls = $this->calls();
        $messages = $this->messageCount($id);

        $refused = $this->turn($token, ['content' => 'Dritte Frage']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ConversationLimitException::CODE_TURN_LIMIT, $refused->toArray(false)['code'] ?? null);
        self::assertSame($calls, $this->calls(), 'no provider call');
        self::assertSame($messages, $this->messageCount($id));
        $this->assertCloseIsAuditedUnder($id);
    }

    public function testStreamedTurnIsCountedAndClosesAtTheTurnLimit(): void
    {
        AnonymousFixtures::configure(['max_turns_per_conversation' => 1]);
        [$id, $token] = $this->anonymousConversation();
        $this->factory()->queueAnswer('Eins');

        $streamed = $this->streamTurn($token, ['content' => 'Erste Frage']);

        self::assertSame(200, $streamed->getStatusCode(), $streamed->getContent(false));
        self::assertStringContainsString('event: done', $streamed->getContent());
        $row = AnonymousFixtures::row($id);
        self::assertSame(1, (int) $row['turn_count']);
        self::assertSame(ConversationStatus::CLOSED, $row['status']);
        self::assertSame(Conversation::CLOSE_REASON_TURN_LIMIT, $row['close_reason']);
        $calls = $this->calls();
        $messages = $this->messageCount($id);

        $refused = $this->streamTurn($token, ['content' => 'Zweite Frage']);

        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(ConversationLimitException::CODE_TURN_LIMIT, $refused->toArray(false)['code'] ?? null);
        self::assertSame($calls, $this->calls(), 'no provider call');
        self::assertSame($messages, $this->messageCount($id));
    }

    public function testReachingTheTokenBudgetClosesTheConversation(): void
    {
        AnonymousFixtures::configure(['max_tokens_per_conversation' => 10]);
        [$id, $token] = $this->anonymousConversation();
        // 30 input + 4 output tokens count; the 10 cached ones do not.
        $this->factory()->getChatProvider()->addResponses(new AssistantMessage('Blau.')->setUsage(new Usage(30, 4, 10, 0)));

        $this->turn($token, ['content' => 'Farbe?']);

        self::assertResponseStatusCodeSame(201);
        $row = AnonymousFixtures::row($id);
        self::assertSame(34, (int) $row['tokens_used']);
        self::assertSame(1, (int) $row['turn_count']);
        self::assertSame(ConversationStatus::CLOSED, $row['status']);
        self::assertSame(Conversation::CLOSE_REASON_TOKEN_LIMIT, $row['close_reason']);
        $calls = $this->calls();

        $refused = $this->turn($token, ['content' => 'Und noch?']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ConversationLimitException::CODE_TOKEN_LIMIT, $refused->toArray(false)['code'] ?? null);
        self::assertSame($calls, $this->calls(), 'no provider call');
        $this->assertCloseIsAuditedUnder($id);
    }

    public function testPassedRetentionClosesTheConversationButKeepsItReadable(): void
    {
        [$id, $token] = $this->anonymousConversation();
        // Default retention: 30 days.
        AnonymousFixtures::connection()->executeStatement(
            "UPDATE neuron_conversation SET created_at = NOW() - INTERVAL '31 days' WHERE id = :id",
            ['id' => $id],
        );
        $calls = $this->calls();

        $refused = $this->turn($token, ['content' => 'Noch da?']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ConversationLimitException::CODE_RETENTION_EXPIRED, $refused->toArray(false)['code'] ?? null);
        self::assertSame($calls, $this->calls(), 'no provider call');
        self::assertSame(0, $this->messageCount($id));
        $row = AnonymousFixtures::row($id);
        self::assertSame(ConversationStatus::CLOSED, $row['status']);
        self::assertSame(Conversation::CLOSE_REASON_RETENTION, $row['close_reason']);
        $this->assertCloseIsAuditedUnder($id);

        // A second turn keeps the code of the close reason.
        $again = $this->turn($token, ['content' => 'Und jetzt?']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(ConversationLimitException::CODE_RETENTION_EXPIRED, $again->toArray(false)['code'] ?? null);

        // Reading with the token still works.
        $read = $this->client->request('GET', '/api/conversations/'.$id, ['headers' => AnonymousFixtures::tokenHeaders($token)]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(ConversationStatus::CLOSED, $read->toArray()['status']);
        self::assertSame(Conversation::CLOSE_REASON_RETENTION, $read->toArray()['closeReason']);
    }

    public function testClosedConversationWithoutReasonAnswersConversationClosed(): void
    {
        [$id, $token] = $this->anonymousConversation(ConversationStatus::CLOSED);

        $refused = $this->turn($token, ['content' => 'Hallo?']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ConversationLimitException::CODE_CLOSED, $refused->toArray(false)['code'] ?? null);
        self::assertSame(0, $this->messageCount($id));
        $this->factory()->getChatProvider()->assertNothingSent();
    }

    public function testResponseSchemaIsRefused(): void
    {
        [$id, $token] = $this->anonymousConversation();

        $this->turn($token, ['content' => 'Farbe?', 'responseSchema' => ['type' => 'object']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->messageCount($id));
        self::assertSame(ConversationStatus::ACTIVE, AnonymousFixtures::row($id)['status']);
        $this->factory()->getChatProvider()->assertNothingSent();
    }

    public function testTurnLimitClosesAnAgentPinnedConversation(): void
    {
        AnonymousFixtures::configure(['max_turns_per_conversation' => 2]);
        [$id, $token] = $this->agentConversation('anon-turns');
        $this->factory()->queueAnswer('Eins')->queueAnswer('Zwei');

        foreach (['Erste Frage', 'Zweite Frage'] as $content) {
            $response = $this->turn($token, ['content' => $content]);
            self::assertSame(201, $response->getStatusCode(), $content.': '.$response->getContent(false));
        }

        $row = AnonymousFixtures::row($id);
        self::assertNotNull($row['agent_version']);
        self::assertSame(ConversationStatus::CLOSED, $row['status']);
        self::assertSame(Conversation::CLOSE_REASON_TURN_LIMIT, $row['close_reason']);
        self::assertSame(2, (int) $row['turn_count']);
        $calls = $this->calls();

        $refused = $this->turn($token, ['content' => 'Dritte Frage']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ConversationLimitException::CODE_TURN_LIMIT, $refused->toArray(false)['code'] ?? null);
        self::assertSame($calls, $this->calls(), 'no provider call');
        $this->assertCloseIsAuditedUnder($id);
    }

    public function testTokenBudgetClosesAnAgentPinnedConversation(): void
    {
        AnonymousFixtures::configure(['max_tokens_per_conversation' => 10]);
        [$id, $token] = $this->agentConversation('anon-tokens');
        $this->factory()->getChatProvider()->addResponses(new AssistantMessage('Blau.')->setUsage(new Usage(30, 4, 0, 0)));

        $response = $this->turn($token, ['content' => 'Farbe?']);

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        $row = AnonymousFixtures::row($id);
        self::assertNotNull($row['agent_version']);
        self::assertSame(34, (int) $row['tokens_used']);
        self::assertSame(ConversationStatus::CLOSED, $row['status']);
        self::assertSame(Conversation::CLOSE_REASON_TOKEN_LIMIT, $row['close_reason']);
        $calls = $this->calls();

        $refused = $this->turn($token, ['content' => 'Und noch?']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ConversationLimitException::CODE_TOKEN_LIMIT, $refused->toArray(false)['code'] ?? null);
        self::assertSame($calls, $this->calls(), 'no provider call');
        $this->assertCloseIsAuditedUnder($id);
    }

    public function testConversationsOfUsersAreNotLimited(): void
    {
        AnonymousFixtures::configure(['max_turns_per_conversation' => 1, 'max_tokens_per_conversation' => 1]);
        $conversation = new Conversation()
            ->setOwnerSubject('test-admin')
            ->setProviderModel(AnonymousFixtures::releaseModel())
            ->setRetrievalDepth(4);
        AnonymousFixtures::entityManager()->persist($conversation);
        AnonymousFixtures::entityManager()->flush();
        $id = (string) $conversation->getId();
        $this->userConversations[] = $id;
        $this->factory()->getChatProvider()->addResponses(
            new AssistantMessage('Eins')->setUsage(new Usage(30, 4, 0, 0)),
            new AssistantMessage('Zwei')->setUsage(new Usage(30, 4, 0, 0)),
        );

        // The default test identity (test-admin) owns it.
        foreach (['Erste Frage', 'Zweite Frage'] as $content) {
            $this->client->request('POST', '/api/conversations/'.$id.'/messages', [
                'headers' => ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
                'json' => ['content' => $content],
            ]);
            self::assertResponseStatusCodeSame(201, $content);
        }

        $row = AnonymousFixtures::row($id);
        self::assertSame(ConversationStatus::ACTIVE, $row['status']);
        self::assertNull($row['close_reason']);
        // The counters are kept for every conversation.
        self::assertSame(2, (int) $row['turn_count']);
        self::assertSame(68, (int) $row['tokens_used']);
    }

    /**
     * An anonymous conversation with a known capability token, written
     * through the entity manager; the entity manager is cleared afterwards
     * so requests load the stored row.
     *
     * @return array{string, string} conversation id and token
     */
    private function anonymousConversation(string $status = ConversationStatus::ACTIVE): array
    {
        $token = (new CapabilityToken())->generate();
        $conversation = new Conversation()
            ->setAnonymous(true)
            ->setTokenHash(CapabilityToken::hash($token))
            ->setAddressHash(str_repeat('a', 64))
            ->setProviderModel(AnonymousFixtures::releaseModel())
            ->setRetrievalDepth(4)
            ->setStatus($status);
        $conversation->setOwnerSubject($conversation->anonymousOwnerSubject());

        $entityManager = AnonymousFixtures::entityManager();
        $entityManager->persist($conversation);
        $entityManager->flush();
        $entityManager->clear();

        return [(string) $conversation->getId(), $token];
    }

    /**
     * An anonymous conversation created through the public endpoint from a
     * release with a published agent; agent, publish and settings go
     * through the shared client.
     *
     * @param string $slug without the prefix
     *
     * @return array{string, string} conversation id and token
     */
    private function agentConversation(string $slug): array
    {
        AgentFixtures::providerConnections();
        $created = AgentFixtures::send($this->client, 'POST', AgentFixtures::AGENTS, AgentFixtures::ADMIN, [
            'name' => 'Agent '.$slug,
            'slug' => AgentFixtures::PREFIX.$slug,
            'candidate' => AgentFixtures::validCandidate(),
        ]);
        self::assertSame(201, $created->getStatusCode(), $created->getContent(false));
        $published = AgentFixtures::send($this->client, 'POST', AgentFixtures::AGENTS.'/'.$created->toArray()['id'].'/publish', AgentFixtures::ADMIN);
        self::assertSame(201, $published->getStatusCode(), $published->getContent(false));
        AnonymousFixtures::configure(['agent' => AgentFixtures::PREFIX.$slug]);

        $response = AnonymousFixtures::create($this->client, AnonymousFixtures::randomAddress());
        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        $data = $response->toArray();
        self::assertSame($published->toArray()['@id'], $data['conversation']['agentVersion']);

        return [(string) $data['conversation']['id'], (string) $data['token']];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function turn(string $token, array $body): ResponseInterface
    {
        $id = (string) AnonymousFixtures::connection()->fetchOne('SELECT id FROM neuron_conversation WHERE token_hash = :hash', ['hash' => CapabilityToken::hash($token)]);

        return $this->client->request('POST', '/api/conversations/'.$id.'/messages', [
            'headers' => AnonymousFixtures::tokenHeaders($token),
            'json' => $body,
        ]);
    }

    /**
     * `POST /api/conversations/{id}/stream` with the capability token.
     *
     * @param array<string, mixed> $body
     */
    private function streamTurn(string $token, array $body): ResponseInterface
    {
        $id = (string) AnonymousFixtures::connection()->fetchOne('SELECT id FROM neuron_conversation WHERE token_hash = :hash', ['hash' => CapabilityToken::hash($token)]);

        return $this->client->request('POST', '/api/conversations/'.$id.'/stream', [
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'text/event-stream'] + AnonymousFixtures::tokenHeaders($token),
            'json' => $body,
        ]);
    }

    /**
     * The close is an audited `update` under the conversation's own
     * principal, never the token.
     */
    private function assertCloseIsAuditedUnder(string $id): void
    {
        $updates = array_values(array_filter(AnonymousFixtures::auditEntries($id), static fn (array $entry): bool => 'update' === $entry['action']));

        self::assertNotEmpty($updates, 'update entry of the close');
        foreach ($updates as $entry) {
            self::assertSame(Conversation::ANONYMOUS_OWNER_PREFIX.$id, $entry['username']);
            self::assertStringNotContainsString(CapabilityToken::PREFIX, (string) $entry['data']);
        }
    }

    private function calls(): int
    {
        return $this->factory()->getChatProvider()->getCallCount();
    }

    private function messageCount(string $id): int
    {
        return (int) AnonymousFixtures::connection()->fetchOne('SELECT COUNT(*) FROM neuron_message WHERE conversation_id = :id', ['id' => $id]);
    }

    private function factory(): FakeProviderFactory
    {
        $factory = static::getContainer()->get(ProviderFactory::class);
        self::assertInstanceOf(FakeProviderFactory::class, $factory);

        return $factory;
    }
}
