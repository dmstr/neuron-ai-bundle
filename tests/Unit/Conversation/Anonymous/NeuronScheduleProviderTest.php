<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:26 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation\Anonymous;

use Dmstr\NeuronAiBundle\Agent\Preview\PurgeAgentPreviewsMessage;
use Dmstr\NeuronAiBundle\Anonymous\NeuronScheduleProvider;
use Dmstr\NeuronAiBundle\Anonymous\PurgeAnonymousConversationsMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Scheduler\Generator\MessageContext;

/**
 * The bundle's schedule `neuron`: the recurring purge messages of
 * anonymous conversations and of agent previews at their configured
 * intervals, stateful on the given cache, only the last missed
 * run, and locked when a lock factory is available. Kernel-free.
 */
final class NeuronScheduleProviderTest extends TestCase
{
    public function testScheduleHoldsThePurgeMessageAtTheConfiguredInterval(): void
    {
        $cache = new ArrayAdapter();
        $schedule = (new NeuronScheduleProvider($cache, '15 minutes'))->getSchedule();

        self::assertSame($cache, $schedule->getState());
        self::assertTrue($schedule->shouldProcessOnlyLastMissedRun());
        self::assertNull($schedule->getLock());

        $messages = $schedule->getRecurringMessages();
        self::assertCount(2, $messages);
        $recurring = array_values($messages)[0];

        $run = new \DateTimeImmutable('2026-10-01 12:00:00 UTC');
        $trigger = $recurring->getTrigger();
        self::assertEquals(new \DateTimeImmutable('2026-10-01 12:15:00 UTC'), $trigger->getNextRunDate($run));

        $context = new MessageContext('neuron', $recurring->getId(), $trigger, $run);
        $sent = iterator_to_array($recurring->getMessages($context), false);
        self::assertCount(1, $sent);
        self::assertInstanceOf(PurgeAnonymousConversationsMessage::class, $sent[0]);
    }

    public function testScheduleHoldsThePreviewPurgeMessageAtItsInterval(): void
    {
        $schedule = (new NeuronScheduleProvider(new ArrayAdapter(), '15 minutes', null, '6 hours'))->getSchedule();

        $recurring = array_values($schedule->getRecurringMessages())[1];
        $run = new \DateTimeImmutable('2026-10-01 12:00:00 UTC');
        $trigger = $recurring->getTrigger();
        self::assertEquals(new \DateTimeImmutable('2026-10-01 18:00:00 UTC'), $trigger->getNextRunDate($run));

        $sent = iterator_to_array($recurring->getMessages(new MessageContext('neuron', $recurring->getId(), $trigger, $run)), false);
        self::assertCount(1, $sent);
        self::assertInstanceOf(PurgeAgentPreviewsMessage::class, $sent[0]);
    }

    public function testPreviewPurgeIntervalDefaultsToOneDay(): void
    {
        $recurring = array_values((new NeuronScheduleProvider(new ArrayAdapter(), '15 minutes'))->getSchedule()->getRecurringMessages())[1];

        $run = new \DateTimeImmutable('2026-10-01 12:00:00 UTC');
        self::assertEquals(new \DateTimeImmutable('2026-10-02 12:00:00 UTC'), $recurring->getTrigger()->getNextRunDate($run));
    }

    public function testScheduleIsLockedWithALockFactory(): void
    {
        $schedule = (new NeuronScheduleProvider(new ArrayAdapter(), '15 minutes', new LockFactory(new InMemoryStore())))->getSchedule();

        self::assertNotNull($schedule->getLock());
    }

    public function testScheduleIsBuiltOnce(): void
    {
        $provider = new NeuronScheduleProvider(new ArrayAdapter(), '1 hour');

        self::assertSame($provider->getSchedule(), $provider->getSchedule());
    }
}

// - revised 2026-10-13 23:25:27 UTC
