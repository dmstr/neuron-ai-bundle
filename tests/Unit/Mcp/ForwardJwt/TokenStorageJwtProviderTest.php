<?php

// file generated with AI assistance: Claude Code - 2026-10-09 13:43:23 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp\ForwardJwt;

use Dmstr\NeuronAiBundle\Security\TokenStorageJwtProvider;
use Dmstr\NeuronAiBundle\Testing\FakeCurrentJwtProvider;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Authenticator\Token\JWTPostAuthenticationToken;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Kernel-free checks of the JWT source of `forward_jwt` connections: only
 * Lexik's JWT token yields the JWT; the system actor's
 * PreAuthenticatedToken, other tokens and no token yield null.
 */
final class TokenStorageJwtProviderTest extends TestCase
{
    private const string JWT = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJwZXJzb24ifQ.c2lnbmF0dXJl';

    public function testLexikTokenYieldsTheJwt(): void
    {
        $storage = new TokenStorage();
        $storage->setToken(new JWTPostAuthenticationToken(new InMemoryUser('person', null, ['ROLE_USER']), 'api', ['ROLE_USER'], self::JWT));

        self::assertSame(self::JWT, (new TokenStorageJwtProvider($storage))->currentJwt());
    }

    public function testSystemActorTokenYieldsNull(): void
    {
        $storage = new TokenStorage();
        $storage->setToken(new PreAuthenticatedToken(new InMemoryUser('system', null, ['ROLE_ADMIN']), 'api', ['ROLE_ADMIN']));

        self::assertNull((new TokenStorageJwtProvider($storage))->currentJwt());
    }

    public function testNoTokenOrNoStorageYieldsNull(): void
    {
        self::assertNull((new TokenStorageJwtProvider(new TokenStorage()))->currentJwt());
        self::assertNull((new TokenStorageJwtProvider())->currentJwt());
    }

    public function testFakeReturnsWhatTheTestSet(): void
    {
        $fake = new FakeCurrentJwtProvider();
        self::assertNull($fake->currentJwt());

        self::assertSame(self::JWT, $fake->setJwt(self::JWT)->currentJwt());

        $fake->reset();
        self::assertNull($fake->currentJwt());
    }
}
