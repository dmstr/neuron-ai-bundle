<?php

// file generated with AI assistance: Claude Code - 2026-10-06 00:15:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider\Auth;

/**
 * Source of the bearer token AuthenticatingHttpClient sends. Asked on every
 * request, because a provider instance (e.g. in the messenger worker) can
 * live longer than a token.
 */
interface AccessTokenProviderInterface
{
    /**
     * The token to send now; never contains CR or LF.
     *
     * @throws ProviderAuthenticationException when no token can be obtained
     */
    public function token(): string;

    /**
     * Discards the current token after the provider rejected it (401/403).
     *
     * @return bool whether token() can yield a different token, i.e.
     *              whether retrying the request makes sense
     */
    public function invalidate(): bool;
}
