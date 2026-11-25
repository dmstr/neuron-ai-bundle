<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:26 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Anonymous;

use Dmstr\NeuronAiBundle\Agent\Preview\PurgeAgentPreviewsMessage;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The bundle's schedule `neuron` (tag `scheduler.schedule_provider` with
 * `name: neuron`, so the framework registers the transport
 * `scheduler_neuron`, which a worker has to consume): a
 * {@see PurgeAnonymousConversationsMessage} every `$purgeInterval`
 * (`dmstr_neuron_ai.anonymous.purge_interval`, a relative date string such
 * as `15 minutes`, an ISO 8601 duration or seconds), and a
 * {@see PurgeAgentPreviewsMessage} every `$previewPurgeInterval`
 * (`dmstr_neuron_ai.agent_previews.purge_interval`, same format).
 *
 * The schedule is stateful on `$state` (the shared `cache.app` pool), so
 * a restarted worker continues from the last run, and only the last missed
 * run is processed. With a lock factory the schedule holds the lock
 * `neuron-schedule`, so of several workers consuming `scheduler_neuron`
 * only one generates the messages; the lock must live in a store shared by
 * all workers (Redis, PostgreSQL), a per-container `flock` store does not
 * coordinate anything.
 */
final class NeuronScheduleProvider implements ScheduleProviderInterface
{
    public const string NAME = 'neuron';
    public const string LOCK = 'neuron-schedule';

    private ?Schedule $schedule = null;

    public function __construct(
        private readonly CacheInterface $state,
        private readonly string $purgeInterval,
        private readonly ?LockFactory $lockFactory = null,
        private readonly string $previewPurgeInterval = '1 day',
    ) {
    }

    public function getSchedule(): Schedule
    {
        if (null !== $this->schedule) {
            return $this->schedule;
        }

        $schedule = (new Schedule())
            ->stateful($this->state)
            ->processOnlyLastMissedRun(true)
            ->add(RecurringMessage::every($this->purgeInterval, new PurgeAnonymousConversationsMessage()))
            ->add(RecurringMessage::every($this->previewPurgeInterval, new PurgeAgentPreviewsMessage()));

        if (null !== $this->lockFactory) {
            $schedule->lock($this->lockFactory->createLock(self::LOCK));
        }

        return $this->schedule = $schedule;
    }
}

// - revised 2026-10-13 23:25:27 UTC
