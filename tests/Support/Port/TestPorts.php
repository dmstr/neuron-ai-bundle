<?php

// file generated with AI assistance: Claude Code - 2026-11-25 21:50:20 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support\Port;

/**
 * Resets the static state of every port double of the test host. Run after
 * every test by {@see \Dmstr\NeuronAiBundle\Tests\Support\ResetTestPortsExtension}
 * (registered in `phpunit.dist.xml`); a test may call it itself, e.g. in
 * the middle of a scenario. State set in `setUpBeforeClass()` therefore
 * only lasts for the first test of a class: set it in `setUp()`.
 */
final class TestPorts
{
    public static function reset(): void
    {
        TestReindexSource::reset();
        InMemoryNeighbourSource::reset();
        InMemorySourceFreshness::reset();
        TestCollectionContentPurger::reset();
        InMemoryAnonymousAccessPolicy::reset();
        InMemoryAgentLimitDefaults::reset();
        InMemoryAgentPreviewRetention::reset();
        InMemoryEvaluationSettings::reset();
    }

    private function __construct()
    {
    }
}
