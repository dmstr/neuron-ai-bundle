<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Replay;

use Dmstr\NeuronAiBundle\Anonymous\ConversationLimitException;
use Dmstr\NeuronAiBundle\Anonymous\SystemActor;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\ConversationReplay;
use Dmstr\NeuronAiBundle\Entity\Message;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Replays the frozen source user messages of a {@see ConversationReplay}
 * as turns of its replay conversation; driven by
 * {@see \Dmstr\NeuronAiBundle\JobProcessor\ConversationReplayJobProcessor}.
 *
 * 1. A pending replay becomes `running` (`startedAt` is kept on a
 *    redelivery);
 * 2. as many source messages are skipped as the replay conversation already
 *    holds user messages (counted in the table, not `turnCount`), so a
 *    redelivered job resumes instead of replaying twice; a user message
 *    stored by a worker that died before the answer counts as replayed
 *    (the next turn marks that turn `aborted`, it is not repeated);
 * 3. before each turn the replay row is read from the database (not from
 *    the cached entity, the cancel request is another process): a gone row
 *    (the replay conversation was deleted) stops; a set cancel flag ends
 *    the replay `cancelled`; a replay conversation that no longer accepts
 *    turns (closed by the target version's user-turn limit) ends it
 *    `partial`; a source message that no longer exists (the source was
 *    deleted) ends it `failed`;
 * 4. every message runs as one turn through the turn callable
 *    (`ConversationTurnService::turn()`, the same non-streaming turn as
 *    `POST /conversations/{id}/messages`), which takes instructions, tools,
 *    rules, templates and limits from the pinned target version, under the
 *    audit actor `ownerSubject` ({@see SystemActor}): the audit entries of
 *    the turns name the triggering user, as the creation's do. A turn whose
 *    final record is `error` or a rule refusal is a replayed turn like any
 *    other; the turn gate refusing ({@see ConversationLimitException}) ends
 *    the replay `partial`; another turn holding the lock (a second delivery
 *    of the job) stops without a change; any other exception (a stale
 *    reference of the target version, a database error) ends it `failed`
 *    with the message;
 * 5. after each turn `turnsReplayed` is updated, everything is flushed and
 *    the checkpoint is called (the job writes `progress` there);
 * 6. once every message ran the replay is `completed`, also when the last
 *    turn closed the conversation.
 *
 * Entity manager: a database error inside a turn's transaction closes it;
 * the manager is then reset through the registry and the replay fetched
 * again before it is ended `failed`.
 */
final class ConversationReplayRunner
{
    /** Longest stored error message of a failed replay. */
    public const int MAX_ERROR_LENGTH = 2000;

    /** @var \Closure(Conversation, string): Message */
    private readonly \Closure $turn;

    private readonly LoggerInterface $logger;

    /**
     * @param \Closure(Conversation, string): Message $turn one non-streaming turn ({@see \Dmstr\NeuronAiBundle\Conversation\ConversationTurnService::turn()})
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $registry,
        \Closure $turn,
        private readonly SystemActor $systemActor,
        private readonly ClockInterface $clock,
        ?LoggerInterface $logger = null,
    ) {
        $this->turn = $turn;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Runs the outstanding turns of $replay.
     *
     * @param callable(ConversationReplay): void|null $checkpoint called after the start and after every turn, with
     *                                                    everything flushed
     *
     * @return ConversationReplay|null the replay in its state after the run (a fresh instance when the entity
     *                                 manager had to be reset), null when its row is gone
     */
    public function run(ConversationReplay $replay, ?callable $checkpoint = null): ?ConversationReplay
    {
        $replayId = $replay->getId();

        if (ConversationReplayStatus::PENDING === $replay->getStatus()) {
            $replay->setStatus(ConversationReplayStatus::RUNNING);
        }
        if (null === $replay->getStartedAt()) {
            $replay->setStartedAt($this->clock->now());
        }

        $sourceIds = $replay->getSourceMessageIds();
        $replayed = $this->replayedCount($replay->getConversation());
        $replay->setTurnsReplayed($replayed);
        $this->entityManager->flush();
        if (null !== $checkpoint) {
            $checkpoint($replay);
        }

        for ($index = $replayed, $total = \count($sourceIds); $index < $total; ++$index) {
            $state = $this->storedState($replayId);
            if (null === $state) {
                return null;
            }
            if ($state['cancelRequested']) {
                $this->entityManager->refresh($replay);

                return $this->end($replay, ConversationReplayStatus::CANCELLED);
            }

            $conversation = $replay->getConversation();
            if (!$conversation->acceptsTurns()) {
                return $this->end($replay, ConversationReplayStatus::PARTIAL);
            }

            $source = $this->entityManager->find(Message::class, Uuid::fromString($sourceIds[$index]));
            if (!$source instanceof Message) {
                return $this->end($replay, ConversationReplayStatus::FAILED, \sprintf(
                    'Source message %d of %d no longer exists (the source conversation was deleted); %d turns were replayed.',
                    $index + 1,
                    $total,
                    $index,
                ));
            }
            $content = (string) $source->getContent();

            try {
                $this->systemActor->run($replay->getOwnerSubject(), fn (): Message => ($this->turn)($conversation, $content));
            } catch (ConversationLimitException) {
                $replay = $this->reload($replayId);

                return null === $replay ? null : $this->end($replay, ConversationReplayStatus::PARTIAL);
            } catch (ConflictHttpException $e) {
                // Another worker runs a turn of this conversation (a second
                // delivery of the job): leave the replay to it.
                $this->logger->warning('Conversation replay {replay} stopped: {message}', ['replay' => (string) $replayId, 'message' => $e->getMessage()]);

                return $replay;
            } catch (\Throwable $e) {
                $message = '' !== $e->getMessage() ? $e->getMessage() : $e::class;
                $this->logger->warning('Conversation replay {replay} failed at source message {index}: {message}', [
                    'replay' => (string) $replayId,
                    'index' => $index + 1,
                    'message' => $message,
                    'exception' => $e,
                ]);
                $replay = $this->reload($replayId);

                return null === $replay ? null : $this->end($replay, ConversationReplayStatus::FAILED, $message);
            }

            if (!$this->entityManager->isOpen()) {
                $replay = $this->reload($replayId);
                if (null === $replay) {
                    return null;
                }
            }

            $replay->setTurnsReplayed($this->replayedCount($replay->getConversation()));
            $this->entityManager->flush();
            if (null !== $checkpoint) {
                $checkpoint($replay);
            }
        }

        return $this->end($replay, ConversationReplayStatus::COMPLETED);
    }

    /**
     * Ends the replay with $status (and $error for `failed`), with the
     * replayed count as stored.
     */
    private function end(ConversationReplay $replay, string $status, ?string $error = null): ConversationReplay
    {
        $replay
            ->setTurnsReplayed($this->replayedCount($replay->getConversation()))
            ->finish($status, $this->clock->now(), null === $error ? null : mb_substr($error, 0, self::MAX_ERROR_LENGTH));
        $this->entityManager->flush();

        return $replay;
    }

    /**
     * The replay fetched again, after resetting a closed entity manager.
     */
    private function reload(Uuid $replayId): ?ConversationReplay
    {
        if (!$this->entityManager->isOpen()) {
            $this->logger->error('The entity manager was closed by a turn of conversation replay {replay}; resetting it.', ['replay' => (string) $replayId]);
            $this->registry->resetManager();
        }

        // The manager the registry hands out after a reset is a new
        // instance; the injected one is the lazy proxy that delegates to it.
        $replay = $this->entityManager->find(ConversationReplay::class, $replayId);
        if ($replay instanceof ConversationReplay) {
            $this->entityManager->refresh($replay);
        }

        return $replay instanceof ConversationReplay ? $replay : null;
    }

    /**
     * User messages the replay conversation holds.
     */
    private function replayedCount(Conversation $conversation): int
    {
        return $this->entityManager->getRepository(Message::class)->count([
            'conversation' => $conversation,
            'role' => Message::SPEAKER_USER,
        ]);
    }

    /**
     * The cancel flag as stored, bypassing the identity map; null when the
     * row is gone.
     *
     * @return array{cancelRequested: bool}|null
     */
    private function storedState(Uuid $replayId): ?array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT cancel_requested_at FROM neuron_conversation_replay WHERE id = :id',
            ['id' => $replayId->toRfc4122()],
        );
        if (false === $row) {
            return null;
        }

        return ['cancelRequested' => null !== $row['cancel_requested_at']];
    }
}
