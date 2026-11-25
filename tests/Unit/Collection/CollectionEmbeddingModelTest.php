<?php

// file generated with AI assistance: Claude Code - 2026-10-08 20:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Collection;

use Dmstr\NeuronAiBundle\Tests\Support\ProviderModelResolverStubTrait;
use Dmstr\NeuronAiBundle\Tests\Support\RecordingVectorStore;
use Dmstr\NeuronAiBundle\Doctrine\CollectionEmbeddingListener;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingIndexManager;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelResolver;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\ProviderModelReferenceException;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the collection's embedding model as a catalogue
 * reference: {@see \Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelResolver}
 * maps an entry to the registered model (the vector label), and
 * {@see CollectionEmbeddingListener} resolves the reference before persist
 * into entry, connection, label and dimension, and ensures the index of
 * the label (not of the catalogue name).
 */
final class CollectionEmbeddingModelTest extends TestCase
{
    use ProviderModelResolverStubTrait;

    private EmbeddingModelRegistry $registry;

    private RecordingVectorStore $store;

    private ProviderModel $nomic;

    private ProviderModel $minilm;

    protected function setUp(): void
    {
        $this->registry = new EmbeddingModelRegistry([
            'nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768],
            'mxbai-embed-large' => ['provider' => 'ollama', 'dimension' => 1024],
        ]);
        $this->store = new RecordingVectorStore();
        $connection = ProviderConnectionFixtures::ollama('ollama-local');
        $this->nomic = new ProviderModel($connection, 'nomic-embed-text:latest');
        $this->minilm = new ProviderModel($connection, 'all-minilm');
    }

    public function testTheResolverGivesTheEntryAndTheRegisteredModel(): void
    {
        $choice = $this->resolver()->resolve($this->nomic->iri());

        self::assertSame($this->nomic, $choice->entry);
        self::assertSame('nomic-embed-text', $choice->model->name, 'The label is the registered name, not the catalogue name.');
        self::assertSame(768, $choice->model->dimension);
        self::assertSame($choice->model, $this->resolver()->check($this->nomic)->model);
    }

    public function testAnUnregisteredEntryIsRejectedWithItsOwnReason(): void
    {
        foreach ([fn () => $this->resolver()->resolve($this->minilm->iri()), fn () => $this->resolver()->check($this->minilm), fn () => $this->resolver()->modelOf($this->minilm)] as $call) {
            try {
                $call();
                self::fail('Expected a ProviderModelReferenceException.');
            } catch (ProviderModelReferenceException $e) {
                self::assertSame(ProviderModelReferenceException::UNREGISTERED, $e->reason);
                self::assertSame($this->minilm->iri(), $e->reference);
                self::assertStringContainsString('is no registered embedding model', $e->getMessage());
            }
        }
    }

    public function testModelOfSkipsTheUsabilityChecks(): void
    {
        $this->nomic->setAvailable(false);

        self::assertSame('nomic-embed-text', $this->resolver()->modelOf($this->nomic)->name);

        $this->expectException(ProviderModelReferenceException::class);
        $this->resolver()->check($this->nomic);
    }

    public function testPrePersistResolvesTheReferenceIntoEntryConnectionLabelAndDimension(): void
    {
        $collection = new Collection()->setName('docs')->setEmbeddingModelReference(' '.$this->nomic->getId()->toRfc4122().' ');

        $this->listener()->prePersist($collection);

        self::assertSame($this->nomic, $collection->getEmbeddingProviderModel());
        self::assertSame($this->nomic->getApiConfiguration(), $collection->getEmbeddingProviderConnection());
        self::assertSame('ollama-local', $collection->getEmbeddingConnection());
        self::assertSame('nomic-embed-text', $collection->getEmbeddingModel());
        self::assertSame(768, $collection->getEmbeddingDimension());
        self::assertNull($collection->embeddingModelInput());
        self::assertSame($this->nomic->iri(), $collection->getEmbeddingModelReference(), 'The representation shows the IRI.');

        $this->listener()->postPersist($collection);
        self::assertSame([['nomic-embed-text', 768]], $this->store->state()->ensuredIndexes, 'The index is the one of the label.');
    }

    public function testPrePersistLabelsAnEntrySetByCode(): void
    {
        $collection = new Collection()->setName('docs')->setEmbeddingProviderModel($this->nomic);

        $this->listener()->prePersist($collection);

        self::assertSame('nomic-embed-text', $collection->getEmbeddingModel());
        self::assertSame(768, $collection->getEmbeddingDimension());
    }

    public function testPrePersistRefusesAFixtureWithoutEntry(): void
    {
        // Label and connection only, as before the model reference: the
        // entry column is NOT NULL.
        $collection = new Collection()->setName('docs')->setEmbeddingModel('mxbai-embed-large')->setEmbeddingProviderConnection($this->nomic->getApiConfiguration());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Collection "docs" has no embedding model; there is no default model.');

        $this->listener()->prePersist($collection);
    }

    public function testPrePersistWithoutModelFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Collection "docs" has no embedding model; there is no default model.');

        $this->listener()->prePersist(new Collection()->setName('docs'));
    }

    public function testPrePersistThrowsTheResolversRejection(): void
    {
        $this->expectException(ProviderModelReferenceException::class);

        $this->listener()->prePersist(new Collection()->setName('docs')->setEmbeddingModelReference('nomic-embed-text'));
    }

    private function resolver(): EmbeddingModelResolver
    {
        return $this->embeddingModelResolver([$this->nomic, $this->minilm], $this->registry);
    }

    private function listener(): CollectionEmbeddingListener
    {
        return new CollectionEmbeddingListener($this->registry, new EmbeddingIndexManager($this->store, $this->registry), $this->resolver());
    }
}
