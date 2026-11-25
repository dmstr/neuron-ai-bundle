<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:22:22 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Kernel-free checks of the reference resolution with stubbed
 * collaborators; the database-backed paths run in the functional tests.
 */
final class McpConnectionResolverTest extends TestCase
{
    /** @var list<ApiConfiguration> */
    private array $stored = [];

    /** @var array<string, object> */
    private array $iris = [];

    private ?\Closure $uuidLookup = null;

    public function testResolvesByName(): void
    {
        $docs = self::connection('mcp', 'docs');
        $this->stored = [$docs, self::connection('github', 'docs')];

        self::assertSame($docs, $this->resolver()->resolve('docs'));
    }

    public function testResolvesByIri(): void
    {
        $docs = self::connection('mcp', 'docs');
        $this->iris['/api/admin/api_configurations/'.$docs->getId()] = $docs;

        self::assertSame($docs, $this->resolver()->resolve('/api/admin/api_configurations/'.$docs->getId()));
    }

    public function testResolvesByPartialUuid(): void
    {
        $docs = self::connection('mcp', 'docs');
        $this->uuidLookup = static fn (string $id): ?ApiConfiguration => str_starts_with((string) $docs->getId(), $id) ? $docs : null;

        self::assertSame($docs, $this->resolver()->resolve(substr((string) $docs->getId(), 0, 8)));
    }

    public function testHexLikeNameFallsBackToNameLookup(): void
    {
        $cafe = self::connection('mcp', 'cafebabe');
        $this->stored = [$cafe];
        $this->uuidLookup = static fn (): ?ApiConfiguration => null;

        self::assertSame($cafe, $this->resolver()->resolve('cafebabe'));
    }

    public function testResolveManyDeduplicates(): void
    {
        $docs = self::connection('mcp', 'docs');
        $this->stored = [$docs];
        $this->iris['/api/admin/api_configurations/'.$docs->getId()] = $docs;

        self::assertSame([$docs], $this->resolver()->resolveMany(['docs', '/api/admin/api_configurations/'.$docs->getId()]));
    }

    public function testUnknownNameIsRejected(): void
    {
        $this->expectRejection('Unknown MCP connection "missing"');

        $this->resolver()->resolve('missing');
    }

    public function testUnknownIriIsRejected(): void
    {
        $this->expectRejection('Unknown MCP connection');

        $this->resolver()->resolve('/api/admin/api_configurations/00000000-0000-4000-8000-000000000000');
    }

    public function testIriOfAnotherResourceIsRejected(): void
    {
        $this->iris['/api/admin/collections/x'] = new \stdClass();
        $this->expectRejection('does not reference a connection');

        $this->resolver()->resolve('/api/admin/collections/x');
    }

    public function testInactiveConnectionIsRejected(): void
    {
        $this->stored = [self::connection('mcp', 'docs')->setActive(false)];
        $this->expectRejection('is inactive');

        $this->resolver()->resolve('docs');
    }

    public function testAmbiguousNameIsRejected(): void
    {
        $this->stored = [self::connection('mcp', 'docs'), self::connection('mcp', 'docs')];
        $this->expectRejection('is ambiguous');

        $this->resolver()->resolve('docs');
    }

    public function testAmbiguousPartialUuidIsRejected(): void
    {
        $this->uuidLookup = static fn (): never => throw new \RuntimeException('Ambiguous partial UUID');
        $this->expectRejection('is ambiguous');

        $this->resolver()->resolve('0199a1b2');
    }

    public function testNonMcpConnectionIsRejected(): void
    {
        $github = self::connection('github', 'repos');
        $this->iris['/api/admin/api_configurations/'.$github->getId()] = $github;
        $this->expectRejection('is not an MCP connection');

        $this->resolver()->resolve('/api/admin/api_configurations/'.$github->getId());
    }

    public function testNonMcpConnectionByUuidIsRejected(): void
    {
        $github = self::connection('github', 'repos');
        $this->uuidLookup = static fn (): ApiConfiguration => $github;
        $this->expectRejection('is not an MCP connection');

        $this->resolver()->resolve((string) $github->getId());
    }

    public function testEmptyReferenceIsRejected(): void
    {
        $this->expectRejection('Empty MCP connection reference');

        $this->resolver()->resolve('  ');
    }

    private function expectRejection(string $message): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage($message);
    }

    private function resolver(): McpConnectionResolver
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturnCallback(fn (array $criteria, ?array $orderBy = null, ?int $limit = null): array => \array_slice(array_values(array_filter(
            $this->stored,
            static fn (ApiConfiguration $c): bool => $c->getType() === $criteria['type'] && $c->getName() === $criteria['name'],
        )), 0, $limit));

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $iriConverter = $this->createStub(IriConverterInterface::class);
        $iriConverter->method('getResourceFromIri')->willReturnCallback(
            fn (string $iri): object => $this->iris[$iri] ?? throw new ItemNotFoundException('Not found'),
        );

        $uuidResolver = $this->createStub(UuidResolver::class);
        $uuidResolver->method('findByPartialUuid')->willReturnCallback(
            fn (string $class, string $id): ?object => null !== $this->uuidLookup ? ($this->uuidLookup)($id) : null,
        );

        return new McpConnectionResolver($entityManager, $iriConverter, $uuidResolver);
    }

    private static function connection(string $type, string $name): ApiConfiguration
    {
        return (new ApiConfiguration())
            ->setName($name)
            ->setConfigJson(['type' => $type, 'endpoint_type' => 'rest', 'url' => 'https://example.org']);
    }
}
