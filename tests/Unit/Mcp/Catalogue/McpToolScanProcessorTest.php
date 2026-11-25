<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:41:04 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp\Catalogue;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Post;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\State\McpToolScanProcessor;
use Dmstr\NeuronAiBundle\Tests\Support\InMemoryCatalogueTrait;
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
 * Kernel-free checks of the scan request: the body is
 * validated against the bundle's scan.input.json, found through a resolver
 * chain configured like the bundle's, and the connection is resolved before
 * any job exists.
 */
final class McpToolScanProcessorTest extends TestCase
{
    use InMemoryCatalogueTrait;

    private const string ENTITY_ROOT = __DIR__.'/../../../../src/Entity';

    /** @var list<object> */
    private array $dispatched = [];

    /** @var array<string, object> */
    private array $iris = [];

    public function testSchemaIsFoundForTheScanOperation(): void
    {
        $schema = self::chain()->resolve('mcp_tool_scan');

        self::assertIsArray($schema);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame('iri-reference', $schema['properties']['apiConfiguration']['format']);
        self::assertSame('/api/admin/api_configurations?type=mcp&active=true', $schema['properties']['apiConfiguration']['x-collection']);
        self::assertNull(self::chain()->resolve('mcp_tool_sync'));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function validBodies(): iterable
    {
        yield 'empty body' => ['', []];
        yield 'empty object' => ['{}', []];
        yield 'empty string' => ['{"apiConfiguration": ""}', ['apiConfiguration' => '']];
        yield 'iri' => ['{"apiConfiguration": "/api/admin/api_configurations/0199a1b2-0000-7000-8000-000000000000"}', ['apiConfiguration' => '/api/admin/api_configurations/0199a1b2-0000-7000-8000-000000000000']];
        yield 'name' => ['{"apiConfiguration": "docs"}', ['apiConfiguration' => 'docs']];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('validBodies')]
    public function testValidBodiesPass(string $body, array $expected): void
    {
        self::assertSame($expected, $this->processor()->validate('mcp_tool_scan', $body));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'unknown property' => ['{"connection": "docs"}'];
        yield 'number' => ['{"apiConfiguration": 42}'];
        yield 'list' => ['[]'];
        yield 'scalar' => ['"docs"'];
    }

    #[DataProvider('invalidBodies')]
    public function testInvalidBodiesAreUnprocessable(string $body): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);

        $this->processor()->validate('mcp_tool_scan', $body);
    }

    public function testMalformedJsonIsABadRequest(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->processor()->validate('mcp_tool_scan', '{"apiConfiguration":');
    }

    public function testOperationWithoutSchemaIsAConfigurationError(): void
    {
        $this->expectException(\LogicException::class);

        $this->processor()->validate('mcp_tool_sync', '{}');
    }

    public function testScanOfAllConnectionsQueuesAJob(): void
    {
        $job = $this->process('');

        self::assertSame('mcp_tool_scan', $job->getType());
        self::assertSame('pending', $job->getStatus());
        self::assertSame([], $job->getInputData());
        self::assertSame([$job], $this->jobs);
        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(JobMessage::class, $this->dispatched[0]);
        self::assertSame($job->getId(), $this->dispatched[0]->getJobId());
    }

    public function testConnectionIsResolvedToItsUuid(): void
    {
        $docs = $this->addConnection('docs', 'https://docs.example.org/mcp');
        $this->iris['/api/admin/api_configurations/'.$docs->getId()] = $docs;

        $byIri = $this->process('{"apiConfiguration": "/api/admin/api_configurations/'.$docs->getId().'"}');
        $byName = $this->process('{"apiConfiguration": "docs"}');

        self::assertSame(['apiConfiguration' => (string) $docs->getId()], $byIri->getInputData());
        self::assertSame(['apiConfiguration' => (string) $docs->getId()], $byName->getInputData());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedConnections(): iterable
    {
        yield 'unknown' => ['missing', 'Unknown MCP connection'];
        yield 'inactive' => ['old', 'is inactive'];
        yield 'non-mcp iri' => ['/api/admin/api_configurations/repos', 'is not an MCP connection'];
    }

    #[DataProvider('rejectedConnections')]
    public function testRejectedConnectionCreatesNoJob(string $reference, string $message): void
    {
        $this->addConnection('old', 'https://old.example.org/mcp', active: false);
        $this->iris['/api/admin/api_configurations/repos'] = $this->addConnection('repos', 'https://git.example.org/api', type: 'github');

        try {
            $this->process(json_encode(['apiConfiguration' => $reference], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
            self::fail('Expected a 422.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }

        self::assertSame([], $this->jobs);
        self::assertSame([], $this->dispatched);
    }

    public function testInvalidBodyCreatesNoJob(): void
    {
        try {
            $this->process('{"apiConfiguration": "docs", "force": true}');
            self::fail('Expected a 422.');
        } catch (UnprocessableEntityHttpException) {
        }

        self::assertSame([], $this->jobs);
        self::assertSame([], $this->dispatched);
    }

    private function process(string $body): Job
    {
        $request = Request::create('/api/refs/mcp_tools/scan', 'POST', content: $body);

        return $this->processor()->process(null, new Post(name: 'mcp_tool_scan'), [], ['request' => $request]);
    }

    private function processor(): McpToolScanProcessor
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

        return new McpToolScanProcessor(
            self::chain(),
            new McpConnectionResolver($entityManager, $iriConverter, $uuidResolver),
            // The core's resolver instance: it does not know the bundle's
            // schema, exactly as in a host application.
            new JobQueueService($entityManager, $bus, new OperationInputSchemaResolver([], [])),
        );
    }

    private static function chain(): ChainInputSchemaResolver
    {
        return new ChainInputSchemaResolver([
            new OperationInputSchemaResolver([], [[self::ENTITY_ROOT, 'Dmstr\\NeuronAiBundle\\Entity']]),
        ]);
    }
}
