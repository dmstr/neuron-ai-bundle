<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:52:51 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation\Anonymous;

use Dmstr\NeuronAiBundle\Security\JwtTokenExtractorDecorator;
use Lexik\Bundle\JWTAuthenticationBundle\TokenExtractor\TokenExtractorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kernel-free test of the JWT extractor decorator: capability tokens
 * (`anon_` without dots) count as "no token" for Lexik's JWT authenticator,
 * everything else passes through unchanged.
 */
final class JwtTokenExtractorDecoratorTest extends TestCase
{
    /**
     * @return iterable<string, array{string|false, string|false}>
     */
    public static function extracted(): iterable
    {
        yield 'no token' => [false, false];
        yield 'JWT' => ['eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ1c2VyIn0.c2lnbmF0dXJl', 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ1c2VyIn0.c2lnbmF0dXJl'];
        yield 'capability token' => ['anon_Q2FwYWJpbGl0eS10b2tlbi1maXh0dXJl', false];
        yield 'capability prefix with a dot' => ['anon_a.b', 'anon_a.b'];
        yield 'empty suffix' => ['anon_', 'anon_'];
    }

    #[DataProvider('extracted')]
    public function testCapabilityTokensAreHidden(string|false $inner, string|false $expected): void
    {
        $decorator = new JwtTokenExtractorDecorator(self::inner($inner));

        self::assertSame($expected, $decorator->extract(Request::create('/api/conversations')));
    }

    private static function inner(string|false $value): TokenExtractorInterface
    {
        return new class($value) implements TokenExtractorInterface {
            public function __construct(private readonly string|false $value)
            {
            }

            public function extract(Request $request): string|false
            {
                return $this->value;
            }
        };
    }
}
