<?php

// file generated with AI assistance: Claude Code - 2026-10-03 21:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Feedback;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * Feedback submissions per subject (user identifier, or the conversation's
 * `ownerSubject` for a capability token) within a sliding hour.
 *
 * Built like {@see \Dmstr\NeuronAiBundle\Anonymous\AnonymousCreationRateLimiter}:
 * the limit is the runtime setting `evaluation.feedback_per_subject_and_hour`,
 * so the limiter is built per call from the current value and a changed
 * limit applies to the next request; the sliding window keeps only hit
 * counts in the storage (`dmstr_neuron_ai.anonymous.rate_limiter_storage`).
 * Without a lock factory concurrent requests of one subject can overshoot
 * the limit slightly.
 *
 * A limit of 0 (feedback disabled) must be handled by the caller: the
 * limiter rejects limits below 1.
 */
final class FeedbackRateLimiter
{
    public const string ID = 'neuron_feedback';

    public const string INTERVAL = '1 hour';

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly ?LockFactory $lockFactory = null,
    ) {
    }

    /**
     * Counts one submission of the subject.
     *
     * @param string $subject the submitter's user identifier
     * @param int    $limit   submissions per hour, at least 1
     *
     * @throws TooManyRequestsHttpException when the limit is used up (with `Retry-After` in seconds)
     * @throws \InvalidArgumentException    for a limit below 1
     */
    public function consume(string $subject, int $limit): void
    {
        $rateLimit = $this->limiter($subject, $limit)->consume();
        if ($rateLimit->isAccepted()) {
            return;
        }

        $retryAfter = max(1, $rateLimit->getRetryAfter()->getTimestamp() - time());

        throw new TooManyRequestsHttpException($retryAfter, 'Too much feedback was submitted within the last hour; try again later.');
    }

    /**
     * Forgets the count of the subject (operators, tests).
     */
    public function reset(string $subject): void
    {
        $this->limiter($subject, 1)->reset();
    }

    private function limiter(string $subject, int $limit): LimiterInterface
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException(\sprintf('The feedback limit must be at least 1, got %d; a limit of 0 disables feedback before the limiter.', $limit));
        }

        $factory = new RateLimiterFactory(
            ['id' => self::ID, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => self::INTERVAL],
            $this->storage,
            $this->lockFactory,
        );

        return $factory->create($subject);
    }
}
