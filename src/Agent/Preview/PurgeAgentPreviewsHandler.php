<?php

// file generated with AI assistance: Claude Code - 2026-10-13 23:25:27 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent\Preview;

/**
 * Messenger handler of {@see PurgeAgentPreviewsMessage}: runs the purge of
 * preview versions. Registered with an explicit `messenger.message_handler`
 * tag (the bundle uses no autoconfiguration).
 */
final class PurgeAgentPreviewsHandler
{
    public function __construct(
        private readonly AgentPreviewPurger $purger,
    ) {
    }

    public function __invoke(PurgeAgentPreviewsMessage $message): void
    {
        $this->purger->run();
    }
}
