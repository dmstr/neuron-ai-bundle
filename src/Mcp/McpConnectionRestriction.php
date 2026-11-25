<?php

// file generated with AI assistance: Claude Code - 2026-10-09 06:42:28 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp;

/**
 * Whether an `mcp` connection is restricted.
 *
 * A restricted connection may only be used through agent versions or by
 * the permission `neuronai:mcp_connection:use_restricted`; it must not be named ad hoc, e.g. in the
 * `mcpConnections` of a chat request. A connection is restricted when its
 * configJson says `restricted: true`, and always with `auth_type:
 * client_credentials`: its service account acts for every user, so the
 * flag cannot be switched off for it (the schema forces `restricted: true`
 * when the key is present, this check also covers configurations that
 * omit it).
 *
 * The configJson may be passed encrypted or decrypted; neither key is a
 * secret. Enforcement is up to the callers.
 *
 *     if (McpConnectionRestriction::isRestricted($connection->getConfigJson())) { ... }
 */
final class McpConnectionRestriction
{
    public const string KEY = 'restricted';

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $configJson the connection's configJson
     */
    public static function isRestricted(array $configJson): bool
    {
        return true === ($configJson[self::KEY] ?? null)
            || 'client_credentials' === ($configJson['auth_type'] ?? null);
    }
}
