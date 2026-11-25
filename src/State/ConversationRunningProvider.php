<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:34:45 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Dmstr\NeuronAiBundle\Conversation\TurnLock;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Doctrine\DBAL\Connection;

/**
 * Item provider of `GET /conversations/{id}`: loads the conversation through
 * the Doctrine item provider (including the partial-UUID decoration of
 * api-platform-utils, which decorates the same service) and sets the
 * read-only `running` flag.
 *
 * A turn holds the PostgreSQL advisory lock {@see TurnLock::keyFor()} for
 * its whole run, and the lock dies with the worker, so "running" is derived
 * from `pg_locks` instead of being stored: one query on the default
 * connection ({@see TurnLock::isHeld()}). The provider keeps the connection
 * as its constructor argument and wraps it in its own {@see TurnLock}, so
 * its service definition did not change.
 *
 * @implements ProviderInterface<Conversation>
 */
final class ConversationRunningProvider implements ProviderInterface
{
    private readonly TurnLock $lock;

    /**
     * @param ProviderInterface<object> $itemProvider
     */
    public function __construct(
        private readonly ProviderInterface $itemProvider,
        Connection $connection,
    ) {
        $this->lock = new TurnLock($connection);
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $conversation = $this->itemProvider->provide($operation, $uriVariables, $context);
        if (!$conversation instanceof Conversation) {
            return $conversation;
        }

        $conversation->setRunning($this->lock->isHeld($conversation->getId()));

        return $conversation;
    }
}
