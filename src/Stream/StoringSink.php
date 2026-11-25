<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:12 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Stream;

use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Sink wrapper that stores every event ({@see StreamEventStore}) before it
 * passes it on with its id, so a client that resumes never misses an event
 * it saw. A failed write is logged and the event is passed on without an
 * id: storage only serves resumption and must not stop the stream.
 */
final class StoringSink implements StreamEventSinkInterface
{
    public function __construct(
        private readonly StreamEventSinkInterface $inner,
        private readonly StreamEventStore $store,
        private readonly Uuid $streamId,
        private readonly Uuid $messageId,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(StreamEvent $event): void
    {
        try {
            $event = $event->withId($this->store->append($this->streamId, $this->messageId, $event->type, $event->payload));
        } catch (\Throwable $e) {
            $this->logger->error('Could not store the stream event: {message}', ['message' => $e->getMessage(), 'exception' => $e, 'type' => $event->type]);
        }

        $this->inner->send($event);
    }

    public function ping(): void
    {
        $this->inner->ping();
    }
}
