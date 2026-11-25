<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:37:11 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Reindex\ReindexSourceInterface;

/**
 * In-memory documents for the kernel-free reindex tests. Each document
 * yields a fixed number of chunks, written to the shared store state
 * (deleting that model's previous rows of the document first, like the
 * real handler). Documents can be switched to fail, and a hook runs before
 * a given document is embedded (e.g. to cancel mid-run).
 */
final class InMemoryReindexSource implements ReindexSourceInterface
{
    public const string SOURCE_TYPE = 'chunk';

    /** @var list<array{string, string}> (sourceName, model) per embed() call */
    public array $embedded = [];

    /** @var list<string> name of the connection per embed() call */
    public array $connections = [];

    /** @var array<string, true> */
    private array $failing = [];

    /** @var array<string, \Closure(Collection): void> */
    private array $hooks = [];

    /**
     * @param list<string> $documents source names, in enumeration order
     */
    public function __construct(
        private readonly RecordingVectorStoreState $store,
        private readonly array $documents,
        private readonly int $chunksPerDocument = 2,
    ) {
    }

    public function fail(string $sourceName): self
    {
        $this->failing[$sourceName] = true;

        return $this;
    }

    /**
     * @param \Closure(Collection): void $hook
     */
    public function before(string $sourceName, \Closure $hook): self
    {
        $this->hooks[$sourceName] = $hook;

        return $this;
    }

    public function enumerate(Collection $collection): iterable
    {
        foreach ($this->documents as $sourceName) {
            yield [self::SOURCE_TYPE, $sourceName];
        }
    }

    public function embed(Collection $collection, string $sourceType, string $sourceName, ApiConfiguration $connection, string $model): int
    {
        if (isset($this->hooks[$sourceName])) {
            ($this->hooks[$sourceName])($collection);
        }
        $this->embedded[] = [$sourceName, $model];
        $this->connections[] = $connection->getName();

        if (isset($this->failing[$sourceName])) {
            throw new \RuntimeException(\sprintf('Embedding of %s failed.', $sourceName));
        }

        $this->store->deleteRows($model, $sourceType, $sourceName);
        $this->store->addRows($model, $sourceType, $sourceName, $this->chunksPerDocument, ['collection_id' => (string) $collection->getId()]);

        return $this->chunksPerDocument;
    }
}
