<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:52:51 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation\Anonymous;

use Dmstr\NeuronAiBundle\Anonymous\CapabilityToken;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Security\AnonymousConversationUser;
use Dmstr\NeuronAiBundle\Security\CapabilityTokenAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Uid\Uuid;

/**
 * Kernel-free test of the capability-token authenticator: which
 * `Authorization` headers it claims, the principal it builds from the
 * conversation found by the token hash, and that neither the exception nor
 * the failure response ever echoes the presented token.
 */
final class CapabilityTokenAuthenticatorTest extends TestCase
{
    private const string TOKEN = 'anon_Q2FwYWJpbGl0eS10b2tlbi1maXh0dXJlLTAx';

    /**
     * @return iterable<string, array{string|null, bool}>
     */
    public static function headers(): iterable
    {
        yield 'no header' => [null, false];
        yield 'empty header' => ['', false];
        yield 'basic scheme' => ['Basic '.base64_encode('user:secret'), false];
        yield 'JWT' => ['Bearer eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ1c2VyIn0.c2lnbmF0dXJl', false];
        yield 'capability token' => ['Bearer anon_abc', true];
        yield 'lower-case scheme' => ['bearer anon_abc', true];
        yield 'base64url alphabet' => ['Bearer anon_aZ09-_', true];
        // Pinned like Lexik's header extractor: exactly one space.
        yield 'two spaces' => ['Bearer  anon_abc', false];
        yield 'surrounding whitespace' => ['  Bearer anon_abc  ', true];
        yield 'dot in the value' => ['Bearer anon_a.b', false];
        yield 'empty suffix' => ['Bearer anon_', false];
        yield 'prefix only, other scheme' => ['Token anon_abc', false];
        yield 'no value' => ['Bearer', false];
    }

    #[DataProvider('headers')]
    public function testSupports(?string $header, bool $expected): void
    {
        $authenticator = new CapabilityTokenAuthenticator($this->createStub(EntityManagerInterface::class));

        self::assertSame($expected, $authenticator->supports(self::request($header)));
    }

    public function testKnownTokenAuthenticatesAsTheConversationOwner(): void
    {
        $conversation = new Conversation();
        $ownerSubject = 'anon:'.$conversation->getId()->toRfc4122();
        $conversation
            ->setOwnerSubject($ownerSubject)
            ->setAnonymous(true)
            ->setTokenHash(CapabilityToken::hash(self::TOKEN));

        $passport = $this->authenticator($conversation)->authenticate(self::request('Bearer '.self::TOKEN));
        $user = $passport->getUser();

        self::assertInstanceOf(AnonymousConversationUser::class, $user);
        self::assertSame($ownerSubject, $user->getUserIdentifier());
        self::assertSame([], $user->getRoles(), 'the principal of a capability token carries no role');
        self::assertTrue($conversation->getId()->equals($user->getConversationId()));
    }

    public function testUnknownTokenFailsWithoutEchoingIt(): void
    {
        $authenticator = $this->authenticator(null);
        $request = self::request('Bearer '.self::TOKEN);

        $exception = null;
        try {
            $authenticator->authenticate($request);
        } catch (AuthenticationException $caught) {
            $exception = $caught;
        }

        self::assertInstanceOf(AuthenticationException::class, $exception, 'An unknown token must not authenticate.');
        foreach ([$exception->getMessage(), $exception->getMessageKey(), serialize($exception->getMessageData())] as $text) {
            self::assertStringNotContainsString(self::TOKEN, $text);
            self::assertStringNotContainsString(CapabilityToken::hash(self::TOKEN), $text);
        }

        $response = $authenticator->onAuthenticationFailure($request, $exception);
        self::assertNotNull($response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['message' => 'Invalid capability token.'], json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::TOKEN, (string) $response->getContent());
        self::assertFalse($response->headers->has('WWW-Authenticate'));
    }

    public function testSuccessLetsTheRequestContinue(): void
    {
        $authenticator = new CapabilityTokenAuthenticator($this->createStub(EntityManagerInterface::class));
        $conversationId = Uuid::v4();
        $passport = new SelfValidatingPassport(new UserBadge(
            'anon:'.$conversationId->toRfc4122(),
            static fn (string $identifier): AnonymousConversationUser => new AnonymousConversationUser($identifier, $conversationId),
        ));
        $token = $authenticator->createToken($passport, 'api');

        self::assertNull($authenticator->onAuthenticationSuccess(self::request('Bearer '.self::TOKEN), $token, 'api'));
    }

    private static function request(?string $header): Request
    {
        $server = null === $header ? [] : ['HTTP_AUTHORIZATION' => $header];

        return Request::create('/api/conversations/0199a0b1-0000-7000-8000-000000000001', 'GET', server: $server);
    }

    /**
     * An authenticator whose repository expects the lookup by the hash of
     * {@see self::TOKEN} on anonymous conversations and returns $found.
     */
    private function authenticator(?Conversation $found): CapabilityTokenAuthenticator
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['tokenHash' => CapabilityToken::hash(self::TOKEN), 'anonymous' => true])
            ->willReturn($found);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')
            ->willReturnCallback(static fn (string $class): EntityRepository => Conversation::class === $class ? $repository : throw new \LogicException('Unexpected repository '.$class));

        return new CapabilityTokenAuthenticator($entityManager);
    }
}
