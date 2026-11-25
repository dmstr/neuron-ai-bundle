<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:12 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Stream;

/**
 * Receiver of the events of one stream: the SSE writer of a response, the
 * storing wrapper of the streaming turn, or a recording sink in tests.
 */
interface StreamEventSinkInterface
{
    /**
     * Receives one event; `$event->id` is set when the event is stored
     * (always for conversation streams).
     */
    public function send(StreamEvent $event): void;

    /**
     * Keep-alive; SSE writers emit a comment line, test sinks may ignore it.
     */
    public function ping(): void;
}
