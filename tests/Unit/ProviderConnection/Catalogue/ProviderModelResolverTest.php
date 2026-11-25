<?php

// file generated with AI assistance: Claude Code - 2026-10-08 15:38:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\ProviderConnection\Catalogue;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\Model\ModelCapability;
use Dmstr\NeuronAiBundle\Provider\ProviderModelReferenceException;
use Dmstr\NeuronAiBundle\Provider\ProviderModelResolver;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\InMemoryProviderCatalogueTrait;
use Dmstr\NeuronAiBundle\Tests\Support\ProviderModelFixtureTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Kernel-free checks of the model references (IRI, full and partial UUID)
 * and their rejection reasons (empty, unknown, ambiguous, unavailable,
 * inactive connection, missing capability), with stubbed collaborators and
 * catalogue entries from {@see ProviderModelFixtureTrait}.
 */
final class ProviderModelResolverTest extends TestCase
{
    use InMemoryProviderCatalogueTrait;
    use ProviderModelFixtureTrait;

    /** @var array<string, object> */
    private array $iris = [];

    private ?\Closure $uuidLookup = null;

    public function testResolvesByIriFullAndPartialUuid(): void
    {
        $model = $this->chatModel();

        self::assertSame($model, $this->resolver()->resolve($model->iri(), ModelCapability::COMPLETION));
        self::assertSame($model, $this->resolver()->resolve(' '.$model->getId()->toRfc4122().' ', ModelCapability::COMPLETION));
        self::assertSame($model, $this->resolver()->resolve(substr($model->getId()->toRfc4122(), 0, 8), ModelCapability::COMPLETION));
    }

    public function testMissingReferenceNamesTheCatalogueAndTheCapability(): void
    {
        foreach ([null, '', '  '] as $reference) {
            $e = $this->rejection(fn () => $this->resolver()->resolve($reference, ModelCapability::COMPLETION));

            self::assertSame(ProviderModelReferenceException::EMPTY, $e->reason);
            self::assertSame(ProviderModelResolver::requiredMessage(ModelCapability::COMPLETION), $e->getMessage());
            self::assertSame('A model is required: IRI or UUID of an available entry of the model catalogue (/api/refs/provider_models) with the capability "completion".', $e->getMessage());
            self::assertInstanceOf(UnprocessableEntityHttpException::class, $e);
            self::assertSame(422, $e->getStatusCode());
        }
    }

    public function testNamesUnknownIrisAndOtherResourcesAreUnknown(): void
    {
        $this->chatModel();
        $connection = ProviderConnectionFixtures::ollama('local');
        $this->iris['/api/admin/api_configurations/'.$connection->getId()] = $connection;

        foreach (['llama3.2', '/api/refs/provider_models/nope', '/api/admin/api_configurations/'.$connection->getId(), '0123abcd'] as $reference) {
            $e = $this->rejection(fn () => $this->resolver()->resolve($reference, ModelCapability::COMPLETION));

            self::assertSame(ProviderModelReferenceException::UNKNOWN, $e->reason, $reference);
            self::assertSame(\sprintf('Unknown model "%s": expected the IRI or UUID of an entry of the model catalogue (/api/refs/provider_models).', $reference), $e->getMessage());
        }
    }

    public function testAmbiguousPartialUuidIsRejected(): void
    {
        $this->uuidLookup = static fn (): never => throw new \RuntimeException('Multiple entities match');

        $e = $this->rejection(fn () => $this->resolver()->resolve('0123abcd', ModelCapability::COMPLETION));

        self::assertSame(ProviderModelReferenceException::AMBIGUOUS, $e->reason);
        self::assertSame('Model reference "0123abcd" is ambiguous; give more digits of the UUID.', $e->getMessage());
    }

    public function testUnavailableModelIsRejected(): void
    {
        $model = $this->chatModel()->setAvailable(false);

        $e = $this->rejection(fn () => $this->resolver()->resolve($model->iri(), ModelCapability::COMPLETION));

        self::assertSame(ProviderModelReferenceException::UNAVAILABLE, $e->reason);
        self::assertSame('Model "llama3.2:latest" of connection "local" is no longer offered by the connection; choose another model or scan the catalogue again.', $e->getMessage());
    }

    public function testModelOfAnInactiveConnectionIsRejected(): void
    {
        $model = $this->chatModel();
        $model->getApiConfiguration()->setActive(false);

        $e = $this->rejection(fn () => $this->resolver()->resolve($model->iri(), ModelCapability::COMPLETION));

        self::assertSame(ProviderModelReferenceException::INACTIVE, $e->reason);
        self::assertSame('Model "llama3.2:latest" of connection "local": the connection is inactive.', $e->getMessage());
    }

    public function testKnownMissingCapabilityIsRejected(): void
    {
        $embedder = $this->register(self::catalogueModel($this->entityManager(), $this->addOllama('local'), 'nomic-embed-text:latest', [ModelCapability::EMBEDDING]));

        $e = $this->rejection(fn () => $this->resolver()->resolve($embedder->iri(), ModelCapability::COMPLETION));

        self::assertSame(ProviderModelReferenceException::MISSING_CAPABILITY, $e->reason);
        self::assertSame('Model "nomic-embed-text:latest" of connection "local" lacks the capability "completion" (known: embedding).', $e->getMessage());
        self::assertSame($embedder, $this->resolver()->resolve($embedder->iri(), ModelCapability::EMBEDDING));
    }

    public function testUnknownCapabilitiesAndStaleEntriesPass(): void
    {
        $local = $this->addOllama('local');
        $unknown = $this->register(self::catalogueModel($this->entityManager(), $local, 'never-described'));
        $stale = $this->register(self::catalogueModel($this->entityManager(), $local, 'moved', [ModelCapability::EMBEDDING]));
        $stale->setEndpointFingerprint(str_repeat('0', 64));

        self::assertSame($unknown, $this->resolver()->resolve($unknown->iri(), ModelCapability::COMPLETION));
        self::assertSame($stale, $this->resolver()->resolve($stale->iri(), ModelCapability::COMPLETION));
    }

    public function testEmbeddingNeedsAnEmbeddingConnectionType(): void
    {
        $claude = $this->register(self::catalogueModel($this->entityManager(), $this->addAnthropic('claude'), 'claude-haiku-4-5-20251001'));

        $e = $this->rejection(fn () => $this->resolver()->resolve($claude->iri(), ModelCapability::EMBEDDING));

        self::assertSame(ProviderModelReferenceException::MISSING_CAPABILITY, $e->reason);
        self::assertSame('Model "claude-haiku-4-5-20251001" of connection "claude": a connection of type "anthropic" cannot serve "embedding" (expected: ollama).', $e->getMessage());
        self::assertSame($claude, $this->resolver()->resolve($claude->iri(), ModelCapability::COMPLETION));
    }

    public function testResolveFieldPutsTheViolationAtTheField(): void
    {
        $model = $this->chatModel()->setAvailable(false);
        $root = new \stdClass();

        try {
            $this->resolver()->resolveField($model->iri(), ModelCapability::COMPLETION, 'candidate/model', $root);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
            $violations = $e->getConstraintViolationList();
            self::assertCount(1, $violations);
            self::assertSame('candidate/model', $violations[0]->getPropertyPath());
            self::assertSame($model->iri(), $violations[0]->getInvalidValue());
            self::assertSame($root, $violations[0]->getRoot());
            self::assertStringContainsString('is no longer offered by the connection', (string) $violations[0]->getMessage());
        }

        self::assertSame($model->setAvailable(true), $this->resolver()->resolveField($model->iri(), ModelCapability::COMPLETION, 'model'));
    }

    public function testUnknownCapabilityIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resolver()->resolve('0123abcd', 'telepathy');
    }

    private function chatModel(): ProviderModel
    {
        return $this->register(self::catalogueModel($this->entityManager(), $this->addOllama('local'), 'llama3.2:latest', [ModelCapability::COMPLETION, ModelCapability::TOOLS]));
    }

    /**
     * Makes the entry resolvable by its IRI and its (partial) UUID.
     */
    private function register(ProviderModel $model): ProviderModel
    {
        $this->iris[$model->iri()] = $model;
        $this->uuidLookup = fn (string $id): ?ProviderModel => array_find(
            $this->models,
            static fn (ProviderModel $row): bool => str_starts_with($row->getId()->toRfc4122(), strtolower($id)),
        );

        return $model;
    }

    private function rejection(callable $call): ProviderModelReferenceException
    {
        try {
            $call();
        } catch (ProviderModelReferenceException $e) {
            return $e;
        }

        self::fail('Expected a ProviderModelReferenceException.');
    }

    private function resolver(): ProviderModelResolver
    {
        $iriConverter = $this->createStub(IriConverterInterface::class);
        $iriConverter->method('getResourceFromIri')->willReturnCallback(
            fn (string $iri): object => $this->iris[$iri] ?? throw new ItemNotFoundException('Not found'),
        );

        $uuidResolver = $this->createStub(UuidResolver::class);
        $uuidResolver->method('findByPartialUuid')->willReturnCallback(
            fn (string $class, string $id): ?object => null !== $this->uuidLookup ? ($this->uuidLookup)($id) : null,
        );

        return new ProviderModelResolver($iriConverter, $uuidResolver);
    }
}
