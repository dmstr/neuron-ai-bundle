<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

/**
 * A chosen model failed {@see ModelAvailabilityCheck::assertAvailable()}.
 *
 * `reason` tells the two cases apart: {@see self::NOT_OFFERED} (the
 * provider answered and does not list the model) and
 * {@see self::UNREACHABLE} (the model could not be verified because the
 * provider could not be listed: unreachable, credentials rejected, unusable
 * answer). Callers map both to a 422 at the model field (the anonymous path
 * to its 503 contract) and use the message as the violation text; it never
 * contains a credential.
 */
final class ModelUnavailableException extends \RuntimeException
{
    public const string NOT_OFFERED = 'not_offered';
    public const string UNREACHABLE = 'unreachable';

    /**
     * @param self::NOT_OFFERED|self::UNREACHABLE $reason
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly string $connectionName,
        public readonly string $model,
        public readonly ?ModelListingException $listingFailure = null,
    ) {
        parent::__construct($message);
    }

    public function isNotOffered(): bool
    {
        return self::NOT_OFFERED === $this->reason;
    }

    public function isUnreachable(): bool
    {
        return self::UNREACHABLE === $this->reason;
    }
}
