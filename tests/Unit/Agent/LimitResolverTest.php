<?php

// file generated with AI assistance: Claude Code - 2026-10-02 21:49:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Agent;

use Dmstr\NeuronAiBundle\Agent\LimitResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free: the order version, settings, bundle default, and "a request
 * may only lower the limit".
 */
final class LimitResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ?int, 1: ?int, 2: ?int, 3: ?int, 4: ?int}>
     */
    public static function cases(): iterable
    {
        // version, settings, bundle, request => expected
        yield 'version wins over settings and bundle' => [5, 7, 9, null, 5];
        yield 'settings when the version is null' => [null, 7, 9, null, 7];
        yield 'bundle when version and settings are null' => [null, null, 9, null, 9];
        yield 'all null is unbounded' => [null, null, null, null, null];
        yield 'request lowers the version' => [5, null, null, 3, 3];
        yield 'request does not raise the version' => [5, null, null, 8, 5];
        yield 'request equal to the version' => [5, null, null, 5, 5];
        yield 'request lowers the settings default' => [null, 7, 9, 4, 4];
        yield 'request does not raise the settings default' => [null, 7, 9, 10, 7];
        yield 'request lowers the bundle default' => [null, null, 9, 2, 2];
        yield 'request does not raise the bundle default' => [null, null, 9, 12, 9];
        yield 'request applies without any base' => [null, null, null, 6, 6];
        yield 'settings null falls through to the bundle' => [null, null, 1, null, 1];
        yield 'version below the request with settings and bundle set' => [2, 7, 9, 4, 2];
    }

    #[DataProvider('cases')]
    public function testResolve(?int $version, ?int $settings, ?int $bundle, ?int $request, ?int $expected): void
    {
        self::assertSame($expected, new LimitResolver()->resolve($version, $settings, $bundle, $request));
    }
}
