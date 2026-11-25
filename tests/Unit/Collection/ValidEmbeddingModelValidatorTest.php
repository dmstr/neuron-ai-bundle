<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:17:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Collection;

use Dmstr\NeuronAiBundle\Tests\Support\ProviderModelResolverStubTrait;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\Model\ModelCapability;
use Dmstr\NeuronAiBundle\Provider\ProviderModelReferenceException;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Validator\ValidEmbeddingModel;
use Dmstr\NeuronAiBundle\Validator\ValidEmbeddingModelValidator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * Kernel-free checks of the collection's embedding model rules: the
 * reference `embeddingModel` (IRI or UUID of a catalogue entry, resolved
 * over stubbed collaborators), the label rule (the registered name that
 * matches the entry, `nomic-embed-text` for `nomic-embed-text:latest`),
 * the dimension, and the change rules of a stored collection, with a
 * stubbed entity manager standing in for the managed (stored) state.
 *
 * Entries: `nomic-embed-text:latest`, `mxbai-embed-large` and the
 * unregistered `all-minilm` on `fake-ollama`, `nomic-embed-text:latest`
 * on `fake-ollama-remote`, the chat model `llama3.2` (capabilities known,
 * without `embedding`), an unavailable `old-embed`, one on the inactive
 * `fake-ollama-off` and one on `fake-anthropic`.
 *
 * @extends ConstraintValidatorTestCase<ValidEmbeddingModelValidator>
 */
final class ValidEmbeddingModelValidatorTest extends ConstraintValidatorTestCase
{
    use ProviderModelResolverStubTrait;

    private const string KNOWN = '"nomic-embed-text", "mxbai-embed-large"';

    private const string MODEL_CHANGE = 'Changing the embedding model requires a reindex: POST /api/admin/collections/{id}/reindex with the "model" to switch to.';

    /** @var array<int, array<string, mixed>> original entity data by spl_object_id() of managed collections */
    private array $managed = [];

    private ApiConfiguration $ollama;

    private ProviderModel $nomic;

    private ProviderModel $mxbai;

    private ProviderModel $minilm;

    private ProviderModel $remoteNomic;

    private ProviderModel $llama;

    private ProviderModel $gone;

    private ProviderModel $off;

    private ProviderModel $claude;

    protected function setUp(): void
    {
        $this->ollama = ProviderConnectionFixtures::ollama();
        $this->nomic = new ProviderModel($this->ollama, 'nomic-embed-text:latest');
        $this->mxbai = new ProviderModel($this->ollama, 'mxbai-embed-large');
        $this->minilm = new ProviderModel($this->ollama, 'all-minilm');
        $this->remoteNomic = new ProviderModel(ProviderConnectionFixtures::ollama('fake-ollama-remote', url: 'http://remote.fake.test:11434/api'), 'nomic-embed-text:latest');
        $this->llama = new ProviderModel($this->ollama, 'llama3.2')
            ->setCapabilities([ModelCapability::COMPLETION, ModelCapability::TOOLS])
            ->setSyncedAt(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $this->gone = new ProviderModel($this->ollama, 'old-embed')->setAvailable(false);
        $this->off = new ProviderModel(ProviderConnectionFixtures::ollama('fake-ollama-off', active: false), 'nomic-embed-text:latest');
        $this->claude = new ProviderModel(ProviderConnectionFixtures::anthropic(), 'claude-haiku-4-5-20251001');
        parent::setUp();
        // Class constraint on the root object: paths start at the entity.
        $this->setPropertyPath('');
    }

    protected function createValidator(): ConstraintValidatorInterface
    {
        $registry = new EmbeddingModelRegistry([
            'nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768],
            'mxbai-embed-large' => ['provider' => 'ollama', 'dimension' => 1024],
        ]);

        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('getOriginalEntityData')->willReturnCallback(
            fn (object $entity): array => $this->managed[spl_object_id($entity)] ?? [],
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('contains')->willReturnCallback(
            fn (object $entity): bool => isset($this->managed[spl_object_id($entity)]),
        );
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

        return new ValidEmbeddingModelValidator(
            $registry,
            $entityManager,
            $this->embeddingModelResolver(
                [$this->nomic, $this->mxbai, $this->minilm, $this->remoteNomic, $this->llama, $this->gone, $this->off, $this->claude],
                $registry,
            ),
        );
    }

    /**
     * @return iterable<string, array{\Closure(ProviderModel): string}>
     */
    public static function references(): iterable
    {
        yield 'iri' => [static fn (ProviderModel $entry): string => $entry->iri()];
        yield 'full uuid' => [static fn (ProviderModel $entry): string => $entry->getId()->toRfc4122()];
        yield 'partial uuid' => [static fn (ProviderModel $entry): string => substr($entry->getId()->toRfc4122(), 0, 8)];
    }

    /**
     * @param \Closure(ProviderModel): string $reference
     */
    #[DataProvider('references')]
    public function testAReferenceToARegisteredEntryIsValid(\Closure $reference): void
    {
        $this->validator->validate($this->collection($reference($this->nomic), 768), new ValidEmbeddingModel());

        $this->assertNoViolation();
    }

    public function testTheTaggedEntryMatchesTheUntaggedRegisteredName(): void
    {
        // The label of the entry nomic-embed-text:latest is nomic-embed-text.
        $collection = $this->collection($this->nomic->iri())->setEmbeddingModel('nomic-embed-text');

        $this->validator->validate($collection, new ValidEmbeddingModel());

        $this->assertNoViolation();
    }

    public function testMissingModelIsRequired(): void
    {
        $this->validator->validate(new Collection()->setName('docs'), new ValidEmbeddingModel());

        $this->buildViolation('The embedding model is required: IRI or UUID of an available entry of the model catalogue (/api/refs/provider_models) with the capability "embedding", whose name is registered in dmstr_neuron_ai.embeddings.models. Known models: {{ known }}.')
            ->setParameter('{{ known }}', self::KNOWN)
            ->setCode(ValidEmbeddingModel::MODEL_REQUIRED_ERROR)
            ->atPath(ValidEmbeddingModel::PATH)
            ->assertRaised();
    }

    /**
     * @return iterable<string, array{\Closure(self): string, string, string, string}>
     */
    public static function unusableReferences(): iterable
    {
        yield 'model name' => [static fn (): string => 'nomic-embed-text', ProviderModelReferenceException::UNKNOWN, ValidEmbeddingModel::INVALID_MODEL_ERROR, 'Unknown model "nomic-embed-text"'];
        yield 'unknown iri' => [static fn (): string => '/api/refs/provider_models/0199a1b2-0000-4000-8000-000000000000', ProviderModelReferenceException::UNKNOWN, ValidEmbeddingModel::INVALID_MODEL_ERROR, 'Unknown model'];
        yield 'unavailable' => [static fn (self $test): string => $test->gone->iri(), ProviderModelReferenceException::UNAVAILABLE, ValidEmbeddingModel::INVALID_MODEL_ERROR, 'is no longer offered'];
        yield 'inactive connection' => [static fn (self $test): string => $test->off->iri(), ProviderModelReferenceException::INACTIVE, ValidEmbeddingModel::INVALID_MODEL_ERROR, 'the connection is inactive'];
        yield 'anthropic connection' => [static fn (self $test): string => $test->claude->iri(), ProviderModelReferenceException::MISSING_CAPABILITY, ValidEmbeddingModel::INVALID_MODEL_ERROR, 'cannot serve "embedding"'];
        yield 'chat model' => [static fn (self $test): string => $test->llama->iri(), ProviderModelReferenceException::MISSING_CAPABILITY, ValidEmbeddingModel::INVALID_MODEL_ERROR, 'lacks the capability "embedding"'];
        yield 'unregistered name' => [static fn (self $test): string => $test->minilm->iri(), ProviderModelReferenceException::UNREGISTERED, ValidEmbeddingModel::UNKNOWN_MODEL_ERROR, 'Model "all-minilm" of connection "fake-ollama" is no registered embedding model; register it (with or without the tag ":latest") under dmstr_neuron_ai.embeddings.models. Known models: '.self::KNOWN.'.'];
    }

    /**
     * @param \Closure(self): string $reference
     */
    #[DataProvider('unusableReferences')]
    public function testAnUnusableReferenceIsReportedAtTheModel(\Closure $reference, string $reason, string $code, string $message): void
    {
        $given = $reference($this);

        $this->validator->validate($this->collection($given), new ValidEmbeddingModel());

        $violation = $this->onlyViolation();
        self::assertSame(ValidEmbeddingModel::PATH, $violation->getPropertyPath());
        self::assertSame($code, $violation->getCode());
        self::assertSame($reason, $violation->getParameters()['{{ reason }}'] ?? null);
        self::assertSame($given, $violation->getInvalidValue());
        self::assertStringContainsString($message, (string) $violation->getMessage());
    }

    public function testAnEntrySetByCodeMustBeUsable(): void
    {
        $this->validator->validate($this->collection(entry: $this->off), new ValidEmbeddingModel());

        $violation = $this->onlyViolation();
        self::assertSame(ValidEmbeddingModel::PATH, $violation->getPropertyPath());
        self::assertSame(ProviderModelReferenceException::INACTIVE, $violation->getParameters()['{{ reason }}'] ?? null);
    }

    public function testAnEntrySetByCodeWithItsLabelIsValid(): void
    {
        $this->validator->validate($this->collection(entry: $this->nomic)->setEmbeddingModel('nomic-embed-text'), new ValidEmbeddingModel());

        $this->assertNoViolation();
    }

    public function testALabelSetByCodeMustMatchTheEntry(): void
    {
        $this->validator->validate($this->collection(entry: $this->nomic)->setEmbeddingModel('mxbai-embed-large'), new ValidEmbeddingModel());

        $this->buildViolation('The embedding model name {{ given }} does not match the catalogue entry {{ entry }}, registered as {{ expected }}.')
            ->setParameter('{{ given }}', '"mxbai-embed-large"')
            ->setParameter('{{ entry }}', '"nomic-embed-text:latest"')
            ->setParameter('{{ expected }}', '"nomic-embed-text"')
            ->setCode(ValidEmbeddingModel::INVALID_MODEL_ERROR)
            ->atPath(ValidEmbeddingModel::PATH)
            ->assertRaised();
    }

    public function testAReferenceNextToAnEntrySetByCodeMustNameIt(): void
    {
        $this->validator->validate($this->collection($this->mxbai->iri(), entry: $this->nomic), new ValidEmbeddingModel());

        $this->assertModelChange();
    }

    public function testWrongDimensionIsReportedOnDimension(): void
    {
        $this->validator->validate($this->collection($this->nomic->iri(), 1024), new ValidEmbeddingModel());

        $this->buildViolation('The embedding dimension {{ given }} does not match the dimension {{ expected }} of the embedding model {{ model }}.')
            ->setParameter('{{ given }}', '1024')
            ->setParameter('{{ expected }}', '768')
            ->setParameter('{{ model }}', '"nomic-embed-text"')
            ->setCode(ValidEmbeddingModel::DIMENSION_MISMATCH_ERROR)
            ->atPath('embeddingDimension')
            ->assertRaised();
    }

    public function testUnchangedModelOfAStoredCollectionIsValid(): void
    {
        $collection = $this->stored($this->mxbai, 'mxbai-embed-large', 1024);
        $collection->setDescription('neu');

        $this->validator->validate($collection, new ValidEmbeddingModel());

        $this->assertNoViolation();
    }

    /**
     * A client may send back the reference it read.
     *
     * @param \Closure(ProviderModel): string $reference
     */
    #[DataProvider('references')]
    public function testAStoredCollectionAcceptsItsOwnEntryAsReference(\Closure $reference): void
    {
        $collection = $this->stored($this->nomic, 'nomic-embed-text', 768);
        $collection->setEmbeddingModelReference($reference($this->nomic));

        $this->validator->validate($collection, new ValidEmbeddingModel());

        $this->assertNoViolation();
    }

    public function testAStoredCollectionAcceptsItsUnavailableEntryByIriWithoutALookup(): void
    {
        $this->nomic->setAvailable(false);
        $collection = $this->stored($this->nomic, 'nomic-embed-text', 768);
        $collection->setEmbeddingModelReference($this->nomic->iri());

        $this->validator->validate($collection, new ValidEmbeddingModel());

        $this->assertNoViolation();
    }

    /**
     * @return iterable<string, array{\Closure(self): ProviderModel}>
     */
    public static function otherEntries(): iterable
    {
        yield 'another model' => [static fn (self $test): ProviderModel => $test->mxbai];
        yield 'the same label on another connection' => [static fn (self $test): ProviderModel => $test->remoteNomic];
    }

    /**
     * @param \Closure(self): ProviderModel $other
     */
    #[DataProvider('otherEntries')]
    public function testAnotherEntryForAStoredCollectionRequiresAReindex(\Closure $other): void
    {
        $collection = $this->stored($this->nomic, 'nomic-embed-text', 768);
        $collection->setEmbeddingModelReference($other($this)->iri());

        $this->validator->validate($collection, new ValidEmbeddingModel());

        $this->assertModelChange();
    }

    public function testAnEntryChangedByCodeOnAStoredCollectionRequiresAReindex(): void
    {
        $collection = $this->stored($this->nomic, 'nomic-embed-text', 768);
        $collection->setEmbeddingProviderModel($this->remoteNomic);

        $this->validator->validate($collection, new ValidEmbeddingModel());

        $this->assertModelChange();
    }

    public function testALabelChangedByCodeOnAStoredCollectionRequiresAReindex(): void
    {
        $collection = $this->stored($this->nomic, 'nomic-embed-text', 768);
        $collection->setEmbeddingModel('mxbai-embed-large');

        $this->validator->validate($collection, new ValidEmbeddingModel());

        $this->assertModelChange();
    }

    public function testAnUnknownReferenceForAStoredCollectionNamesTheReference(): void
    {
        $collection = $this->stored($this->nomic, 'nomic-embed-text', 768);
        $collection->setEmbeddingModelReference('nomic-embed-text');

        $this->validator->validate($collection, new ValidEmbeddingModel());

        $violation = $this->onlyViolation();
        self::assertSame(ValidEmbeddingModel::PATH, $violation->getPropertyPath());
        self::assertSame(ProviderModelReferenceException::UNKNOWN, $violation->getParameters()['{{ reason }}'] ?? null);
    }

    public function testNullIsIgnored(): void
    {
        $this->validator->validate(null, new ValidEmbeddingModel());

        $this->assertNoViolation();
    }

    private function assertModelChange(): void
    {
        $this->buildViolation(self::MODEL_CHANGE)
            ->setCode(ValidEmbeddingModel::MODEL_CHANGE_ERROR)
            ->atPath(ValidEmbeddingModel::PATH)
            ->assertRaised();
    }

    private function onlyViolation(): ConstraintViolationInterface
    {
        $violations = $this->context->getViolations();
        self::assertCount(1, $violations);

        return $violations[0];
    }

    /**
     * A new collection with the reference $reference, or with the entry
     * $entry set by code.
     */
    private function collection(?string $reference = null, ?int $dimension = null, ?ProviderModel $entry = null): Collection
    {
        $collection = new Collection()
            ->setName('docs')
            ->setEmbeddingModelReference($reference);
        if (null !== $entry) {
            $collection->setEmbeddingProviderModel($entry);
        }

        return null !== $dimension ? $collection->setEmbeddingDimension($dimension) : $collection;
    }

    /**
     * A managed collection loaded with $entry (every stored collection has
     * one: `embedding_model_id` is NOT NULL), $label and $dimension.
     */
    private function stored(ProviderModel $entry, string $label, int $dimension): Collection
    {
        $collection = new Collection()
            ->setName('docs')
            ->setEmbeddingModel($label)
            ->setEmbeddingDimension($dimension)
            ->setEmbeddingProviderModel($entry);
        $this->managed[spl_object_id($collection)] = [
            'embeddingModel' => $label,
            'embeddingDimension' => $dimension,
            'embeddingProviderModel' => $entry,
            'embeddingProviderConnection' => $collection->getEmbeddingProviderConnection(),
        ];

        return $collection;
    }
}
