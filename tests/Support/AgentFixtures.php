<?php

// file generated with AI assistance: Claude Code - 2026-11-25 22:00:17 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Refs\McpTool;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Static fixtures, requests and cleanup of the database-bound agent,
 * conversation, chat, stream and evaluation tests (the shared helpers of
 * the agent lifecycle test). Abstract, never run itself: a test calls the
 * helpers statically (`AgentFixtures::createAgent('docs')`) or extends the
 * class; both work, because the kernel and the client of
 * {@see ApiTestCase} are static.
 *
 * Fixtures carry the prefix {@see self::PREFIX} (agent slugs, collection
 * and connection names); {@see cleanUp()} removes them, their versions,
 * the conversations on the fixture connections and every audit entry of
 * {@see Agent}. {@see validCandidate()} references the catalogue entry of
 * {@see FakeProviderFactory::ANTHROPIC_MODEL} on the `anthropic` fixture
 * connection {@see self::ANTHROPIC_CONNECTION} ({@see model()});
 * createAgent(), storeAgent() and publish() create both provider fixture
 * connections ({@see providerConnections()}) when they are missing, a test
 * that publishes through a raw request calls providerConnections() itself.
 * Requests without identity headers run as `test-admin` with `ROLE_ADMIN`.
 *
 * Needs the test database: entityManager() and dbal() run
 * {@see TestDatabase::migrate()} (once per process), so every helper that
 * touches the database has the schema. Tests using them belong to the
 * group `database`.
 */
abstract class AgentFixtures extends ApiTestCase
{
    use ProviderModelFixtureTrait;

    protected static ?bool $alwaysBootKernel = true;

    public const string PREFIX = 'test-agent-';
    public const string AGENTS = '/api/agents';
    public const string VERSIONS = '/api/agent_versions';
    public const string CONNECTIONS = '/api/admin/api_configurations';

    /** Names of the provider fixture connections, removed by cleanUp(). */
    public const string OLLAMA_CONNECTION = self::PREFIX.FakeProviderFactory::OLLAMA_CONNECTION;
    public const string ANTHROPIC_CONNECTION = self::PREFIX.FakeProviderFactory::ANTHROPIC_CONNECTION;

    /** Test identities: role lists of the `X-Test-Roles` header; null = default admin. */
    public const ?string ADMIN = null;
    public const string AGENT_ADMIN = 'ROLE_AGENT_ADMIN';
    public const string USER = 'ROLE_USER';

    /**
     * Headers of a test identity.
     *
     * @return array<string, string>
     */
    public static function identity(?string $roles): array
    {
        return null === $roles ? [] : [TestHeaderAuthenticator::HEADER_ROLES => $roles];
    }

    /**
     * One request with a fresh client. PATCH is sent as merge patch.
     *
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $headers extra headers, e.g. `X-Test-Auth`
     */
    public static function request(string $method, string $uri, ?string $roles, ?array $json = null, array $headers = []): ResponseInterface
    {
        return self::send(static::createClient(), $method, $uri, $roles, $json, $headers);
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $headers
     */
    public static function send(Client $client, string $method, string $uri, ?string $roles, ?array $json = null, array $headers = []): ResponseInterface
    {
        $options = ['headers' => ['Accept' => 'application/ld+json'] + self::identity($roles) + $headers];
        if (null !== $json) {
            $options['headers']['Content-Type'] = 'PATCH' === $method ? 'application/merge-patch+json' : 'application/ld+json';
            // An empty list would encode as `[]`; bodies are JSON objects.
            $options['body'] = json_encode([] === $json ? new \stdClass() : $json, \JSON_THROW_ON_ERROR);
        }

        return $client->request($method, $uri, $options);
    }

    /**
     * A complete, publishable candidate: the IRI of the catalogue entry of
     * {@see FakeProviderFactory::ANTHROPIC_MODEL} on the `anthropic` fixture
     * connection ({@see model()}), no other references.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function validCandidate(array $overrides = []): array
    {
        return $overrides + [
            'instructions' => ['de' => 'Du bist ein hilfreicher Assistent.'],
            'model' => self::model()->iri(),
            'languages' => ['de'],
            'defaultLanguage' => 'de',
        ];
    }

    /**
     * Creates an agent through the API as the default admin.
     *
     * @param string                    $slug      without the prefix
     * @param array<string, mixed>|null $candidate null = a valid candidate
     *
     * @return string the agent id
     */
    public static function createAgent(string $slug, ?array $candidate = null, ?string $roles = self::ADMIN): string
    {
        self::providerConnections();
        $response = self::request('POST', self::AGENTS, $roles, ['name' => 'Agent '.$slug, 'slug' => self::PREFIX.$slug, 'candidate' => $candidate ?? self::validCandidate()]);
        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));

        return (string) $response->toArray()['id'];
    }

    /**
     * An agent written directly through the entity manager, bypassing the
     * structure check of POST/PATCH (for candidates the API would reject).
     *
     * @param array<string, mixed> $candidate
     *
     * @return string the agent id
     */
    public static function storeAgent(string $slug, array $candidate): string
    {
        self::providerConnections();
        $agent = new Agent()->setName('Agent '.$slug)->setSlug(self::PREFIX.$slug)->setCandidate($candidate);
        self::entityManager()->persist($agent);
        self::entityManager()->flush();

        return $agent->getId()->toRfc4122();
    }

    /**
     * Publishes as the default admin.
     *
     * @return array<string, mixed> the response body
     */
    public static function publish(string $id, ?string $roles = self::ADMIN): array
    {
        self::providerConnections();

        return self::request('POST', self::AGENTS.'/'.$id.'/publish', $roles)->toArray(false);
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function rollback(string $id, array $body, ?string $roles = self::ADMIN): ResponseInterface
    {
        $response = self::request('POST', self::AGENTS.'/'.$id.'/rollback', $roles, $body);
        $response->getStatusCode();

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public static function agent(string $id): array
    {
        return self::request('GET', self::AGENTS.'/'.$id, self::USER)->toArray();
    }

    /**
     * The version row as stored, for "unchanged" assertions.
     *
     * @return array<string, mixed>
     */
    public static function versionRow(string $id): array
    {
        $row = self::dbal()->fetchAssociative('SELECT * FROM neuron_agent_version WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row, 'Version '.$id.' is missing.');

        return $row;
    }

    public static function agentCount(): int
    {
        return (int) self::dbal()->fetchOne('SELECT COUNT(*) FROM neuron_agent WHERE slug LIKE :prefix', ['prefix' => self::PREFIX.'%']);
    }

    public static function versionCount(): int
    {
        return (int) self::dbal()->fetchOne(
            'SELECT COUNT(*) FROM neuron_agent_version WHERE agent_id IN (SELECT id FROM neuron_agent WHERE slug LIKE :prefix)',
            ['prefix' => self::PREFIX.'%'],
        );
    }

    /**
     * @return list<string>
     */
    public static function violationPaths(ResponseInterface $response): array
    {
        return array_column($response->toArray(false)['violations'] ?? [], 'propertyPath');
    }

    /**
     * The provider fixture connections {@see self::OLLAMA_CONNECTION} and
     * {@see self::ANTHROPIC_CONNECTION}, created when missing (an existing
     * one is returned as it is, also when a test deactivated it). The fake
     * model lister offers every model by default.
     *
     * @return array{ollama: ApiConfiguration, anthropic: ApiConfiguration}
     */
    public static function providerConnections(): array
    {
        $repository = self::entityManager()->getRepository(ApiConfiguration::class);
        $ollama = $repository->findOneBy(['name' => self::OLLAMA_CONNECTION]) ?? ProviderConnectionFixtures::ollama(self::OLLAMA_CONNECTION);
        $anthropic = $repository->findOneBy(['name' => self::ANTHROPIC_CONNECTION]) ?? ProviderConnectionFixtures::anthropic(self::ANTHROPIC_CONNECTION);
        ProviderConnectionFixtures::persist(self::entityManager(), $ollama, $anthropic);

        return ['ollama' => $ollama, 'anthropic' => $anthropic];
    }

    /**
     * The model catalogue entry of a provider fixture connection
     * ({@see providerConnections()}, created when missing and left as it
     * is otherwise, also inactive) for $name, created when missing and
     * made usable ({@see ProviderModelFixtureTrait::catalogueModel()});
     * candidates reference it by IRI, versions by UUID.
     *
     * @param 'anthropic'|'ollama' $type
     * @param string|null          $name null = the fake's model of the type
     */
    public static function model(string $type = 'anthropic', ?string $name = null): ProviderModel
    {
        return self::catalogueModel(self::entityManager(), self::providerConnections()[$type], $name ?? ('anthropic' === $type ? FakeProviderFactory::ANTHROPIC_MODEL : FakeProviderFactory::OLLAMA_MODEL));
    }

    /**
     * A further provider connection.
     *
     * @param string $name without the prefix
     * @param string $type `ollama` or `anthropic`
     */
    public static function providerConnection(string $name, string $type = 'ollama', bool $active = true): ApiConfiguration
    {
        $connection = 'anthropic' === $type
            ? ProviderConnectionFixtures::anthropic(self::PREFIX.$name, $active)
            : ProviderConnectionFixtures::ollama(self::PREFIX.$name, $active);
        ProviderConnectionFixtures::persist(self::entityManager(), $connection);

        return $connection;
    }

    /**
     * A collection embedding with `nomic-embed-text` through the active
     * `ollama` fixture connection {@see self::OLLAMA_CONNECTION}.
     *
     * @param string $name without the prefix
     */
    public static function collection(string $name): Collection
    {
        $collection = new Collection()
            ->setName(self::PREFIX.$name)
            ->setEmbeddingModel('nomic-embed-text')
            ->setEmbeddingProviderModel(ConversationConnections::model('ollama', 'nomic-embed-text:latest'));
        self::entityManager()->persist($collection);
        self::entityManager()->flush();

        return $collection;
    }

    /**
     * An API connection; type `mcp` unless given.
     *
     * @param string $name without the prefix
     */
    public static function connection(string $name, bool $active = true, string $type = 'mcp'): ApiConfiguration
    {
        $connection = new ApiConfiguration()
            ->setName(self::PREFIX.$name)
            ->setConfigJson(['mcp' => true, 'type' => $type, 'endpoint_type' => 'rest', 'url' => 'https://'.$name.'.agent.example.org/mcp', 'transport' => 'streamable-http', 'auth_type' => 'none'])
            ->setActive($active);
        self::entityManager()->persist($connection);
        self::entityManager()->flush();

        return $connection;
    }

    /**
     * A catalogue row of a connection.
     */
    public static function tool(ApiConfiguration $connection, string $name): McpTool
    {
        $tool = new McpTool($connection, $name)
            ->setDescription('Tool '.$name)
            ->setInputSchema(['type' => 'object'])
            ->setSyncedAt(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        self::entityManager()->persist($tool);
        self::entityManager()->flush();

        return $tool;
    }

    /**
     * Removes the test agents with their versions, every audit entry of
     * Agent, and the test collections and connections (their catalogue
     * rows cascade). Conversations of any owner that run on a test provider
     * connection go first ({@see deleteProviderConnections()}), because
     * `neuron_conversation.provider_connection_id` has a foreign key
     * without cascade. The current-version link is cut first, because
     * agent and version reference each other.
     */
    public static function cleanUp(): void
    {
        static::bootKernel();
        $connection = self::dbal();
        $prefix = ['prefix' => self::PREFIX.'%'];
        self::deleteConversationsOnConnections($connection, self::PREFIX.'%');
        $connection->executeStatement('UPDATE neuron_agent SET current_version_id = NULL WHERE slug LIKE :prefix', $prefix);
        $connection->executeStatement('DELETE FROM neuron_agent_version WHERE agent_id IN (SELECT id FROM neuron_agent WHERE slug LIKE :prefix)', $prefix);
        $connection->executeStatement('DELETE FROM neuron_agent WHERE slug LIKE :prefix', $prefix);
        $connection->executeStatement('DELETE FROM dmstr_log_entries WHERE object_class = :class', ['class' => Agent::class]);
        $connection->executeStatement('DELETE FROM neuron_collection WHERE name LIKE :prefix', $prefix);
        self::deleteProviderConnections($connection, self::PREFIX.'%');
        static::ensureKernelShutdown();
    }

    /**
     * Deletes the connections whose name matches the LIKE pattern
     * $namePattern (any type; catalogue rows cascade), after the
     * conversations that run on one of them (messages, stream events and
     * replay records cascade) and their audit entries, and after the
     * collections (of any suite) that embed through one of them or one of
     * their catalogue entries as the current model, with their vectors in
     * the store table: `neuron_collection.embedding_model_id` is NOT NULL
     * and references the entries (which cascade with the connection)
     * without cascade, so such a collection cannot outlive its connection.
     * A collection whose reindex target is on one of them only loses the
     * pending target (`pending_embedding_model_id` is nullable). Plain SQL,
     * so the delete guard of the entity manager is not involved.
     */
    public static function deleteProviderConnections(Connection $connection, string $namePattern): void
    {
        self::deleteConversationsOnConnections($connection, $namePattern);
        $matching = 'SELECT a.id FROM dmstr_api_configuration a WHERE a.name LIKE :pattern';
        $entries = 'SELECT pm.id FROM neuron_provider_model pm WHERE pm.api_configuration_id IN ('.$matching.')';
        $pattern = ['pattern' => $namePattern];
        $collections = 'SELECT c.id FROM neuron_collection c WHERE c.embedding_connection_id IN ('.$matching.') OR c.embedding_model_id IN ('.$entries.')';
        $connection->executeStatement("DELETE FROM embedding WHERE metadata->>'collection_id' IN (SELECT CAST(x.id AS TEXT) FROM (".$collections.') x)', $pattern);
        $connection->executeStatement('DELETE FROM neuron_collection WHERE id IN ('.$collections.')', $pattern);
        $connection->executeStatement('UPDATE neuron_collection SET pending_embedding_model = NULL, pending_embedding_connection_id = NULL, pending_embedding_model_id = NULL WHERE pending_embedding_connection_id IN ('.$matching.') OR pending_embedding_model_id IN ('.$entries.')', $pattern);
        $connection->executeStatement('DELETE FROM dmstr_api_configuration WHERE name LIKE :pattern', $pattern);
    }

    public static function entityManager(): EntityManagerInterface
    {
        if (null === static::$kernel) {
            static::bootKernel();
        }
        TestDatabase::migrate(static::$kernel);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    public static function dbal(): Connection
    {
        if (null === static::$kernel) {
            static::bootKernel();
        }
        TestDatabase::migrate(static::$kernel);

        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');

        return $connection;
    }

    /**
     * Deletes the conversations (of any owner) that run on a provider
     * connection matching the LIKE pattern $namePattern, directly or
     * through a catalogue entry of it, with their audit entries.
     */
    private static function deleteConversationsOnConnections(Connection $connection, string $namePattern): void
    {
        $onConnections = 'SELECT c.id FROM neuron_conversation c JOIN dmstr_api_configuration a ON a.id = c.provider_connection_id WHERE a.name LIKE :pattern'
            .' UNION SELECT c.id FROM neuron_conversation c JOIN neuron_provider_model pm ON pm.id = c.provider_model_id JOIN dmstr_api_configuration a ON a.id = pm.api_configuration_id WHERE a.name LIKE :pattern';
        $connection->executeStatement(
            'DELETE FROM dmstr_log_entries WHERE object_class = :class AND object_id IN (SELECT CAST(o.id AS TEXT) FROM ('.$onConnections.') o)',
            ['class' => Conversation::class, 'pattern' => $namePattern],
        );
        $connection->executeStatement('DELETE FROM neuron_conversation WHERE id IN ('.$onConnections.')', ['pattern' => $namePattern]);
    }
}
