<?php

// file generated with AI assistance: Claude Code - 2026-10-09 07:01:30 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Mcp\Restriction;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Anonymous\CapabilityToken;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Tests\Integration\Conversation\AccessTest;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\AnonymousFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Functional test of restricted MCP connections (`restricted: true` or
 * `auth_type: client_credentials`, a service account that acts for every
 * user): a plain `ROLE_USER` naming one directly in
 * `POST /api/conversations` gets 403 before any provider call or stored
 * row; `ROLE_AGENT_ADMIN` (and `ROLE_ADMIN`, listed in the permission map) may;
 * through an agent version, whose `mcpTools` an agent administrator
 * approved, a `ROLE_USER` uses its tools (agent chat, agent conversation).
 * Anonymous conversations never
 * get MCP tools in a turn, even when their row carries connections or
 * their pinned version was changed to have `mcpTools` afterwards.
 *
 * The direct-use check needs the authorization checker on
 * McpConnectionResolver (service definition in DmstrNeuronAiBundle); without
 * it every restricted connection is refused on the direct paths (fail
 * closed), so the agent-admin cases fail until it is wired.
 *
 * Needs the migrated test database (CI only). Agents, connections and
 * collections use the prefix and cleanup of {@see AgentFixtures};
 * conversations of identified users the owner prefix of
 * {@see AccessTest}; anonymous conversations are removed by id.
 */
#[Group('database')]
final class RestrictedMcpConnectionAccessTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const string OWNER = AccessTest::PREFIX.'restricted';
    private const array QUESTION = [['role' => 'user', 'content' => 'Welche Aufgaben sind offen?']];

    private Client $client;

    /** @var list<string> */
    private array $anonymousConversations = [];

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        AccessTest::cleanUp();
        AgentFixtures::cleanUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        $dbal = AgentFixtures::dbal();
        foreach ($this->anonymousConversations as $id) {
            $dbal->executeStatement('DELETE FROM neuron_conversation WHERE id = :id', ['id' => $id]);
            $dbal->executeStatement('DELETE FROM dmstr_log_entries WHERE object_class = :class AND object_id = :id', ['class' => Conversation::class, 'id' => $id]);
        }
        AccessTest::cleanUp();
        AgentFixtures::cleanUp();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function restrictions(): iterable
    {
        yield 'restricted flag' => [['restricted' => true]];
        yield 'client credentials' => [['auth_type' => 'client_credentials']];
    }

    /**
     * @param array<string, mixed> $restriction
     */
    #[DataProvider('restrictions')]
    public function testPlainUserCreatingAConversationWithARestrictedConnectionGets403(array $restriction): void
    {
        $flowable = $this->restricted('flowable', $restriction);
        $docs = AgentFixtures::connection('docs');

        $response = $this->send('POST', '/api/conversations', 'ROLE_USER', AccessTest::createBody(['mcpConnections' => [$docs->getName(), $flowable->getName()]]));

        self::assertSame(403, $response->getStatusCode(), $response->getContent(false));
        self::assertStringContainsString(\sprintf('MCP connection "%s" is restricted', $flowable->getName()), self::detail($response));
        self::assertSame(0, AccessTest::conversationCount());
    }

    public function testAgentAdminMayCreateAConversationWithARestrictedConnection(): void
    {
        $flowable = $this->restricted('flowable', ['restricted' => true]);

        $response = $this->send('POST', '/api/conversations', 'ROLE_AGENT_ADMIN', AccessTest::createBody(['mcpConnections' => [$flowable->getName()]]));

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        self::assertSame([$flowable->getId()->toRfc4122()], $response->toArray()['mcpConnections']);
    }

    public function testPlainUserUsesARestrictedConnectionThroughAnAgentVersion(): void
    {
        $flowable = $this->restricted('flowable', ['auth_type' => 'client_credentials']);
        AgentFixtures::tool($flowable, 'flowable_task_list');
        $id = $this->publishedAgent('flowable', ['mcpTools' => [['connection' => $flowable->getName(), 'name' => 'flowable_task_list']]]);

        // Agent chat.
        $this->providerFactory()->queueAnswer('Zwei Aufgaben.');
        $chat = AgentFixtures::send($this->client, 'POST', AgentFixtures::AGENTS.'/'.$id.'/chat', AgentFixtures::USER, ['messages' => self::QUESTION]);

        self::assertSame(200, $chat->getStatusCode(), $chat->getContent(false));
        self::assertSame([$flowable->getName()], $chat->toArray()['mcpConnections']);
        self::assertSame(['flowable_task_list'], $this->offeredTools());

        // Agent conversation, created and turned by the same plain user.
        $created = $this->send('POST', '/api/conversations', 'ROLE_USER', ['agent' => AgentFixtures::PREFIX.'flowable']);
        self::assertSame(201, $created->getStatusCode(), $created->getContent(false));
        self::assertSame([$flowable->getId()->toRfc4122()], $created->toArray()['mcpConnections']);

        $this->providerFactory()->queueAnswer('Immer noch zwei.');
        $turn = $this->send('POST', '/api/conversations/'.$created->toArray()['id'].'/messages', 'ROLE_USER', ['content' => 'Und jetzt?']);
        self::assertSame(201, $turn->getStatusCode(), $turn->getContent(false));
        self::assertSame(['flowable_task_list'], $this->offeredTools(last: true));
    }

    public function testAnonymousTurnOffersNoMcpToolsFromItsRow(): void
    {
        $docs = AgentFixtures::connection('docs');
        AgentFixtures::tool($docs, 'search');
        [$conversation, $token] = $this->anonymousConversation(static fn (Conversation $c): Conversation => $c->setMcpConnections([$docs->getId()->toRfc4122()]));
        $this->providerFactory()->queueAnswer('Ohne Werkzeuge.');

        $response = $this->client->request('POST', '/api/conversations/'.$conversation.'/messages', [
            'headers' => AnonymousFixtures::tokenHeaders($token),
            'json' => ['content' => 'Suche bitte.'],
        ]);

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        self::assertSame([], $this->offeredTools());
        self::assertSame(0, $this->mcpFake()->getTransportCount());
    }

    public function testAnonymousTurnOffersNoMcpToolsOfAVersionChangedAfterwards(): void
    {
        $flowable = $this->restricted('flowable', ['auth_type' => 'client_credentials']);
        AgentFixtures::tool($flowable, 'flowable_task_list');
        $id = $this->publishedAgent('anon-later');
        $version = $this->currentVersion($id);

        // The pinned version gains MCP tools after the conversation was
        // created (published versions are immutable through the API, so
        // the row is changed directly).
        [$conversation, $token] = $this->anonymousConversation(static fn (Conversation $c): Conversation => $c->setAgentVersion($version));
        $configuration = $version->getConfiguration();
        $configuration['mcpTools'] = [['connection' => $flowable->getId()->toRfc4122(), 'name' => 'flowable_task_list', 'terminal' => false]];
        AgentFixtures::dbal()->executeStatement('UPDATE neuron_agent_version SET configuration = CAST(:configuration AS json) WHERE id = :id', [
            'configuration' => json_encode($configuration, \JSON_THROW_ON_ERROR),
            'id' => $version->getId()->toRfc4122(),
        ]);
        AgentFixtures::entityManager()->clear();
        $this->providerFactory()->queueAnswer('Ohne Werkzeuge.');

        $response = $this->client->request('POST', '/api/conversations/'.$conversation.'/messages', [
            'headers' => AnonymousFixtures::tokenHeaders($token),
            'json' => ['content' => 'Welche Aufgaben sind offen?'],
        ]);

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        self::assertSame([], $this->offeredTools());
        self::assertSame(0, $this->mcpFake()->getTransportCount());
    }

    /**
     * An `mcp` connection of the agent test prefix with $restriction in its configJson.
     *
     * @param array<string, mixed> $restriction
     */
    private function restricted(string $name, array $restriction): ApiConfiguration
    {
        $connection = AgentFixtures::connection($name);
        $connection->setConfigJson($restriction + $connection->getConfigJson());
        AgentFixtures::entityManager()->flush();

        return $connection;
    }

    /**
     * A request on the shared client as {@see self::OWNER} with $roles.
     *
     * @param array<string, mixed> $json
     */
    private function send(string $method, string $uri, string $roles, array $json): ResponseInterface
    {
        $response = $this->client->request($method, $uri, [
            'headers' => [
                'Accept' => 'application/ld+json',
                'Content-Type' => 'application/ld+json',
                TestHeaderAuthenticator::HEADER_ROLES => $roles,
                TestHeaderAuthenticator::HEADER_USER => self::OWNER,
            ],
            'json' => $json,
        ]);
        $response->getStatusCode();

        return $response;
    }

    /**
     * Creates (as the default admin) and publishes an agent with a valid
     * candidate plus $overrides on the shared client.
     *
     * @param array<string, mixed> $overrides
     *
     * @return string the agent id
     */
    private function publishedAgent(string $slug, array $overrides = []): string
    {
        AgentFixtures::providerConnections();
        $created = AgentFixtures::send($this->client, 'POST', AgentFixtures::AGENTS, AgentFixtures::ADMIN, [
            'name' => 'Agent '.$slug,
            'slug' => AgentFixtures::PREFIX.$slug,
            'candidate' => AgentFixtures::validCandidate($overrides),
        ]);
        self::assertSame(201, $created->getStatusCode(), $created->getContent(false));
        $id = (string) $created->toArray()['id'];

        $published = AgentFixtures::send($this->client, 'POST', AgentFixtures::AGENTS.'/'.$id.'/publish', AgentFixtures::ADMIN);
        self::assertSame(201, $published->getStatusCode(), $published->getContent(false));

        return $id;
    }

    private function currentVersion(string $agentId): AgentVersion
    {
        $agent = AgentFixtures::entityManager()->find(Agent::class, $agentId);
        self::assertInstanceOf(Agent::class, $agent);
        $version = $agent->getCurrentVersion();
        self::assertInstanceOf(AgentVersion::class, $version);

        return $version;
    }

    /**
     * An anonymous conversation with a known capability token, written
     * through the entity manager after $configure; removed in tearDown().
     *
     * @param \Closure(Conversation): Conversation $configure
     *
     * @return array{0: string, 1: string} the conversation id and the token
     */
    private function anonymousConversation(\Closure $configure): array
    {
        $token = (new CapabilityToken())->generate();
        $conversation = new Conversation()
            ->setAnonymous(true)
            ->setTokenHash(CapabilityToken::hash($token))
            ->setAddressHash(str_repeat('a', 64))
            ->setProviderModel(ConversationConnections::model())
            ->setRetrievalDepth(4);
        $conversation = $configure($conversation);
        $conversation->setOwnerSubject($conversation->anonymousOwnerSubject());
        $entityManager = AgentFixtures::entityManager();
        $entityManager->persist($conversation);
        $entityManager->flush();
        $this->anonymousConversations[] = (string) $conversation->getId();

        return [(string) $conversation->getId(), $token];
    }

    /**
     * Tool names offered to the chat provider on its first (or last) call.
     *
     * @return list<string>
     */
    private function offeredTools(bool $last = false): array
    {
        $records = $this->providerFactory()->getChatProvider()->getRecorded();
        self::assertNotEmpty($records);
        $record = $last ? $records[array_key_last($records)] : $records[0];
        self::assertInstanceOf(RequestRecord::class, $record);

        return array_values(array_map(static fn (ToolInterface|ProviderToolInterface $tool): string => $tool->getName(), $record->tools));
    }

    private static function detail(ResponseInterface $response): string
    {
        return (string) ($response->toArray(false)['detail'] ?? '');
    }

    private function providerFactory(): FakeProviderFactory
    {
        $factory = static::getContainer()->get(ProviderFactory::class);
        self::assertInstanceOf(FakeProviderFactory::class, $factory);

        return $factory;
    }

    private function mcpFake(): FakeMcpTransportFactory
    {
        $fake = static::getContainer()->get(FakeMcpTransportFactory::class);
        self::assertInstanceOf(FakeMcpTransportFactory::class, $fake);

        return $fake;
    }
}
