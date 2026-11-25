<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\ProviderConnection;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Provider\Connection\ProviderConnectionReferenceException;
use Dmstr\NeuronAiBundle\Provider\Connection\ProviderConnectionResolver;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Kernel-free checks of the provider connection references (IRI, UUID,
 * partial UUID, name) and their rejection reasons, with stubbed
 * collaborators.
 */
final class ProviderConnectionResolverTest extends TestCase
{
    /** @var list<ApiConfiguration> */
    private array $stored = [];

    /** @var array<string, object> */
    private array $iris = [];

    private ?\Closure $uuidLookup = null;

    public function testResolvesChatByNameAcrossBothTypes(): void
    {
        $ollama = ProviderConnectionFixtures::ollama('local');
        $anthropic = ProviderConnectionFixtures::anthropic('claude');
        // An mcp connection may share a name; it is not looked up.
        $this->stored = [self::mcp('local'), $ollama, $anthropic];

        self::assertSame($ollama, $this->resolver()->resolveChat('local'));
        self::assertSame($anthropic, $this->resolver()->resolveChat(' claude '));
        self::assertSame($ollama, $this->resolver()->resolveEmbedding('local'));
    }

    public function testResolvesByIriAndFullUuid(): void
    {
        $ollama = ProviderConnectionFixtures::ollama('local');
        $iri = '/api/admin/api_configurations/'.$ollama->getId();
        $this->iris[$iri] = $ollama;
        $this->uuidLookup = static fn (string $id): ?ApiConfiguration => str_starts_with($ollama->getId()->toRfc4122(), $id) ? $ollama : null;

        self::assertSame($ollama, $this->resolver()->resolveChat($iri));
        self::assertSame($ollama, $this->resolver()->resolveChat($ollama->getId()->toRfc4122()));
        self::assertSame($ollama, $this->resolver()->resolveEmbedding(substr($ollama->getId()->toRfc4122(), 0, 8)));
    }

    public function testHexLikeNameFallsBackToNameLookup(): void
    {
        $cafe = ProviderConnectionFixtures::ollama('cafebabe');
        $this->stored = [$cafe];

        self::assertSame($cafe, $this->resolver()->resolveChat('cafebabe'));
    }

    public function testEmbeddingRejectsAnthropic(): void
    {
        $this->stored = [ProviderConnectionFixtures::anthropic('claude')];

        $e = $this->rejection(fn () => $this->resolver()->resolveEmbedding('claude'));

        self::assertSame(ProviderConnectionReferenceException::WRONG_TYPE, $e->reason);
        self::assertSame('Connection "claude" is an anthropic connection, which cannot serve embeddings (expected: ollama).', $e->getMessage());
    }

    public function testMcpConnectionByUuidIsAWrongType(): void
    {
        $mcp = self::mcp('docs');
        $this->uuidLookup = static fn (string $id): ?ApiConfiguration => $mcp->getId()->toRfc4122() === $id ? $mcp : null;

        $e = $this->rejection(fn () => $this->resolver()->resolveChat($mcp->getId()->toRfc4122()));

        self::assertSame(ProviderConnectionReferenceException::WRONG_TYPE, $e->reason);
        self::assertStringContainsString('is not a provider connection', $e->getMessage());
    }

    public function testMcpConnectionByIriIsAWrongType(): void
    {
        $mcp = self::mcp('docs');
        $this->iris['/api/admin/api_configurations/'.$mcp->getId()] = $mcp;

        self::assertSame(ProviderConnectionReferenceException::WRONG_TYPE, $this->rejection(fn () => $this->resolver()->resolveChat('/api/admin/api_configurations/'.$mcp->getId()))->reason);
    }

    public function testMcpNameIsUnknown(): void
    {
        $this->stored = [self::mcp('docs')];

        $e = $this->rejection(fn () => $this->resolver()->resolveChat('docs'));

        self::assertSame(ProviderConnectionReferenceException::UNKNOWN, $e->reason);
        self::assertSame('Unknown provider connection "docs".', $e->getMessage());
    }

    public function testInactiveConnectionIsRejected(): void
    {
        $this->stored = [ProviderConnectionFixtures::ollama('local', active: false)];

        $e = $this->rejection(fn () => $this->resolver()->resolveChat('local'));

        self::assertSame(ProviderConnectionReferenceException::INACTIVE, $e->reason);
        self::assertSame('Provider connection "local" is inactive.', $e->getMessage());
    }

    public function testDuplicateNameIsAmbiguous(): void
    {
        $this->stored = [ProviderConnectionFixtures::ollama('twin'), ProviderConnectionFixtures::anthropic('twin')];

        self::assertSame(ProviderConnectionReferenceException::AMBIGUOUS, $this->rejection(fn () => $this->resolver()->resolveChat('twin'))->reason);
    }

    public function testAmbiguousPartialUuidIsRejected(): void
    {
        $this->uuidLookup = static fn (): never => throw new \RuntimeException('Multiple entities match');

        self::assertSame(ProviderConnectionReferenceException::AMBIGUOUS, $this->rejection(fn () => $this->resolver()->resolveChat('0123abcd'))->reason);
    }

    public function testUnknownIriAndEmptyReference(): void
    {
        self::assertSame(ProviderConnectionReferenceException::UNKNOWN, $this->rejection(fn () => $this->resolver()->resolveChat('/api/admin/api_configurations/nope'))->reason);
        $empty = $this->rejection(fn () => $this->resolver()->resolveChat('  '));
        self::assertSame(ProviderConnectionReferenceException::EMPTY, $empty->reason);
        self::assertInstanceOf(UnprocessableEntityHttpException::class, $empty);
        self::assertSame(422, $empty->getStatusCode());
    }

    private function rejection(callable $call): ProviderConnectionReferenceException
    {
        try {
            $call();
        } catch (ProviderConnectionReferenceException $e) {
            return $e;
        }

        self::fail('Expected a ProviderConnectionReferenceException.');
    }

    private function resolver(): ProviderConnectionResolver
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturnCallback(fn (array $criteria, ?array $orderBy = null, ?int $limit = null): array => \array_slice(array_values(array_filter(
            $this->stored,
            static fn (ApiConfiguration $c): bool => \in_array($c->getType(), (array) $criteria['type'], true) && $c->getName() === $criteria['name'],
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

        return new ProviderConnectionResolver($entityManager, $iriConverter, $uuidResolver);
    }

    private static function mcp(string $name): ApiConfiguration
    {
        return new ApiConfiguration()
            ->setName($name)
            ->setConfigJson(['mcp' => true, 'type' => 'mcp', 'endpoint_type' => 'rest', 'url' => 'https://mcp.example.org/mcp']);
    }
}
