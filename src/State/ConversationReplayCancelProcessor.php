<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\NeuronAiBundle\Entity\ConversationReplay;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /conversations/{id}/replay_run/cancel` (`conversation_replay_cancel`;
 * `{id}` = the replay conversation): asks a pending or running
 * replay to stop and returns it (200).
 *
 * The operation has loaded the record and checked `neuronai:eval:write`.
 * Cancel is a flag: `cancelRequestedAt` is set (a second request keeps the
 * first time) and the replay job
 * ({@see \Dmstr\NeuronAiBundle\Replay\ConversationReplayRunner}) reads it
 * from the database before each turn and ends the replay `cancelled`; a
 * turn that is already running completes first. A replay that has already
 * ended answers 422, like {@see EvalRunCancelProcessor}.
 *
 * @implements ProcessorInterface<ConversationReplay, ConversationReplay>
 */
final class ConversationReplayCancelProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws UnprocessableEntityHttpException when the replay has already ended
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ConversationReplay
    {
        if (!$data instanceof ConversationReplay) {
            throw new NotFoundHttpException('Not Found');
        }
        if (!$data->isActive()) {
            throw new UnprocessableEntityHttpException(\sprintf('The conversation replay has already ended (status "%s").', $data->getStatus()));
        }

        if (!$data->isCancelRequested()) {
            $data->setCancelRequestedAt($this->clock->now());
            $this->entityManager->flush();
        }

        return $data;
    }
}
