<?php

// file generated with AI assistance: Claude Code - 2026-10-13 18:45:18 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * `POST /admin/collections/{id}/lock` (`collection_lock`, `neuronai:collection:lock`): locks
 * the collection for the current user (`lockedBy` is the user identifier,
 * the Keycloak subject) and returns it. A locked collection keeps its lock
 * unchanged (200, idempotent). The change is audited (Gedmo loggable).
 *
 * @implements ProcessorInterface<Collection, Collection>
 */
final class CollectionLockProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Collection
    {
        if (!$data instanceof Collection) {
            throw new NotFoundHttpException('Not Found');
        }
        if ($data->isLocked()) {
            return $data;
        }

        $token = $this->tokenStorage->getToken();
        $identifier = null !== $token?->getUser() ? $token->getUserIdentifier() : '';
        if ('' === $identifier) {
            throw new AccessDeniedException('Locking a collection requires an authenticated user.');
        }

        $data->lock($identifier, new \DateTimeImmutable());
        $this->entityManager->flush();

        return $data;
    }
}
