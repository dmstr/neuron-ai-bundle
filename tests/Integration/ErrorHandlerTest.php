<?php

// file generated with AI assistance: Claude Code - 2026-11-25 23:30:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A kernel booted outside a test method must not leave Symfony's
 * ErrorHandler behind as the root error handler: PHPUnit would forward
 * every warning to it, and in debug mode it throws them as ErrorException
 * (see `tests/Kernel/bootstrap.php`). The class boots the kernel in
 * setUpBeforeClass(), where no PHPUnit handler is active, and then reads a
 * missing array key, as tests do with properties a response leaves out.
 */
final class ErrorHandlerTest extends KernelTestCase
{
    public static function setUpBeforeClass(): void
    {
        static::bootKernel();
        static::ensureKernelShutdown();
    }

    public function testAWarningOfTestCodeIsNoErrorException(): void
    {
        $data = ['locked' => false];

        $lockedAt = $data['lockedAt'];

        self::assertNull($lockedAt);
    }
}
