<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:54:19 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation\Anonymous;

use Dmstr\NeuronAiBundle\Anonymous\ClientAddressHasher;
use PHPUnit\Framework\TestCase;

/**
 * Keyed hash of client addresses. Kernel-free.
 */
final class ClientAddressHasherTest extends TestCase
{
    public function testHashIsADeterministicHmac(): void
    {
        $hasher = new ClientAddressHasher('key-a');

        self::assertSame($hasher->hash('198.51.100.7'), $hasher->hash('198.51.100.7'));
        self::assertSame(hash_hmac('sha256', '198.51.100.7', 'key-a'), $hasher->hash('198.51.100.7'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hasher->hash('198.51.100.7'));
        self::assertNotSame($hasher->hash('198.51.100.7'), $hasher->hash('198.51.100.8'));
    }

    public function testHashDependsOnTheKey(): void
    {
        self::assertNotSame((new ClientAddressHasher('key-a'))->hash('198.51.100.7'), (new ClientAddressHasher('key-b'))->hash('198.51.100.7'));
    }

    public function testAddressIsNormalized(): void
    {
        $hasher = new ClientAddressHasher('key-a');

        self::assertSame($hasher->hash('2001:db8::1'), $hasher->hash('2001:DB8::1'));
        self::assertSame($hasher->hash('198.51.100.7'), $hasher->hash(" 198.51.100.7\n"));
    }

    public function testEmptyKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClientAddressHasher('');
    }

    public function testBlankKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClientAddressHasher('   ');
    }
}
