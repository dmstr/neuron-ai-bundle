<?php

// file generated with AI assistance: Claude Code - 2026-10-13 18:45:18 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /admin/collections/{id}/unlock` (`collection_unlock`,
 * `neuronai:collection:unlock`): clears the lock and returns the collection. An
 * unlocked collection stays unchanged (200, idempotent). The change is
 * audited (Gedmo loggable, the actor comes from the token).
 * {@see \Dmstr\NeuronAiBundle\Doctrine\CollectionLockListener} lets this
 * change of a locked collection through because only the lock fields
 * change.
 *
 * @implements ProcessorInterface<Collection, Collection>
 */
final class CollectionUnlockProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Collection
    {
        if (!$data instanceof Collection) {
            throw new NotFoundHttpException('Not Found');
        }
        if (!$data->isLocked()) {
            return $data;
        }

        $data->unlock();
        $this->entityManager->flush();

        return $data;
    }
}
