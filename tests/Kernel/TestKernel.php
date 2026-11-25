<?php

// file generated with AI assistance: Claude Code - 2026-11-25 21:50:20 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Kernel;

use ApiPlatform\Symfony\Bundle\ApiPlatformBundle;
use Dmstr\ApiConfiguration\ApiConfigurationBundle;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiPlatformUtils\ApiPlatformUtilsBundle;
use Dmstr\DoctrineAuditLog\DoctrineAuditLogBundle;
use Dmstr\DoctrineAuditLog\Entity\LogEntry;
use Dmstr\NeuronAiBundle\Agent\AgentLimitDefaultsInterface;
use Dmstr\NeuronAiBundle\Agent\AgentPreviewFactory;
use Dmstr\NeuronAiBundle\Agent\Preview\AgentPreviewRetentionInterface;
use Dmstr\NeuronAiBundle\Agent\Preview\PurgeAgentPreviewsMessage;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousAccessPolicyInterface;
use Dmstr\NeuronAiBundle\Anonymous\PurgeAnonymousConversationsMessage;
use Dmstr\NeuronAiBundle\DmstrNeuronAiBundle;
use Dmstr\NeuronAiBundle\Evaluation\EvaluationSettingsInterface;
use Dmstr\NeuronAiBundle\Knowledge\CollectionContentPurgerInterface;
use Dmstr\NeuronAiBundle\Mcp\McpTransportFactoryInterface;
use Dmstr\NeuronAiBundle\Provider\ModelDescriberInterface;
use Dmstr\NeuronAiBundle\Provider\ModelListerInterface;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\Reindex\ReindexSourceInterface;
use Dmstr\NeuronAiBundle\Retrieval\NeighbourSourceInterface;
use Dmstr\NeuronAiBundle\Retrieval\SourceFreshnessInterface;
use Dmstr\NeuronAiBundle\Security\CapabilityTokenAuthenticator;
use Dmstr\NeuronAiBundle\Security\CurrentJwtProviderInterface;
use Dmstr\NeuronAiBundle\Testing\ConfigSecretsFixture;
use Dmstr\NeuronAiBundle\Testing\FakeCurrentJwtProvider;
use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use Dmstr\NeuronAiBundle\Testing\FakeModelDescriber;
use Dmstr\NeuronAiBundle\Testing\FakeModelLister;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryAgentLimitDefaults;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryAgentPreviewRetention;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryAnonymousAccessPolicy;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryEvaluationSettings;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryNeighbourSource;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemorySourceFreshness;
use Dmstr\NeuronAiBundle\Tests\Support\Port\TestCollectionContentPurger;
use Dmstr\NeuronAiBundle\Tests\Support\Port\TestReindexSource;
use Dmstr\NeuronPgvectorStore\Doctrine\VectorType;
use Dmstr\OpenApiJsonSchema\OpenApi\OperationInputSchemaDecorator;
use Dmstr\OpenApiJsonSchema\OpenApiJsonSchemaBundle;
use Dmstr\OpenApiJsonSchema\Service\ChainInputSchemaResolver;
use Dmstr\OpenApiJsonSchema\Service\OperationInputSchemaResolver;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Message\JobMessage;
use Dmstr\SymfonyJobQueue\SymfonyJobQueueBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Gedmo\Loggable\Entity\MappedSuperclass\AbstractLogEntry;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Minimal host application for the kernel tests of the bundle. It wires
 * what a host application provides around the bundle, so the tests see the
 * bundle as an application runs it.
 *
 * Booting: tests extend `KernelTestCase` or API Platform's `ApiTestCase`;
 * `KERNEL_CLASS` and `APP_ENV=test` come from `phpunit.dist.xml`
 * (`static::bootKernel()`, `static::createClient()`). The variant without
 * permission voter is `static::bootKernel(['environment' => TestKernel::ENV_NO_VOTER])`.
 *
 * - PostgreSQL with pgvector from `DATABASE_URL` (default in
 *   `phpunit.dist.xml`). The container itself never connects, so tests
 *   without the group `database` run without a server. Tests of that group
 *   create the schema with {@see TestDatabase::migrate()}: the migrations of
 *   all bundles, then the store table {@see self::STORE_TABLE} through
 *   `PgVectorStore::setupTable()` (no migration of the bundle owns it).
 *   Doctrine: DBAL type `vector`, a schema filter that keeps the schema tool
 *   away from the store table and `messenger_messages`, IDENTITY generation
 *   on PostgreSQL (the audit-log table keeps the sequence name of its
 *   predecessor), savepoints.
 * - API Platform below {@see self::API_PREFIX} with the resources of the
 *   bundles, client page sizes up to 200, dmstr/api-platform-utils-bundle
 *   with partial UUIDs, relation labels and stable ordering as a host
 *   enables them, and the insecure test key of
 *   {@see ConfigSecretsFixture::INSECURE_TEST_KEY} as credential key of
 *   dmstr/api-configuration-bundle; the host-side services the schema
 *   bundles expect ({@see OperationInputSchemaResolver} in the resolver
 *   chain, {@see OperationInputSchemaDecorator} on the OpenAPI factory).
 * - dmstr/symfony-job-queue-bundle and dmstr/doctrine-audit-log-bundle
 *   (audit actor: {@see TestActorProvider}). dmstr/mcp-endpoint-bundle (a
 *   `suggest` of the bundle) is installed for its classes but not
 *   registered: API Platform enables its MCP layer only when
 *   symfony/object-mapper is a non-dev dependency of the root package. So
 *   the knowledge MCP tools of the bundle are not registered here and there
 *   is no `/mcp/{slug}` route.
 * - Messenger with the in-memory transports `async`, `maintenance` and
 *   `failed` (failure transport) and the routing of a host; no worker runs,
 *   tests consume or handle messages themselves. The schedule `neuron` of
 *   the bundle is registered (symfony/scheduler).
 * - Lock store `flock`, `cache.app` as array, `cache.rate_limiter` on the
 *   filesystem (an array pool would be reset between the requests of the
 *   test client, so a creation limit could never be reached), trusted proxy
 *   127.0.0.1 with `X-Forwarded-*` (the test client sends from there), a
 *   fixed anonymous address hash key, artifacts below
 *   {@see self::artifactRoot()} (not created by the kernel).
 * - The test seams of a host: {@see FakeProviderFactory} as
 *   {@see ProviderFactory} (no model provider is contacted; env
 *   `FAKE_PROVIDER_ECHO` and `FAKE_PROVIDER_CHUNK_DELAY_MS`, both off),
 *   {@see FakeMcpTransportFactory}, {@see FakeCurrentJwtProvider},
 *   {@see FakeModelLister} and {@see FakeModelDescriber}, all public, and
 *   the public alias `test.neuron_ai.agent_preview_factory`.
 * - The ports a host implements, as in-memory doubles from
 *   `tests/Support/Port` with static state (reset after every test):
 *   {@see TestReindexSource}, {@see InMemoryNeighbourSource},
 *   {@see InMemorySourceFreshness}, {@see TestCollectionContentPurger},
 *   {@see InMemoryAnonymousAccessPolicy}, {@see InMemoryAgentLimitDefaults},
 *   {@see InMemoryAgentPreviewRetention}, {@see InMemoryEvaluationSettings};
 *   the interface ids are public aliases. Not aliased, so the bundle
 *   defaults apply: artifact limits, collection document counter, MCP
 *   endpoint policy.
 * - Security: firewall `api` (`^/api`, stateless) with the bundle's
 *   {@see CapabilityTokenAuthenticator} and {@see TestHeaderAuthenticator}
 *   (entry point, 401), firewall `mcp` (`^/mcp/`) with the header
 *   authenticator only (a host's MCP endpoints; no route here), firewall
 *   `main` for the rest. access_control: public
 *   `POST /api/conversations/anonymous` and the OAuth callback, a full
 *   login for `^/mcp/` and the rest of `^/api`.
 * - The permission contract of a host: {@see self::process()} collects the
 *   `za7_core.permission` tags into `za7_core.permissions.registered`,
 *   {@see TestRoles::PERMISSIONS} is `za7_core.permissions`,
 *   {@see TestRoles::HIERARCHY} the role hierarchy, and
 *   {@see TestPermissionVoter} decides with all three.
 *
 * Variants, selected by the kernel environment:
 * - {@see self::ENV_WITH_VOTER}: everything above;
 * - {@see self::ENV_NO_VOTER}: no permission voter, so every permission
 *   check of the bundle is denied (no voter grants it); the conversation
 *   voter of the bundle still decides the `*_own` attributes.
 *
 * Cache and logs live under the system temp directory, one directory per
 * variant, so a test run leaves no files in the repository.
 */
final class TestKernel extends Kernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    public const string ENV_WITH_VOTER = 'test';
    public const string ENV_NO_VOTER = 'test_no_voter';

    public const string API_PREFIX = '/api';
    public const string STORE_TABLE = 'embedding';

    /** Key of the keyed hash of client addresses of anonymous conversations. */
    public const string ANONYMOUS_ADDRESS_HASH_KEY = 'test-address-hash-key';

    public const string PERMISSION_TAG = 'za7_core.permission';
    public const string PERMISSION_MAP_PARAMETER = 'za7_core.permissions';
    public const string PERMISSIONS_REGISTERED_PARAMETER = 'za7_core.permissions.registered';

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new DoctrineBundle();
        yield new DoctrineMigrationsBundle();
        yield new ApiPlatformBundle();
        yield new OpenApiJsonSchemaBundle();
        yield new ApiPlatformUtilsBundle();
        yield new ApiConfigurationBundle();
        yield new SymfonyJobQueueBundle();
        yield new DoctrineAuditLogBundle();
        yield new DmstrNeuronAiBundle();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return self::tmpDir().'/'.$this->environment.'/cache';
    }

    public function getLogDir(): string
    {
        return self::tmpDir().'/'.$this->environment.'/log';
    }

    public function hasPermissionVoter(): bool
    {
        return self::ENV_NO_VOTER !== $this->environment;
    }

    /**
     * Root of the artifact files (`dmstr_neuron_ai.artifacts.root`); shared
     * by the variants and not created by the kernel, tests that need it
     * create it.
     */
    public static function artifactRoot(): string
    {
        return self::tmpDir().'/artifacts';
    }

    /**
     * Host-application wiring that needs the complete container: the list of
     * registered permissions and the actor of audit-log entries.
     */
    public function process(ContainerBuilder $container): void
    {
        $registered = [];
        foreach ($container->findTaggedServiceIds(self::PERMISSION_TAG) as $tags) {
            foreach ($tags as $attributes) {
                if (\is_string($attributes['permission'] ?? null)) {
                    $registered[$attributes['permission']] = true;
                }
            }
        }
        $registered = array_keys($registered);
        sort($registered);
        $container->setParameter(self::PERMISSIONS_REGISTERED_PARAMETER, $registered);

        if ($container->hasDefinition('gedmo.listener.loggable')) {
            $container->getDefinition('gedmo.listener.loggable')
                ->addMethodCall('setActorProvider', [new Reference(TestActorProvider::class)]);
        }
    }

    private static function tmpDir(): string
    {
        return sys_get_temp_dir().'/dmstr-neuron-ai-bundle-tests';
    }

    /**
     * Not the repository's `config/` (the bundle's service definitions): in
     * debug mode FrameworkBundle dumps `reference.php` into an existing
     * config directory. This one does not exist, so nothing is written.
     */
    private function getConfigDir(): string
    {
        return self::tmpDir().'/'.$this->environment.'/config';
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->parameters()
            ->set(self::PERMISSION_MAP_PARAMETER, TestRoles::PERMISSIONS)
            // Required by dmstr/api-configuration-bundle (OAuth callback of
            // its connection types); no test follows it.
            ->set('app.oauth.redirect_uri', 'http://localhost/api/oauth/callback')
            // Fake provider: no echo fallback, no chunk pacing unless the
            // environment sets them.
            ->set('env(FAKE_PROVIDER_ECHO)', '0')
            ->set('env(FAKE_PROVIDER_CHUNK_DELAY_MS)', '0');

        $container->extension('framework', [
            'test' => true,
            'secret' => 'test-secret',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['default_uri' => 'http://localhost'],
            'trusted_proxies' => '127.0.0.1',
            'trusted_headers' => ['x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-port', 'x-forwarded-host'],
            'property_info' => ['with_constructor_extractor' => true],
            'validation' => ['not_compromised_password' => false],
            'lock' => 'flock',
            'cache' => [
                'app' => 'cache.adapter.array',
                'pools' => [
                    // Not an array pool: array pools are reset by
                    // `services_resetter` between requests of the test
                    // client, so a sliding-window counter would never reach
                    // its limit. Tests use random client addresses.
                    'cache.rate_limiter' => ['adapter' => 'cache.adapter.filesystem'],
                ],
            ],
            'messenger' => [
                'failure_transport' => 'failed',
                'transports' => [
                    'async' => 'in-memory://',
                    'maintenance' => 'in-memory://',
                    'failed' => 'in-memory://',
                    'sync' => 'sync://',
                ],
                'routing' => [
                    JobMessage::class => 'async',
                    PurgeAnonymousConversationsMessage::class => 'maintenance',
                    PurgeAgentPreviewsMessage::class => 'maintenance',
                ],
            ],
        ]);

        $container->extension('security', [
            'providers' => [
                'test_users' => ['memory' => ['users' => []]],
            ],
            'role_hierarchy' => TestRoles::HIERARCHY,
            'firewalls' => [
                // The whole API: a test identity from headers, or the
                // capability token of an anonymous conversation
                // (`X-Test-Auth: anonymous` plus `Authorization: Bearer anon_…`).
                // Without `X-Test-Auth` both succeed and the later one, the
                // test identity, wins.
                'api' => [
                    'pattern' => '^'.self::API_PREFIX,
                    'stateless' => true,
                    'provider' => 'test_users',
                    'custom_authenticators' => [CapabilityTokenAuthenticator::class, TestHeaderAuthenticator::class],
                    'entry_point' => TestHeaderAuthenticator::class,
                ],
                // A host's MCP endpoints: test identities only, no
                // capability tokens.
                'mcp' => [
                    'pattern' => '^/mcp/',
                    'stateless' => true,
                    'provider' => 'test_users',
                    'custom_authenticators' => [TestHeaderAuthenticator::class],
                    'entry_point' => TestHeaderAuthenticator::class,
                ],
                'main' => [
                    'lazy' => true,
                ],
            ],
            // Only whether a login is needed; the operations check their
            // permissions themselves. Capability-token holders
            // (ROLE_CONVERSATION_TOKEN, no ROLE_USER) pass the catch-all and
            // get 403 from the operations they may not use.
            'access_control' => [
                ['path' => '^'.self::API_PREFIX.'/oauth/callback$', 'methods' => ['GET'], 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^'.self::API_PREFIX.'/conversations/anonymous$', 'methods' => ['POST'], 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/mcp/', 'roles' => 'IS_AUTHENTICATED_FULLY'],
                ['path' => '^'.self::API_PREFIX, 'roles' => 'IS_AUTHENTICATED_FULLY'],
            ],
        ]);

        $container->extension('doctrine', [
            'dbal' => [
                'url' => '%env(resolve:DATABASE_URL)%',
                'types' => ['vector' => VectorType::class],
                'mapping_types' => ['vector' => 'vector'],
                // The store table is written through the PDO store and
                // created by PgVectorStore::setupTable(); the schema tool
                // keeps away from it (and from the messenger table).
                'schema_filter' => '~^(?!'.self::STORE_TABLE.'$|messenger_messages$)~',
                'use_savepoints' => true,
            ],
            'orm' => [
                'enable_native_lazy_objects' => true,
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
                // AUTO ids on PostgreSQL with DBAL 3: IDENTITY, as a host
                // configures it. The audit-log table was created with a
                // SERIAL column under another name, so SEQUENCE would ask
                // for a sequence that does not exist.
                'identity_generation_preferences' => [
                    PostgreSQLPlatform::class => 'identity',
                ],
                // The entities of the platform bundles, as a host maps them;
                // this bundle prepends its own.
                'mappings' => [
                    'DmstrApiConfiguration' => self::attributeMapping(ApiConfiguration::class, 'Dmstr\\ApiConfiguration\\Entity'),
                    'DmstrSymfonyJobQueue' => self::attributeMapping(Job::class, 'Dmstr\\SymfonyJobQueue\\Entity'),
                    'DmstrDoctrineAuditLog' => self::attributeMapping(LogEntry::class, 'Dmstr\\DoctrineAuditLog\\Entity'),
                    'GedmoLoggable' => self::attributeMapping(AbstractLogEntry::class, 'Gedmo\\Loggable\\Entity\\MappedSuperclass'),
                ],
            ],
        ]);

        $container->extension('doctrine_migrations', [
            'storage' => [
                'table_storage' => ['table_name' => 'doctrine_migration_versions'],
            ],
        ]);

        $container->extension('api_platform', [
            'title' => 'neuron-ai-bundle tests',
            'docs_formats' => [
                'jsonld' => ['application/ld+json'],
                'jsonopenapi' => ['application/vnd.openapi+json'],
            ],
            'defaults' => [
                'stateless' => true,
                'pagination_client_items_per_page' => true,
                'pagination_maximum_items_per_page' => 200,
            ],
            'serializer' => ['hydra_prefix' => true],
            'enable_swagger_ui' => false,
            'enable_re_doc' => false,
        ]);

        $container->extension('dmstr_api_platform_utils', [
            'credential_encryption' => [
                'key' => ConfigSecretsFixture::INSECURE_TEST_KEY,
            ],
            'relation_field_decorator' => [
                'label_property_candidates' => ['name', 'title', 'label', 'displayName', 'email'],
            ],
            'partial_uuid_item_provider' => ['enabled' => true],
            'auto_order' => ['enabled' => true],
            'stable_order' => ['enabled' => true],
            'relation_labels' => ['enabled' => true],
        ]);

        $container->extension('dmstr_neuron_ai', [
            'store' => [
                'table' => self::STORE_TABLE,
                'top_k' => 4,
            ],
            'turn' => [
                'deadline_seconds' => 300,
            ],
            'artifacts' => [
                'root' => self::artifactRoot(),
            ],
            'anonymous' => [
                'address_hash_key' => self::ANONYMOUS_ADDRESS_HASH_KEY,
            ],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        // Keep the test output free of framework log lines
        $services->set('logger', NullLogger::class);

        // --- Test seams of the bundle (src/Testing) -----------------------
        // No model provider is contacted: deterministic embeddings per
        // registered model, scripted chat answers. Public, so tests script
        // answers and read the recorded requests.
        $services->set(FakeProviderFactory::class)
            ->arg('$echo', '%env(bool:FAKE_PROVIDER_ECHO)%')
            ->arg('$chunkDelayMs', '%env(int:FAKE_PROVIDER_CHUNK_DELAY_MS)%')
            ->public();
        $services->alias(ProviderFactory::class, FakeProviderFactory::class)->public();

        // No MCP server is contacted: scripted transports.
        $services->set(FakeMcpTransportFactory::class)->public();
        $services->alias(McpTransportFactoryInterface::class, FakeMcpTransportFactory::class)->public();

        // The JWT forwarded by `forward_jwt` MCP connections (null unless a
        // test sets one).
        $services->set(FakeCurrentJwtProvider::class)->public();
        $services->alias(CurrentJwtProviderInterface::class, FakeCurrentJwtProvider::class)->public();

        // No provider is asked for its models: every model is available
        // unless a test scripts otherwise.
        $services->set(FakeModelLister::class)->public();
        $services->alias(ModelListerInterface::class, FakeModelLister::class)->public();

        // No provider describes its models: no models unless scripted.
        $services->set(FakeModelDescriber::class)->public();
        $services->alias(ModelDescriberInterface::class, FakeModelDescriber::class)->public();

        // Previews are created through the factory directly in tests.
        $services->alias('test.neuron_ai.agent_preview_factory', AgentPreviewFactory::class)->public();

        // --- Ports of the host (tests/Support/Port) ------------------------
        $ports = [
            ReindexSourceInterface::class => TestReindexSource::class,
            NeighbourSourceInterface::class => InMemoryNeighbourSource::class,
            SourceFreshnessInterface::class => InMemorySourceFreshness::class,
            AnonymousAccessPolicyInterface::class => InMemoryAnonymousAccessPolicy::class,
            AgentLimitDefaultsInterface::class => InMemoryAgentLimitDefaults::class,
            AgentPreviewRetentionInterface::class => InMemoryAgentPreviewRetention::class,
            EvaluationSettingsInterface::class => InMemoryEvaluationSettings::class,
        ];
        foreach ($ports as $port => $implementation) {
            $services->set($implementation);
            $services->alias($port, $implementation)->public();
        }
        $services->set(TestCollectionContentPurger::class)
            ->arg('$connection', service('doctrine.dbal.default_connection'))
            ->arg('$storeTable', self::STORE_TABLE);
        $services->alias(CollectionContentPurgerInterface::class, TestCollectionContentPurger::class)->public();

        // --- Host-side services of the schema bundles ----------------------
        // The application's input-schema resolver, required by
        // dmstr/symfony-job-queue-bundle and part of the resolver chain. It
        // knows no schema directory of its own; the bundles register their
        // schemas with resolvers of their own.
        $services->set(OperationInputSchemaResolver::class)
            ->arg('$schemaBasePaths', [])
            ->tag('dmstr_openapi_json_schema.input_resolver', ['priority' => 0]);

        // Turns the resolved input schemas into request bodies
        // (dmstr/openapi-json-schema-bundle ships it unregistered).
        $services->set(OperationInputSchemaDecorator::class)
            ->decorate('api_platform.openapi.factory')
            ->arg('$decorated', service('.inner'))
            ->arg('$resolver', service(ChainInputSchemaResolver::class));

        // --- Security ------------------------------------------------------
        $services->set(TestHeaderAuthenticator::class);
        $services->set(TestActorProvider::class);

        if ($this->hasPermissionVoter()) {
            // Autoconfiguration adds the `security.voter` tag; public so that
            // tests can tell the variants apart.
            $services->set(TestPermissionVoter::class)
                ->args([
                    service('security.role_hierarchy'),
                    param(self::PERMISSIONS_REGISTERED_PARAMETER),
                    param(self::PERMISSION_MAP_PARAMETER),
                ])
                ->public();
        }
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'api_platform')->prefix(self::API_PREFIX);
        $routes->import('@DmstrNeuronAiBundle/config/routes.php')->prefix(self::API_PREFIX);
        $routes->import('@ApiConfigurationBundle/config/routes.yaml');
    }

    /**
     * Attribute mapping of the directory that holds $class.
     *
     * @param class-string $class
     *
     * @return array{type: string, is_bundle: bool, dir: string, prefix: string}
     */
    private static function attributeMapping(string $class, string $prefix): array
    {
        return [
            'type' => 'attribute',
            'is_bundle' => false,
            'dir' => \dirname((string) (new \ReflectionClass($class))->getFileName()),
            'prefix' => $prefix,
        ];
    }
}
