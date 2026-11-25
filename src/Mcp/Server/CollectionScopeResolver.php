<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:16:39 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp\Server;

use Dmstr\McpEndpoint\Scope\ScopeResolverInterface;
use Dmstr\McpEndpoint\Scope\UnknownScopeReferenceException;
use Dmstr\NeuronAiBundle\Retrieval\CollectionReferenceResolver;
use Dmstr\NeuronAiBundle\Retrieval\UnknownCollectionReferenceException;

/**
 * The scope key `collections` of MCP endpoints (dmstr/mcp-endpoint-bundle):
 * knowledge collections given by UUID or name, stored as collection ids.
 *
 * Resolution goes through the one {@see CollectionReferenceResolver}; every
 * reference that matches no collection is reported in one
 * {@see UnknownScopeReferenceException}. Registered (and tagged
 * `dmstr_mcp_endpoint.scope_resolver`) only while the endpoint bundle is
 * loaded, see {@see \Dmstr\NeuronAiBundle\DmstrNeuronAiBundle}.
 */
final readonly class CollectionScopeResolver implements ScopeResolverInterface
{
    public const string SCOPE_KEY = 'collections';

    public function __construct(
        private CollectionReferenceResolver $collections,
    ) {
    }

    public function scopeKey(): string
    {
        return self::SCOPE_KEY;
    }

    public function resolve(array $refs): array
    {
        $ids = [];
        $unknown = [];
        foreach ($refs as $ref) {
            try {
                $ids[] = $this->collections->collection($ref)->getId()->toRfc4122();
            } catch (UnknownCollectionReferenceException) {
                $unknown[] = $ref;
            }
        }

        if ([] !== $unknown) {
            throw new UnknownScopeReferenceException($unknown);
        }

        return $ids;
    }
}
