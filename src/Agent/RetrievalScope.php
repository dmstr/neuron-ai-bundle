<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:31:09 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent;

/**
 * What a turn of an agent version searches: the knowledge collections
 * (stored UUID strings, in configuration order), the retrieval depth
 * (`retrievalDepth` of the version, else the bundle default
 * `dmstr_neuron_ai.store.top_k`; a version holding an explicit null, as
 * every version published before the key existed does, keeps that default) and
 * the hybrid search (`hybrid` and `hybridWeight` of the version; a version
 * without these keys searches vector-only). With hybrid on, the scores of
 * the pool are RRF scores, not cosine similarities.
 *
 * The one place that resolves both, shared by the turn mapping
 * ({@see VersionTurnMapper::map()}, which fills
 * {@see TurnConfiguration::$collections},
 * {@see TurnConfiguration::$retrievalDepth}, {@see TurnConfiguration::$hybrid}
 * and {@see TurnConfiguration::$hybridWeight} from it) and the retrieval
 * measurement of evaluation runs
 * ({@see VersionTurnMapper::retrievalScope()}), so the measurement searches
 * exactly what the turn searches without mapping the whole version (which
 * builds MCP tools).
 */
final readonly class RetrievalScope
{
    /**
     * @param list<string> $collections  collection UUID strings; empty = no retrieval
     * @param bool         $hybrid       keyword plus vector search, fused by weighted RRF
     * @param float        $hybridWeight keyword share of the fusion, 0..1
     */
    public function __construct(
        public array $collections,
        public int $depth,
        public bool $hybrid = false,
        public float $hybridWeight = 0.5,
    ) {
    }

    /**
     * @param int $defaultDepth `dmstr_neuron_ai.store.top_k`, used when the configuration sets no `retrievalDepth`
     */
    public static function of(AgentConfiguration $configuration, int $defaultDepth): self
    {
        return new self($configuration->collections, $configuration->retrievalDepth ?? $defaultDepth, $configuration->hybrid, $configuration->hybridWeight);
    }

    /**
     * Whether the version searches at all (it has knowledge collections).
     */
    public function hasCollections(): bool
    {
        return [] !== $this->collections;
    }
}
