<?php

// file generated with AI assistance: Claude Code - 2026-10-09 13:43:23 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp;

/**
 * What a transport is created for, passed to
 * {@see McpTransportFactoryInterface::create()}.
 *
 * Only `auth_type: forward_jwt` depends on it: a `turn` context with the
 * JWT of the person chatting sends that JWT as bearer; every other context
 * (`scan`, `health`, a `turn` without JWT, or no context at all) uses the
 * connection's scan service account. Other auth types ignore the context.
 *
 * The JWT is captured when the transport is created (tool building, during
 * the request), never read later: a streamed turn runs its tools after the
 * request has ended.
 *
 *     $transportFactory->create($config, McpTransportContext::turn($jwtProvider->currentJwt()));
 */
final readonly class McpTransportContext
{
    public const string PURPOSE_SCAN = 'scan';
    public const string PURPOSE_HEALTH = 'health';
    public const string PURPOSE_TURN = 'turn';

    /**
     * @param string      $purpose one of the PURPOSE_* constants
     * @param string|null $jwt     raw JWT of the person chatting (turn only); '' counts as none
     */
    private function __construct(
        public string $purpose,
        #[\SensitiveParameter]
        public ?string $jwt = null,
    ) {
    }

    /**
     * Catalogue scan and sync.
     */
    public static function scan(): self
    {
        return new self(self::PURPOSE_SCAN);
    }

    /**
     * Health check of the connection.
     */
    public static function health(): self
    {
        return new self(self::PURPOSE_HEALTH);
    }

    /**
     * Tools of a chat or conversation turn; $jwt is the raw JWT of the
     * person chatting, null for a turn without one (system actor: replay,
     * batch evaluation; capability tokens).
     */
    public static function turn(#[\SensitiveParameter] ?string $jwt): self
    {
        return new self(self::PURPOSE_TURN, '' === $jwt ? null : $jwt);
    }

    /**
     * Whether a `forward_jwt` connection sends the JWT of this context
     * instead of the scan service account's token.
     */
    public function forwardsJwt(): bool
    {
        return self::PURPOSE_TURN === $this->purpose && null !== $this->jwt;
    }

    /**
     * Keeps the JWT out of dumps.
     *
     * @return array{purpose: string, jwt: string|null}
     */
    public function __debugInfo(): array
    {
        return ['purpose' => $this->purpose, 'jwt' => null === $this->jwt ? null : '********'];
    }
}
