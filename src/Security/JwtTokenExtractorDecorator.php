<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:50:51 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Security;

use Dmstr\NeuronAiBundle\Anonymous\CapabilityToken;
use Lexik\Bundle\JWTAuthenticationBundle\TokenExtractor\TokenExtractorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Hides capability tokens from Lexik's JWT authenticator: decorates
 * `lexik_jwt_authentication.extractor.chain_extractor` and reports "no
 * token" (`false`) when the extracted value is a capability token.
 *
 * Lexik's JWTAuthenticator supports every request its extractor finds a
 * value in, and its failure response (401) ends the request before any
 * later authenticator runs. Without this decorator a `Bearer anon_…` value
 * would be parsed as a JWT and refused, and
 * {@see CapabilityTokenAuthenticator} would never be reached.
 *
 * The prefix `anon_` is reserved for capability tokens: a JWT always starts
 * with `eyJ` (the base64url of `{"`) and contains dots, a capability token
 * has no dots, so the two never overlap. A separate authorization scheme
 * (for example `Capability anon_…`) was rejected because the API and the
 * planned streaming endpoints accept credentials as `Bearer` only.
 *
 * Registered with IGNORE_ON_INVALID_REFERENCE, so an application without
 * the Lexik bundle simply has no decorator.
 */
final class JwtTokenExtractorDecorator implements TokenExtractorInterface
{
    public function __construct(
        private readonly TokenExtractorInterface $inner,
    ) {
    }

    public function extract(Request $request): string|false
    {
        $token = $this->inner->extract($request);
        if (!\is_string($token) || CapabilityToken::isCapabilityToken($token)) {
            return false;
        }

        return $token;
    }
}
