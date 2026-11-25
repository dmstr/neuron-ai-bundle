<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:12 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\NeuronAiBundle\Conversation\TurnLock;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /conversations/{id}/cancel` (`conversation_cancel`): asks the
 * running turn of the conversation to stop.
 *
 * While a turn holds the conversation's lock ({@see TurnLock::isHeld()}),
 * `cancel_requested_at` is set with a plain DBAL update; the streaming turn
 * polls the column and ends with an `aborted` record. Without a running
 * turn the request is a no-op (200). The next turn clears the column when
 * it acquires the lock. The response is the conversation with `running`.
 *
 * @implements ProcessorInterface<Conversation, Conversation>
 */
final class ConversationCancelProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TurnLock $lock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Conversation
    {
        if (!$data instanceof Conversation) {
            throw new NotFoundHttpException('Not Found');
        }

        $running = $this->lock->isHeld($data->getId());
        if ($running) {
            $this->connection->executeStatement(
                'UPDATE neuron_conversation SET cancel_requested_at = now() WHERE id = :id',
                ['id' => $data->getId()->toRfc4122()],
            );
        }

        return $data->setRunning($running);
    }
}
