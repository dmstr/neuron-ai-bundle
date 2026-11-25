<?php

// file generated with AI assistance: Claude Code - 2026-10-03 21:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Feedback;

use Dmstr\NeuronAiBundle\Anonymous\AnonymousCreationRateLimiter;
use Dmstr\NeuronAiBundle\Feedback\FeedbackRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Feedback submissions per subject on a cache storage: the
 * limit comes from the call, so a changed setting applies immediately;
 * subjects count separately; 0 is the caller's business. Kernel-free.
 */
final class FeedbackRateLimiterTest extends TestCase
{
    private FeedbackRateLimiter $limiter;

    protected function setUp(): void
    {
        $this->limiter = new FeedbackRateLimiter(new CacheStorage(new ArrayAdapter()));
    }

    public function testThirdSubmissionAtLimitTwoIsRefusedWithRetryAfter(): void
    {
        $this->limiter->consume('user-a', 2);
        $this->limiter->consume('user-a', 2);

        try {
            $this->limiter->consume('user-a', 2);
            self::fail('The third submission was accepted.');
        } catch (TooManyRequestsHttpException $e) {
            self::assertSame(429, $e->getStatusCode());
            self::assertArrayHasKey('Retry-After', $e->getHeaders());
            self::assertMatchesRegularExpression('/^\d+$/', (string) $e->getHeaders()['Retry-After']);
            self::assertGreaterThanOrEqual(1, (int) $e->getHeaders()['Retry-After']);
            // 3601: getRetryAfter()->getTimestamp() - time() can round up by one second.
            self::assertLessThanOrEqual(3601, (int) $e->getHeaders()['Retry-After']);
        }
    }

    public function testSubjectsCountSeparately(): void
    {
        $this->limiter->consume('user-a', 1);

        // A user identifier and a capability-token subject each have their own count.
        $this->limiter->consume('user-b', 1);
        $this->limiter->consume('anon:0199a7b2-0000-7000-8000-000000000001', 1);
        $this->addToAssertionCount(1);

        $this->expectException(TooManyRequestsHttpException::class);
        $this->limiter->consume('user-a', 1);
    }

    public function testRaisedLimitAppliesImmediately(): void
    {
        $this->limiter->consume('user-a', 2);
        $this->limiter->consume('user-a', 2);

        $this->limiter->consume('user-a', 3);

        $this->expectException(TooManyRequestsHttpException::class);
        $this->limiter->consume('user-a', 3);
    }

    public function testLoweredLimitAppliesImmediately(): void
    {
        $this->limiter->consume('user-a', 30);
        $this->limiter->consume('user-a', 30);

        $this->expectException(TooManyRequestsHttpException::class);
        $this->limiter->consume('user-a', 2);
    }

    public function testRefusedSubmissionsAreNotCounted(): void
    {
        $this->limiter->consume('user-a', 1);
        for ($i = 0; $i < 3; ++$i) {
            try {
                $this->limiter->consume('user-a', 1);
                self::fail('The limit of 1 was exceeded.');
            } catch (TooManyRequestsHttpException) {
            }
        }

        // With the limit raised to 2 exactly one more submission fits.
        $this->limiter->consume('user-a', 2);
        $this->expectException(TooManyRequestsHttpException::class);
        $this->limiter->consume('user-a', 2);
    }

    public function testResetForgetsTheCount(): void
    {
        $this->limiter->consume('user-a', 1);
        $this->limiter->reset('user-a');

        $this->limiter->consume('user-a', 1);
        $this->addToAssertionCount(1);
    }

    public function testLimitBelowOneIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->limiter->consume('user-a', 0);
    }

    public function testSharedStorageDoesNotMixWithOtherLimiters(): void
    {
        $storage = new CacheStorage(new ArrayAdapter());
        $feedback = new FeedbackRateLimiter($storage);
        $other = new AnonymousCreationRateLimiter($storage);

        $other->consume('same-key', 1);
        $feedback->consume('same-key', 1);
        $this->addToAssertionCount(1);
    }
}
