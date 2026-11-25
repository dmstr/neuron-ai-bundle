<?php

// file generated with AI assistance: Claude Code - 2026-11-25 21:50:20 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Kernel;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Authenticates test requests from plain headers, no credentials involved.
 *
 * - Without any of the headers below, the request is authenticated as
 *   {@see self::ADMIN_IDENTIFIER} with `ROLE_ADMIN`.
 * - `X-Test-Roles: ROLE_A, ROLE_B` replaces the roles (comma-separated,
 *   trimmed, empty entries ignored); the identifier becomes
 *   {@see self::USER_IDENTIFIER} unless the list contains `ROLE_ADMIN`. An
 *   empty value means authenticated with `ROLE_USER` only.
 * - `X-Test-User: alice` replaces the derived identifier, e.g. for
 *   ownership checks with two users.
 * - `X-Test-Auth: anonymous` skips this authenticator, whatever else is
 *   sent; with `Authorization: Bearer anon_…` the capability token
 *   authenticator of the bundle on the same firewall takes over.
 *
 * Every authenticated user holds `ROLE_USER` in addition to the listed
 * roles, as a login of an identity provider does. As entry point it answers
 * 401 with `WWW-Authenticate: Bearer` for requests that need a login.
 */
final class TestHeaderAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public const string HEADER_AUTH = 'X-Test-Auth';
    public const string HEADER_ROLES = 'X-Test-Roles';
    public const string HEADER_USER = 'X-Test-User';

    public const string ADMIN_IDENTIFIER = 'test-admin';
    public const string USER_IDENTIFIER = 'test-user';

    public const string ANONYMOUS = 'anonymous';
    public const string MESSAGE_UNAUTHORIZED = 'Authentication required';

    private const string ADMIN_ROLE = 'ROLE_ADMIN';
    private const string USER_ROLE = 'ROLE_USER';

    public function supports(Request $request): ?bool
    {
        return self::ANONYMOUS !== strtolower(trim((string) $request->headers->get(self::HEADER_AUTH, '')));
    }

    public function authenticate(Request $request): Passport
    {
        $roles = $request->headers->has(self::HEADER_ROLES)
            ? self::parseRoles((string) $request->headers->get(self::HEADER_ROLES, ''))
            : [self::ADMIN_ROLE];
        $identifier = trim((string) $request->headers->get(self::HEADER_USER, ''));
        if ('' === $identifier) {
            $identifier = \in_array(self::ADMIN_ROLE, $roles, true) ? self::ADMIN_IDENTIFIER : self::USER_IDENTIFIER;
        }
        $roles = array_values(array_unique([...$roles, self::USER_ROLE]));

        return new SelfValidatingPassport(new UserBadge(
            $identifier,
            static fn (string $identifier): InMemoryUser => new InMemoryUser($identifier, null, $roles),
        ));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return self::unauthorized($exception->getMessageKey());
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::unauthorized(self::MESSAGE_UNAUTHORIZED);
    }

    /**
     * @return list<string>
     */
    public static function parseRoles(string $header): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $header)),
            static fn (string $role): bool => '' !== $role,
        )));
    }

    private static function unauthorized(string $message): JsonResponse
    {
        return new JsonResponse(
            ['code' => Response::HTTP_UNAUTHORIZED, 'message' => $message],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => 'Bearer'],
        );
    }
}
