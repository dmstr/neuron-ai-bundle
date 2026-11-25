<?php

// file generated with AI assistance: Claude Code - 2026-10-09 13:43:23 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Security;

/**
 * The raw JWT the current request was authenticated with, for MCP
 * connections with `auth_type: forward_jwt` that call their server as the
 * person chatting.
 *
 * Only a JWT authentication counts: a request authenticated otherwise
 * (capability token, test authenticator) and code running under
 * {@see \Dmstr\NeuronAiBundle\Anonymous\SystemActor} yield null. Under
 * `when@test` the app aliases this interface to
 * {@see \Dmstr\NeuronAiBundle\Testing\FakeCurrentJwtProvider}.
 */
interface CurrentJwtProviderInterface
{
    /**
     * The raw JWT (header.payload.signature), or null when the current
     * security token is not a JWT authentication.
     */
    public function currentJwt(): ?string;
}
