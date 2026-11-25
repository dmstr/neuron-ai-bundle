<?php

// file generated with AI assistance: Claude Code - 2026-11-25 21:50:20 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Kernel;

use Gedmo\Tool\ActorProviderInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Actor of audit-log entries: the identifier of the authenticated user
 * (`test-admin`, `test-user`, `anon:<id>` of a capability token, or the
 * identifier of the bundle's system actor), null without one. A host
 * application wires the same kind of provider into the Gedmo loggable
 * listener ({@see TestKernel::process()}).
 */
final class TestActorProvider implements ActorProviderInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    /**
     * @return string|null
     */
    public function getActor(): ?string
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return null !== $user ? $user->getUserIdentifier() : null;
    }
}
