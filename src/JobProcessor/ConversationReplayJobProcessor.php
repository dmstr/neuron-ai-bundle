<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\JobProcessor;

use Dmstr\NeuronAiBundle\Entity\ConversationReplay;
use Dmstr\NeuronAiBundle\Replay\ConversationReplayRunner;
use Dmstr\NeuronAiBundle\Replay\ConversationReplayStatus;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\Job\JobProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Runs a conversation replay for jobs of type
 * {@see ConversationReplay::JOB_TYPE}, queued by
 * `POST /api/conversations/{id}/replay`.
 *
 * Job input: `{"replay": "<uuid>", "ownerSubject": "<identifier>"}` (the
 * owner is informational; the record's `ownerSubject` is authoritative).
 * `resultData` is `{status, turnsReplayed, turnsTotal}` of the record,
 * written with `progress` (percent of `turnsTotal` replayed) after every
 * turn.
 *
 * - a replay that no longer exists (its replay conversation was deleted,
 *   which deletes the record) throws: the job fails;
 * - a replay that has already ended (`completed`, `partial`, `cancelled`,
 *   `failed`) or belongs to another job is a no-op with
 *   `resultData.skipped`: this covers a message redelivered after the end;
 * - a redelivery of a replay that is still pending or running resumes it
 *   ({@see ConversationReplayRunner}: messages already replayed are
 *   skipped, the replay conversation stays the one the request created);
 * - anything that escapes the runner ends the replay `failed` with
 *   `finishedAt` when that can still be stored, and is rethrown, so the
 *   job fails; a retry then finds the replay ended and is a no-op.
 */
final class ConversationReplayJobProcessor implements JobProcessorInterface
{
    public function __construct(
        private readonly ConversationReplayRunner $runner,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function supports(string $jobType): bool
    {
        return ConversationReplay::JOB_TYPE === $jobType;
    }

    public function process(Job $job): void
    {
        $input = $job->getInputData();
        $replay = $this->replay((string) ($input['replay'] ?? ''));

        if (!$replay->isActive()) {
            $job->setResultData(['skipped' => \sprintf('the conversation replay has already ended (status "%s")', $replay->getStatus())]);

            return;
        }
        $jobId = $replay->getJobId();
        if (null !== $jobId && $jobId !== $job->getId()) {
            $job->setResultData(['skipped' => \sprintf('the conversation replay belongs to job %s', $jobId)]);

            return;
        }

        $replayId = $replay->getId();
        try {
            $final = $this->runner->run($replay, function (ConversationReplay $replay) use ($job): void {
                $job->setProgress(self::progress($replay));
                $job->setResultData(self::result($replay));
                $this->entityManager->flush();
            });
        } catch (\Throwable $e) {
            $this->markFailed($replayId, $e);

            throw $e;
        }

        if (null === $final) {
            $job->setResultData(['skipped' => 'the conversation replay was deleted while it ran']);

            return;
        }
        if (!$final->isActive()) {
            $job->setProgress(100);
        }
        $job->setResultData(self::result($final));
    }

    /**
     * @return array{status: string, turnsReplayed: int, turnsTotal: int}
     */
    private static function result(ConversationReplay $replay): array
    {
        return [
            'status' => $replay->getStatus(),
            'turnsReplayed' => $replay->getTurnsReplayed(),
            'turnsTotal' => $replay->getTurnsTotal(),
        ];
    }

    private static function progress(ConversationReplay $replay): int
    {
        $total = $replay->getTurnsTotal();

        return 0 === $total ? 100 : intdiv(100 * min($replay->getTurnsReplayed(), $total), $total);
    }

    /**
     * Ends the replay `failed`, if the entity manager can still store it.
     */
    private function markFailed(Uuid $replayId, \Throwable $error): void
    {
        if (!$this->entityManager->isOpen()) {
            return;
        }

        try {
            $replay = $this->entityManager->find(ConversationReplay::class, $replayId);
            if ($replay instanceof ConversationReplay && $replay->isActive()) {
                $message = '' !== $error->getMessage() ? $error->getMessage() : $error::class;
                $replay->finish(ConversationReplayStatus::FAILED, $this->clock->now(), mb_substr($message, 0, ConversationReplayRunner::MAX_ERROR_LENGTH));
                $this->entityManager->flush();
            }
        } catch (\Throwable) {
            // The original failure is the one to report.
        }
    }

    private function replay(string $id): ConversationReplay
    {
        $replay = Uuid::isValid($id)
            ? $this->entityManager->find(ConversationReplay::class, Uuid::fromString($id))
            : null;

        if (!$replay instanceof ConversationReplay) {
            throw new \RuntimeException(\sprintf('Conversation replay "%s" does not exist (anymore).', $id));
        }

        return $replay;
    }
}
