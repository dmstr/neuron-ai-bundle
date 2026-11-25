<?php

// file generated with AI assistance: Claude Code - 2026-10-16 08:40:52 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Artifact;

/**
 * The artifact access of one turn, resolved before the turn runs: whose
 * artifacts, which working folder and whether the write tools are offered.
 *
 * `writable` is decided when the tools are built, from the security token
 * of the request, never when a tool runs: a streamed turn executes its tools
 * after the request has been answered (the same rule as the JWT of
 * `forward_jwt` MCP connections).
 */
final readonly class ArtifactAccess
{
    /**
     * @param string $ownerSubject   the conversation owner, valid for {@see ArtifactScope::SUBJECT_PATTERN}
     * @param string $conversationId the conversation UUID (lower-case RFC 4122), also the name of the working folder
     * @param string $scopeRoot      the canonical, existing user root `<root>/<ownerSubject>` ({@see ArtifactScope::ensure()})
     * @param bool   $writable       whether `write_file` and `edit_file` are offered and allowed
     */
    public function __construct(
        public string $ownerSubject,
        public string $conversationId,
        public string $scopeRoot,
        public bool $writable,
    ) {
    }
}
