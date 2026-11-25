<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:26 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Anonymous;

/**
 * Messenger handler of {@see PurgeAnonymousConversationsMessage}: runs the
 * purge. Registered with an explicit `messenger.message_handler` tag (the
 * bundle uses no autoconfiguration).
 */
final class PurgeAnonymousConversationsHandler
{
    public function __construct(
        private readonly AnonymousConversationPurger $purger,
    ) {
    }

    public function __invoke(PurgeAnonymousConversationsMessage $message): void
    {
        $this->purger->run();
    }
}
