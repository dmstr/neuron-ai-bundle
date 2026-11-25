<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

/**
 * The models of a provider connection could not be listed
 * ({@see ModelListerInterface}).
 *
 * The message is built from the connection name, the HTTP status and a
 * short reason and never contains a credential. The transport exception is
 * deliberately not chained as `previous`: it carries the request with its
 * headers (`x-api-key`), which an exception renderer could print.
 */
final class ModelListingException extends \RuntimeException
{
    /** Network error, timeout or HTTP 5xx. */
    public const string UNREACHABLE = 'UNREACHABLE';

    /** HTTP 401 or 403: the provider rejected the credentials. */
    public const string UNAUTHENTICATED = 'UNAUTHENTICATED';

    /** The provider answered, but not with the expected model list. */
    public const string PROTOCOL = 'PROTOCOL';

    /** The connection is no provider connection or its configuration is unusable. */
    public const string CONFIGURATION = 'CONFIGURATION';

    /**
     * @param self::* $reason
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly ?int $statusCode = null,
    ) {
        parent::__construct($message);
    }
}
