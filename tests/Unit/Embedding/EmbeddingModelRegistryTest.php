<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:17:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Embedding;

use Dmstr\NeuronAiBundle\DmstrNeuronAiBundle;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The embedding model registry and the `embeddings` config tree it is built
 * from, without a kernel.
 */
class EmbeddingModelRegistryTest extends TestCase
{
    public function testTreeDefaultsProvideTheThreeModels(): void
    {
        $registry = $this->registryFromConfig([]);

        self::assertSame(['nomic-embed-text', 'mxbai-embed-large', 'bge-m3'], $registry->names());
        $nomic = $registry->get('nomic-embed-text');
        self::assertSame(768, $nomic->dimension);
        self::assertSame('ollama', $nomic->provider);
        self::assertSame('search_query: ', $nomic->queryPrefix);
        self::assertSame('search_document: ', $nomic->documentPrefix);
        self::assertNull($nomic->contextLength);

        $mxbai = $registry->get('mxbai-embed-large');
        self::assertSame(1024, $mxbai->dimension);
        self::assertSame('Represent this sentence for searching relevant passages: ', $mxbai->queryPrefix);
        self::assertSame('', $mxbai->documentPrefix);

        $bge = $registry->get('bge-m3');
        self::assertSame(1024, $bge->dimension);
        self::assertSame('', $bge->queryPrefix);
        self::assertSame('', $bge->documentPrefix);

        self::assertTrue($registry->has('mxbai-embed-large'));
        self::assertFalse($registry->has('unknown'));
        self::assertCount(3, $registry->all());
    }

    public function testCustomMapReplacesTheDefaults(): void
    {
        $registry = $this->registryFromConfig([
            'models' => [
                'bge-m3:567m' => ['dimension' => 1024, 'context_length' => 8192],
            ],
        ]);

        self::assertSame(['bge-m3:567m'], $registry->names());
        self::assertSame(8192, $registry->get('bge-m3:567m')->contextLength);
        self::assertSame('', $registry->get('bge-m3:567m')->queryPrefix);
    }

    /**
     * The registered name is the vector label; a catalogue name matches it
     * exactly or with or without `:latest`, the exact name first.
     */
    public function testACatalogueNameFindsItsRegisteredModel(): void
    {
        $registry = $this->registryFromConfig([
            'models' => [
                'nomic-embed-text' => ['dimension' => 768],
                'bge-m3:latest' => ['dimension' => 1024],
                'mxbai-embed-large' => ['dimension' => 1024],
                'mxbai-embed-large:latest' => ['dimension' => 512],
            ],
        ]);

        self::assertSame('nomic-embed-text', $registry->findEquivalent('nomic-embed-text:latest')?->name);
        self::assertSame('nomic-embed-text', $registry->findEquivalent('nomic-embed-text')?->name);
        self::assertSame('bge-m3:latest', $registry->findEquivalent('bge-m3')?->name);
        self::assertSame('mxbai-embed-large:latest', $registry->findEquivalent('mxbai-embed-large:latest')?->name, 'The exact name wins.');
        self::assertSame('mxbai-embed-large', $registry->findEquivalent('mxbai-embed-large')?->name);
        self::assertNull($registry->findEquivalent('nomic-embed-text:v1.5'));
        self::assertNull($registry->findEquivalent(''));

        $entry = new ProviderModel(ProviderConnectionFixtures::ollama('ollama-local'), 'nomic-embed-text:latest');
        self::assertSame(768, $registry->forEntry($entry)->dimension);
    }

    public function testAnUnregisteredEntryNamesItsConnectionAndTheKnownModels(): void
    {
        $registry = $this->registryFromConfig([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Model "all-minilm" of connection "ollama-local" is no registered embedding model; register it (with or without the tag ":latest") under dmstr_neuron_ai.embeddings.models. Known models: "nomic-embed-text", "mxbai-embed-large", "bge-m3".');

        $registry->forEntry(new ProviderModel(ProviderConnectionFixtures::ollama('ollama-local'), 'all-minilm'));
    }

    public function testUnknownModelMessageListsKnownModels(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown embedding model "text-embedding-3-small"; known models: "nomic-embed-text", "mxbai-embed-large", "bge-m3".');

        $this->registryFromConfig([])->get('text-embedding-3-small');
    }

    public function testTheRemovedDefaultModelOptionIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unrecognized option "default_model"');

        $this->registryFromConfig(['default_model' => 'nomic-embed-text']);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function names(): iterable
    {
        yield 'plain' => ['nomic-embed-text', true];
        yield 'tag and namespace' => ['jina/jina-embeddings-v2-base-de:latest', true];
        yield 'dots and underscore' => ['bge_m3.v1', true];
        yield 'quote' => ["nomic'; DROP TABLE x; --", false];
        yield 'space' => ['nomic embed', false];
        yield 'leading dash' => ['-model', false];
        yield 'empty' => ['', false];
        yield 'too long' => [str_repeat('a', 191), false];
        yield 'max length' => [str_repeat('a', 190), true];
    }

    #[DataProvider('names')]
    public function testNamePattern(string $name, bool $valid): void
    {
        self::assertSame($valid, EmbeddingModelRegistry::isValidName($name));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidModels(): iterable
    {
        yield 'invalid name' => [['bad name' => ['dimension' => 8]]];
        yield 'quote in name' => [["x'y" => ['dimension' => 8]]];
        yield 'dimension zero' => [['zero' => ['dimension' => 0]]];
        yield 'dimension above 2000' => [['huge' => ['dimension' => 3072]]];
        yield 'unknown provider' => [['other' => ['dimension' => 8, 'provider' => 'openai']]];
    }

    /**
     * @param array<string, mixed> $models
     */
    #[DataProvider('invalidModels')]
    public function testConfigTreeRejectsInvalidModels(array $models): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processConfig(['models' => $models]);
    }

    /**
     * @param array<string, mixed> $embeddings
     */
    private function registryFromConfig(array $embeddings): EmbeddingModelRegistry
    {
        $config = $this->processConfig($embeddings);

        return new EmbeddingModelRegistry($config['embeddings']['models']);
    }

    /**
     * @param array<string, mixed> $embeddings
     *
     * @return array<string, mixed>
     */
    private function processConfig(array $embeddings): array
    {
        $extension = (new DmstrNeuronAiBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertNotNull($configuration);

        return (new Processor())->processConfiguration($configuration, [['embeddings' => $embeddings]]);
    }
}
