<?php

// file generated with AI assistance: Claude Code - 2026-10-06 00:15:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider\Auth;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

/**
 * Decorates Neuron's HTTP client of an `ollama` provider with
 * `Authorization: Bearer <token>`.
 *
 * The token is resolved on every request()/stream() call, not once per
 * provider: the messenger worker keeps providers alive longer than a token
 * lives. It is added to a copy of the HttpRequest that only the inner
 * client sees (neuron-ai 4.x clients take every header from the request
 * and are never configured after construction); the caller's HttpRequest
 * keeps no Authorization header.
 *
 * A 401/403 of the provider arrives as Neuron's HttpException with the
 * response (every 4.x client throws for status >= 400), so the rejection
 * is classified here: for a renewable token (client credentials) the token
 * is evicted and the call retried exactly once; a second 401/403, or any
 * 401/403 with a static token, becomes a ProviderAuthenticationException
 * without `previous`. For stream() the retry covers only a rejection
 * before the stream is returned, which is where the client throws it.
 *
 * Every other failure of the inner client keeps its type but loses what
 * could carry the token: an HttpException is rethrown as a new
 * HttpException with the caller's request (without the header), the same
 * status and headers, without `previous`, with the token masked in the
 * message and the response body (a proxy may echo it). Any other exception
 * that chains a previous one or names the token becomes such an
 * HttpException, too; a plain exception without any of these passes
 * through unchanged. TurnRunner logs the failure object, so nothing
 * reachable from it may hold the token.
 *
 * Why a decorator and not the provider's own headers (neuron-ai 4.x): `HttpClientInterface` has only request() and
 * stream() and no hook for a refresh after 401, and a provider's
 * `$httpHeaders` are fixed at construction. Static bearer tokens go through
 * the decorator as well, so both auth types keep the same rejection
 * handling and redaction.
 */
final readonly class AuthenticatingHttpClient implements HttpClientInterface
{
    public function __construct(
        private HttpClientInterface $inner,
        private AccessTokenProviderInterface $tokens,
        private string $connectionName = '',
    ) {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        return $this->send($request, false);
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        return $this->send($request, true);
    }

    /**
     * Sends $request with the token, retrying once after a rejection of a
     * renewable token. Exceptions replacing a failure are constructed here,
     * not in a helper: the trace of the new exception must not hold the
     * failure or the token as arguments, and the arguments of this method
     * are the caller's request and a flag.
     */
    private function send(HttpRequest $request, bool $stream): HttpResponse|StreamInterface
    {
        $token = $this->token();
        try {
            return $this->call($request, $token, $stream);
        } catch (HttpException $e) {
            $status = self::rejectionStatus($e);
            if (null === $status) {
                throw new HttpException(...self::scrubbed($e, $token, $request));
            }
            if (!$this->tokens->invalidate()) {
                throw $this->rejected($status, false);
            }
        } catch (ProviderAuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw self::isClean($e, $token) ? $e : new HttpException(...self::scrubbed($e, $token, $request));
        }

        $token = $this->token();
        try {
            return $this->call($request, $token, $stream);
        } catch (HttpException $e) {
            $status = self::rejectionStatus($e);
            if (null === $status) {
                throw new HttpException(...self::scrubbed($e, $token, $request));
            }
            throw $this->rejected($status, true);
        } catch (ProviderAuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw self::isClean($e, $token) ? $e : new HttpException(...self::scrubbed($e, $token, $request));
        }
    }

    private function token(): string
    {
        $token = $this->tokens->token();
        if ('' === $token || TokenErrors::hasLineBreak($token)) {
            // Token providers refuse these already; checked again because
            // this is where the header is built.
            throw new ProviderAuthenticationException(\sprintf('The token of Ollama connection "%s" is empty or contains a line break; refused.', TokenErrors::name($this->connectionName)), null, $this->connectionName);
        }

        return $token;
    }

    /**
     * Sends a copy of $request with the bearer header through the inner
     * client.
     */
    private function call(HttpRequest $request, #[\SensitiveParameter] string $token, bool $stream): HttpResponse|StreamInterface
    {
        $authorized = $request->withHeaders(['Authorization' => 'Bearer '.$token]);

        return $stream ? $this->inner->stream($authorized) : $this->inner->request($authorized);
    }

    /**
     * Whether $e may pass unchanged: no HttpException, nothing chained,
     * token not named.
     */
    private static function isClean(\Throwable $e, #[\SensitiveParameter] string $token): bool
    {
        return !$e instanceof HttpException
            && null === $e->getPrevious()
            && !str_contains($e->getMessage(), $token);
    }

    /**
     * Constructor arguments of the HttpException replacing $e: message and
     * response body with the token masked, the caller's $request (the one
     * $e carries holds the header), the same status and headers, no
     * `previous`. The caller constructs the exception itself, so its trace
     * starts in send(), whose arguments hold neither $e nor the token.
     *
     * @return array{string, ?HttpRequest, ?HttpResponse}
     */
    private static function scrubbed(\Throwable $e, #[\SensitiveParameter] string $token, HttpRequest $request): array
    {
        if (!$e instanceof HttpException) {
            return [self::masked($e->getMessage(), $token), null, null];
        }

        $response = null === $e->response ? null : new HttpResponse(
            $e->response->statusCode,
            self::masked($e->response->body, $token),
            $e->response->headers,
        );

        return [self::masked($e->getMessage(), $token), $request, $response];
    }

    private static function masked(string $text, #[\SensitiveParameter] string $token): string
    {
        return str_replace($token, '***', $text);
    }

    /**
     * 401 or 403 of the provider, otherwise null.
     */
    private static function rejectionStatus(HttpException $e): ?int
    {
        $status = $e->response?->statusCode;

        return 401 === $status || 403 === $status ? $status : null;
    }

    private function rejected(int $status, bool $afterRenewal): ProviderAuthenticationException
    {
        return new ProviderAuthenticationException(
            \sprintf(
                'Ollama connection "%s" rejected the %s (HTTP %d)%s.',
                TokenErrors::name($this->connectionName),
                $this->tokens instanceof StaticTokenProvider ? 'bearer token' : 'access token',
                $status,
                $afterRenewal ? ' also after fetching a new token' : '',
            ),
            $status,
            $this->connectionName,
        );
    }
}

// - revised 2026-10-14
