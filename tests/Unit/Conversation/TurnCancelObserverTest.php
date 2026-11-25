<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:12 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use Dmstr\NeuronAiBundle\Conversation\TurnCancelledException;
use Dmstr\NeuronAiBundle\Conversation\TurnCancelObserver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the cancellation observer of the streaming turn:
 * it asks its closure only at `inference-start`, `tool-calling` and
 * `tool-called`, and throws only when the closure answers true.
 */
final class TurnCancelObserverTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function checkedEvents(): iterable
    {
        yield 'inference-start' => ['inference-start'];
        yield 'tool-calling' => ['tool-calling'];
        yield 'tool-called' => ['tool-called'];
    }

    #[DataProvider('checkedEvents')]
    public function testThrowsAtACheckedEventWhenCancelled(string $event): void
    {
        $observer = new TurnCancelObserver(static fn (): bool => true);

        $this->expectException(TurnCancelledException::class);
        $observer->onEvent($event, $this);
    }

    #[DataProvider('checkedEvents')]
    public function testDoesNotThrowAtACheckedEventWhenNotCancelled(string $event): void
    {
        $calls = 0;
        $observer = new TurnCancelObserver(static function () use (&$calls): bool {
            ++$calls;

            return false;
        });

        $observer->onEvent($event, $this);

        self::assertSame(1, $calls);
    }

    public function testOtherEventsNeitherAskNorThrow(): void
    {
        $calls = 0;
        $observer = new TurnCancelObserver(static function () use (&$calls): bool {
            ++$calls;

            return true;
        });

        foreach (['inference-stop', 'message-saving', 'message-saved', 'workflow-start', 'workflow-node-end', 'workflow-end', 'error'] as $event) {
            $observer->onEvent($event, $this);
        }

        self::assertSame(0, $calls);
    }

    public function testFollowsTheClosureFromCallToCall(): void
    {
        $cancelled = false;
        $observer = new TurnCancelObserver(static function () use (&$cancelled): bool {
            return $cancelled;
        });

        $observer->onEvent('tool-calling', $this);
        $cancelled = true;

        $this->expectException(TurnCancelledException::class);
        $observer->onEvent('tool-called', $this);
    }
}
