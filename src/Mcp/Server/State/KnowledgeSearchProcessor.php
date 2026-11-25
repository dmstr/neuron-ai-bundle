<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:16:39 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp\Server\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Mcp\Server\Dto\KnowledgeSearchInput;
use Dmstr\NeuronAiBundle\Mcp\Server\Dto\KnowledgeSearchResult;
use Dmstr\NeuronAiBundle\Mcp\Server\EndpointRetrievalScope;
use Dmstr\NeuronAiBundle\Retrieval\KnowledgeSearch;

/**
 * MCP tool `rag_search`: {@see KnowledgeSearch} restricted to the collection
 * scope of the current endpoint ({@see EndpointRetrievalScope}).
 *
 * - With `collection`, that collection must lie in the scope; unknown and
 *   outside the scope are both "not found".
 * - Without it, every existing collection of the scope is searched; an
 *   empty scope (or no endpoint) returns no hits and never searches all
 *   collections.
 * - `k` is clamped to the endpoint's `maxK`; `hybrid` and `hybridWeight`
 *   come from the endpoint with the policy defaults.
 *
 * KnowledgeSearch arrives as a closure: its vector store opens the database
 * connection when it is created, and a refused call (not found, empty
 * scope) must not need one.
 *
 * @implements ProcessorInterface<KnowledgeSearchInput, KnowledgeSearchResult>
 */
final readonly class KnowledgeSearchProcessor implements ProcessorInterface
{
    /**
     * @param \Closure(): KnowledgeSearch $knowledgeSearch
     */
    public function __construct(
        private EndpointRetrievalScope $scope,
        private \Closure $knowledgeSearch,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): KnowledgeSearchResult
    {
        if (!$data instanceof KnowledgeSearchInput) {
            throw new \InvalidArgumentException('Expected KnowledgeSearchInput payload');
        }

        // Validated before (validate: true): query a non-blank string, k a
        // positive integer, collection and topic strings or null.
        $ref = \is_string($data->collection) && '' !== $data->collection ? $data->collection : null;
        $collections = null !== $ref ? [$this->scope->collection($ref)] : $this->scope->collections();
        if ([] === $collections) {
            return new KnowledgeSearchResult();
        }

        $hits = ($this->knowledgeSearch)()->search(
            (string) $data->query,
            array_map(static fn (Collection $collection): string => $collection->getId()->toRfc4122(), $collections),
            $this->scope->clampK(\is_int($data->k) ? $data->k : KnowledgeSearchInput::DEFAULT_K),
            \is_string($data->topic) ? $data->topic : null,
            $this->scope->hybrid(),
            $this->scope->hybridWeight(),
        );

        return new KnowledgeSearchResult($hits);
    }
}
