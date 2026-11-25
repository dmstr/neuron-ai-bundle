<?php

// file generated with AI assistance: Claude Code - 2026-10-08 20:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelResolver;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\ProviderModelResolver;

/**
 * Kernel-free {@see ProviderModelResolver} and {@see EmbeddingModelResolver}
 * over a fixed list of catalogue entries: an IRI resolves through a stubbed
 * IRI converter, a full or partial UUID through a stubbed UUID resolver
 * (prefix match, the first entry wins). For tests of the callers of the
 * resolvers; the resolver's own rules are covered by
 * {@see ProviderModelResolverTest}.
 */
trait ProviderModelResolverStubTrait
{
    /**
     * @param list<ProviderModel> $entries
     */
    private function providerModelResolver(array $entries): ProviderModelResolver
    {
        $iriConverter = $this->createStub(IriConverterInterface::class);
        $iriConverter->method('getResourceFromIri')->willReturnCallback(
            static fn (string $iri): ProviderModel => array_find($entries, static fn (ProviderModel $entry): bool => $entry->iri() === $iri)
                ?? throw new ItemNotFoundException('Not found'),
        );

        $uuidResolver = $this->createStub(UuidResolver::class);
        $uuidResolver->method('findByPartialUuid')->willReturnCallback(
            static fn (string $class, string $id): ?ProviderModel => array_find(
                $entries,
                static fn (ProviderModel $entry): bool => str_starts_with($entry->getId()->toRfc4122(), strtolower($id)),
            ),
        );

        return new ProviderModelResolver($iriConverter, $uuidResolver);
    }

    /**
     * @param list<ProviderModel> $entries
     */
    private function embeddingModelResolver(array $entries, EmbeddingModelRegistry $registry): EmbeddingModelResolver
    {
        return new EmbeddingModelResolver($this->providerModelResolver($entries), $registry);
    }
}
