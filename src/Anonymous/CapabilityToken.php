<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:54:19 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Anonymous;

/**
 * The secret capability token of an anonymous conversation:
 * `anon_` followed by the base64url encoding (RFC 4648 §5, no padding) of
 * 32 random bytes. It is returned once, by the creation; only its SHA-256
 * hash is stored (`neuron_conversation.token_hash`).
 *
 * The prefix is reserved in the `Authorization: Bearer` space: JWTs start
 * with `eyJ` and contain dots, a capability token never does, so the JWT
 * extractor and the capability authenticator can tell them apart with
 * {@see isCapabilityToken()}.
 */
final class CapabilityToken
{
    public const string PREFIX = 'anon_';

    private const int RANDOM_BYTES = 32;

    /**
     * True for `anon_` followed by at least one base64url character and
     * nothing else.
     */
    public static function isCapabilityToken(string $value): bool
    {
        return 1 === preg_match('/^'.preg_quote(self::PREFIX, '/').'[A-Za-z0-9_-]+$/D', $value);
    }

    /**
     * Lowercase SHA-256 hex digest (64 characters) of the complete token,
     * prefix included.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * A new random token.
     */
    public function generate(): string
    {
        return self::PREFIX.rtrim(strtr(base64_encode(random_bytes(self::RANDOM_BYTES)), '+/', '-_'), '=');
    }
}
