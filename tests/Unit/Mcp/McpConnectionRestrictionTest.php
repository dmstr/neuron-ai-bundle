<?php

// file generated with AI assistance: Claude Code - 2026-10-09 06:42:28 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp;

use Dmstr\NeuronAiBundle\Mcp\McpConnectionRestriction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the restriction rule of `mcp` connections.
 */
final class McpConnectionRestrictionTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function configs(): iterable
    {
        yield 'empty' => [[], false];
        yield 'no auth, flag omitted' => [['auth_type' => 'none'], false];
        yield 'no auth, flag false' => [['auth_type' => 'none', 'restricted' => false], false];
        yield 'no auth, flag true' => [['auth_type' => 'none', 'restricted' => true], true];
        yield 'bearer, flag true' => [['auth_type' => 'bearer', 'token' => 'x', 'restricted' => true], true];
        yield 'header, flag omitted' => [['auth_type' => 'header'], false];
        yield 'flag truthy but not true' => [['restricted' => 1], false];
        yield 'flag as string' => [['restricted' => 'true'], false];
        yield 'client credentials, flag omitted' => [['auth_type' => 'client_credentials'], true];
        yield 'client credentials, flag true' => [['auth_type' => 'client_credentials', 'restricted' => true], true];
        yield 'client credentials, flag false (schema forbids it)' => [['auth_type' => 'client_credentials', 'restricted' => false], true];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('configs')]
    public function testIsRestricted(array $config, bool $expected): void
    {
        self::assertSame($expected, McpConnectionRestriction::isRestricted($config));
    }
}
