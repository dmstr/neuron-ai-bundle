<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:12 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Testing;

use Dmstr\NeuronAiBundle\Stream\StreamEvent;
use Dmstr\NeuronAiBundle\Stream\StreamEventSinkInterface;

/**
 * In-memory sink for tests: records every event in order and counts the
 * pings. An optional hook runs after each recorded event, for tests that
 * must act mid-stream:
 *
 *     $sink = new RecordingStreamSink();
 *     $sink->onSend(static function (StreamEvent $event) use (&$cancel): void {
 *         $cancel = StreamEvent::TOOL_CALL === $event->type;
 *     });
 */
final class RecordingStreamSink implements StreamEventSinkInterface
{
    /** @var list<StreamEvent> */
    private array $events = [];

    private int $pings = 0;

    /** @var (\Closure(StreamEvent): void)|null */
    private ?\Closure $hook = null;

    public function send(StreamEvent $event): void
    {
        $this->events[] = $event;
        if (null !== $this->hook) {
            ($this->hook)($event);
        }
    }

    public function ping(): void
    {
        ++$this->pings;
    }

    /**
     * @param (\Closure(StreamEvent): void)|null $hook called after each recorded event
     */
    public function onSend(?\Closure $hook): self
    {
        $this->hook = $hook;

        return $this;
    }

    /**
     * @return list<StreamEvent>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * @return list<string>
     */
    public function getTypes(): array
    {
        return array_map(static fn (StreamEvent $event): string => $event->type, $this->events);
    }

    /**
     * The joined `text` of all events of $type (`token` or `reasoning`).
     */
    public function textOf(string $type): string
    {
        $text = '';
        foreach ($this->events as $event) {
            if ($type === $event->type) {
                $text .= (string) ($event->payload['text'] ?? '');
            }
        }

        return $text;
    }

    public function pings(): int
    {
        return $this->pings;
    }
}
