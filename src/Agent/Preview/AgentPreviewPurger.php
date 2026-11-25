<?php

// file generated with AI assistance: Claude Code - 2026-10-13 23:25:27 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent\Preview;

use Dmstr\NeuronAiBundle\Agent\AgentVersionKind;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousConversationPurger;
use Dmstr\NeuronAiBundle\Anonymous\SystemActor;
use Dmstr\NeuronAiBundle\Conversation\TurnLock;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Evaluation\EvalRunStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Removes preview versions of agents
 * ({@see AgentVersionKind::PREVIEW}) after their retention, run by the
 * `neuron` schedule ({@see PurgeAgentPreviewsHandler}) and by
 * `neuron:agent:previews:purge`. One run, under the system actor
 * `system:neuron:purge` (the audit actor of every conversation it
 * deletes):
 *
 * 1. reads the retention from {@see AgentPreviewRetentionInterface} once
 *    and the time once; a retention of 0 days purges nothing;
 * 2. takes every preview whose `publishedAt` (its creation time) lies more
 *    than the retention back, in keyset pages of {@see BATCH_SIZE} ids;
 * 3. keeps (skips and counts per reason, see
 *    {@see AgentPreviewPurgeReport}) a preview that is the current version
 *    of its agent (never expected, the entity refuses it), the target of a
 *    conversation replay (the foreign key is RESTRICT, and the replay
 *    record belongs to its replay conversation, not to the preview), that
 *    has an evaluation run in status `pending` or `running`, or one of
 *    whose conversations has a turn running (its turn lock is held);
 * 4. deletes every other due preview with everything that references it,
 *    in foreign key order: its evaluation runs (plain SQL; results,
 *    evaluations and suggestions follow by database cascade, the
 *    `conversation_id` of a result is set null), its conversations through
 *    the entity manager (an audited `remove`; messages, stream events,
 *    feedback and replay records follow by database cascade), then the
 *    version itself.
 *
 * Completed, cancelled and failed evaluation runs of a preview go with it,
 * including the human ratings and AI evaluations of their results: a
 * preview is a throwaway test of a candidate, and what is worth keeping is
 * tested again on the published version. The
 * evaluation conversations of those runs are conversations of the preview
 * (pinned to it) and are deleted as well.
 *
 * Running conversations: a conversation of the preview is not closed first
 * ({@see \Dmstr\NeuronAiBundle\Anonymous\ConversationCloser}), because it
 * is deleted anyway; instead every conversation's turn lock
 * ({@see TurnLock}) is taken before anything is deleted, and when one is
 * held by another session the whole preview is skipped and taken by a later
 * run. The locks are released after the commit, so a turn that starts
 * meanwhile is rejected, not cut off.
 *
 * Each preview is handled in its own transaction that first locks the
 * version row (`FOR UPDATE`) and then checks the skip rules again, so an
 * evaluation run, replay or conversation created on the preview at the
 * same moment either is seen by the checks or waits for the commit and then
 * fails on its foreign key; it never loses its version silently. A failure
 * rolls back only that preview, earlier previews stay deleted (the entity
 * manager is closed by the failure, so the run stops there). The run is
 * idempotent: a second run directly after the first finds nothing to do.
 *
 * A dry run applies the same selection and skip rules (turn locks are
 * inspected, not taken) and reports what would be deleted without
 * changing anything.
 *
 * Without a retention port (the application aliases none) a run logs a
 * warning and does nothing.
 */
final class AgentPreviewPurger
{
    /** Audit actor, shared with the purge of anonymous conversations. */
    public const string ACTOR = AnonymousConversationPurger::ACTOR;
    public const int BATCH_SIZE = 100;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TurnLock $turnLock,
        private readonly SystemActor $systemActor,
        private readonly ?AgentPreviewRetentionInterface $retention,
        private readonly ClockInterface $clock,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function run(bool $dryRun = false): AgentPreviewPurgeReport
    {
        if (null === $this->retention) {
            $this->logger->warning('Agent previews are not purged: the application supplies no {interface}.', ['interface' => AgentPreviewRetentionInterface::class]);

            return AgentPreviewPurgeReport::notRun($dryRun);
        }

        $retentionDays = max(0, $this->retention->retentionDays());
        if (0 === $retentionDays) {
            $this->logger->info('Agent previews are not purged: the retention is 0 (keep forever).');

            return new AgentPreviewPurgeReport(retentionDays: 0, dryRun: $dryRun);
        }

        $before = $this->clock->now()->modify(\sprintf('-%d days', $retentionDays));

        /** @var AgentPreviewPurgeReport $report */
        $report = $this->systemActor->run(self::ACTOR, fn (): AgentPreviewPurgeReport => $this->purge($before, $retentionDays, $dryRun));

        $this->logger->info('Purged agent previews: {report}.', [
            'report' => (string) $report,
            'candidates' => $report->candidates,
            'deleted' => $report->deleted,
            'skipped' => $report->skipped,
            'dry_run' => $dryRun,
        ]);

        return $report;
    }

    private function purge(\DateTimeImmutable $before, int $retentionDays, bool $dryRun): AgentPreviewPurgeReport
    {
        $candidates = 0;
        $deleted = 0;
        $skipped = [];
        $evalRuns = 0;
        $conversations = 0;
        $after = null;

        do {
            $ids = $this->candidateIds($before, $after);
            foreach ($ids as $id) {
                $after = $id;
                $outcome = $dryRun ? $this->inspect($id, $before) : $this->delete($id, $before);
                if (null === $outcome) {
                    // Deleted or changed meanwhile: not a candidate any more.
                    continue;
                }

                ++$candidates;
                [$reason, $runs, $removed] = $outcome;
                if (null !== $reason) {
                    $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
                    continue;
                }

                ++$deleted;
                $evalRuns += $runs;
                $conversations += $removed;
            }
        } while (\count($ids) === self::BATCH_SIZE);

        return new AgentPreviewPurgeReport($candidates, $deleted, $skipped, $evalRuns, $conversations, $retentionDays, $dryRun);
    }

    /**
     * Deletes one due preview with its evaluation runs and conversations,
     * in its own transaction (see the class comment).
     *
     * @return array{?string, int, int}|null skip reason (null: deleted), eval runs and conversations removed; null when the preview is no candidate any more
     */
    private function delete(Uuid $id, \DateTimeImmutable $before): ?array
    {
        /** @var list<Uuid> $locked */
        $locked = [];

        try {
            return $this->entityManager->wrapInTransaction(function () use ($id, $before, &$locked): ?array {
                if (!$this->isDue($id, $before, true)) {
                    return null;
                }
                if (null !== $reason = $this->skipReason($id)) {
                    return [$reason, 0, 0];
                }

                $conversationIds = $this->conversationIds($id);
                foreach ($conversationIds as $conversationId) {
                    if (!$this->turnLock->acquire($conversationId)) {
                        return [AgentPreviewPurgeReport::SKIP_RUNNING_TURN, 0, 0];
                    }
                    $locked[] = $conversationId;
                }

                $runs = (int) $this->connection()->executeStatement(
                    'DELETE FROM neuron_eval_run WHERE agent_version_id = :id',
                    ['id' => $id->toRfc4122()],
                );

                $removed = 0;
                foreach ($conversationIds as $conversationId) {
                    $conversation = $this->entityManager->find(Conversation::class, $conversationId);
                    if ($conversation instanceof Conversation) {
                        $this->entityManager->remove($conversation);
                        ++$removed;
                    }
                }

                $version = $this->entityManager->find(AgentVersion::class, $id);
                if ($version instanceof AgentVersion) {
                    $this->entityManager->remove($version);
                }
                $this->entityManager->flush();

                return [null, $runs, $removed];
            });
        } finally {
            foreach ($locked as $conversationId) {
                $this->turnLock->release($conversationId);
            }
        }
    }

    /**
     * What {@see delete()} would do, without changing anything.
     *
     * @return array{?string, int, int}|null
     */
    private function inspect(Uuid $id, \DateTimeImmutable $before): ?array
    {
        if (!$this->isDue($id, $before, false)) {
            return null;
        }
        if (null !== $reason = $this->skipReason($id)) {
            return [$reason, 0, 0];
        }

        $conversationIds = $this->conversationIds($id);
        foreach ($conversationIds as $conversationId) {
            if ($this->turnLock->isHeld($conversationId)) {
                return [AgentPreviewPurgeReport::SKIP_RUNNING_TURN, 0, 0];
            }
        }

        $runs = (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM neuron_eval_run WHERE agent_version_id = :id',
            ['id' => $id->toRfc4122()],
        );

        return [null, $runs, \count($conversationIds)];
    }

    /**
     * Whether the row is still a preview older than $before; with $lock the
     * row is locked for the rest of the transaction.
     */
    private function isDue(Uuid $id, \DateTimeImmutable $before, bool $lock): bool
    {
        $sql = 'SELECT id FROM neuron_agent_version WHERE id = :id AND kind = :kind AND published_at < :before'.($lock ? ' FOR UPDATE' : '');

        return false !== $this->connection()->fetchOne(
            $sql,
            ['id' => $id->toRfc4122(), 'kind' => AgentVersionKind::PREVIEW->value, 'before' => $before],
            ['before' => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    /**
     * The first skip rule the preview meets, null when it may be deleted
     * (the turn locks of its conversations are checked separately).
     */
    private function skipReason(Uuid $id): ?string
    {
        $connection = $this->connection();
        $parameters = ['id' => $id->toRfc4122()];

        if (false !== $connection->fetchOne('SELECT 1 FROM neuron_agent WHERE current_version_id = :id LIMIT 1', $parameters)) {
            return AgentPreviewPurgeReport::SKIP_CURRENT_VERSION;
        }
        if (false !== $connection->fetchOne('SELECT 1 FROM neuron_conversation_replay WHERE target_version_id = :id LIMIT 1', $parameters)) {
            return AgentPreviewPurgeReport::SKIP_REPLAY_TARGET;
        }
        if (false !== $connection->fetchOne(
            'SELECT 1 FROM neuron_eval_run WHERE agent_version_id = :id AND status IN (:active) LIMIT 1',
            $parameters + ['active' => [EvalRunStatus::PENDING, EvalRunStatus::RUNNING]],
            ['active' => ArrayParameterType::STRING],
        )) {
            return AgentPreviewPurgeReport::SKIP_ACTIVE_EVAL_RUN;
        }

        return null;
    }

    /**
     * Ids of the conversations pinned to the version (any origin), in id
     * order, so turn locks are always taken in the same order.
     *
     * @return list<Uuid>
     */
    private function conversationIds(Uuid $id): array
    {
        $ids = $this->connection()->fetchFirstColumn(
            'SELECT id FROM neuron_conversation WHERE agent_version = :id ORDER BY id',
            ['id' => $id->toRfc4122()],
        );

        return array_values(array_map(static fn (mixed $value): Uuid => Uuid::fromString((string) $value), $ids));
    }

    /**
     * One keyset page of ids of previews created before $before.
     *
     * @return list<Uuid>
     */
    private function candidateIds(\DateTimeImmutable $before, ?Uuid $after): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('v.id')
            ->from(AgentVersion::class, 'v')
            ->where('v.kind = :kind AND v.publishedAt < :before')
            ->setParameter('kind', AgentVersionKind::PREVIEW->value, Types::STRING)
            ->setParameter('before', $before, Types::DATETIMETZ_IMMUTABLE)
            ->orderBy('v.id', 'ASC')
            ->setMaxResults(self::BATCH_SIZE);
        if (null !== $after) {
            $query->andWhere('v.id > :after')->setParameter('after', $after, 'uuid');
        }

        return array_values(array_map(
            static fn (array $row): Uuid => $row['id'] instanceof Uuid ? $row['id'] : Uuid::fromString((string) $row['id']),
            $query->getQuery()->getArrayResult(),
        ));
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
