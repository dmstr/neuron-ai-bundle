<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:54:19 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Anonymous;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * Creations of anonymous conversations per client address within a sliding
 * hour.
 *
 * The limit is a runtime setting, so the limiter is not configured under
 * `framework.rate_limiter` (static limit) but built per call from the
 * current value. The sliding window policy keeps only hit counts in the
 * storage and reads the limit from the limiter, so a changed limit applies
 * to the next request. Without a lock factory concurrent requests of one
 * address can overshoot the limit slightly.
 *
 * A limit of 0 (creation disabled) must be handled by the caller: the
 * limiter rejects limits below 1.
 */
final class AnonymousCreationRateLimiter
{
    public const string ID = 'neuron_anonymous_creation';

    public const string INTERVAL = '1 hour';

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly ?LockFactory $lockFactory = null,
    ) {
    }

    /**
     * Counts one creation for the address.
     *
     * @param string $addressHash the keyed hash of the client address, never the raw address
     * @param int    $limit       creations per hour, at least 1
     *
     * @throws TooManyRequestsHttpException when the limit is used up (with `Retry-After`)
     * @throws \InvalidArgumentException    for a limit below 1
     */
    public function consume(string $addressHash, int $limit): void
    {
        $rateLimit = $this->limiter($addressHash, $limit)->consume();
        if ($rateLimit->isAccepted()) {
            return;
        }

        $retryAfter = max(1, $rateLimit->getRetryAfter()->getTimestamp() - time());

        throw new TooManyRequestsHttpException($retryAfter, 'Too many anonymous conversations were created from this address; try again later.');
    }

    /**
     * Forgets the count of the address (operators, tests).
     */
    public function reset(string $addressHash): void
    {
        $this->limiter($addressHash, 1)->reset();
    }

    private function limiter(string $addressHash, int $limit): LimiterInterface
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException(\sprintf('The creation limit must be at least 1, got %d; a limit of 0 disables creation before the limiter.', $limit));
        }

        $factory = new RateLimiterFactory(
            ['id' => self::ID, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => self::INTERVAL],
            $this->storage,
            $this->lockFactory,
        );

        return $factory->create($addressHash);
    }
}
