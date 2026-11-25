<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:37:11 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingIndexManager;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingsProviderLocator;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\Reindex\CollectionReindexer;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds a {@see CollectionReindexer} without a kernel: real registry,
 * locator and index manager, the fake provider factory, a
 * {@see RecordingVectorStore} and a stubbed entity manager whose
 * wrapInTransaction() runs the callable, whose refresh() keeps the
 * in-memory state and whose flush() is counted. Collections embed through
 * the (unsaved) `ollama` connection {@see $ollama}; {@see $remote} is a
 * second one. {@see entry()} gives the catalogue entry of a model on a
 * connection, one per pair; a registered label gets the entry name with
 * `:latest` (as Ollama lists it), so every test also runs through the
 * label rule (`nomic-embed-text:latest` is labelled `nomic-embed-text`).
 */
trait ReindexTestTrait
{
    private EmbeddingModelRegistry $registry;

    private RecordingVectorStore $store;

    private int $flushes = 0;

    /** @var list<Collection> entities find() can return */
    private array $collections = [];

    private ApiConfiguration $ollama;

    private ApiConfiguration $remote;

    /** @var array<string, ProviderModel> catalogue entries by connection name and label */
    private array $entries = [];

    private function setUpReindex(): void
    {
        $this->ollama = ProviderConnectionFixtures::ollama();
        $this->remote = ProviderConnectionFixtures::ollama('fake-ollama-remote', url: 'http://remote.fake.test:11434/api');
        $this->registry = new EmbeddingModelRegistry([
            'nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768, 'query_prefix' => 'search_query: ', 'document_prefix' => 'search_document: '],
            'mxbai-embed-large' => ['provider' => 'ollama', 'dimension' => 1024],
        ]);
        $this->store = new RecordingVectorStore();
        $this->flushes = 0;
        $this->collections = [];
        $this->entries = [];
    }

    /**
     * The catalogue entry of the label $label on $connection (default
     * {@see $ollama}), created once per pair: named `<label>:latest` for an
     * untagged label, as Ollama lists it.
     */
    private function entry(string $label, ?ApiConfiguration $connection = null): ProviderModel
    {
        $connection ??= $this->ollama;

        return $this->entries[$connection->getName().'|'.$label] ??= new ProviderModel($connection, str_contains($label, ':') ? $label : $label.':latest');
    }

    private function reindexer(InMemoryReindexSource $source, ?ProviderFactory $factory = null): CollectionReindexer
    {
        return new CollectionReindexer(
            $source,
            new EmbeddingsProviderLocator($factory ?? new FakeProviderFactory($this->registry), $this->registry),
            $this->registry,
            $this->store,
            new EmbeddingIndexManager($this->store, $this->registry),
            $this->entityManager(),
        );
    }

    /**
     * @param list<string> $documents
     */
    private function source(array $documents, int $chunksPerDocument = 2): InMemoryReindexSource
    {
        return new InMemoryReindexSource($this->store->state(), $documents, $chunksPerDocument);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $func): mixed => $func($entityManager),
        );
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });
        $entityManager->method('find')->willReturnCallback(function (string $class, mixed $id): ?object {
            foreach ($this->collections as $collection) {
                if ((string) $collection->getId() === (string) $id) {
                    return $collection;
                }
            }

            return null;
        });

        return $entityManager;
    }

    /**
     * A collection on the entry of $model on {@see $ollama} (or
     * $connection); a pending model is pending with its entry on
     * $pendingConnection, default {@see $ollama} (false = no connection and
     * no entry, queued before provider connections).
     */
    private function collection(string $name = 'docs-de', string $model = 'nomic-embed-text', ?string $pending = null, ?ApiConfiguration $connection = null, ApiConfiguration|false|null $pendingConnection = null): Collection
    {
        $collection = new Collection()
            ->setName($name)
            ->setEmbeddingModel($model)
            ->setEmbeddingDimension($this->registry->get($model)->dimension)
            ->setEmbeddingProviderModel($this->entry($model, $connection))
            ->setPendingEmbeddingModel($pending);
        if (null !== $pending && false !== $pendingConnection) {
            $collection->setPendingEmbeddingProviderModel($this->entry($pending, $pendingConnection));
        }
        $this->collections[] = $collection;

        return $collection;
    }
}
