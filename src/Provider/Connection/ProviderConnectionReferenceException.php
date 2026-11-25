<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider\Connection;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A provider connection reference that does not resolve
 * ({@see ProviderConnectionResolver}). HTTP 422 by itself; `reason` lets a
 * caller map it to a violation at its own field or to a stale reference of
 * a stored version, conversation or collection.
 */
final class ProviderConnectionReferenceException extends UnprocessableEntityHttpException
{
    /** The reference is empty. */
    public const string EMPTY = 'empty';

    /** No connection matches the reference. */
    public const string UNKNOWN = 'unknown';

    /** The connection exists but is not active. */
    public const string INACTIVE = 'inactive';

    /** The connection is of another type (`mcp`, `firecrawl`, or `anthropic` where only embedding types are allowed). */
    public const string WRONG_TYPE = 'wrong_type';

    /** Several connections match the reference. */
    public const string AMBIGUOUS = 'ambiguous';

    /**
     * @param self::EMPTY|self::UNKNOWN|self::INACTIVE|self::WRONG_TYPE|self::AMBIGUOUS $reason
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly string $reference,
    ) {
        parent::__construct($message);
    }
}
