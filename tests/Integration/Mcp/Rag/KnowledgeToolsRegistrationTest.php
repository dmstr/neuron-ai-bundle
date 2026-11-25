<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:16:39 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Mcp\Rag;

use Dmstr\McpEndpoint\McpEndpointBundle;
use Dmstr\NeuronAiBundle\DmstrNeuronAiBundle;
use Dmstr\NeuronAiBundle\Mcp\Server\ChunkEmbeddings;
use Dmstr\NeuronAiBundle\Mcp\Server\CollectionDocumentCounter;
use Dmstr\NeuronAiBundle\Mcp\Server\CollectionScopeResolver;
use Dmstr\NeuronAiBundle\Mcp\Server\EndpointRetrievalScope;
use Dmstr\NeuronAiBundle\Mcp\Server\KnowledgeStoreTable;
use Dmstr\NeuronAiBundle\Mcp\Server\State\KnowledgeCollectionsProvider;
use Dmstr\NeuronAiBundle\Mcp\Server\State\KnowledgeContextProcessor;
use Dmstr\NeuronAiBundle\Mcp\Server\State\KnowledgeSearchProcessor;
use Dmstr\NeuronAiBundle\Retrieval\KnowledgeSearch;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

/**
 * Registration of the knowledge MCP tools: only while
 * dmstr/mcp-endpoint-bundle is a kernel bundle (it is a `suggest` of the
 * neuron-ai-bundle). Checked on the extension with and without the endpoint
 * bundle in `kernel.bundles` (the test kernel of this package does not
 * register it, see KernelBootTest).
 */
final class KnowledgeToolsRegistrationTest extends KernelTestCase
{
    private const array SERVICES = [
        CollectionScopeResolver::class,
        EndpointRetrievalScope::class,
        KnowledgeStoreTable::class,
        CollectionDocumentCounter::class,
        ChunkEmbeddings::class,
        KnowledgeCollectionsProvider::class,
        KnowledgeSearchProcessor::class,
        KnowledgeContextProcessor::class,
    ];

    public function testWithoutEndpointBundleNothingIsRegistered(): void
    {
        $container = self::loadExtension([DmstrNeuronAiBundle::class]);

        foreach (self::SERVICES as $id) {
            self::assertFalse($container->hasDefinition($id), $id);
        }
        self::assertNotContains(self::resourcePath(), self::apiPlatformMappingPaths($container));
        self::assertTrue($container->hasDefinition(KnowledgeSearch::class), 'The rest of the bundle is unchanged.');
    }

    public function testWithEndpointBundleTheToolsAreRegistered(): void
    {
        $container = self::loadExtension([DmstrNeuronAiBundle::class, McpEndpointBundle::class]);

        foreach (self::SERVICES as $id) {
            self::assertTrue($container->hasDefinition($id), $id);
        }
        self::assertTrue($container->getDefinition(CollectionScopeResolver::class)->hasTag(McpEndpointBundle::SCOPE_RESOLVER_TAG));
        self::assertContains(self::resourcePath(), self::apiPlatformMappingPaths($container));
    }

    /**
     * Prepends and loads the neuron-ai-bundle extension alone, with
     * $bundles as `kernel.bundles`.
     *
     * @param list<class-string> $bundles
     */
    private static function loadExtension(array $bundles): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', array_combine(array_map(static fn (string $class): string => substr($class, strrpos($class, '\\') + 1), $bundles), $bundles));
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->setParameter('kernel.project_dir', \dirname(__DIR__, 4));

        $extension = (new DmstrNeuronAiBundle())->getContainerExtension();
        self::assertNotNull($extension);
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);
        $container->registerExtension($extension);
        $extension->prepend($container);
        $extension->load([], $container);

        return $container;
    }

    /**
     * @return list<string>
     */
    private static function apiPlatformMappingPaths(ContainerBuilder $container): array
    {
        $paths = [];
        foreach ($container->getExtensionConfig('api_platform') as $config) {
            foreach ($config['mapping']['paths'] ?? [] as $path) {
                $paths[] = realpath($path) ?: $path;
            }
        }

        return $paths;
    }

    private static function resourcePath(): string
    {
        $path = \dirname((string) (new \ReflectionClass(DmstrNeuronAiBundle::class))->getFileName()).'/Mcp/Server/Resource';

        return realpath($path) ?: $path;
    }
}
