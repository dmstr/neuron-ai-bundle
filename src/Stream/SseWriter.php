<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:20:14 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Stream;

/**
 * Writes stream events to the response body in the server-sent events
 * wire format and flushes after every write:
 *
 *     id: 42
 *     event: token
 *     data: {"text":"Hello"}
 *
 * The `id:` line is omitted for events without an id; {@see ping()} writes
 * the comment line `: ping`. Output buffers are never touched: the stream
 * pool runs with `output_buffering = 0`, and the kernel test client
 * captures the output through its own buffer.
 *
 * Once the client is gone (`connection_aborted()`), the writer stops
 * writing but keeps returning normally, so the producer (e.g. a turn that
 * must finish and store its records) goes on undisturbed. The caller is
 * expected to have set `ignore_user_abort(true)`.
 */
final class SseWriter implements StreamEventSinkInterface
{
    private const int JSON_FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;

    private bool $closed = false;

    /**
     * Writes one event block.
     *
     * @throws \JsonException when the payload cannot be encoded
     */
    public function send(StreamEvent $event): void
    {
        // An empty payload is still a JSON object on the wire.
        $data = json_encode([] === $event->payload ? new \stdClass() : $event->payload, self::JSON_FLAGS);

        $block = (null === $event->id ? '' : 'id: '.$event->id."\n")
            .'event: '.$event->type."\n"
            .'data: '.$data."\n\n";

        $this->write($block);
    }

    /**
     * Writes a keep-alive comment line.
     */
    public function ping(): void
    {
        $this->write(": ping\n\n");
    }

    /**
     * Whether the writer has stopped because the client disconnected.
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    private function write(string $block): void
    {
        if ($this->closed) {
            return;
        }
        if (0 !== connection_aborted()) {
            $this->closed = true;

            return;
        }

        echo $block;
        flush();
    }
}
