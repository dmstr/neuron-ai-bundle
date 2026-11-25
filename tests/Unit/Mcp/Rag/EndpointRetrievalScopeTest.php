<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:16:39 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp\Rag;

use Dmstr\NeuronAiBundle\Tests\Support\FixedMcpEndpointPolicy;
use Dmstr\NeuronAiBundle\Tests\Support\InMemoryCollectionReferenceResolver;
use Dmstr\McpEndpoint\Context\McpEndpointContext;
use Dmstr\McpEndpoint\Entity\McpEndpoint;
use Dmstr\NeuronAiBundle\Mcp\Server\EndpointRetrievalScope;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The one reader of the current endpoint's collection scope and retrieval
 * settings, with a fake context, policy and reference resolver: default
 * deny without endpoint, dead ids skipped, "not found" for unknown and
 * out-of-scope references alike, k clamped to maxK.
 */
final class EndpointRetrievalScopeTest extends TestCase
{
    private const string HANDBOOK_ID = '0199c0de-0000-7000-8000-0000000000a1';
    private const string FAQ_ID = '0199c0de-0000-7000-8000-0000000000a2';
    private const string DEAD_ID = '0199c0de-0000-7000-8000-0000000000de';

    private McpEndpointContext $context;

    private InMemoryCollectionReferenceResolver $collections;

    protected function setUp(): void
    {
        $this->context = new McpEndpointContext();
        $this->collections = new InMemoryCollectionReferenceResolver(
            $this->createStub(EntityManagerInterface::class),
            InMemoryCollectionReferenceResolver::make('handbook', self::HANDBOOK_ID),
            InMemoryCollectionReferenceResolver::make('faq', self::FAQ_ID),
        );
    }

    public function testWithoutEndpointTheScopeIsEmpty(): void
    {
        $scope = $this->scope();

        self::assertFalse($scope->hasEndpoint());
        self::assertSame([], $scope->collectionIds());
        self::assertSame([], $scope->collections());
        self::assertFalse($scope->contains(self::HANDBOOK_ID));
        $this->assertNotFound('handbook', $scope);
        self::assertSame([], $this->collections->lookups, 'No lookup without endpoint.');
    }

    public function testScopeIdsAreNormalizedAndUnique(): void
    {
        $scope = $this->scope(['collections' => [strtoupper(self::HANDBOOK_ID), self::HANDBOOK_ID, self::DEAD_ID]]);

        self::assertTrue($scope->hasEndpoint());
        self::assertSame([self::HANDBOOK_ID, self::DEAD_ID], $scope->collectionIds());
        self::assertTrue($scope->contains(strtoupper(self::HANDBOOK_ID)));
        self::assertFalse($scope->contains(self::FAQ_ID));
        self::assertFalse($scope->contains(null));
        self::assertSame([], $this->collections->lookups, 'Ids and contains() need no lookup.');
    }

    public function testOtherScopeKeysDoNotCount(): void
    {
        self::assertSame([], $this->scope(['test_items' => [self::HANDBOOK_ID]])->collectionIds());
    }

    public function testDeadIdsAreSkippedSilently(): void
    {
        $names = array_map(
            static fn ($collection): string => $collection->getName(),
            $this->scope(['collections' => [self::DEAD_ID, self::FAQ_ID, self::HANDBOOK_ID]])->collections(),
        );

        self::assertSame(['faq', 'handbook'], $names);
    }

    public function testCollectionInScopeResolvesByNameAndId(): void
    {
        $scope = $this->scope(['collections' => [self::HANDBOOK_ID]]);

        self::assertSame(self::HANDBOOK_ID, $scope->collection('handbook')->getId()->toRfc4122());
        self::assertSame(self::HANDBOOK_ID, $scope->collection(strtoupper(self::HANDBOOK_ID))->getId()->toRfc4122());
    }

    public function testUuidOutsideScopeIsNotFoundWithoutLookup(): void
    {
        $this->assertNotFound(self::FAQ_ID, $this->scope(['collections' => [self::HANDBOOK_ID]]));
        self::assertSame([], $this->collections->lookups);
    }

    public function testEmptyScopeIsNotFoundWithoutLookup(): void
    {
        $this->assertNotFound('handbook', $this->scope(['collections' => []]));
        self::assertSame([], $this->collections->lookups);
    }

    /** An existing collection outside the scope and an unknown one answer alike. */
    public function testNameOutsideScopeAndUnknownNameAnswerAlike(): void
    {
        $scope = $this->scope(['collections' => [self::HANDBOOK_ID]]);

        $this->assertNotFound('faq', $scope);
        $this->assertNotFound('missing', $scope);
    }

    public function testDeadIdInScopeIsNotFound(): void
    {
        $this->assertNotFound(self::DEAD_ID, $this->scope(['collections' => [self::DEAD_ID]]));
    }

    public function testRetrievalDefaultsComeFromThePolicy(): void
    {
        $scope = $this->scope(['collections' => [self::HANDBOOK_ID]], [], new FixedMcpEndpointPolicy(7, true, 0.3));

        self::assertSame(7, $scope->maxK());
        self::assertTrue($scope->hybrid());
        self::assertSame(0.3, $scope->hybridWeight());
    }

    public function testRetrievalOfTheEndpointWins(): void
    {
        $scope = $this->scope(['collections' => [self::HANDBOOK_ID]], ['maxK' => 3, 'hybrid' => false, 'hybridWeight' => 1], new FixedMcpEndpointPolicy(7, true, 0.3));

        self::assertSame(3, $scope->maxK());
        self::assertFalse($scope->hybrid());
        self::assertSame(1.0, $scope->hybridWeight());
    }

    public function testWithoutEndpointThePolicyDefaultsApply(): void
    {
        $scope = $this->scope(policy: new FixedMcpEndpointPolicy(4, true, 0.25));

        self::assertSame(4, $scope->maxK());
        self::assertTrue($scope->hybrid());
        self::assertSame(0.25, $scope->hybridWeight());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function maxKBounds(): iterable
    {
        yield 'above the hard cap' => [['maxK' => 500], 50];
        yield 'zero' => [['maxK' => 0], 1];
        yield 'not an integer' => [['maxK' => '5'], 10];
    }

    /**
     * @param array<string, mixed> $retrieval
     */
    #[DataProvider('maxKBounds')]
    public function testMaxKStaysWithinBounds(array $retrieval, int $expected): void
    {
        self::assertSame($expected, $this->scope(['collections' => [self::HANDBOOK_ID]], $retrieval)->maxK());
    }

    public function testPolicyDefaultIsCappedToo(): void
    {
        self::assertSame(50, $this->scope(policy: new FixedMcpEndpointPolicy(999))->maxK());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function kValues(): iterable
    {
        yield 'within' => [2, 2];
        yield 'at maxK' => [3, 3];
        yield 'above maxK' => [500, 3];
        yield 'zero' => [0, 1];
        yield 'negative' => [-1, 1];
    }

    #[DataProvider('kValues')]
    public function testKIsClampedToMaxK(int $k, int $expected): void
    {
        self::assertSame($expected, $this->scope(['collections' => [self::HANDBOOK_ID]], ['maxK' => 3])->clampK($k));
    }

    public function testHybridWeightStaysWithinZeroAndOne(): void
    {
        self::assertSame(1.0, $this->scope([], ['hybridWeight' => 3])->hybridWeight());
        self::assertSame(0.0, $this->scope([], ['hybridWeight' => -0.5])->hybridWeight());
    }

    /**
     * An endpoint with $scope and $retrieval in the context; no endpoint
     * when both are null.
     *
     * @param array<string, list<string>>|null $scope
     * @param array<string, mixed>|null        $retrieval
     */
    private function scope(?array $scope = null, ?array $retrieval = null, ?FixedMcpEndpointPolicy $policy = null): EndpointRetrievalScope
    {
        if (null !== $scope || null !== $retrieval) {
            $this->context->set((new McpEndpoint())->setSlug('rag')->setScope($scope ?? [])->setRetrieval($retrieval ?? []));
        }

        return new EndpointRetrievalScope($this->context, $policy ?? new FixedMcpEndpointPolicy(), $this->collections);
    }

    private function assertNotFound(string $ref, EndpointRetrievalScope $scope): void
    {
        try {
            $scope->collection($ref);
            self::fail('Expected NotFoundHttpException');
        } catch (NotFoundHttpException $exception) {
            self::assertSame(\sprintf('Collection "%s" not found', $ref), $exception->getMessage());
        }
    }
}
