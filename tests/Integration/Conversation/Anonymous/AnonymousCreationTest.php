<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:54:19 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Conversation\Anonymous;

use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Anonymous\CapabilityToken;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\AnonymousFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Public creation of anonymous conversations,
 * `POST /api/conversations/anonymous`: the released configuration of the
 * host's policy ({@see \Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryAnonymousAccessPolicy}), the capability token (returned once, stored as its SHA-256
 * hash), the audit actor `anon:<id>`, the body guard and the creation limit
 * per client address (429 with `Retry-After`, 403 when disabled, real
 * client address only behind a trusted proxy).
 *
 * A release without an agent names its model as a reference to the model
 * catalogue (IRI or UUID; the entry names the provider connection), there
 * is no default. A missing model, a reference that does not resolve (a
 * model name, an unknown, unavailable entry, one of an inactive or an `mcp`
 * connection), a model the connection does not offer and a provider that
 * cannot be reached answer 503 `misconfigured (model)`, never with the key.
 * {@see configure()} adds the IRI of the release entry {@see releaseModel()}
 * ({@see FakeProviderFactory::ANTHROPIC_MODEL} on the `anthropic` fixture
 * connection {@see self::RELEASE_CONNECTION}, both created on the way)
 * unless the values name `model` themselves, so tests of other concerns
 * keep a working release.
 *
 * A release with `agent` pins the agent's current published
 * version and copies its values (provider connection and its type as
 * `provider`, model, collections, retrieval depth, default language,
 * empty variables); the released collections and model
 * are ignored, the body must still be `{}`, a `responseSchema`
 * on a turn answers 422, and an unknown (typo), disabled or unpublished
 * agent or a stale reference of its version (also a deactivated provider
 * connection) answers 503
 * (`misconfigured (agent)`). The test kernel runs with debug, so the
 * detail of a 503 is visible. Agents use the helpers and the `test-agent-`
 * prefix of {@see AgentFixtures}.
 *
 * Anonymous conversations have no MCP tools: an agent whose version has
 * `mcpTools` answers 422 at creation. Validating the released values on
 * save is the host's job and not covered here.
 *
 * Requests carry `X-Test-Auth: anonymous`, so the test authenticator adds
 * no identity. Every creation sends its own random documentation address
 * (`2001:db8::/32`) as `X-Forwarded-For` from the trusted test client
 * (127.0.0.1, trusted proxy of the test kernel), so creation counters never leak between tests
 * or runs. The counters must survive between requests: the test env needs
 * a `cache.rate_limiter` pool that the kernel does not reset per request
 * (an array pool is reset by `services_resetter`).
 *
 * Needs the migrated test database (CI only). Anonymous conversations
 * (`owner_subject LIKE 'anon:%'`), their audit entries, the settings row of
 * `anonymous_conversations`, fixtures with the prefix `test-anon-` and the
 * agents of {@see AgentFixtures::cleanUp()} are removed before and
 * after each test. The static helpers are shared with
 * {@see AnonymousLimitsTest}.
 */
#[Group('database')]
final class AnonymousCreationTest extends AnonymousFixtures
{
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

    public function testCreationReturnsTheReleasedConfigurationAndATokenOnce(): void
    {
        $client = static::createClient();
        $collection = $this->collection('handbuch');
        self::configure([
            'collections' => [self::PREFIX.'handbuch'],
            'model' => self::releaseModel()->iri(),
        ]);
        $address = self::randomAddress();

        $response = self::create($client, $address);

        self::assertResponseStatusCodeSame(201);
        $data = $response->toArray();
        $token = $data['token'];
        self::assertIsString($token);
        self::assertTrue(CapabilityToken::isCapabilityToken($token));
        $conversation = $data['conversation'];
        self::assertIsArray($conversation, 'The conversation is embedded, not an IRI.');
        $id = (string) $conversation['id'];
        self::assertTrue($conversation['anonymous']);
        self::assertSame(Conversation::ANONYMOUS_OWNER_PREFIX.$id, $conversation['ownerSubject']);
        self::assertSame('active', $conversation['status']);
        self::assertSame([(string) $collection->getId()], $conversation['collections']);
        self::assertSame([], $conversation['mcpConnections'], 'anonymous conversations have no MCP tools');
        self::assertSame('anthropic', $conversation['provider']);
        self::assertSame(self::releaseModel()->iri(), $conversation['model']);
        self::assertSame(FakeProviderFactory::ANTHROPIC_MODEL, $conversation['modelName']);
        self::assertSame(self::RELEASE_CONNECTION, $conversation['connection']);
        self::assertSame(0, $conversation['turnCount']);
        self::assertSame(self::releaseConnection()->getId()->toRfc4122(), (string) self::row($id)['provider_connection_id']);
        self::assertSame(self::releaseModel()->getId()->toRfc4122(), (string) self::row($id)['provider_model_id']);
        self::assertSame(0, $conversation['tokensUsed']);

        // Only the hash of the token is stored, never the token itself.
        $row = self::row($id);
        self::assertTrue((bool) $row['anonymous']);
        self::assertSame(hash('sha256', $token), $row['token_hash']);
        self::assertSame(self::addressHash($address), $row['address_hash']);
        $stored = (string) json_encode($row);
        self::assertStringNotContainsString($token, $stored);
        self::assertStringNotContainsString(substr($token, \strlen(CapabilityToken::PREFIX)), $stored);

        // Neither hash reaches the visitor.
        $body = $response->getContent();
        foreach (['tokenHash', 'token_hash', 'addressHash', 'address_hash', (string) $row['token_hash'], (string) $row['address_hash']] as $secret) {
            self::assertStringNotContainsString($secret, $body);
        }

        // The creation is audited under the conversation, not the token.
        $entries = self::auditEntries($id);
        self::assertSame([['create', Conversation::ANONYMOUS_OWNER_PREFIX.$id]], array_map(static fn (array $entry): array => [$entry['action'], $entry['username']], $entries));
        foreach ($entries as $entry) {
            self::assertStringNotContainsString(CapabilityToken::PREFIX, (string) $entry['username']);
            self::assertStringNotContainsString($token, (string) $entry['data']);
        }
    }

    public function testReleaseByUuidOfAnOllamaEntryCreatesAnOllamaConversation(): void
    {
        $client = static::createClient();
        $ollama = ProviderConnectionFixtures::ollama(self::PREFIX.'local');
        ProviderConnectionFixtures::persist(self::entityManager(), $ollama);
        $entry = self::catalogueModel(self::entityManager(), $ollama, FakeProviderFactory::OLLAMA_MODEL);
        self::configure(['model' => $entry->getId()->toRfc4122()]);

        $response = self::create($client, self::randomAddress());

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        $conversation = $response->toArray()['conversation'];
        self::assertSame('ollama', $conversation['provider']);
        self::assertSame($entry->iri(), $conversation['model']);
        self::assertSame(FakeProviderFactory::OLLAMA_MODEL, $conversation['modelName']);
        self::assertSame([], $conversation['collections']);
        self::assertSame([], $conversation['mcpConnections']);
    }

    /**
     * The section defaults name no model: a release without one cannot
     * create anonymous conversations.
     */
    public function testReleaseWithoutModelIsAnOperatorError(): void
    {
        $client = static::createClient();
        self::configure(['model' => null]);

        self::assertMisconfigured($client, 'model');
    }

    public function testUntouchedSectionIsAnOperatorError(): void
    {
        self::assertMisconfigured(static::createClient(), 'model');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unresolvableModels(): iterable
    {
        yield 'model name' => ['name'];
        yield 'unknown entry' => ['unknown'];
        yield 'unavailable entry' => ['unavailable'];
        yield 'inactive connection' => ['inactive'];
        yield 'mcp connection' => ['mcp'];
    }

    #[DataProvider('unresolvableModels')]
    public function testUnresolvableModelIsAnOperatorError(string $case): void
    {
        $client = static::createClient();
        $reference = match ($case) {
            'name' => FakeProviderFactory::ANTHROPIC_MODEL,
            'unknown' => '/api/refs/provider_models/0199a1b2-0000-4000-8000-000000000000',
            'unavailable' => self::unavailable(self::releaseModel())->iri(),
            'inactive' => self::catalogueModel(self::entityManager(), self::inactiveConnection(), FakeProviderFactory::OLLAMA_MODEL)->iri(),
            'mcp' => self::catalogueModel(self::entityManager(), $this->mcpConnection('not-a-provider'), 'search')->iri(),
        };
        self::configure(['model' => $reference]);

        self::assertMisconfigured($client, 'model');
    }

    public function testModelNotOfferedByTheConnectionIsAnOperatorError(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        self::configure(['model' => self::catalogueModelIri(self::entityManager(), self::releaseConnection(), 'claude-retired')]);
        self::modelLister()->offer([FakeProviderFactory::ANTHROPIC_MODEL]);

        self::assertMisconfigured($client, 'model');
        self::assertSame([self::RELEASE_CONNECTION], self::modelLister()->getListedConnections());
    }

    public function testUnreachableProviderIsAnOperatorErrorWithoutTheKey(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        self::configure([]);
        self::modelLister()->unreachable();

        $response = self::assertMisconfigured($client, 'model');
        self::assertStringNotContainsString(ProviderConnectionFixtures::PLACEHOLDER_API_KEY, $response->getContent(false));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedBodies(): iterable
    {
        yield 'collections' => ['{"collections": ["handbuch"]}'];
        yield 'mcpConnections' => ['{"mcpConnections": ["docs"]}'];
        yield 'provider' => ['{"provider": "ollama"}'];
        yield 'connection' => ['{"connection": "test-anon-fake-anthropic"}'];
        yield 'model' => ['{"model": "claude-opus"}'];
        yield 'title' => ['{"title": "Meine Frage"}'];
        yield 'ownerSubject' => ['{"ownerSubject": "test-admin"}'];
        yield 'not an object' => ['[]'];
    }

    #[DataProvider('rejectedBodies')]
    public function testConfigurationInTheBodyIsRejected(string $body): void
    {
        self::create(static::createClient(), self::randomAddress(), $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, self::anonymousCount());
    }

    public function testCreationsPerAddressAreLimited(): void
    {
        $client = static::createClient();
        self::configure(['creations_per_address_and_hour' => 3]);
        $address = self::randomAddress();

        for ($i = 1; $i <= 3; ++$i) {
            self::create($client, $address);
            self::assertResponseStatusCodeSame(201, 'creation '.$i);
        }

        $refused = self::create($client, $address);
        self::assertResponseStatusCodeSame(429);
        $retryAfter = $refused->getHeaders(false)['retry-after'][0] ?? null;
        self::assertNotNull($retryAfter);
        self::assertGreaterThanOrEqual(1, (int) $retryAfter);
        self::assertSame(3, self::anonymousCount());

        // Another address has its own counter.
        self::create($client, self::randomAddress());
        self::assertResponseStatusCodeSame(201);
        self::assertSame(4, self::anonymousCount());
    }

    public function testForwardedAddressFromAnUntrustedPeerIsIgnored(): void
    {
        $client = static::createClient();
        self::configure(['creations_per_address_and_hour' => 2]);
        $peer = self::randomAddress();
        $client->getKernelBrowser()->setServerParameter('REMOTE_ADDR', $peer);

        // Every request claims another address; the peer is not a trusted
        // proxy, so all of them count against the peer.
        $ids = [];
        for ($i = 1; $i <= 2; ++$i) {
            $response = self::create($client, self::randomAddress());
            self::assertResponseStatusCodeSame(201, 'creation '.$i);
            $ids[] = (string) $response->toArray()['conversation']['id'];
        }
        self::create($client, self::randomAddress());
        self::assertResponseStatusCodeSame(429);

        foreach ($ids as $id) {
            self::assertSame(self::addressHash($peer), self::row($id)['address_hash']);
        }
    }

    public function testCreationIsForbiddenWhenDisabled(): void
    {
        $client = static::createClient();
        self::configure(['creations_per_address_and_hour' => 0]);

        self::create($client, self::randomAddress());

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, self::anonymousCount());
    }

    public function testUnresolvableReleaseIsAnOperatorError(): void
    {
        $client = static::createClient();
        self::configure(['collections' => [self::PREFIX.'gibt-es-nicht']]);

        self::create($client, self::randomAddress());

        self::assertResponseStatusCodeSame(503);
        self::assertSame(0, self::anonymousCount());
    }

    public function testAgentReleasePinsTheVersionAndCopiesItsValues(): void
    {
        $collection = AgentFixtures::collection('anon-copy');
        [, $version] = self::publishedAgent('anon-copy', [
            'instructions' => ['en' => 'You are a helpful assistant.'],
            'languages' => ['de', 'en'],
            'defaultLanguage' => 'en',
            'collections' => [$collection->getName()],
            'retrievalDepth' => 6,
            'maxUserTurns' => 3,
        ]);

        $client = static::createClient();
        // The released references would not resolve: with an agent they
        // are not used at all.
        self::configure([
            'agent' => AgentFixtures::PREFIX.'anon-copy',
            'collections' => [self::PREFIX.'gibt-es-nicht'],
            'model' => 'nicht-angeboten',
        ]);

        $response = self::create($client, self::randomAddress());

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        $data = $response->toArray();
        $conversation = $data['conversation'];
        $id = (string) $conversation['id'];
        self::assertTrue($conversation['anonymous']);
        self::assertSame($version['@id'], $conversation['agentVersion']);
        self::assertSame('anthropic', $conversation['provider']);
        self::assertSame(AgentFixtures::model()->iri(), $conversation['model'], 'the entry of the version');
        self::assertSame(FakeProviderFactory::ANTHROPIC_MODEL, $conversation['modelName']);
        self::assertSame(AgentFixtures::providerConnections()['anthropic']->getId()->toRfc4122(), (string) self::row($id)['provider_connection_id']);
        self::assertSame([$collection->getId()->toRfc4122()], $conversation['collections']);
        self::assertSame([], $conversation['mcpConnections']);
        self::assertSame(6, $conversation['retrievalDepth']);
        self::assertSame('en', $conversation['language']);
        self::assertSame(3, $conversation['maxUserTurns']);

        // Variables are an item field: empty, read with the token.
        $item = $client->request('GET', '/api/conversations/'.$id, ['headers' => self::tokenHeaders($data['token'])]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $item->toArray()['variables']);
        self::assertSame([], json_decode((string) self::row($id)['variables'], true));
    }

    public function testAgentWithMcpToolsIsRejected(): void
    {
        $docs = AgentFixtures::connection('anon-docs');
        AgentFixtures::tool($docs, 'search');
        self::publishedAgent('anon-tools', ['mcpTools' => [['connection' => $docs->getName(), 'name' => 'search']]]);
        $client = static::createClient();
        self::configure(['agent' => AgentFixtures::PREFIX.'anon-tools']);

        $response = self::create($client, self::randomAddress());

        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertSame('Anonymous conversations are misconfigured (agent): anonymous conversations cannot use MCP tools; please contact the operator.', $response->toArray(false)['detail'] ?? null);
        self::assertSame(0, self::anonymousCount());
    }

    public function testAgentReleaseKeepsTheEmptyBody(): void
    {
        self::publishedAgent('anon-body');
        $client = static::createClient();
        self::configure(['agent' => AgentFixtures::PREFIX.'anon-body']);

        foreach (['{"agent": "'.AgentFixtures::PREFIX.'anon-body"}', '{"variables": {}}', '{"language": "de"}', '{"collections": []}'] as $body) {
            self::create($client, self::randomAddress(), $body);
            self::assertResponseStatusCodeSame(422, $body);
        }
        self::assertSame(0, self::anonymousCount());

        self::create($client, self::randomAddress());
        self::assertResponseStatusCodeSame(201);
    }

    public function testResponseSchemaOnATurnOfAnAgentConversationIsRejected(): void
    {
        self::publishedAgent('anon-schema');
        $client = static::createClient();
        self::configure(['agent' => AgentFixtures::PREFIX.'anon-schema']);
        $created = self::create($client, self::randomAddress());
        self::assertResponseStatusCodeSame(201);
        $data = $created->toArray();
        $id = (string) $data['conversation']['id'];

        $response = $client->request('POST', '/api/conversations/'.$id.'/messages', [
            'headers' => self::tokenHeaders($data['token']),
            'json' => ['content' => 'Farbe?', 'responseSchema' => ['type' => 'object']],
        ]);

        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertSame('"responseSchema" is fixed by the agent version and cannot be set on a turn of an agent conversation.', $response->toArray(false)['detail'] ?? null);
        self::assertSame(0, (int) self::connection()->fetchOne('SELECT COUNT(*) FROM neuron_message WHERE conversation_id = :id', ['id' => $id]));
    }

    public function testUnknownAgentIsAnOperatorError(): void
    {
        self::publishedAgent('anon-typo');

        self::assertMisconfiguredAgent(AgentFixtures::PREFIX.'anon-tpyo');
    }

    public function testUnpublishedAgentIsAnOperatorError(): void
    {
        AgentFixtures::createAgent('anon-candidate');

        self::assertMisconfiguredAgent(AgentFixtures::PREFIX.'anon-candidate');
    }

    public function testDisabledAgentIsAnOperatorError(): void
    {
        [$id] = self::publishedAgent('anon-disabled');
        $patch = AgentFixtures::request('PATCH', AgentFixtures::AGENTS.'/'.$id, AgentFixtures::ADMIN, ['enabled' => false]);
        self::assertSame(200, $patch->getStatusCode(), $patch->getContent(false));

        self::assertMisconfiguredAgent(AgentFixtures::PREFIX.'anon-disabled');
    }

    public function testStaleReferenceOfTheVersionIsAnOperatorError(): void
    {
        $collection = AgentFixtures::collection('anon-stale');
        $collectionId = $collection->getId();
        self::publishedAgent('anon-stale', ['collections' => [$collection->getName()]]);

        $entityManager = AgentFixtures::entityManager();
        $stale = $entityManager->find(Collection::class, $collectionId);
        self::assertInstanceOf(Collection::class, $stale);
        $entityManager->remove($stale);
        $entityManager->flush();

        self::assertMisconfiguredAgent(AgentFixtures::PREFIX.'anon-stale');
    }

    public function testDeactivatedProviderConnectionOfTheVersionIsAnOperatorError(): void
    {
        self::publishedAgent('anon-stale-provider');

        $entityManager = AgentFixtures::entityManager();
        $connection = AgentFixtures::providerConnections()['anthropic'];
        $connection->setActive(false);
        $entityManager->flush();

        self::assertMisconfiguredAgent(AgentFixtures::PREFIX.'anon-stale-provider');
    }

    /**
     * End to end with the capability token: reading the new conversation
     * with the returned token. Depends on the capability token
     * authenticator of the security layer; the creation itself is
     * covered above without it.
     */
    public function testReturnedTokenReadsTheConversation(): void
    {
        $client = static::createClient();
        self::configure([]);
        $created = self::create($client, self::randomAddress());
        self::assertResponseStatusCodeSame(201);
        $data = $created->toArray();
        $id = (string) $data['conversation']['id'];

        $response = $client->request('GET', '/api/conversations/'.$id, ['headers' => self::tokenHeaders($data['token'])]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(Conversation::ANONYMOUS_OWNER_PREFIX.$id, $response->toArray()['ownerSubject']);
    }

    // --- Shared helpers ----------------------------------------------------

    /**
     * Releases $agent and expects the creation to answer 503 naming the
     * `agent` setting, without storing a conversation.
     */
    private static function assertMisconfiguredAgent(string $agent): void
    {
        $client = static::createClient();
        self::configure(['agent' => $agent]);

        $response = self::create($client, self::randomAddress());

        self::assertSame(503, $response->getStatusCode(), $response->getContent(false));
        self::assertStringContainsString('misconfigured (agent)', (string) ($response->toArray(false)['detail'] ?? ''));
        self::assertSame(0, self::anonymousCount());
    }

    /**
     * Expects the creation to answer 503 naming $setting, without storing a
     * conversation.
     */
    private static function assertMisconfigured(Client $client, string $setting): ResponseInterface
    {
        $response = self::create($client, self::randomAddress());

        self::assertSame(503, $response->getStatusCode(), $response->getContent(false));
        self::assertStringContainsString(\sprintf('Anonymous conversations are misconfigured (%s); please contact the operator.', $setting), (string) ($response->toArray(false)['detail'] ?? ''));
        self::assertSame(0, self::anonymousCount());

        return $response;
    }

    /**
     * $model, marked as no longer offered by its connection.
     */
    private static function unavailable(ProviderModel $model): ProviderModel
    {
        $model->setAvailable(false);
        self::entityManager()->flush();

        return $model;
    }

    private static function inactiveConnection(): ApiConfiguration
    {
        $connection = ProviderConnectionFixtures::ollama(self::PREFIX.'offline', active: false);
        ProviderConnectionFixtures::persist(self::entityManager(), $connection);

        return $connection;
    }

    private function collection(string $name): Collection
    {
        $collection = new Collection()->setName(self::PREFIX.$name)->setEmbeddingModel('nomic-embed-text')->setEmbeddingProviderModel(ConversationConnections::model('ollama', 'nomic-embed-text:latest'));
        self::entityManager()->persist($collection);
        self::entityManager()->flush();

        return $collection;
    }

    private function mcpConnection(string $name): ApiConfiguration
    {
        $connection = (new ApiConfiguration())
            ->setName(self::PREFIX.$name)
            ->setConfigJson(['mcp' => true, 'type' => 'mcp', 'endpoint_type' => 'rest', 'url' => 'https://docs.example.org/mcp', 'transport' => 'streamable-http', 'auth_type' => 'none'])
            ->setActive(true);
        self::entityManager()->persist($connection);
        self::entityManager()->flush();

        return $connection;
    }
}
