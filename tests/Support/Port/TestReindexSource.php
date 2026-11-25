<?php

// file generated with AI assistance: Claude Code - 2026-11-25 21:50:20 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support\Port;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Reindex\ReindexSourceInterface;

/**
 * The host's documents of a collection for the bundle's reindex
 * ({@see ReindexSourceInterface}, a mandatory port), scripted per process:
 * every collection enumerates the source names of {@see documents()} (none
 * by default), embed() records the call and reports
 * {@see $chunksPerDocument} chunks without writing to the store, and a
 * source name switched by {@see fail()} throws.
 *
 * State is static, so it survives the kernel reboots of the test client;
 * {@see TestPorts::reset()} (run after every test by
 * {@see \Dmstr\NeuronAiBundle\Tests\Support\ResetTestPortsExtension}) clears it.
 */
final class TestReindexSource implements ReindexSourceInterface
{
    public const string SOURCE_TYPE = 'chunk';

    /** @var list<string> */
    private static array $documents = [];

    private static int $chunksPerDocument = 1;

    /** @var array<string, true> */
    private static array $failing = [];

    /** @var list<array{collection: string, sourceType: string, sourceName: string, connection: string, model: string}> */
    private static array $embedded = [];

    /**
     * @param list<string> $sourceNames enumerated for every collection, in this order
     */
    public static function documents(array $sourceNames, int $chunksPerDocument = 1): void
    {
        self::$documents = $sourceNames;
        self::$chunksPerDocument = $chunksPerDocument;
    }

    public static function fail(string $sourceName): void
    {
        self::$failing[$sourceName] = true;
    }

    /**
     * The embed() calls since the last reset: collection name, source, name
     * of the connection and model.
     *
     * @return list<array{collection: string, sourceType: string, sourceName: string, connection: string, model: string}>
     */
    public static function embedded(): array
    {
        return self::$embedded;
    }

    public static function reset(): void
    {
        self::$documents = [];
        self::$chunksPerDocument = 1;
        self::$failing = [];
        self::$embedded = [];
    }

    public function enumerate(Collection $collection): iterable
    {
        foreach (self::$documents as $sourceName) {
            yield [self::SOURCE_TYPE, $sourceName];
        }
    }

    public function embed(Collection $collection, string $sourceType, string $sourceName, ApiConfiguration $connection, string $model): int
    {
        self::$embedded[] = [
            'collection' => (string) $collection->getName(),
            'sourceType' => $sourceType,
            'sourceName' => $sourceName,
            'connection' => (string) $connection->getName(),
            'model' => $model,
        ];
        if (isset(self::$failing[$sourceName])) {
            throw new \RuntimeException(\sprintf('Embedding of %s failed.', $sourceName));
        }

        return self::$chunksPerDocument;
    }
}
