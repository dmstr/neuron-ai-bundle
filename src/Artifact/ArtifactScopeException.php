<?php

// file generated with AI assistance: Claude Code - 2026-10-16 08:40:52 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Artifact;

/**
 * The artifact folder of a conversation cannot be used: invalid owner
 * subject or conversation id, or a missing or read-only storage root. The
 * caller offers no artifact tools then (fail-closed) instead of failing the
 * turn. The message may name server paths: log it, never show it to the
 * model.
 */
final class ArtifactScopeException extends \RuntimeException
{
}
