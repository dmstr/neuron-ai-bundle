<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Replay;

use Dmstr\NeuronAiBundle\Anonymous\ConversationLimitException;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Refuses manual turns on a replay conversation (`origin` = `replay`)
 * while its replay has not ended: the replay job runs its
 * turns as the conversation's owner, and a turn in between would shift the
 * replayed messages.
 *
 * Called by the two manual entry points, `POST /conversations/{id}/messages`
 * ({@see \Dmstr\NeuronAiBundle\State\ConversationTurnProcessor}) and
 * `POST /conversations/{id}/stream`
 * ({@see \Dmstr\NeuronAiBundle\Controller\ConversationStreamController}),
 * never by the replay runner, which calls the turn service directly.
 *
 * The refusal is the 422 problem document of the turn gate
 * ({@see ConversationLimitException}) with the existing code
 * `conversation_closed` (no new code value): the conversation accepts no
 * manual turn for now. The status is read from the table, not from an
 * entity, because the job changes it in another process. Once the replay
 * has ended (`completed`, `partial`, `cancelled`, `failed`) the owner may
 * continue the conversation like any other, as long as it is active.
 */
final class ConversationReplayGuard
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws ConversationLimitException (422, code `conversation_closed`) while the conversation's replay is pending or running
     */
    public function assertAcceptsManualTurn(Conversation $conversation): void
    {
        if (Conversation::ORIGIN_REPLAY !== $conversation->getOrigin()) {
            return;
        }

        $status = $this->entityManager->getConnection()->fetchOne(
            'SELECT status FROM neuron_conversation_replay WHERE conversation_id = :id',
            ['id' => $conversation->getId()->toRfc4122()],
        );
        if (!\is_string($status) || !ConversationReplayStatus::isActive($status)) {
            return;
        }

        throw ConversationLimitException::create(
            ConversationLimitException::CODE_CLOSED,
            \sprintf('The conversation is a replay that has not ended yet (status "%s"); it accepts no manual turn until the replay has ended.', $status),
        );
    }
}
