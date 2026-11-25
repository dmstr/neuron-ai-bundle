<?php

// file generated with AI assistance: Claude Code - 2026-10-09 16:16:39 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use Dmstr\McpEndpoint\Policy\McpEndpointPolicyInterface;

/**
 * Endpoint policy with fixed values for the unit tests of the knowledge MCP
 * tools.
 */
final readonly class FixedMcpEndpointPolicy implements McpEndpointPolicyInterface
{
    public function __construct(
        private int $defaultMaxK = 10,
        private bool $defaultHybrid = false,
        private float $defaultHybridWeight = 0.5,
    ) {
    }

    public function allowWrite(): bool
    {
        return false;
    }

    public function defaultMaxK(): int
    {
        return $this->defaultMaxK;
    }

    public function defaultHybrid(): bool
    {
        return $this->defaultHybrid;
    }

    public function defaultHybridWeight(): float
    {
        return $this->defaultHybridWeight;
    }
}
