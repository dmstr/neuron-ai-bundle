<?php

// file generated with AI assistance: Claude Code - 2026-10-05 05:40:45 UTC, updated 2026-10-06

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\OpenApi;

/**
 * OpenAPI tags that group the operations of this bundle by area.
 *
 * The names share the prefix `Neuron AI/`; admin clients that read a `/` in
 * a tag as nesting show one `Neuron AI` group with the
 * areas as subgroups, everywhere else the tag is just a name.
 *
 * Every resource sets its tag at resource level; an operation with its own
 * `openapi` attribute sets it again, because API Platform does not merge the
 * resource-level tags into an operation-level `openapi` and falls back to the
 * short name otherwise. The constants keep the names in one place; an
 * application that exposes resources of the same area (e.g. its own document
 * upload) uses them too.
 */
final class OpenApiTag
{
    /** Provider, embedding and MCP infrastructure shared by all areas. */
    public const CORE = 'Neuron AI/Core';

    /** Collections, documents, chunks, embeddings and retrieval. */
    public const RAG = 'Neuron AI/RAG';

    /** Agents, agent versions, evaluation and conversation replay. */
    public const AGENTS = 'Neuron AI/Agents';

    /** Conversations, messages, streaming and feedback. */
    public const CHAT = 'Neuron AI/Chat';

    private function __construct()
    {
    }
}
