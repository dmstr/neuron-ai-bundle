<?php

// file generated with AI assistance: Claude Code - 2026-10-14 01:10:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Stream;

/**
 * Sink without a reader, for a streamed turn that nobody watches while it
 * runs (the start turn of {@see \Dmstr\NeuronAiBundle\Conversation\ConversationStarter}).
 * The events are stored all the same: {@see \Dmstr\NeuronAiBundle\Conversation\StreamingTurn::run()}
 * wraps every sink in {@see StoringSink}, and `GET /conversations/{id}/stream`
 * reads them from there.
 */
final class DiscardingSink implements StreamEventSinkInterface
{
    public function send(StreamEvent $event): void
    {
    }

    public function ping(): void
    {
    }
}
