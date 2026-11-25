<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:54:19 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation\Anonymous;

use Dmstr\NeuronAiBundle\Anonymous\AnonymousCreationRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Creation limit per address hash on a cache storage: the limit comes from
 * the call, so a changed setting applies immediately; 0 is the caller's
 * business. Kernel-free.
 */
final class AnonymousCreationRateLimiterTest extends TestCase
{
    private AnonymousCreationRateLimiter $limiter;

    protected function setUp(): void
    {
        $this->limiter = new AnonymousCreationRateLimiter(new CacheStorage(new ArrayAdapter()));
    }

    public function testFourthCreationWithinTheHourIsRefusedWithRetryAfter(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->limiter->consume('hash-a', 3);
        }

        try {
            $this->limiter->consume('hash-a', 3);
            self::fail('The fourth creation was accepted.');
        } catch (TooManyRequestsHttpException $e) {
            self::assertSame(429, $e->getStatusCode());
            self::assertArrayHasKey('Retry-After', $e->getHeaders());
            self::assertGreaterThanOrEqual(1, (int) $e->getHeaders()['Retry-After']);
        }
    }

    public function testAnotherAddressIsUnaffected(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->limiter->consume('hash-a', 3);
        }

        $this->limiter->consume('hash-b', 3);
        $this->addToAssertionCount(1);
    }

    public function testRaisedLimitAppliesImmediately(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->limiter->consume('hash-a', 3);
        }

        // Two more fit under the raised limit, the sixth does not.
        $this->limiter->consume('hash-a', 5);
        $this->limiter->consume('hash-a', 5);

        $this->expectException(TooManyRequestsHttpException::class);
        $this->limiter->consume('hash-a', 5);
    }

    public function testLoweredLimitAppliesImmediately(): void
    {
        $this->limiter->consume('hash-a', 5);
        $this->limiter->consume('hash-a', 5);

        $this->expectException(TooManyRequestsHttpException::class);
        $this->limiter->consume('hash-a', 2);
    }

    public function testRefusedAttemptsAreNotCounted(): void
    {
        $this->limiter->consume('hash-a', 1);
        for ($i = 0; $i < 3; ++$i) {
            try {
                $this->limiter->consume('hash-a', 1);
                self::fail('The limit of 1 was exceeded.');
            } catch (TooManyRequestsHttpException) {
            }
        }

        // With the limit raised to 2 exactly one more creation fits.
        $this->limiter->consume('hash-a', 2);
        $this->expectException(TooManyRequestsHttpException::class);
        $this->limiter->consume('hash-a', 2);
    }

    public function testResetForgetsTheCount(): void
    {
        $this->limiter->consume('hash-a', 1);
        $this->limiter->reset('hash-a');

        $this->limiter->consume('hash-a', 1);
        $this->addToAssertionCount(1);
    }

    public function testLimitBelowOneIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->limiter->consume('hash-a', 0);
    }
}
