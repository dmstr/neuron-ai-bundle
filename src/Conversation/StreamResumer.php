<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:12 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Conversation;

use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Stream\StreamEvent;
use Dmstr\NeuronAiBundle\Stream\StreamEventSinkInterface;
use Dmstr\NeuronAiBundle\Stream\StreamEventStore;
use Symfony\Component\Uid\Uuid;

/**
 * Resumption of a conversation's stream (`GET /conversations/{id}/stream`
 * with `Last-Event-ID`).
 *
 * Replays the stored events after the given id; while a turn of the
 * conversation runs (its lock is held, by another worker), it polls the
 * event table every poll interval and sends new events as they are stored,
 * with a ping every ping interval, until a terminal event was sent. When
 * the lock is gone and no terminal event was stored (the worker died), it
 * ends with a synthetic `error` event that is not stored.
 */
final class StreamResumer
{
    public const string ENDED_WITHOUT_RESULT = 'The turn ended without a result.';

    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /**
     * @param (\Closure(int): void)|null $sleep sleeps the given milliseconds (default usleep); a seam for tests
     */
    public function __construct(
        private readonly StreamEventStore $store,
        private readonly TurnLock $lock,
        private readonly int $pollIntervalMs = 200,
        private readonly int $pingIntervalMs = 15000,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * False when the conversation has no stored events and no running
     * turn (the controller answers 404).
     */
    public function canResume(Conversation $conversation): bool
    {
        return $this->store->hasEvents($conversation->getId()) || $this->lock->isHeld($conversation->getId());
    }

    /**
     * Sends the stored events with an id greater than $lastEventId (all
     * when null), then follows the running turn until its terminal event.
     */
    public function resume(Conversation $conversation, ?int $lastEventId, StreamEventSinkInterface $sink): void
    {
        $id = $conversation->getId();
        $last = max(0, $lastEventId ?? 0);
        $sincePing = 0;

        while (true) {
            if ($this->replay($id, $last, $sink)) {
                return;
            }

            if (!$this->lock->isHeld($id)) {
                // The turn may have stored its last events between the read
                // and the lock check.
                if ($this->replay($id, $last, $sink) || $this->store->hasTerminal($id)) {
                    return;
                }
                $sink->send(new StreamEvent(StreamEvent::ERROR, [
                    'messageId' => null,
                    'status' => MessageStatus::ERROR,
                    'message' => self::ENDED_WITHOUT_RESULT,
                ]));

                return;
            }

            if ($sincePing >= $this->pingIntervalMs) {
                $sink->ping();
                $sincePing = 0;
            }
            ($this->sleep)($this->pollIntervalMs);
            $sincePing += $this->pollIntervalMs;
        }
    }

    /**
     * Sends the stored events after $last and advances it.
     *
     * @return bool true when a terminal event was sent
     */
    private function replay(Uuid $id, int &$last, StreamEventSinkInterface $sink): bool
    {
        foreach ($this->store->after($id, $last) as $event) {
            $sink->send($event);
            $last = (int) $event->id;
            if ($event->isTerminal()) {
                return true;
            }
        }

        return false;
    }
}
