<?php

// file generated with AI assistance: Claude Code - 2026-10-09 13:43:23 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Security\Authenticator\Token\JWTPostAuthenticationToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Reads the JWT from the security token in the token storage, never from
 * the request headers: only Lexik's {@see JWTPostAuthenticationToken}
 * carries one. {@see \Dmstr\NeuronAiBundle\Anonymous\SystemActor::run()}
 * swaps in a PreAuthenticatedToken, and capability tokens and the test
 * authenticator create other tokens, so all of them yield null although the
 * request may still carry an `Authorization` header.
 *
 * Without the Lexik bundle no token is ever a JWTPostAuthenticationToken
 * (the instanceof check does not autoload a missing class), and without
 * a token storage (no security bundle) the result is always null.
 */
final class TokenStorageJwtProvider implements CurrentJwtProviderInterface
{
    public function __construct(
        private readonly ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public function currentJwt(): ?string
    {
        $token = $this->tokenStorage?->getToken();
        if (!$token instanceof JWTPostAuthenticationToken) {
            return null;
        }
        $jwt = $token->getCredentials();

        return '' === $jwt ? null : $jwt;
    }
}
