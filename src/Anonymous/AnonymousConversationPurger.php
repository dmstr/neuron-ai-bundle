<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:26 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Anonymous;

use Dmstr\NeuronAiBundle\Conversation\ConversationStatus;
use Dmstr\NeuronAiBundle\Conversation\TurnLock;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Retention close and deletion of anonymous conversations, run by the
 * `neuron` schedule ({@see PurgeAnonymousConversationsHandler}) and by
 * `neuron:anonymous:purge`. One run, under the system actor
 * `system:neuron:purge` (the audit actor of every close and deletion):
 *
 * 1. reads the current {@see AnonymousAccessPolicy} once and the time once;
 * 2. closes every anonymous conversation that is not closed yet (active or
 *    archived) and was created at least `retentionDays` ago, with reason
 *    `retention` at the run's time (an audited `update`);
 * 3. deletes every closed anonymous conversation whose `closedAt` lies at
 *    least `deletionDelayDays` back, through the entity manager (an
 *    audited `remove`; its messages go with the database cascade). A
 *    closed conversation without `closedAt` is never deleted.
 *
 * Each conversation is handled under its turn lock ({@see TurnLock}): a
 * conversation whose lock another session holds (a running turn) is
 * skipped and counted, and taken by a later run. After the lock is taken
 * the row is read again with its conditions, so a conversation a turn
 * closed or a user deleted meanwhile is left alone. Candidates are read in
 * keyset pages of {@see BATCH_SIZE} ids and every conversation is flushed
 * and detached on its own, so memory stays bounded and a failure leaves the
 * earlier ones done. The run is idempotent: a second run directly after
 * the first finds nothing to do.
 *
 * Without an access policy (the application aliases none) a run logs a
 * warning and does nothing.
 */
final class AnonymousConversationPurger
{
    public const string ACTOR = 'system:neuron:purge';
    public const int BATCH_SIZE = 100;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TurnLock $turnLock,
        private readonly ConversationCloser $closer,
        private readonly SystemActor $systemActor,
        private readonly ?AnonymousAccessPolicyInterface $policy,
        private readonly ClockInterface $clock,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function run(): PurgeReport
    {
        if (null === $this->policy) {
            $this->logger->warning('Anonymous conversations are not purged: the application supplies no {interface}.', ['interface' => AnonymousAccessPolicyInterface::class]);

            return PurgeReport::notRun();
        }

        $policy = $this->policy->current();
        $now = $this->clock->now();

        /** @var PurgeReport $report */
        $report = $this->systemActor->run(self::ACTOR, function () use ($policy, $now): PurgeReport {
            $closeBefore = $now->modify(\sprintf('-%d days', $policy->retentionDays));
            [$closed, $skippedClose] = $this->closeExpired($closeBefore, $now);

            $deleteBefore = $now->modify(\sprintf('-%d days', $policy->deletionDelayDays));
            [$deleted, $skippedDelete] = $this->deleteDue($deleteBefore);

            return new PurgeReport($closed, $deleted, $skippedClose + $skippedDelete);
        });

        $this->logger->info('Purged anonymous conversations: {report}.', [
            'report' => (string) $report,
            'closed' => $report->closed,
            'deleted' => $report->deleted,
            'skipped' => $report->skipped,
        ]);

        return $report;
    }

    /**
     * @return array{int, int} closed and skipped
     */
    private function closeExpired(\DateTimeImmutable $createdBefore, \DateTimeImmutable $now): array
    {
        $where = 'c.anonymous = true AND c.status <> :closed AND c.createdAt <= :before';
        $parameters = ['closed' => [ConversationStatus::CLOSED, Types::STRING], 'before' => [$createdBefore, Types::DATETIMETZ_IMMUTABLE]];

        return $this->each($where, $parameters, function (Conversation $conversation) use ($now): bool {
            if (!$this->closer->close($conversation, Conversation::CLOSE_REASON_RETENTION, $now)) {
                return false;
            }
            $this->entityManager->flush();
            $this->entityManager->detach($conversation);

            return true;
        });
    }

    /**
     * @return array{int, int} deleted and skipped
     */
    private function deleteDue(\DateTimeImmutable $closedBefore): array
    {
        $where = 'c.anonymous = true AND c.status = :closed AND c.closedAt IS NOT NULL AND c.closedAt <= :before';
        $parameters = ['closed' => [ConversationStatus::CLOSED, Types::STRING], 'before' => [$closedBefore, Types::DATETIMETZ_IMMUTABLE]];

        return $this->each($where, $parameters, function (Conversation $conversation): bool {
            $this->entityManager->remove($conversation);
            $this->entityManager->flush();

            return true;
        });
    }

    /**
     * Applies $action to every conversation matching $where, in keyset
     * pages of ids, each under its turn lock and re-read with $where once
     * the lock is held.
     *
     * @param array<string, array{mixed, string}> $parameters name => [value, DBAL type]
     * @param callable(Conversation): bool          $action     true when it changed the conversation
     *
     * @return array{int, int} handled and skipped
     */
    private function each(string $where, array $parameters, callable $action): array
    {
        $handled = 0;
        $skipped = 0;
        $after = null;

        do {
            $ids = $this->candidateIds($where, $parameters, $after);
            foreach ($ids as $id) {
                $after = $id;
                if (!$this->turnLock->acquire($id)) {
                    ++$skipped;
                    continue;
                }

                try {
                    $conversation = $this->reload($id, $where, $parameters);
                    if (null !== $conversation && $action($conversation)) {
                        ++$handled;
                    }
                } finally {
                    $this->turnLock->release($id);
                }
            }
        } while (\count($ids) === self::BATCH_SIZE);

        return [$handled, $skipped];
    }

    /**
     * @param array<string, array{mixed, string}> $parameters name => [value, DBAL type]
     *
     * @return list<Uuid>
     */
    private function candidateIds(string $where, array $parameters, ?Uuid $after): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('c.id')
            ->from(Conversation::class, 'c')
            ->where($where)
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(self::BATCH_SIZE);
        foreach ($parameters as $name => [$value, $type]) {
            $query->setParameter($name, $value, $type);
        }
        if (null !== $after) {
            $query->andWhere('c.id > :after')->setParameter('after', $after, 'uuid');
        }

        return array_values(array_map(
            static fn (array $row): Uuid => $row['id'] instanceof Uuid ? $row['id'] : Uuid::fromString((string) $row['id']),
            $query->getQuery()->getArrayResult(),
        ));
    }

    /**
     * The conversation with its current database state if it still matches
     * $where; a managed instance is refreshed, not reused stale.
     *
     * @param array<string, array{mixed, string}> $parameters name => [value, DBAL type]
     */
    private function reload(Uuid $id, string $where, array $parameters): ?Conversation
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Conversation::class, 'c')
            ->where($where)
            ->andWhere('c.id = :id')
            ->setParameter('id', $id, 'uuid');
        foreach ($parameters as $name => [$value, $type]) {
            $query->setParameter($name, $value, $type);
        }

        $conversation = $query->getQuery()->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();

        return $conversation instanceof Conversation ? $conversation : null;
    }
}
