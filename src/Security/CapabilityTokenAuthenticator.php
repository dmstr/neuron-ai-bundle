<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:50:51 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Security;

use Dmstr\NeuronAiBundle\Anonymous\CapabilityToken;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticates `Authorization: Bearer anon_…` with the capability token of
 * an anonymous conversation (stateless, one lookup per request).
 *
 * The presented value is hashed ({@see CapabilityToken::hash()}) and the
 * anonymous conversation with that `tokenHash` becomes the principal
 * {@see AnonymousConversationUser}, whose identifier is the conversation's
 * `ownerSubject` (`anon:<uuid>`). A closed conversation still
 * authenticates, so its holder can keep reading it; the turn path refuses
 * new turns on its own.
 *
 * Only requests whose bearer value has the reserved prefix are supported;
 * everything else (JWTs start with `eyJ`) is left to the JWT authenticator
 * on the same firewall, whose token extractor in turn ignores capability
 * tokens ({@see JwtTokenExtractorDecorator}). No entry point: the JWT
 * authenticator owns the 401 challenge of the firewall.
 *
 * The token, its hash and the header never appear in an exception message,
 * a log line or a response: authentication failures are logged at info
 * level with their message, and the proxy already redacts the header.
 */
final class CapabilityTokenAuthenticator extends AbstractAuthenticator
{
    public const string MESSAGE_INVALID = 'Invalid capability token.';

    private const string HEADER = 'Authorization';
    private const string SCHEME = 'Bearer';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return null !== self::extract($request);
    }

    public function authenticate(Request $request): Passport
    {
        $token = self::extract($request);
        if (null === $token) {
            throw new CustomUserMessageAuthenticationException(self::MESSAGE_INVALID);
        }

        $conversation = $this->entityManager->getRepository(Conversation::class)->findOneBy([
            'tokenHash' => CapabilityToken::hash($token),
            'anonymous' => true,
        ]);
        $identifier = $conversation?->getOwnerSubject();
        if (!$conversation instanceof Conversation || null === $identifier || '' === $identifier) {
            throw new CustomUserMessageAuthenticationException(self::MESSAGE_INVALID);
        }

        $conversationId = $conversation->getId();

        return new SelfValidatingPassport(
            new UserBadge($identifier, static fn (string $identifier): AnonymousConversationUser => new AnonymousConversationUser($identifier, $conversationId)),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['message' => self::MESSAGE_INVALID], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * The capability token of the `Authorization` header: scheme `Bearer`
     * (case-insensitive), exactly one space, a value of the token format.
     * Splits like Lexik's AuthorizationHeaderTokenExtractor, so both
     * authenticators agree on what a bearer value is.
     */
    private static function extract(Request $request): ?string
    {
        $header = trim((string) $request->headers->get(self::HEADER, ''));
        if ('' === $header) {
            return null;
        }

        $parts = explode(' ', $header);
        if (2 !== \count($parts) || 0 !== strcasecmp($parts[0], self::SCHEME)) {
            return null;
        }

        return CapabilityToken::isCapabilityToken($parts[1]) ? $parts[1] : null;
    }
}
