<?php

// file generated with AI assistance: Claude Code - 2026-07-10 16:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle;

use ApiPlatform\Metadata\IriConverterInterface;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Artifact\ArtifactAccessResolver;
use Dmstr\NeuronAiBundle\Artifact\ArtifactLedgerInterface;
use Dmstr\NeuronAiBundle\Artifact\ArtifactLimitsInterface;
use Dmstr\NeuronAiBundle\Artifact\ArtifactScope;
use Dmstr\NeuronAiBundle\Artifact\ArtifactToolkitFactory;
use Dmstr\NeuronAiBundle\Artifact\DefaultArtifactLimits;
use Dmstr\NeuronAiBundle\Artifact\DoctrineArtifactLedger;
use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\McpEndpoint\Context\McpEndpointContext;
use Dmstr\McpEndpoint\McpEndpointBundle;
use Dmstr\McpEndpoint\Policy\McpEndpointPolicyInterface;
use Dmstr\McpEndpoint\Scope\ScopePruner;
use Dmstr\NeuronAiBundle\Command\EmbeddingIndexSyncCommand;
use Dmstr\NeuronAiBundle\Conversation\ContextRetriever;
use Dmstr\NeuronAiBundle\Command\McpConnectionSeedCommand;
use Dmstr\NeuronAiBundle\Command\McpToolsScanCommand;
use Dmstr\NeuronAiBundle\Command\McpToolsSyncCommand;
use Dmstr\NeuronAiBundle\Command\ProviderModelsScanCommand;
use Dmstr\NeuronAiBundle\Command\ProviderModelsSyncCommand;
use Dmstr\NeuronAiBundle\Command\ProviderConnectionSeedCommand;
use Dmstr\NeuronAiBundle\Doctrine\CollectionEmbeddingListener;
use Dmstr\NeuronAiBundle\Doctrine\CollectionLockListener;
use Dmstr\NeuronAiBundle\Doctrine\ExcludeStoreTableListener;
use Dmstr\NeuronAiBundle\Doctrine\ProviderConnectionDeleteGuard;
use Dmstr\NeuronAiBundle\Document\Chunker\MarkdownChunker;
use Dmstr\NeuronAiBundle\Document\Extractor\ExtractorRegistry;
use Dmstr\NeuronAiBundle\Document\Extractor\PdfExtractor;
use Dmstr\NeuronAiBundle\Document\Extractor\SpreadsheetExtractor;
use Dmstr\NeuronAiBundle\Document\Extractor\TextExtractor;
use Dmstr\NeuronAiBundle\Document\TypeDetector;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingDocumentSchema;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingIndexManager;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelResolver;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingsProviderLocator;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\JobProcessor\CollectionReindexJobProcessor;
use Dmstr\NeuronAiBundle\JobProcessor\McpToolScanJobProcessor;
use Dmstr\NeuronAiBundle\JobProcessor\ProviderModelScanJobProcessor;
use Dmstr\NeuronAiBundle\Knowledge\CollectionContentPurgerInterface;
use Dmstr\NeuronAiBundle\Knowledge\CollectionLockGuard;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\Mcp\McpExtension;
use Dmstr\NeuronAiBundle\Mcp\McpToolFactory;
use Dmstr\NeuronAiBundle\Mcp\McpToolScanner;
use Dmstr\NeuronAiBundle\Mcp\Server\CollectionDocumentCounter;
use Dmstr\NeuronAiBundle\Mcp\Server\CollectionDocumentCounterInterface;
use Dmstr\NeuronAiBundle\Mcp\Server\CollectionScopeResolver;
use Dmstr\NeuronAiBundle\Mcp\Server\EndpointRetrievalScope;
use Dmstr\NeuronAiBundle\Mcp\Server\KnowledgeStoreTable;
use Dmstr\NeuronAiBundle\Mcp\Server\ChunkEmbeddings;
use Dmstr\NeuronAiBundle\Mcp\Server\State\KnowledgeCollectionsProvider;
use Dmstr\NeuronAiBundle\Mcp\Server\State\KnowledgeContextProcessor;
use Dmstr\NeuronAiBundle\Mcp\Server\State\KnowledgeSearchProcessor;
use Dmstr\NeuronAiBundle\Mcp\McpTransportFactoryInterface;
use Dmstr\NeuronAiBundle\Mcp\StreamableHttpTransportFactory;
use Dmstr\NeuronAiBundle\OpenApi\PickerSchemaResolver;
use Dmstr\NeuronAiBundle\Provider\AnthropicModelDescriber;
use Dmstr\NeuronAiBundle\Provider\AnthropicModelLister;
use Dmstr\NeuronAiBundle\Provider\Auth\OllamaHttpClientFactory;
use Dmstr\NeuronAiBundle\Provider\Connection\AnthropicConnectionType;
use Dmstr\NeuronAiBundle\Provider\Connection\OllamaConnectionType;
use Dmstr\NeuronAiBundle\Provider\Connection\ProviderConnectionResolver;
use Dmstr\NeuronAiBundle\Provider\ModelAvailabilityCheck;
use Dmstr\NeuronAiBundle\Provider\ModelCapabilityCheck;
use Dmstr\NeuronAiBundle\Provider\ModelDescriberInterface;
use Dmstr\NeuronAiBundle\Provider\ModelListerInterface;
use Dmstr\NeuronAiBundle\Provider\OllamaModelDescriber;
use Dmstr\NeuronAiBundle\Provider\OllamaModelLister;
use Dmstr\NeuronAiBundle\Provider\ProviderModelDescriber;
use Dmstr\NeuronAiBundle\Provider\ProviderModelResolver;
use Dmstr\NeuronAiBundle\Provider\ProviderModelScanner;
use Dmstr\NeuronAiBundle\Provider\ProviderModelScanQueue;
use Dmstr\NeuronAiBundle\Provider\ToolModelCheck;
use Dmstr\NeuronAiBundle\Provider\ProviderModelLister;
use Dmstr\NeuronAiBundle\Reindex\CollectionReindexer;
use Dmstr\NeuronAiBundle\Reindex\ReindexSourceInterface;
use Dmstr\NeuronAiBundle\Retrieval\CollectionReferenceResolver;
use Dmstr\NeuronAiBundle\Retrieval\CollectionRetriever;
use Dmstr\NeuronAiBundle\Retrieval\KnowledgeSearch;
use Dmstr\NeuronAiBundle\Retrieval\NeighbourSourceInterface;
use Dmstr\NeuronAiBundle\Security\CurrentJwtProviderInterface;
use Dmstr\NeuronAiBundle\Security\TokenStorageJwtProvider;
use Dmstr\NeuronAiBundle\State\CollectionDeleteProcessor;
use Dmstr\NeuronAiBundle\State\CollectionLockProcessor;
use Dmstr\NeuronAiBundle\State\CollectionReindexCancelProcessor;
use Dmstr\NeuronAiBundle\State\CollectionReindexProcessor;
use Dmstr\NeuronAiBundle\State\CollectionUnlockProcessor;
use Dmstr\NeuronAiBundle\State\InputBodyValidator;
use Dmstr\NeuronAiBundle\State\McpToolScanProcessor;
use Dmstr\NeuronAiBundle\State\McpToolSyncProcessor;
use Dmstr\NeuronAiBundle\State\ProviderConnectionScanTrigger;
use Dmstr\NeuronAiBundle\State\ProviderModelOptionsSchemaProvider;
use Dmstr\NeuronAiBundle\State\ProviderModelScanProcessor;
use Dmstr\NeuronAiBundle\State\ProviderModelSyncProcessor;
use Dmstr\NeuronAiBundle\Validator\McpConnectionNameUniqueValidator;
use Dmstr\NeuronAiBundle\Validator\ProviderConnectionNameUniqueValidator;
use Dmstr\NeuronAiBundle\Validator\ValidEmbeddingModelValidator;
use Dmstr\NeuronPgvectorStore\PgVectorStore;
use Dmstr\OpenApiJsonSchema\Interface\InputSchemaResolverInterface;
use Dmstr\OpenApiJsonSchema\Service\OperationInputSchemaResolver;
use Dmstr\SymfonyJobQueue\Service\JobQueueService;
use NeuronAI\RAG\Schema\DocumentSchema;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

class DmstrNeuronAiBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        // @phpstan-ignore-next-line
        $definition->rootNode()
            ->children()
                ->arrayNode('embeddings')->addDefaultsIfNotSet()->children()
                    // Registry of the embedding models a collection may use.
                    // An app that sets `models` replaces this default map.
                    ->arrayNode('models')
                        ->useAttributeAsKey('name')
                        ->normalizeKeys(false)
                        ->defaultValue([
                            'nomic-embed-text' => [
                                'provider' => 'ollama',
                                'dimension' => 768,
                                'query_prefix' => 'search_query: ',
                                'document_prefix' => 'search_document: ',
                                'context_length' => null,
                            ],
                            'mxbai-embed-large' => [
                                'provider' => 'ollama',
                                'dimension' => 1024,
                                'query_prefix' => 'Represent this sentence for searching relevant passages: ',
                                'document_prefix' => '',
                                'context_length' => null,
                            ],
                            // Multilingual (100+ languages incl. German), no task prefixes.
                            'bge-m3' => [
                                'provider' => 'ollama',
                                'dimension' => 1024,
                                'query_prefix' => '',
                                'document_prefix' => '',
                                'context_length' => null,
                            ],
                        ])
                        ->arrayPrototype()
                            ->children()
                                ->enumNode('provider')->values(['ollama'])->defaultValue('ollama')->end()
                                // Vector dimension the model produces
                                // (HNSW indexes support up to 2000).
                                ->integerNode('dimension')->isRequired()->end()
                                // Task prefixes, prepended by
                                // PrefixedEmbeddingsProvider (query: search
                                // input, document: indexed content).
                                ->scalarNode('query_prefix')->defaultValue('')->end()
                                ->scalarNode('document_prefix')->defaultValue('')->end()
                                // Context length in tokens (Ollama num_ctx);
                                // null keeps the provider default.
                                ->integerNode('context_length')->defaultNull()->min(1)->end()
                            ->end()
                        ->end()
                        ->validate()
                            ->ifTrue(static fn (array $models): bool => self::invalidModels($models) !== [])
                            ->then(static function (array $models): never {
                                throw new \InvalidArgumentException(sprintf(
                                    'Invalid embedding models: %s. Model names must match %s and have at most %d characters; dimensions must be within 1..%d.',
                                    implode('; ', self::invalidModels($models)),
                                    EmbeddingModelRegistry::NAME_PATTERN,
                                    EmbeddingModelRegistry::MAX_NAME_LENGTH,
                                    EmbeddingModelRegistry::MAX_DIMENSION,
                                ));
                            })
                        ->end()
                    ->end()
                ->end()->end()
                ->arrayNode('store')->addDefaultsIfNotSet()->children()
                    ->scalarNode('table')->defaultValue('neuron_documents')->end()
                    ->integerNode('top_k')->defaultValue(4)->end()
                ->end()->end()
                // Limits of one conversation turn (and of the stateless chat,
                // which runs on the same turn).
                ->arrayNode('turn')->addDefaultsIfNotSet()->children()
                    // Wall-clock deadline of a turn; also the HTTP timeout of
                    // provider calls. The stream php-fpm pool has no cap of
                    // its own, so 0 (unlimited) is rejected.
                    ->integerNode('deadline_seconds')->defaultValue(120)->min(1)->end()
                    // Context cap: at most this many retrieved chunks ...
                    ->integerNode('context_max_chunks')->defaultValue(8)->min(1)->end()
                    // ... with at most this many characters in total.
                    ->integerNode('context_max_chars')->defaultValue(6000)->min(1)->end()
                    // Instruction in front of the retrieved context for turns
                    // without an agent and agent versions without a text of
                    // their own (`contextInstructions`). Versions stored
                    // without these keys keep the legacy text.
                    ->scalarNode('context_instruction')->defaultValue(ContextRetriever::DEFAULT_INSTRUCTION)->cannotBeEmpty()
                        ->info('Instruction in front of the retrieved context; agent versions may override it per language.')
                    ->end()
                    // Earlier user messages searched together with the
                    // current one, so a follow-up or a submitted form finds
                    // the passages of its topic; 0 searches the message alone.
                    ->integerNode('retrieval_history_messages')->defaultValue(2)->min(0)->end()
                    // Context window (tokens) of the in-memory chat history
                    // the reconstructed conversation is replayed into.
                    ->integerNode('history_context_window')->defaultValue(200000)->min(1)->end()
                    // Executed tool calls per turn after which the next
                    // inference is forced to answer without tools; null is
                    // unbounded. A request's own bound overrides it.
                    ->integerNode('max_tool_calls_default')->defaultNull()->min(1)
                        ->info('Tool calls per turn before the model is forced to answer; null = unbounded.')
                    ->end()
                ->end()->end()
                // Model catalogue of the provider connections (descriptions
                // with capabilities and context length).
                ->arrayNode('provider_models')->addDefaultsIfNotSet()->children()
                    // Ollama `POST /show` calls per connection and scan;
                    // models over the budget keep their stored values.
                    ->integerNode('max_describe_calls')->defaultValue(OllamaModelDescriber::DEFAULT_MAX_DESCRIBE_CALLS)->min(1)
                        ->info('Ollama /show calls per connection and catalogue scan; models over the budget keep their stored values.')
                    ->end()
                ->end()->end()
                // Anonymous conversations (public creation with a capability
                // token). Release values and limits are runtime values of
                // the app's Anonymous\AnonymousAccessPolicyInterface; the
                // token prefix `anon_` is fixed (Anonymous\CapabilityToken).
                ->arrayNode('anonymous')->addDefaultsIfNotSet()->children()
                    // Key of the HMAC over client addresses (rate limiter key
                    // and stored address hash). Independent of APP_SECRET.
                    // Usually an env placeholder, so emptiness is checked at
                    // runtime: an empty key makes anonymous creation fail
                    // (Anonymous\ClientAddressHasher) instead of hashing with
                    // a guessable key.
                    ->scalarNode('address_hash_key')->defaultValue('')->end()
                    // Cache pool holding the creation counters; shared by all
                    // php containers in production (e.g. Redis).
                    ->scalarNode('rate_limiter_cache_pool')->defaultValue('cache.rate_limiter')->cannotBeEmpty()->end()
                    // How often the scheduled purge runs (closes expired and
                    // deletes due anonymous conversations).
                    ->scalarNode('purge_interval')->defaultValue('15 minutes')->cannotBeEmpty()->end()
                ->end()->end()
                // Preview versions of agents (frozen candidates for test
                // chats and evaluations). The retention is a runtime value
                // of the application's
                // Agent\Preview\AgentPreviewRetentionInterface.
                ->arrayNode('agent_previews')->addDefaultsIfNotSet()->children()
                    // How often the scheduled purge runs (deletes previews
                    // past their retention, schedule `neuron`).
                    ->scalarNode('purge_interval')->defaultValue('1 day')->cannotBeEmpty()
                        ->info('How often the scheduled purge deletes agent previews past their retention (relative date string, ISO 8601 duration or seconds).')
                    ->end()
                ->end()->end()
                // Artifact files agents write with the file tools:
                // one folder per user below the root, one working
                // folder per conversation below that. The root must exist
                // and be writable (production mounts a persistent volume);
                // it is never created, so a missing mount means no artifact
                // tools instead of files in the container's file system.
                ->arrayNode('artifacts')->addDefaultsIfNotSet()->children()
                    ->scalarNode('root')->defaultValue('%kernel.project_dir%/var/artifacts')->cannotBeEmpty()
                        ->info('Storage root of the artifact files (one folder per user); must exist and be writable.')
                    ->end()
                ->end()->end()
                // OpenAI-compatible chat API (`GET /api/v1/models`,
                // `POST /api/v1/chat/completions`).
                ->arrayNode('openai_compatible')->addDefaultsIfNotSet()->children()
                    ->scalarNode('owned_by')->defaultValue('dmstr')->cannotBeEmpty()
                        ->info('Value of `owned_by` in the model list of `GET /api/v1/models`.')
                    ->end()
                ->end()->end()
            ->end();
    }

    /**
     * Problems of a configured models map: names that are not usable as
     * model names and dimensions outside 1..2000.
     *
     * @param array<array-key, array{dimension?: mixed}> $models
     *
     * @return list<string>
     */
    private static function invalidModels(array $models): array
    {
        $problems = [];
        foreach ($models as $name => $model) {
            if (!EmbeddingModelRegistry::isValidName((string) $name)) {
                $problems[] = sprintf('name "%s"', $name);
            }
            $dimension = $model['dimension'] ?? null;
            if (!\is_int($dimension) || $dimension < 1 || $dimension > EmbeddingModelRegistry::MAX_DIMENSION) {
                $problems[] = sprintf('dimension of "%s"', $name);
            }
        }

        return $problems;
    }

    /**
     * Registers the bundle's entities (ORM + API Platform), migrations and
     * the validation mapping it adds to vendor entities.
     * Prepended config has lower priority than the app's own config.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'DmstrNeuronAi' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => __DIR__.'/Entity',
                        'prefix' => 'Dmstr\\NeuronAiBundle\\Entity',
                        'alias' => 'DmstrNeuronAi',
                    ],
                ],
            ],
        ]);

        // Anonymous/ holds the error resource of the turn gate
        // (ConversationLimitException); only a class in a mapping path is a
        // resource class whose `code` reaches the problem document.
        $builder->prependExtensionConfig('api_platform', [
            'mapping' => [
                'paths' => [__DIR__.'/Entity', __DIR__.'/Anonymous'],
            ],
        ]);

        // The knowledge MCP tools (Mcp/Server/Resource/Knowledge) exist only
        // with dmstr/mcp-endpoint-bundle: they read their scope from its
        // endpoint context and declare its scope key.
        if (self::mcpEndpointBundleLoaded($builder)) {
            $builder->prependExtensionConfig('api_platform', [
                'mapping' => [
                    'paths' => [__DIR__.'/Mcp/Server/Resource'],
                ],
            ]);
        }

        $builder->prependExtensionConfig('doctrine_migrations', [
            'migrations_paths' => [
                'Dmstr\\NeuronAiBundle\\Migrations' => __DIR__.'/../migrations',
            ],
        ]);

        // Name uniqueness of mcp connections on the vendor ApiConfiguration
        // entity (the entity itself cannot carry the constraint). Deliberately
        // not under config/validation/: FrameworkBundle loads that directory
        // of every bundle by convention, and the constraint would then be
        // registered twice.
        $builder->prependExtensionConfig('framework', [
            'validation' => [
                'mapping' => [
                    'paths' => [__DIR__.'/../config/validator/ApiConfiguration.xml'],
                ],
            ],
        ]);

        // Access tokens of `ollama` and `mcp` connections with auth_type
        // `client_credentials` (Provider\Auth). On cache.app so every php
        // container shares them (Redis in dev/prod, array in test).
        $builder->prependExtensionConfig('framework', [
            'cache' => [
                'pools' => [
                    'cache.neuron_provider_tokens' => ['adapter' => 'cache.app'],
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Settings are exposed as parameters and services read them from
        // there. Chat and embedding endpoints are no configuration: they are
        // provider connections (ApiConfiguration of type `ollama` or
        // `anthropic`), chosen explicitly per agent candidate, chat request,
        // conversation, anonymous release and collection. A parameter that
        // carries an env placeholder keeps that env var "used" when an app
        // replaces the service reading it (e.g. ProviderFactory with
        // Testing\FakeProviderFactory under when@test); otherwise the
        // container dump fails with "Environment variables ... are never used".
        $container->parameters()
            ->set('dmstr_neuron_ai.store.top_k', $config['store']['top_k'])
            ->set('dmstr_neuron_ai.turn.deadline_seconds', $config['turn']['deadline_seconds'])
            ->set('dmstr_neuron_ai.turn.max_tool_calls_default', $config['turn']['max_tool_calls_default'])
            ->set('dmstr_neuron_ai.turn.context_max_chunks', $config['turn']['context_max_chunks'])
            ->set('dmstr_neuron_ai.turn.context_max_chars', $config['turn']['context_max_chars'])
            ->set('dmstr_neuron_ai.turn.context_instruction', $config['turn']['context_instruction'])
            ->set('dmstr_neuron_ai.turn.retrieval_history_messages', $config['turn']['retrieval_history_messages'])
            ->set('dmstr_neuron_ai.turn.history_context_window', $config['turn']['history_context_window'])
            ->set('dmstr_neuron_ai.provider_models.max_describe_calls', $config['provider_models']['max_describe_calls'])
            ->set('dmstr_neuron_ai.anonymous.address_hash_key', $config['anonymous']['address_hash_key'])
            ->set('dmstr_neuron_ai.anonymous.rate_limiter_cache_pool', $config['anonymous']['rate_limiter_cache_pool'])
            ->set('dmstr_neuron_ai.anonymous.purge_interval', $config['anonymous']['purge_interval'])
            ->set('dmstr_neuron_ai.agent_previews.purge_interval', $config['agent_previews']['purge_interval'])
            ->set('dmstr_neuron_ai.artifacts.root', $config['artifacts']['root'])
            ->set('dmstr_neuron_ai.openai_compatible.owned_by', $config['openai_compatible']['owned_by']);

        // Service definitions split by concern (config/services/*.php):
        // turn.php holds ProviderFactory, ChatService and the turn runtime,
        // anonymous.php the anonymous conversations.
        $container->import(__DIR__.'/../config/services/*.php');

        $services = $container->services();

        // Storage of the anonymous creation counters (anonymous.php) on the
        // configured cache pool; defined here because the pool id is
        // configuration, not a parameter a service reference could use.
        $services->set('dmstr_neuron_ai.anonymous.rate_limiter_storage', CacheStorage::class)
            ->args([service($config['anonymous']['rate_limiter_cache_pool'])]);

        // --- Embedding models -----------------------------------------------
        // Registry of the models collections may use (dimension, task
        // prefixes, context length); the endpoint is the collection's
        // `ollama` connection. There is no default model.
        $services->set(EmbeddingModelRegistry::class)
            ->args([$config['embeddings']['models']]);

        // Embeddings provider per model, wrapped with the model's task
        // prefixes. ProviderFactory is defined in config/services/turn.php.
        // Goes through the ProviderFactory service id, so an app
        // replacement (e.g. the testing fake under when@test) is used.
        $services->set(EmbeddingsProviderLocator::class)
            ->args([
                service(ProviderFactory::class),
                service(EmbeddingModelRegistry::class),
            ]);

        // The embedding model of a collection: a catalogue entry usable for
        // embeddings whose name is registered (the label).
        $services->set(EmbeddingModelResolver::class)
            ->args([
                service(ProviderModelResolver::class),
                service(EmbeddingModelRegistry::class),
            ]);

        // Model, dimension and change rules of collections.
        $services->set(ValidEmbeddingModelValidator::class)
            ->args([
                service(EmbeddingModelRegistry::class),
                service('doctrine.orm.entity_manager'),
                service(EmbeddingModelResolver::class),
            ])
            ->tag('validator.constraint_validator');

        $services->set('dmstr_neuron_ai.pdo', \PDO::class)
            ->factory([service('doctrine.dbal.default_connection'), 'getNativeConnection']);

        $services->set(PgVectorStore::class)
            ->args([
                service('dmstr_neuron_ai.pdo'),
                $config['store']['table'],
                $config['store']['top_k'],
            ])
            // neuron-ai 4.x: the filterable metadata keys of the embeddings
            ->arg('$schema', inline_service(DocumentSchema::class)->factory([EmbeddingDocumentSchema::class, 'schema']));

        // Keeps the ORM schema tool away from the migration-owned store
        // table, even when an app maps it onto a read-only entity.
        $services->set(ExcludeStoreTableListener::class)
            ->args([$config['store']['table']])
            ->tag('doctrine.event_listener', ['event' => 'postGenerateSchema']);

        // Per-model partial vector indexes of the store table.
        $services->set(EmbeddingIndexManager::class)
            ->args([
                service(PgVectorStore::class),
                service(EmbeddingModelRegistry::class),
            ]);

        // Resolves the embedding model reference (entry, connection, label)
        // and fills the dimension of new collections (no default model) and
        // creates the vector index of their label.
        $services->set(CollectionEmbeddingListener::class)
            ->args([
                service(EmbeddingModelRegistry::class),
                service(EmbeddingIndexManager::class),
                service(EmbeddingModelResolver::class),
            ])
            ->tag('doctrine.orm.entity_listener', ['entity' => Collection::class, 'event' => 'prePersist'])
            ->tag('doctrine.orm.entity_listener', ['entity' => Collection::class, 'event' => 'postPersist']);

        // Similarity search across collections with different models.
        $services->set(CollectionRetriever::class)
            ->args([
                service(EmbeddingsProviderLocator::class),
                service(EmbeddingModelRegistry::class),
                service(PgVectorStore::class),
                service('doctrine.orm.entity_manager'),
            ]);

        // Creates the missing vector indexes of all models in use (current
        // and pending models of all collections); never drops anything.
        // The index manager is passed as a closure: its store connects to
        // the database on creation, and `--help` must work without one.
        $services->set(EmbeddingIndexSyncCommand::class)
            ->args([
                service_closure(EmbeddingIndexManager::class),
                service(EmbeddingModelRegistry::class),
                service('doctrine.orm.entity_manager'),
            ])
            ->tag('console.command');

        // --- Knowledge search: collection references and search composition
        // One resolver of collection references (UUID or name, unknown →
        // 422) for the search, the stateless chat, the app's document upload
        // and the `collections` scope of MCP endpoints.
        $services->set(CollectionReferenceResolver::class)
            ->args([service('doctrine.orm.entity_manager')]);

        // Resolution plus CollectionRetriever plus ContextChunk assembly,
        // shared by POST /api/search and the MCP tool rag_search
        // (registerMcpServerServices()).
        $services->set(KnowledgeSearch::class)
            ->args([
                service(CollectionRetriever::class),
                service(CollectionReferenceResolver::class),
            ]);

        // --- Reindex: moving a collection to another embedding model -------
        // The documents come from the application: it aliases
        // ReindexSourceInterface to its implementation (the bundle cannot
        // know where documents live). Without that alias the container
        // fails to compile as soon as the reindexer is used.
        $services->set(CollectionReindexer::class)
            ->args([
                service(ReindexSourceInterface::class),
                service(EmbeddingsProviderLocator::class),
                service(EmbeddingModelRegistry::class),
                service(PgVectorStore::class),
                service(EmbeddingIndexManager::class),
                service('doctrine.orm.entity_manager'),
                service('logger')->nullOnInvalid(),
                service(CollectionLockGuard::class),
            ]);

        // POST /admin/collections/{id}/reindex and .../reindex/cancel; the
        // reindex body is validated against Entity/Collection/reindex.input.json.
        $services->set(CollectionReindexProcessor::class)
            ->args([
                service(InputSchemaResolverInterface::class),
                service(CollectionReindexer::class),
                service(JobQueueService::class),
                service('doctrine.orm.entity_manager'),
                service(ProviderModelResolver::class),
            ])
            ->tag('api_platform.state_processor');

        // The model picker of the reindex form on top of reindex.input.json;
        // ahead of the plain file resolver in the chain.
        $services->set('dmstr_neuron_ai.input_schema_resolver.collection_reindex', PickerSchemaResolver::class)
            ->args([
                service('dmstr_neuron_ai.input_schema_resolver'),
                Collection::OPERATION_REINDEX,
                'model',
                ProviderModel::EMBEDDING_MODEL_PICKER,
            ])
            ->tag('dmstr_openapi_json_schema.input_resolver', ['priority' => 20]);

        $services->set(CollectionReindexCancelProcessor::class)
            ->args([service(CollectionReindexer::class)])
            ->tag('api_platform.state_processor');

        // --- Collection lock ------------------------------------------------
        // The lock check of every write path of a collection's content; it
        // reads the stored lock state (workers hold stale entities). The
        // service id is the class, so the app autowires it.
        $services->set(CollectionLockGuard::class)
            ->args([service('doctrine.dbal.default_connection')]);

        // 423 for PUT, PATCH and DELETE of a locked collection and every
        // other EntityManager path; changes of the lock fields pass.
        $services->set(CollectionLockListener::class)
            ->args([service(CollectionLockGuard::class)])
            ->tag('doctrine.orm.entity_listener', ['entity' => Collection::class, 'event' => 'preUpdate'])
            ->tag('doctrine.orm.entity_listener', ['entity' => Collection::class, 'event' => 'preRemove']);

        // POST /admin/collections/{id}/lock and .../unlock.
        $services->set(CollectionLockProcessor::class)
            ->args([
                service('doctrine.orm.entity_manager'),
                service('security.token_storage'),
            ])
            ->tag('api_platform.state_processor');

        $services->set(CollectionUnlockProcessor::class)
            ->args([service('doctrine.orm.entity_manager')])
            ->tag('api_platform.state_processor');

        // DELETE /admin/collections/{id}: 423 when locked, 409 while a
        // reindex is pending, then the content purge of the application
        // (it aliases CollectionContentPurgerInterface; without the alias
        // only empty collections are deleted), the scope pruning of the MCP
        // endpoints (only with dmstr/mcp-endpoint-bundle) and the removal in
        // one transaction; stored files after the commit.
        $services->set(CollectionDeleteProcessor::class)
            ->args([
                service('doctrine.orm.entity_manager'),
                service(CollectionLockGuard::class),
                $config['store']['table'],
                service(CollectionContentPurgerInterface::class)->nullOnInvalid(),
                self::mcpEndpointBundleLoaded($builder) ? service(ScopePruner::class) : null,
                service('logger')->nullOnInvalid(),
            ])
            ->tag('api_platform.state_processor');

        $services->set(CollectionReindexJobProcessor::class)
            ->args([
                service(CollectionReindexer::class),
                service('doctrine.orm.entity_manager'),
            ])
            ->tag('dmstr.job_queue.processor');

        // --- MCP servers as the `mcp` connection type ----------------------
        // Transport seam: health, catalogue and chat obtain MCP transports
        // here; under when@test the app aliases the interface to
        // Testing\FakeMcpTransportFactory. Tokens of `client_credentials`
        // connections share the cache.neuron_provider_tokens pool with the
        // ollama connections (key prefix neuron_mcp_token_).
        $services->set(StreamableHttpTransportFactory::class)
            ->args([
                service('cache.neuron_provider_tokens'),
                null,
                null,
                service('logger')->nullOnInvalid(),
            ]);
        $services->alias(McpTransportFactoryInterface::class, StreamableHttpTransportFactory::class);

        // Connection type (health client) and its configJson schema branch.
        // Tags are explicit: the app's _instanceof and the api-configuration
        // bundle's autoconfiguration do not reach services defined here.
        $services->set(McpExtension::class)
            ->args([service(McpTransportFactoryInterface::class)])
            ->tag('dmstr_api_configuration.extension')
            ->tag('dmstr_openapi_json_schema.provider');

        // The authorization checker decides the direct use of restricted
        // connections (neuronai:mcp_connection:use_restricted); without it every restricted
        // connection would be refused on the direct-use path.
        $services->set(McpConnectionResolver::class)
            ->args([
                service('doctrine.orm.entity_manager'),
                service(IriConverterInterface::class),
                service(UuidResolver::class),
                service('security.authorization_checker'),
            ]);

        $services->set(McpConnectionNameUniqueValidator::class)
            ->args([service('doctrine.orm.entity_manager')])
            ->tag('validator.constraint_validator');

        // Idempotent seed of one mcp connection (make setup); validates
        // through the validator, never contacts the server.
        $services->set(McpConnectionSeedCommand::class)
            ->args([
                service('doctrine.orm.entity_manager'),
                service('validator'),
                service(ConfigSecrets::class),
            ])
            ->tag('console.command');

        // --- MCP tool catalogue (Entity\Refs\McpTool) -----------------------
        $services->set(McpToolScanner::class)
            ->args([
                service('doctrine.orm.entity_manager'),
                service(McpTransportFactoryInterface::class),
                service(ConfigSecrets::class),
            ]);

        // Input schemas of the bundle's own operations
        // (Entity/Refs/McpTool/scan.input.json,
        // Entity/Collection/reindex.input.json,
        // Entity/Conversation/turn.input.json). the application's resolver
        // only searches its own src/Entity; this instance joins the resolver
        // chain, so the OpenAPI requestBody and the scan processor's
        // validation use the same file.
        $services->set('dmstr_neuron_ai.input_schema_resolver', OperationInputSchemaResolver::class)
            ->args([
                [],
                [[__DIR__.'/Entity', 'Dmstr\\NeuronAiBundle\\Entity']],
            ])
            ->tag('dmstr_openapi_json_schema.input_resolver', ['priority' => 10]);

        $services->set(McpToolScanProcessor::class)
            ->args([
                service(InputSchemaResolverInterface::class),
                service(McpConnectionResolver::class),
                service(JobQueueService::class),
            ])
            ->tag('api_platform.state_processor');

        $services->set(McpToolSyncProcessor::class)
            ->args([service(McpToolScanner::class)])
            ->tag('api_platform.state_processor');

        $services->set(McpToolScanJobProcessor::class)
            ->args([
                service(McpToolScanner::class),
                service('doctrine.orm.entity_manager'),
            ])
            ->tag('dmstr.job_queue.processor');

        $services->set(McpToolsScanCommand::class)
            ->args([
                service(McpToolScanner::class),
                service(McpConnectionResolver::class),
            ])
            ->tag('console.command');

        $services->set(McpToolsSyncCommand::class)
            ->args([
                service(McpToolScanner::class),
                service('doctrine.orm.entity_manager'),
            ])
            ->tag('console.command');

        // JWT of the person chatting for `forward_jwt` connections, read
        // from the security token (Lexik's JWTPostAuthenticationToken);
        // null without security or Lexik. Under when@test the app aliases
        // the interface to Testing\FakeCurrentJwtProvider.
        $services->set(TokenStorageJwtProvider::class)
            ->args([service('security.token_storage')->nullOnInvalid()]);
        $services->alias(CurrentJwtProviderInterface::class, TokenStorageJwtProvider::class);

        // Catalogue rows -> Neuron tools (no I/O until a tool is called);
        // used by the chat and reusable wherever an agent gets MCP tools.
        $services->set(McpToolFactory::class)
            ->args([
                service('doctrine.orm.entity_manager'),
                service(McpTransportFactoryInterface::class),
                service('logger')->nullOnInvalid(),
                service(ConfigSecrets::class),
                service(CurrentJwtProviderInterface::class),
            ]);

        $this->registerProviderConnectionServices($container);
        $this->registerProviderModelCatalogueServices($container);
        $this->registerDocumentServices($container);
        $this->registerArtifactServices($container);

        if (self::mcpEndpointBundleLoaded($builder)) {
            $this->registerMcpServerServices($container, $config['store']['table']);
        }
    }

    /**
     * Whether dmstr/mcp-endpoint-bundle (a `suggest` of this bundle) is
     * installed and registered in the kernel. Without it the knowledge MCP
     * tools, their services and the `collections` scope resolver are not
     * registered, and the bundle behaves as before.
     */
    private static function mcpEndpointBundleLoaded(ContainerBuilder $builder): bool
    {
        if (!class_exists(McpEndpointBundle::class)) {
            return false;
        }

        $bundles = $builder->hasParameter('kernel.bundles') ? $builder->getParameter('kernel.bundles') : [];

        return \is_array($bundles) && \in_array(McpEndpointBundle::class, $bundles, true);
    }

    /**
     * The knowledge base as MCP tools of dmstr/mcp-endpoint-bundle
     * (Mcp/Server): the `collections` scope resolver (tagged explicitly,
     * the endpoint bundle's autoconfiguration does not reach services
     * defined here), the one reader of the current endpoint's scope and
     * retrieval settings, the reads of the store table (document counter,
     * chunk embeddings), and the provider and processors of the tools
     * `rag_list_collections`, `rag_search` and `rag_get_context`.
     * Registered only while the endpoint bundle is loaded.
     */
    private function registerMcpServerServices(ContainerConfigurator $container, string $storeTable): void
    {
        $services = $container->services();

        $services->set(CollectionScopeResolver::class)
            ->args([service(CollectionReferenceResolver::class)])
            ->tag(McpEndpointBundle::SCOPE_RESOLVER_TAG);

        // Scope and retrieval settings of the endpoint in the request's
        // McpEndpointContext, with the policy defaults; empty scope without
        // endpoint (default deny). Shared by every knowledge tool.
        $services->set(EndpointRetrievalScope::class)
            ->args([
                service(McpEndpointContext::class),
                service(McpEndpointPolicyInterface::class),
                service(CollectionReferenceResolver::class),
            ]);

        // Read access to the vector store table, shared by the document
        // counter and the chunk lookup of rag_get_context.
        $services->set(KnowledgeStoreTable::class)
            ->args([
                service('doctrine.dbal.default_connection'),
                $storeTable,
            ]);

        $services->set(CollectionDocumentCounter::class)
            ->args([service(KnowledgeStoreTable::class)]);
        // Bundle default of the document counter; the application aliases
        // the interface to a counter of its document tables.
        $services->alias(CollectionDocumentCounterInterface::class, CollectionDocumentCounter::class);

        $services->set(ChunkEmbeddings::class)
            ->args([service(KnowledgeStoreTable::class)]);

        $services->set(KnowledgeCollectionsProvider::class)
            ->args([
                service(EndpointRetrievalScope::class),
                service(CollectionDocumentCounterInterface::class),
            ])
            ->tag('api_platform.state_provider');

        // KnowledgeSearch as a closure: its store connects to the database
        // on creation, and refused calls must not need it.
        $services->set(KnowledgeSearchProcessor::class)
            ->args([
                service(EndpointRetrievalScope::class),
                service_closure(KnowledgeSearch::class),
            ])
            ->tag('api_platform.state_processor');

        // Neighbours from the application's NeighbourSourceInterface alias
        // (none without one), asked only after the scope check.
        $services->set(KnowledgeContextProcessor::class)
            ->args([
                service(EndpointRetrievalScope::class),
                service(ChunkEmbeddings::class),
                service(NeighbourSourceInterface::class)->nullOnInvalid(),
            ])
            ->tag('api_platform.state_processor');
    }

    /**
     * Model catalogue of the provider connections: the describers
     * behind ModelDescriberInterface (aliased to Testing\FakeModelDescriber
     * by the app under when@test), with the Ollama describer authenticating
     * like the lister and capped at `provider_models.max_describe_calls`
     * `/show` calls per connection and scan; the scanner with its
     * per-connection lock, the scan queue, the scan and sync processors,
     * the job processor, the console twins, the scan trigger on
     * provider connection writes through the API, and the capability
     * check with the tool-model check of publish and chat. Tags are explicit
     * because autoconfiguration does not reach bundle services; the
     * CapabilityFilter is created by API Platform from its #[ApiFilter].
     */
    private function registerProviderModelCatalogueServices(ContainerConfigurator $container): void
    {
        $services = $container->services();

        $services->set(OllamaModelDescriber::class)
            ->args([
                service(ConfigSecrets::class),
                '$authClients' => service(OllamaHttpClientFactory::class),
                '$maxDescribeCalls' => param('dmstr_neuron_ai.provider_models.max_describe_calls'),
            ]);
        $services->set(AnthropicModelDescriber::class)
            ->args([service(ConfigSecrets::class)]);
        $services->set(ProviderModelDescriber::class)
            ->args([
                service(OllamaModelDescriber::class),
                service(AnthropicModelDescriber::class),
            ]);
        $services->alias(ModelDescriberInterface::class, ProviderModelDescriber::class);

        // --- Model catalogue (Entity\Refs\ProviderModel) -------------------
        // The per-connection lock serialises scans and syncs of a connection
        // (lock.factory, a shared store outside tests); its TTL derives from
        // the describe budget.
        $services->set(ProviderModelScanner::class)
            ->args([
                service('doctrine.orm.entity_manager'),
                service(ModelDescriberInterface::class),
                service('lock.factory'),
                param('dmstr_neuron_ai.provider_models.max_describe_calls'),
            ]);

        $services->set(ProviderModelScanQueue::class)
            ->args([
                service(JobQueueService::class),
                service('doctrine.orm.entity_manager'),
            ]);

        // The scan form's connection picker, built from
        // ProviderModel::CONNECTION_TYPES on top of scan.input.json; ahead of
        // the plain file resolver in the chain.
        $services->set('dmstr_neuron_ai.input_schema_resolver.provider_model_scan', PickerSchemaResolver::class)
            ->args([
                service('dmstr_neuron_ai.input_schema_resolver'),
                ProviderModel::OPERATION_SCAN,
                'apiConfiguration',
                ['x-collection' => ProviderModel::connectionCollectionUrl()],
            ])
            ->tag('dmstr_openapi_json_schema.input_resolver', ['priority' => 20]);

        $services->set(ProviderModelScanProcessor::class)
            ->args([
                service(InputBodyValidator::class),
                service(ProviderConnectionResolver::class),
                service(ProviderModelScanQueue::class),
            ])
            ->tag('api_platform.state_processor');

        $services->set(ProviderModelSyncProcessor::class)
            ->args([service(ProviderModelScanner::class)])
            ->tag('api_platform.state_processor');

        // The schema of the agent model options a catalogue model accepts.
        $services->set(ProviderModelOptionsSchemaProvider::class)
            ->args([service('api_platform.doctrine.orm.state.item_provider')])
            ->tag('api_platform.state_provider');

        $services->set(ProviderModelScanJobProcessor::class)
            ->args([
                service(ProviderModelScanner::class),
                service('doctrine.orm.entity_manager'),
            ])
            ->tag('dmstr.job_queue.processor');

        $services->set(ProviderModelsScanCommand::class)
            ->args([
                service(ProviderModelScanner::class),
                service(ProviderConnectionResolver::class),
            ])
            ->tag('console.command');

        $services->set(ProviderModelsSyncCommand::class)
            ->args([
                service(ProviderModelScanner::class),
                service('doctrine.orm.entity_manager'),
            ])
            ->tag('console.command');

        // Creating, activating or re-pointing a provider connection through
        // the API queues a scan of that connection; seeding does not.
        $services->set(ProviderConnectionScanTrigger::class)
            ->decorate('api_platform.doctrine.orm.state.persist_processor')
            ->args([
                service('.inner'),
                service(ProviderModelScanQueue::class),
                service('logger')->nullOnInvalid(),
            ]);

        // Capabilities of a model from the catalogue (no network I/O), and
        // the check whether a model may take MCP tools, shared by the agent
        // publish and the stateless chat.
        $services->set(ModelCapabilityCheck::class)
            ->args([service('doctrine.orm.entity_manager')]);
        $services->set(ToolModelCheck::class)
            ->args([service(ModelCapabilityCheck::class)]);

        // Model references (IRI or UUID of a catalogue entry) of agent
        // candidates, conversations, the stateless chat and collections.
        $services->set(ProviderModelResolver::class)
            ->args([
                service(IriConverterInterface::class),
                service(UuidResolver::class),
            ]);
    }

    /**
     * Provider connections: the `ollama` and `anthropic`
     * connection types (schema branch and health probe, tags explicit
     * because the api-configuration bundle's autoconfiguration does not
     * reach services defined here), the model listers behind
     * ModelListerInterface (aliased to Testing\FakeModelLister by the app
     * under when@test), the model availability check, the reference
     * resolver, the name uniqueness across both types, the delete guard and
     * the idempotent seed command.
     */
    private function registerProviderConnectionServices(ContainerConfigurator $container): void
    {
        $services = $container->services();

        $services->set(OllamaModelLister::class)
            ->args([
                service(ConfigSecrets::class),
                '$authClients' => service(OllamaHttpClientFactory::class),
            ]);
        $services->set(AnthropicModelLister::class)
            ->args([service(ConfigSecrets::class)]);
        $services->set(ProviderModelLister::class)
            ->args([
                service(OllamaModelLister::class),
                service(AnthropicModelLister::class),
            ]);
        $services->alias(ModelListerInterface::class, ProviderModelLister::class);

        // Authentication of `ollama` connections (auth_type bearer or
        // client_credentials): wraps the HTTP client of a provider; the
        // client credentials tokens live in the cache.neuron_provider_tokens
        // pool prepended above.
        $services->set(OllamaHttpClientFactory::class)
            ->args([
                service('cache.neuron_provider_tokens'),
                null,
                null,
                service('logger')->nullOnInvalid(),
            ]);

        // The single entry point of the model check (publish, chat,
        // conversations without an agent, anonymous creation).
        $services->set(ModelAvailabilityCheck::class)
            ->args([service(ModelListerInterface::class)]);

        foreach ([OllamaConnectionType::class, AnthropicConnectionType::class] as $type) {
            $services->set($type)
                ->args([service(ModelListerInterface::class)])
                ->tag('dmstr_openapi_json_schema.provider')
                ->tag('dmstr_api_configuration.health_probe');
        }

        $services->set(ProviderConnectionResolver::class)
            ->args([
                service('doctrine.orm.entity_manager'),
                service(IriConverterInterface::class),
                service(UuidResolver::class),
            ]);

        $services->set(ProviderConnectionNameUniqueValidator::class)
            ->args([service('doctrine.orm.entity_manager')])
            ->tag('validator.constraint_validator');

        // Refuses to delete a referenced provider connection (409), for the
        // API and every other EntityManager path.
        $services->set(ProviderConnectionDeleteGuard::class)
            ->tag('doctrine.orm.entity_listener', ['entity' => ApiConfiguration::class, 'event' => 'preRemove']);

        // Idempotent seed of provider connections (make seed); validates
        // through the validator, never contacts a provider.
        $services->set(ProviderConnectionSeedCommand::class)
            ->args([
                service('doctrine.orm.entity_manager'),
                service('validator'),
                service(ConfigSecrets::class),
            ])
            ->tag('console.command');
    }

    /**
     * Artifact tools: path building under `artifacts.root`, the
     * per-turn toolkit factory, the limits port with the bundle default
     * (an app alias of ArtifactLimitsInterface, e.g. a settings reader,
     * replaces it) and the ledger on `neuron_artifact`. The per-user write
     * lock comes from `lock.factory` (symfony/lock), which must be a store
     * shared by all php containers in production.
     *
     * The gate of a turn, `ArtifactAccessResolver` (security token,
     * authorization checker for `neuronai:artifact:write`, scope), and the toolkit
     * factory reach the turn through `VersionTurnMapper` (both turn
     * services build their `TurnPersistence` from the mapper); its
     * definition in config/services/agent.php references both services.
     */
    private function registerArtifactServices(ContainerConfigurator $container): void
    {
        $services = $container->services();

        $services->set(ArtifactScope::class)
            ->args([param('dmstr_neuron_ai.artifacts.root')]);

        $services->set(DefaultArtifactLimits::class);
        $services->alias(ArtifactLimitsInterface::class, DefaultArtifactLimits::class);

        $services->set(DoctrineArtifactLedger::class)
            ->args([service('doctrine.dbal.default_connection')]);
        $services->alias(ArtifactLedgerInterface::class, DoctrineArtifactLedger::class);

        $services->set(ArtifactToolkitFactory::class)
            ->args([
                service(ArtifactLimitsInterface::class),
                service(ArtifactLedgerInterface::class),
                service('lock.factory'),
                service('logger')->nullOnInvalid(),
            ]);

        $services->set(ArtifactAccessResolver::class)
            ->args([
                service('security.token_storage'),
                service('security.authorization_checker'),
                service(ArtifactScope::class),
                service('logger')->nullOnInvalid(),
            ]);
    }

    /**
     * Document extraction and chunking (Document\*): type detection, the
     * extractor registry with the built-in text, PDF and spreadsheet
     * extractors (default limits, see their DEFAULT_* constants), and the
     * Markdown chunker. Further extractors are services tagged
     * `dmstr_neuron_ai.document_extractor`; the tag is explicit because the
     * app's autoconfiguration does not reach services defined here.
     */
    private function registerDocumentServices(ContainerConfigurator $container): void
    {
        $services = $container->services();

        $services->set(TypeDetector::class);

        $services->set(TextExtractor::class)
            ->tag('dmstr_neuron_ai.document_extractor');

        $services->set(PdfExtractor::class)
            ->tag('dmstr_neuron_ai.document_extractor');

        $services->set(SpreadsheetExtractor::class)
            ->tag('dmstr_neuron_ai.document_extractor');

        $services->set(ExtractorRegistry::class)
            ->args([tagged_iterator('dmstr_neuron_ai.document_extractor')]);

        $services->set(MarkdownChunker::class);
    }
}

// - revised 2026-10-09 06:42:28 UTC
// - revised 2026-10-09
// - revised 2026-10-09 13:43:23 UTC
// - revised 2026-10-09 16:16:39 UTC
// - revised 2026-10-09 16:30:10 UTC
// - revised 2026-10-13
// - revised 2026-10-13 19:06:18 UTC
// - revised 2026-10-13 20:10:00 UTC
// - revised 2026-10-13 23:25:27 UTC
// - revised 2026-10-14
// - revised 2026-10-16
// - revised 2026-10-16
// - revised 2026-11-09
