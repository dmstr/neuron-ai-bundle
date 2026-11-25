<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:16:39 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp\Rag;

use Dmstr\NeuronAiBundle\Tests\Support\InMemoryCollectionReferenceResolver;
use Dmstr\McpEndpoint\Scope\UnknownScopeReferenceException;
use Dmstr\NeuronAiBundle\Mcp\Server\CollectionScopeResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The `collections` scope key of MCP endpoints: names and UUIDs resolve to
 * collection ids through the bundle's CollectionReferenceResolver (here an
 * in-memory double), unknown references are reported together.
 */
final class CollectionScopeResolverTest extends TestCase
{
    private const string HANDBOOK_ID = '0199c0de-0000-7000-8000-0000000000a1';
    private const string FAQ_ID = '0199c0de-0000-7000-8000-0000000000a2';

    private CollectionScopeResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CollectionScopeResolver(new InMemoryCollectionReferenceResolver(
            $this->createStub(EntityManagerInterface::class),
            InMemoryCollectionReferenceResolver::make('handbook', self::HANDBOOK_ID),
            InMemoryCollectionReferenceResolver::make('faq', self::FAQ_ID),
        ));
    }

    public function testScopeKeyIsCollections(): void
    {
        self::assertSame('collections', $this->resolver->scopeKey());
    }

    public function testNamesAndIdsResolveToIdsInOrder(): void
    {
        self::assertSame(
            [self::FAQ_ID, self::HANDBOOK_ID, self::FAQ_ID],
            $this->resolver->resolve(['faq', strtoupper(self::HANDBOOK_ID), self::FAQ_ID]),
        );
    }

    public function testEveryUnknownReferenceIsReported(): void
    {
        try {
            $this->resolver->resolve(['handbook', 'missing', '0199c0de-0000-7000-8000-0000000000ff']);
            self::fail('Expected UnknownScopeReferenceException');
        } catch (UnknownScopeReferenceException $exception) {
            self::assertSame(['missing', '0199c0de-0000-7000-8000-0000000000ff'], $exception->references);
        }
    }

    public function testNoReferencesResolveToNoIds(): void
    {
        self::assertSame([], $this->resolver->resolve([]));
    }
}
