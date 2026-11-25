<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:37:11 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Reindex;

use ApiPlatform\Metadata\Post;
use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\NeuronAiBundle\Tests\Support\ProviderModelResolverStubTrait;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\OpenApi\PickerSchemaResolver;
use Dmstr\NeuronAiBundle\Provider\Model\ModelCapability;
use Dmstr\NeuronAiBundle\State\CollectionReindexCancelProcessor;
use Dmstr\NeuronAiBundle\State\CollectionReindexProcessor;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\ReindexTestTrait;
use Dmstr\OpenApiJsonSchema\Service\ChainInputSchemaResolver;
use Dmstr\OpenApiJsonSchema\Service\OperationInputSchemaResolver;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Message\JobMessage;
use Dmstr\SymfonyJobQueue\Service\JobQueueService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Kernel-free checks of the reindex request: the body is validated against
 * the bundle's Collection/reindex.input.json (found through a resolver
 * chain configured like the bundle's, with the model picker on top), the
 * target `model` is resolved as a catalogue reference over the entries of
 * {@see ReindexTestTrait::entry()} (plus a chat model, an unavailable one
 * and one on an inactive connection), the reindexer's preconditions map
 * to 422 with the violation at `model`, `connection` is rejected at
 * `connection`, and a valid request queues a job whose id ends up on the
 * collection.
 */
final class CollectionReindexProcessorTest extends TestCase
{
    use ProviderModelResolverStubTrait;
    use ReindexTestTrait;

    private const string ENTITY_ROOT = __DIR__.'/../../../src/Entity';

    /** @var list<object> */
    private array $dispatched = [];

    /** @var array<string, ProviderModel> entries the resolver knows beside those of the trait */
    private array $extra = [];

    protected function setUp(): void
    {
        $this->setUpReindex();
        $this->extra = [
            'minilm' => new ProviderModel($this->ollama, 'all-minilm'),
            'llama' => new ProviderModel($this->ollama, 'llama3.2')
                ->setCapabilities([ModelCapability::COMPLETION])
                ->setSyncedAt(new \DateTimeImmutable('2026-01-01T00:00:00+00:00')),
            'gone' => new ProviderModel($this->ollama, 'old-embed')->setAvailable(false),
            'off' => new ProviderModel(ProviderConnectionFixtures::ollama('fake-ollama-off', active: false), 'mxbai-embed-large'),
        ];
    }

    public function testSchemaIsFoundForTheReindexOperationOnly(): void
    {
        $schema = self::chain()->resolve('collection_reindex');

        self::assertIsArray($schema);
        self::assertSame(['model'], $schema['required']);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(190, $schema['properties']['model']['maxLength']);
        self::assertSame(['model'], array_keys($schema['properties']), 'connection is gone: the entry names it.');
        self::assertSame(ProviderModel::EMBEDDING_MODEL_COLLECTION_URL, $schema['properties']['model']['x-collection'], 'The picker of the embedding models.');
        self::assertSame(ProviderModel::modelCollectionUrl(ModelCapability::EMBEDDING), ProviderModel::EMBEDDING_MODEL_COLLECTION_URL);
        self::assertNull(self::chain()->resolve('collection_reindex_cancel'));
    }

    public function testValidBodyPasses(): void
    {
        self::assertSame(['model' => '/api/refs/provider_models/x'], $this->processor()->validate('collection_reindex', '{"model": "/api/refs/provider_models/x"}'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'empty body' => [''];
        yield 'missing model' => ['{}'];
        yield 'extra property' => ['{"model": "mxbai-embed-large", "force": true}'];
        yield 'empty model' => ['{"model": ""}'];
        yield 'number' => ['{"model": 42}'];
        yield 'list' => ['[]'];
    }

    #[DataProvider('invalidBodies')]
    public function testInvalidBodiesAreUnprocessable(string $body): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);

        $this->processor()->validate('collection_reindex', $body);
    }

    public function testMalformedJsonIsABadRequest(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->processor()->validate('collection_reindex', '{"model":');
    }

    /**
     * @return iterable<string, array{\Closure(self): string}>
     */
    public static function references(): iterable
    {
        yield 'iri' => [static fn (self $test): string => $test->entry('mxbai-embed-large')->iri()];
        yield 'full uuid' => [static fn (self $test): string => $test->entry('mxbai-embed-large')->getId()->toRfc4122()];
        yield 'partial uuid' => [static fn (self $test): string => substr($test->entry('mxbai-embed-large')->getId()->toRfc4122(), 0, 8)];
    }

    /**
     * @param \Closure(self): string $reference
     */
    #[DataProvider('references')]
    public function testValidRequestQueuesAJobAndStoresItsId(\Closure $reference): void
    {
        $collection = $this->collection();
        $target = $this->entry('mxbai-embed-large');

        $job = $this->process($collection, json_encode(['model' => $reference($this)], \JSON_THROW_ON_ERROR));

        self::assertSame('collection_reindex', $job->getType());
        self::assertSame(['collection' => (string) $collection->getId(), 'model' => 'mxbai-embed-large', 'providerModel' => $target->getId()->toRfc4122()], $job->getInputData());
        self::assertSame('mxbai-embed-large', $collection->getPendingEmbeddingModel());
        self::assertSame($target, $collection->getPendingEmbeddingProviderModel());
        self::assertSame($this->ollama, $collection->getPendingEmbeddingProviderConnection());
        self::assertSame($job->getId(), $collection->getReindexJobId());
        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(JobMessage::class, $this->dispatched[0]);
    }

    public function testAModelOfAnotherConnectionQueuesAJob(): void
    {
        $collection = $this->collection();
        $target = $this->entry('mxbai-embed-large', $this->remote);

        $job = $this->process($collection, json_encode(['model' => $target->iri()], \JSON_THROW_ON_ERROR));

        self::assertSame($target->getId()->toRfc4122(), $job->getInputData()['providerModel']);
        self::assertSame($this->remote, $collection->getPendingEmbeddingProviderConnection());
        self::assertSame($this->ollama, $collection->getEmbeddingProviderConnection());
    }

    public function testConnectionIsRejectedAtConnection(): void
    {
        $collection = $this->collection();

        foreach (['{"model": "mxbai-embed-large", "connection": "fake-ollama-remote"}', '{"connection": null}'] as $body) {
            try {
                $this->process($collection, $body);
                self::fail('Expected a 422.');
            } catch (ValidationException $e) {
                $violations = $e->getConstraintViolationList();
                self::assertCount(1, $violations);
                self::assertSame('connection', $violations[0]->getPropertyPath());
                self::assertSame(CollectionReindexProcessor::CONNECTION_REMOVED, (string) $violations[0]->getMessage());
            }
        }

        self::assertSame([], $this->dispatched);
        self::assertNull($collection->getPendingEmbeddingModel());
    }

    /**
     * @return iterable<string, array{\Closure(self): string, ?string, string}>
     */
    public static function rejectedModels(): iterable
    {
        yield 'model name' => [static fn (): string => 'mxbai-embed-large', null, 'Unknown model "mxbai-embed-large"'];
        yield 'unregistered' => [static fn (self $test): string => $test->extra['minilm']->iri(), null, 'is no registered embedding model'];
        yield 'chat model' => [static fn (self $test): string => $test->extra['llama']->iri(), null, 'lacks the capability "embedding"'];
        yield 'unavailable' => [static fn (self $test): string => $test->extra['gone']->iri(), null, 'is no longer offered'];
        yield 'inactive connection' => [static fn (self $test): string => $test->extra['off']->iri(), null, 'the connection is inactive'];
        yield 'current' => [static fn (self $test): string => $test->entry('nomic-embed-text')->iri(), null, 'already uses'];
        yield 'current label on another connection' => [static fn (self $test): string => $test->entry('nomic-embed-text', $test->remote)->iri(), null, 'a different connection needs a different model as well'];
        yield 'other pending' => [static fn (self $test): string => $test->entry('mxbai-embed-large')->iri(), 'other-model', 'cancel it first'];
    }

    /**
     * @param \Closure(self): string $reference
     */
    #[DataProvider('rejectedModels')]
    public function testRejectedModelIsAViolationAtModelAndCreatesNoJob(\Closure $reference, ?string $pending, string $message): void
    {
        $collection = $this->collection(pending: $pending);
        $given = $reference($this);

        try {
            $this->process($collection, json_encode(['model' => $given], \JSON_THROW_ON_ERROR));
            self::fail('Expected a 422.');
        } catch (ValidationException $e) {
            $violations = $e->getConstraintViolationList();
            self::assertCount(1, $violations);
            self::assertSame('model', $violations[0]->getPropertyPath());
            self::assertSame($given, $violations[0]->getInvalidValue());
            self::assertStringContainsString($message, (string) $violations[0]->getMessage());
        }

        self::assertSame([], $this->dispatched);
        self::assertSame($pending, $collection->getPendingEmbeddingModel());
    }

    public function testCancelReturnsTheCollection(): void
    {
        $collection = $this->collection(pending: 'mxbai-embed-large');
        $processor = new CollectionReindexCancelProcessor($this->reindexer($this->source([])));

        self::assertSame($collection, $processor->process($collection, new Post(name: 'collection_reindex_cancel')));
        self::assertNull($collection->getPendingEmbeddingModel());
    }

    public function testCancelWithoutPendingModelIsUnprocessable(): void
    {
        $processor = new CollectionReindexCancelProcessor($this->reindexer($this->source([])));

        $this->expectException(UnprocessableEntityHttpException::class);

        $processor->process($this->collection(), new Post(name: 'collection_reindex_cancel'));
    }

    private function process(object $collection, string $body): Job
    {
        $request = Request::create('/api/admin/collections/x/reindex', 'POST', content: $body);

        return $this->processor()->process($collection, new Post(name: 'collection_reindex'), [], ['request' => $request]);
    }

    private function processor(): CollectionReindexProcessor
    {
        $entityManager = $this->entityManager();

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        return new CollectionReindexProcessor(
            self::chain(),
            $this->reindexer($this->source([])),
            new JobQueueService($entityManager, $bus, new OperationInputSchemaResolver([], [])),
            $entityManager,
            $this->providerModelResolver([
                $this->entry('nomic-embed-text'),
                $this->entry('mxbai-embed-large'),
                $this->entry('nomic-embed-text', $this->remote),
                $this->entry('mxbai-embed-large', $this->remote),
                ...array_values($this->extra),
            ]),
        );
    }

    /**
     * The resolver chain of the bundle: the model picker on top of the
     * file resolver.
     */
    private static function chain(): ChainInputSchemaResolver
    {
        $files = new OperationInputSchemaResolver([], [[self::ENTITY_ROOT, 'Dmstr\\NeuronAiBundle\\Entity']]);

        return new ChainInputSchemaResolver([
            new PickerSchemaResolver($files, Collection::OPERATION_REINDEX, 'model', ProviderModel::EMBEDDING_MODEL_PICKER),
            $files,
        ]);
    }
}
