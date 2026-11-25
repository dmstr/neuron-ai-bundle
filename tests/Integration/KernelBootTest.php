<?php

// file generated with AI assistance: Claude Code - 2026-11-25 21:50:20 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Agent\AgentLimitDefaultsInterface;
use Dmstr\NeuronAiBundle\Agent\AgentPreviewFactory;
use Dmstr\NeuronAiBundle\Agent\Preview\AgentPreviewRetentionInterface;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousAccessPolicyInterface;
use Dmstr\NeuronAiBundle\Evaluation\EvaluationSettingsInterface;
use Dmstr\NeuronAiBundle\Knowledge\CollectionContentPurgerInterface;
use Dmstr\NeuronAiBundle\Mcp\McpTransportFactoryInterface;
use Dmstr\NeuronAiBundle\Mcp\Server\CollectionScopeResolver;
use Dmstr\NeuronAiBundle\Provider\ModelDescriberInterface;
use Dmstr\NeuronAiBundle\Provider\ModelListerInterface;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\Reindex\ReindexSourceInterface;
use Dmstr\NeuronAiBundle\Retrieval\NeighbourSourceInterface;
use Dmstr\NeuronAiBundle\Retrieval\SourceFreshnessInterface;
use Dmstr\NeuronAiBundle\Security\CurrentJwtProviderInterface;
use Dmstr\NeuronAiBundle\Security\Permissions;
use Dmstr\NeuronAiBundle\Testing\FakeCurrentJwtProvider;
use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use Dmstr\NeuronAiBundle\Testing\FakeModelDescriber;
use Dmstr\NeuronAiBundle\Testing\FakeModelLister;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestKernel;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestPermissionVoter;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestRoles;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryAgentLimitDefaults;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryAgentPreviewRetention;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryAnonymousAccessPolicy;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryEvaluationSettings;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryNeighbourSource;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemorySourceFreshness;
use Dmstr\NeuronAiBundle\Tests\Support\Port\TestCollectionContentPurger;
use Dmstr\NeuronAiBundle\Tests\Support\Port\TestReindexSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The test kernel: both variants boot and compile, the test seams and ports
 * are wired, the firewalls, access rules and routes a host sets up are in
 * place, every registered permission is mapped, and (group `database`) the
 * schema comes up from the migrations plus the store table.
 */
final class KernelBootTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const string UUID = '0199aa00-0000-7000-8000-000000000001';

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function environments(): iterable
    {
        yield 'with voter' => [TestKernel::ENV_WITH_VOTER];
        yield 'without voter' => [TestKernel::ENV_NO_VOTER];
    }

    #[DataProvider('environments')]
    public function testTheContainerCompilesAndPassesTheLint(string $environment): void
    {
        $kernel = static::bootKernel(['environment' => $environment]);

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        $exitCode = $application->run(new ArrayInput(['command' => 'lint:container']), $output);

        self::assertSame(0, $exitCode, $output->fetch());
        self::assertSame(TestKernel::ENV_NO_VOTER !== $environment, static::getContainer()->has(TestPermissionVoter::class));
    }

    /**
     * @return iterable<string, array{0: string, 1: class-string}>
     */
    public static function seams(): iterable
    {
        yield 'provider factory' => [ProviderFactory::class, FakeProviderFactory::class];
        yield 'mcp transports' => [McpTransportFactoryInterface::class, FakeMcpTransportFactory::class];
        yield 'forwarded jwt' => [CurrentJwtProviderInterface::class, FakeCurrentJwtProvider::class];
        yield 'model lister' => [ModelListerInterface::class, FakeModelLister::class];
        yield 'model describer' => [ModelDescriberInterface::class, FakeModelDescriber::class];
        yield 'preview factory' => ['test.neuron_ai.agent_preview_factory', AgentPreviewFactory::class];
        yield 'reindex source' => [ReindexSourceInterface::class, TestReindexSource::class];
        yield 'neighbour source' => [NeighbourSourceInterface::class, InMemoryNeighbourSource::class];
        yield 'source freshness' => [SourceFreshnessInterface::class, InMemorySourceFreshness::class];
        yield 'content purger' => [CollectionContentPurgerInterface::class, TestCollectionContentPurger::class];
        yield 'anonymous policy' => [AnonymousAccessPolicyInterface::class, InMemoryAnonymousAccessPolicy::class];
        yield 'agent limit defaults' => [AgentLimitDefaultsInterface::class, InMemoryAgentLimitDefaults::class];
        yield 'preview retention' => [AgentPreviewRetentionInterface::class, InMemoryAgentPreviewRetention::class];
        yield 'evaluation settings' => [EvaluationSettingsInterface::class, InMemoryEvaluationSettings::class];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('seams')]
    public function testTheSeamsAndPortsAreWired(string $id, string $class): void
    {
        static::bootKernel();

        self::assertInstanceOf($class, static::getContainer()->get($id));
    }

    public function testTheKnowledgeMcpToolsAreNotRegisteredWithoutTheEndpointBundle(): void
    {
        static::bootKernel();

        self::assertFalse(static::getContainer()->has(CollectionScopeResolver::class));
    }

    public function testFakeProviderOptionsComeFromTheEnvironment(): void
    {
        static::bootKernel();

        self::assertSame('0', $_SERVER['FAKE_PROVIDER_ECHO'] ?? $_ENV['FAKE_PROVIDER_ECHO'] ?? '0');
        self::assertInstanceOf(FakeProviderFactory::class, static::getContainer()->get(FakeProviderFactory::class));
    }

    public function testHostParameters(): void
    {
        static::bootKernel();
        $container = static::getContainer();

        self::assertSame(TestKernel::artifactRoot(), $container->getParameter('dmstr_neuron_ai.artifacts.root'));
        self::assertSame(TestKernel::ANONYMOUS_ADDRESS_HASH_KEY, $container->getParameter('dmstr_neuron_ai.anonymous.address_hash_key'));
        self::assertSame(TestRoles::PERMISSIONS, $container->getParameter(TestKernel::PERMISSION_MAP_PARAMETER));
        self::assertSame('dmstr', $container->getParameter('dmstr_neuron_ai.openai_compatible.owned_by'));
    }

    public function testEveryRegisteredPermissionIsMapped(): void
    {
        static::bootKernel();

        $registered = static::getContainer()->getParameter(TestKernel::PERMISSIONS_REGISTERED_PARAMETER);
        self::assertIsArray($registered);
        foreach (Permissions::REGISTERED as $permission) {
            self::assertContains($permission, $registered);
        }
        self::assertSame([], array_values(array_diff($registered, array_keys(TestRoles::PERMISSIONS))), 'Registered permissions without a role.');
        self::assertSame([], array_values(array_diff(array_keys(TestRoles::PERMISSIONS), $registered)), 'Mapped permissions nobody registers.');
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: string, 2: bool}>
     */
    public static function decisions(): iterable
    {
        yield 'user reads collections' => [['ROLE_USER'], Permissions::COLLECTION_READ, true];
        yield 'user writes no collection' => [['ROLE_USER'], Permissions::COLLECTION_WRITE, false];
        yield 'editor writes collections' => [['ROLE_EDITOR'], Permissions::COLLECTION_WRITE, true];
        yield 'editor rates' => [['ROLE_EDITOR'], Permissions::EVAL_RATE, true];
        yield 'developer writes agents' => [['ROLE_DEVELOPER'], Permissions::AGENT_WRITE, true];
        yield 'developer does not unlock' => [['ROLE_DEVELOPER'], Permissions::COLLECTION_UNLOCK, false];
        yield 'abstract role alone' => [[TestRoles::EVAL_ADMIN], Permissions::EVAL_WRITE, true];
        yield 'admin reads any conversation' => [['ROLE_ADMIN'], Permissions::READ_ANY, true];
        yield 'admin unlocks' => [['ROLE_ADMIN'], Permissions::COLLECTION_UNLOCK, true];
        yield 'capability token holds nothing' => [['ROLE_CONVERSATION_TOKEN'], Permissions::CONVERSATION_LIST, false];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('decisions')]
    public function testThePermissionMapDecidesThroughTheHierarchy(array $roles, string $permission, bool $granted): void
    {
        static::bootKernel();
        $manager = static::getContainer()->get('security.access.decision_manager');
        self::assertInstanceOf(AccessDecisionManagerInterface::class, $manager);

        $token = new UsernamePasswordToken(new InMemoryUser('someone', null, $roles), 'api', $roles);

        self::assertSame($granted, $manager->decide($token, [$permission]));
    }

    public function testWithoutVoterNoPermissionIsGranted(): void
    {
        static::bootKernel(['environment' => TestKernel::ENV_NO_VOTER]);
        $manager = static::getContainer()->get('security.access.decision_manager');
        self::assertInstanceOf(AccessDecisionManagerInterface::class, $manager);

        $token = new UsernamePasswordToken(new InMemoryUser('test-admin', null, ['ROLE_ADMIN']), 'api', ['ROLE_ADMIN']);

        self::assertFalse($manager->decide($token, [Permissions::COLLECTION_READ]));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function firewalls(): iterable
    {
        yield 'anonymous creation' => ['/api/conversations/anonymous', 'api'];
        yield 'stream' => ['/api/conversations/'.self::UUID.'/stream', 'api'];
        yield 'openai models' => ['/api/v1/models', 'api'];
        yield 'mcp endpoint' => ['/mcp/knowledge', 'mcp'];
        yield 'outside' => ['/', 'main'];
    }

    #[DataProvider('firewalls')]
    public function testFirewalls(string $path, string $firewall): void
    {
        static::bootKernel();
        $map = static::getContainer()->get('security.firewall.map');
        self::assertInstanceOf(FirewallMap::class, $map);

        self::assertSame($firewall, $map->getFirewallConfig(Request::create($path))?->getName());
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function routes(): iterable
    {
        yield 'anonymous creation' => ['POST', '/api/conversations/anonymous', 'conversation_anonymous'];
        yield 'stream' => ['POST', '/api/conversations/'.self::UUID.'/stream', 'dmstr_neuron_ai_conversation_stream'];
        yield 'resume' => ['GET', '/api/conversations/'.self::UUID.'/stream', 'dmstr_neuron_ai_conversation_resume'];
        yield 'eval execute' => ['POST', '/api/eval_questions/'.self::UUID.'/execute', 'dmstr_neuron_ai_eval_question_execute'];
        yield 'openai models' => ['GET', '/api/v1/models', 'dmstr_neuron_ai_openai_models'];
        yield 'openai completions' => ['POST', '/api/v1/chat/completions', 'dmstr_neuron_ai_openai_chat_completions'];
        yield 'collections' => ['GET', '/api/admin/collections', '_api_'];
        yield 'connections' => ['GET', '/api/admin/api_configurations', '_api_'];
    }

    #[DataProvider('routes')]
    public function testRoutes(string $method, string $path, string $routePrefix): void
    {
        static::bootKernel();
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $router->getContext()->setMethod($method);

        self::assertStringStartsWith($routePrefix, (string) $router->match($path)['_route']);
    }

    /**
     * HTTP requests need the database: the kernel.terminate subscriber
     * ConversationStarter pulls in the vector store, whose PDO connection
     * connects when the service is built.
     */
    #[Group('database')]
    public function testAnonymousRequestsGetTheEntryPoint(): void
    {
        $response = static::createClient()->request('GET', '/api/v1/models', [
            'headers' => [TestHeaderAuthenticator::HEADER_AUTH => TestHeaderAuthenticator::ANONYMOUS],
        ]);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringStartsWith('Bearer', $response->getHeaders(false)['www-authenticate'][0] ?? '');
    }

    #[Group('database')]
    public function testTheOpenApiDocumentBuilds(): void
    {
        $response = static::createClient()->request('GET', '/api/docs.jsonopenapi', [
            'headers' => ['Accept' => 'application/vnd.openapi+json'],
        ]);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        $paths = $response->toArray()['paths'] ?? [];
        self::assertArrayHasKey('/api/conversations/anonymous', $paths);
        self::assertArrayHasKey('/api/admin/collections', $paths);
    }

    #[Group('database')]
    public function testTheSchemaComesFromTheMigrationsAndTheStore(): void
    {
        $kernel = static::bootKernel();
        TestDatabase::migrate($kernel);
        $connection = TestDatabase::connection($kernel);

        foreach (['neuron_collection', 'neuron_conversation', 'neuron_agent_version', 'dmstr_api_configuration', 'dmstr_job', 'dmstr_log_entries', TestKernel::STORE_TABLE] as $table) {
            self::assertNotNull($connection->fetchOne('SELECT to_regclass(:table)::text', ['table' => $table]), $table);
        }
        self::assertSame('vector', $connection->fetchOne("SELECT extname FROM pg_extension WHERE extname = 'vector'"));
        $executed = (int) $connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions');
        self::assertGreaterThanOrEqual(37, $executed);
        TestDatabase::run($kernel);
        self::assertSame($executed, (int) $connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions'), 'A second run executes nothing.');

        TestDatabase::purge($connection);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM dmstr_api_configuration'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM '.TestKernel::STORE_TABLE));
    }
}
