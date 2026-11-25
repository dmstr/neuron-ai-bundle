<?php

// file generated with AI assistance: Claude Code - 2026-10-09 13:43:23 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Testing;

use Dmstr\NeuronAiBundle\Security\CurrentJwtProviderInterface;

/**
 * JWT source for tests: returns whatever the test set, null by default (no
 * JWT, as for the test authenticator and the system actor). Under
 * `when@test` the app aliases
 * {@see CurrentJwtProviderInterface} to this class (public service):
 *
 *     static::getContainer()->get(FakeCurrentJwtProvider::class)->setJwt('header.payload.signature');
 *     // ... a turn on a forward_jwt connection now forwards this JWT ...
 *
 * State lives in this service instance: set it after the kernel serving
 * the code under test has booted and do not rely on it across kernel
 * reboots.
 */
class FakeCurrentJwtProvider implements CurrentJwtProviderInterface
{
    public function __construct(
        #[\SensitiveParameter]
        private ?string $jwt = null,
    ) {
    }

    public function setJwt(#[\SensitiveParameter] ?string $jwt): self
    {
        $this->jwt = $jwt;

        return $this;
    }

    public function currentJwt(): ?string
    {
        return $this->jwt;
    }

    public function reset(): void
    {
        $this->jwt = null;
    }
}
