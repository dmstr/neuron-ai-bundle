<?php

// file generated with AI assistance: Claude Code - 2026-10-03 10:33:41 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent;

use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A reference of a published agent version that no longer resolves when a
 * turn uses the version (a collection, an MCP connection or catalogue tool
 * removed after publishing, a provider connection removed, deactivated or
 * missing because the version was published before provider connections),
 * raised by {@see VersionTurnMapper} before any provider is contacted
 * (HTTP 422).
 *
 * The detail names the version and the path of the reference inside the
 * version's configuration, e.g.
 * `Agent version 3 of "support" (/api/agent_versions/<uuid>): configuration/mcpTools/1/name: MCP connection "docs" offers no tool "search" in the tool catalogue.`
 */
final class StaleReferenceException extends UnprocessableEntityHttpException
{
    /**
     * @param string $path the path below the version, e.g. `configuration/collections/0`
     */
    private function __construct(
        string $message,
        public readonly string $path,
    ) {
        parent::__construct($message);
    }

    public static function at(AgentVersion $version, string $path, string $reason): self
    {
        return new self(
            \sprintf(
                'Agent version %d of "%s" (/api/agent_versions/%s): %s: %s',
                $version->getVersionNumber(),
                $version->getAgent()->getSlug(),
                $version->getId()->toRfc4122(),
                $path,
                $reason,
            ),
            $path,
        );
    }
}
