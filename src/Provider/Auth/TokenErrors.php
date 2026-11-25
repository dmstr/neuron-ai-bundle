<?php

// file generated with AI assistance: Claude Code - 2026-10-06 00:15:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider\Auth;

/**
 * Small helpers shared by the token layer for safe failure texts.
 *
 * @internal
 */
final class TokenErrors
{
    /** Maximum length of the Keycloak `error` / `error_description` part. */
    public const int DETAIL_MAX = 200;

    private function __construct()
    {
    }

    /**
     * The connection name for a message; never empty.
     */
    public static function name(string $connectionName): string
    {
        return '' === $connectionName ? '(unnamed)' : $connectionName;
    }

    public static function hasLineBreak(string $value): bool
    {
        return false !== strpbrk($value, "\r\n");
    }

    /**
     * Keycloak's `error` and `error_description` of an error response as one
     * line, cut to DETAIL_MAX chars; null when the body is no such JSON.
     * Every value in $redact is masked, should the endpoint echo it.
     *
     * @param list<string> $redact
     */
    public static function oauthDetail(?string $body, array $redact = []): ?string
    {
        if (null === $body || '' === $body) {
            return null;
        }
        try {
            $data = json_decode($body, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($data)) {
            return null;
        }

        $parts = [];
        foreach (['error', 'error_description'] as $field) {
            if (\is_string($data[$field] ?? null) && '' !== $data[$field]) {
                $parts[] = $data[$field];
            }
        }
        if ([] === $parts) {
            return null;
        }

        $detail = $parts[0].(isset($parts[1]) ? ': '.$parts[1] : '');
        foreach ($redact as $secret) {
            if ('' !== $secret) {
                $detail = str_replace($secret, '***', $detail);
            }
        }
        // One line, no control characters (log injection).
        $detail = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $detail);

        return mb_strlen($detail) > self::DETAIL_MAX ? mb_substr($detail, 0, self::DETAIL_MAX - 1).'…' : $detail;
    }
}
