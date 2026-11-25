<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:54:19 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation\Anonymous;

use Dmstr\NeuronAiBundle\Anonymous\CapabilityToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Format, recognition and hash of the capability token of anonymous
 * conversations. Kernel-free.
 */
final class CapabilityTokenTest extends TestCase
{
    public function testGeneratedTokenHasThePrefixAndABase64UrlBodyOf32Bytes(): void
    {
        $token = (new CapabilityToken())->generate();

        self::assertStringStartsWith(CapabilityToken::PREFIX, $token);
        $body = substr($token, \strlen(CapabilityToken::PREFIX));
        // 32 bytes in base64url without padding: 43 characters.
        self::assertSame(43, \strlen($body));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $body);
        self::assertSame(32, \strlen((string) base64_decode(strtr($body, '-_', '+/'), true)));
        self::assertTrue(CapabilityToken::isCapabilityToken($token));
    }

    public function testTwoGenerationsDiffer(): void
    {
        $generator = new CapabilityToken();

        self::assertNotSame($generator->generate(), $generator->generate());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function values(): iterable
    {
        yield 'generated shape' => ['anon_Qm9vZ2llLXdvb2dpZV9ib29naWUtd29vZ2llLTEyMw', true];
        yield 'one character' => ['anon_x', true];
        yield 'dash and underscore' => ['anon_a-b_c', true];
        yield 'empty suffix' => ['anon_', false];
        yield 'prefix only without underscore' => ['anon', false];
        yield 'empty' => ['', false];
        yield 'jwt' => ['eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ4In0.c2ln', false];
        yield 'dot in the body' => ['anon_abc.def', false];
        yield 'padding' => ['anon_abc=', false];
        yield 'standard base64 characters' => ['anon_ab+c/d', false];
        yield 'whitespace' => ['anon_abc ', false];
        yield 'trailing newline' => ["anon_abc\n", false];
        yield 'upper-case prefix' => ['ANON_abc', false];
        yield 'prefix not at the start' => ['xanon_abc', false];
    }

    #[DataProvider('values')]
    public function testIsCapabilityToken(string $value, bool $expected): void
    {
        self::assertSame($expected, CapabilityToken::isCapabilityToken($value));
    }

    public function testHashIsTheSha256HexOfTheWholeToken(): void
    {
        $token = (new CapabilityToken())->generate();

        self::assertSame(hash('sha256', $token), CapabilityToken::hash($token));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', CapabilityToken::hash($token));
        self::assertNotSame(CapabilityToken::hash($token), CapabilityToken::hash(substr($token, \strlen(CapabilityToken::PREFIX))));
    }
}
