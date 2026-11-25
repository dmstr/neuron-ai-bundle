<?php

// file generated with AI assistance: Claude Code - 2026-10-08 15:38:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\ProviderConnection\Catalogue;

use Dmstr\NeuronAiBundle\Provider\Model\ModelCapability;
use Dmstr\NeuronAiBundle\Tests\Support\InMemoryProviderCatalogueTrait;
use Dmstr\NeuronAiBundle\Tests\Support\ProviderModelFixtureTrait;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of {@see ProviderModelFixtureTrait} on the in-memory
 * catalogue: one usable entry per connection and model name, reused by
 * exact or equivalent name.
 */
final class ProviderModelFixtureTraitTest extends TestCase
{
    use InMemoryProviderCatalogueTrait;
    use ProviderModelFixtureTrait;

    public function testCreatesOneEntryPerConnectionAndName(): void
    {
        $local = $this->addOllama('local');
        $remote = $this->addOllama('remote', 'https://remote.example.org/api');

        $first = self::catalogueModel($this->entityManager(), $local, 'qwen3:14b', [ModelCapability::COMPLETION, ModelCapability::TOOLS]);
        $again = self::catalogueModel($this->entityManager(), $local, 'qwen3:14b');
        $other = self::catalogueModel($this->entityManager(), $remote, 'qwen3:14b');

        self::assertSame($first, $again);
        self::assertNotSame($first, $other);
        self::assertSame(['completion', 'tools'], $again->getCapabilities(), 'null keeps the capabilities');
        self::assertNull($other->getCapabilities());
        self::assertCount(1, $this->modelsOf($local));
        self::assertCount(1, $this->modelsOf($remote));
        self::assertSame($first->iri(), self::catalogueModelIri($this->entityManager(), $local, 'qwen3:14b'));
    }

    public function testReusesAnEquivalentNameButPrefersTheExactOne(): void
    {
        $local = $this->addOllama('local');
        $latest = $this->addModel($local, 'llama3.2:latest');

        self::assertSame($latest, self::catalogueModel($this->entityManager(), $local, 'llama3.2'));

        $plain = $this->addModel($local, 'llama3.2');
        self::assertSame($plain, self::catalogueModel($this->entityManager(), $local, 'llama3.2'));
    }

    public function testMakesAReusedEntryUsableAgain(): void
    {
        $local = $this->addOllama('local');
        $model = $this->addModel($local, 'gone', [ModelCapability::COMPLETION])
            ->setAvailable(false)
            ->setLastError('Model is no longer offered by the connection')
            ->setEndpointFingerprint(str_repeat('0', 64));
        self::assertTrue($model->isStale());

        self::assertSame($model, self::catalogueModel($this->entityManager(), $local, 'gone'));
        self::assertTrue($model->isAvailable());
        self::assertNull($model->getLastError());
        self::assertFalse($model->isStale());
        self::assertSame(['completion'], $model->getCapabilities());
    }
}
