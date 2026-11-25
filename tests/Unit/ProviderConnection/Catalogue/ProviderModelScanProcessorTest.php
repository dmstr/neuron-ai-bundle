<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\ProviderConnection\Catalogue;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Post;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\OpenApi\PickerSchemaResolver;
use Dmstr\NeuronAiBundle\Provider\Connection\ProviderConnectionResolver;
use Dmstr\NeuronAiBundle\Provider\ProviderModelScanQueue;
use Dmstr\NeuronAiBundle\State\InputBodyValidator;
use Dmstr\NeuronAiBundle\State\ProviderModelScanProcessor;
use Dmstr\NeuronAiBundle\Tests\Support\InMemoryProviderCatalogueTrait;
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
 * Kernel-free checks of the model catalogue scan request: the body is
 * validated against the bundle's scan.input.json through a real
 * InputBodyValidator, the connection is resolved before any job exists,
 * and an open scan job with the same input is returned instead of a new one.
 */
final class ProviderModelScanProcessorTest extends TestCase
{
    use InMemoryProviderCatalogueTrait;

    private const string ENTITY_ROOT = __DIR__.'/../../../../src/Entity';

    /** @var list<object> */
    private array $dispatched = [];

    /** @var array<string, object> */
    private array $iris = [];

    public function testSchemaIsFoundForTheScanOperation(): void
    {
        $schema = self::resolver()->resolve('provider_model_scan');

        self::assertIsArray($schema);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['apiConfiguration'], array_keys($schema['properties']));
        self::assertSame('/api/admin/api_configurations?type[]=ollama&type[]=anthropic&active=true', $schema['properties']['apiConfiguration']['x-collection']);
        self::assertSame(ProviderModel::connectionCollectionUrl(), $schema['properties']['apiConfiguration']['x-collection']);
        self::assertFalse(self::resolver()->supports('provider_model_sync'));
    }

    public function testEmptyBodyQueuesAScanOfAllConnections(): void
    {
        $job = $this->process('');

        self::assertSame('provider_model_scan', $job->getType());
        self::assertSame('pending', $job->getStatus());
        self::assertSame([], $job->getInputData());
        self::assertSame([$job], $this->jobs);
        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(JobMessage::class, $this->dispatched[0]);
        self::assertSame($job->getId(), $this->dispatched[0]->getJobId());
    }

    public function testEmptyReferenceQueuesAScanOfAllConnections(): void
    {
        $job = $this->process('{"apiConfiguration": ""}');

        self::assertSame([], $job->getInputData());
        self::assertSame([$job], $this->jobs);
    }

    public function testConnectionIsResolvedToItsUuid(): void
    {
        $remote = $this->addOllama('remote');
        $claude = $this->addAnthropic('claude');
        $this->iris['/api/admin/api_configurations/'.$remote->getId()] = $remote;

        $byIri = $this->process('{"apiConfiguration": "/api/admin/api_configurations/'.$remote->getId().'"}');
        $byName = $this->process('{"apiConfiguration": "claude"}');

        self::assertSame(['apiConfiguration' => (string) $remote->getId()], $byIri->getInputData());
        self::assertSame(['apiConfiguration' => (string) $claude->getId()], $byName->getInputData());
    }

    public function testOpenJobWithTheSameInputIsReturned(): void
    {
        $remote = $this->addOllama('remote');

        $first = $this->process('{"apiConfiguration": "remote"}');
        $second = $this->process('{"apiConfiguration": "remote"}');
        $all = $this->process('{}');

        self::assertSame($first, $second);
        self::assertNotSame($first, $all);
        self::assertCount(2, $this->jobs);
        self::assertCount(2, $this->dispatched);
        self::assertSame(['apiConfiguration' => (string) $remote->getId()], $first->getInputData());
    }

    public function testRunningJobIsReusedByTheScanAction(): void
    {
        $this->addOllama('remote');
        $first = $this->process('{"apiConfiguration": "remote"}');
        $first->setStatus('running');

        self::assertSame($first, $this->process('{"apiConfiguration": "remote"}'));
    }

    public function testFinishedJobIsNotReused(): void
    {
        $this->addOllama('remote');
        $first = $this->process('{"apiConfiguration": "remote"}');
        $first->setStatus('completed');

        $second = $this->process('{"apiConfiguration": "remote"}');

        self::assertNotSame($first, $second);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedConnections(): iterable
    {
        yield 'unknown' => ['missing', 'Unknown provider connection'];
        yield 'inactive' => ['old', 'is inactive'];
        yield 'mcp iri' => ['/api/admin/api_configurations/docs', 'docs'];
    }

    #[DataProvider('rejectedConnections')]
    public function testRejectedConnectionCreatesNoJob(string $reference, string $message): void
    {
        $this->addOllama('old', active: false);
        $this->iris['/api/admin/api_configurations/docs'] = $this->addMcp('docs');

        try {
            $this->process(json_encode(['apiConfiguration' => $reference], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
            self::fail('Expected a 422.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }

        self::assertSame([], $this->jobs);
        self::assertSame([], $this->dispatched);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'unknown property' => ['{"connection": "remote"}'];
        yield 'number' => ['{"apiConfiguration": 42}'];
        yield 'list' => ['[]'];
    }

    #[DataProvider('invalidBodies')]
    public function testInvalidBodyIsUnprocessableWithoutJob(string $body): void
    {
        try {
            $this->process($body);
            self::fail('Expected a 422.');
        } catch (UnprocessableEntityHttpException) {
        }

        self::assertSame([], $this->jobs);
    }

    public function testMalformedJsonIsABadRequestWithoutJob(): void
    {
        try {
            $this->process('{"apiConfiguration":');
            self::fail('Expected a 400.');
        } catch (BadRequestHttpException) {
        }

        self::assertSame([], $this->jobs);
    }

    private function process(string $body): Job
    {
        $request = Request::create('/api/refs/provider_models/scan', 'POST', content: $body);

        return $this->processor()->process(null, new Post(name: 'provider_model_scan'), [], ['request' => $request]);
    }

    private function processor(): ProviderModelScanProcessor
    {
        $entityManager = $this->entityManager();

        $iriConverter = $this->createStub(IriConverterInterface::class);
        $iriConverter->method('getResourceFromIri')->willReturnCallback(
            fn (string $iri): object => $this->iris[$iri] ?? throw new ItemNotFoundException('Not found'),
        );
        $uuidResolver = $this->createStub(UuidResolver::class);
        $uuidResolver->method('findByPartialUuid')->willReturn(null);

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        return new ProviderModelScanProcessor(
            new InputBodyValidator(self::resolver()),
            new ProviderConnectionResolver($entityManager, $iriConverter, $uuidResolver),
            // The core's resolver instance: it does not know the bundle's
            // schema, exactly as in a host application.
            new ProviderModelScanQueue(new JobQueueService($entityManager, $bus, new OperationInputSchemaResolver([], [])), $entityManager),
        );
    }

    private static function resolver(): PickerSchemaResolver
    {
        return new PickerSchemaResolver(
            new OperationInputSchemaResolver([], [[self::ENTITY_ROOT, 'Dmstr\\NeuronAiBundle\\Entity']]),
            ProviderModel::OPERATION_SCAN,
            'apiConfiguration',
            ['x-collection' => ProviderModel::connectionCollectionUrl()],
        );
    }
}
