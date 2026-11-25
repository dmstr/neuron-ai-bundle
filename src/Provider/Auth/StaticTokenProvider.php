<?php

// file generated with AI assistance: Claude Code - 2026-10-06 00:15:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider\Auth;

/**
 * The static `token` of a connection with `auth_type: bearer`. A rejected
 * static token cannot be renewed, so invalidate() never asks for a retry.
 */
final readonly class StaticTokenProvider implements AccessTokenProviderInterface
{
    /**
     * @throws ProviderAuthenticationException when the token is empty or
     *                                         contains CR/LF (header injection)
     */
    public function __construct(
        #[\SensitiveParameter]
        private string $token,
        string $connectionName = '',
    ) {
        if ('' === trim($token)) {
            throw new ProviderAuthenticationException(\sprintf('Ollama connection "%s" uses auth_type "bearer" without a "token".', TokenErrors::name($connectionName)), null, $connectionName);
        }
        if (TokenErrors::hasLineBreak($token)) {
            throw new ProviderAuthenticationException(\sprintf('The bearer token of Ollama connection "%s" contains a line break and is refused.', TokenErrors::name($connectionName)), null, $connectionName);
        }
    }

    public function token(): string
    {
        return $this->token;
    }

    public function invalidate(): bool
    {
        return false;
    }
}
