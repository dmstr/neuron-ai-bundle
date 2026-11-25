<?php

// file generated with AI assistance: Claude Code - 2026-10-14 12:09:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Testing;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

/**
 * In-memory Neuron HTTP client for kernel-free tests: answers from a queue
 * of scripted outcomes and records every request it is given.
 *
 * The behaviour follows neuron-ai's CurlHttpClient (the 4.x default), so
 * code under test sees the same contract as in production:
 *
 * - URLs are sent as given and must be absolute; a relative URL fails as a
 *   network error, because 4.x components never rely on a client base URI.
 * - Headers: the client's `$customHeaders`, overridden case-insensitively by
 *   the request's headers; an array body that is not multipart is sent as
 *   JSON with `Content-Type: application/json` unless the request sets one.
 *   A header with CR, LF or NUL is refused with an InvalidArgumentException
 *   before anything is recorded.
 * - Timeout: the request's `timeout`, else the client's.
 * - A status >= 400 throws HttpException::statusError() with the response,
 *   from request() and from stream() (before a stream is returned).
 * - stream() hands out the body in the scripted chunks, raw; a Throwable in
 *   the chunk list is thrown when reading reaches it (a cut connection).
 * - The returned HttpResponse is Neuron's own, so json() throws a
 *   JsonException for an empty or non-JSON body.
 *
 * Scripting: queue() takes, in order of the requests,
 *
 * - an HttpResponse,
 * - a Throwable (thrown as is), e.g. from {@see networkError()},
 * - a Closure(HttpRequest): (HttpResponse|\Throwable|array{statusCode: int,
 *   headers: array<string, string>, chunks: list<string|\Throwable>}), e.g.
 *   from {@see networkError()} or {@see chunked()}.
 *
 * A request without a queued outcome throws an OutOfBoundsException.
 *
 * Recording: every request that passed the header check is recorded before
 * its outcome is resolved (failed requests included) as an array with
 * `method`, `url`, `headers` (merged, as sent), `body` (string as sent;
 * multipart bodies stay arrays; null without body), `timeout` (seconds),
 * `stream` (whether stream() was called) and `request` (the HttpRequest
 * object received). Read them with {@see sent()}, or let the client append
 * them to a test's own array with {@see recordInto()}; {@see headerOf()}
 * reads a header of a record case-insensitively.
 *
 * @phpstan-type SentRequest array{method: string, url: string, headers: array<string, string>, body: array<string, mixed>|string|null, timeout: float, stream: bool, request: HttpRequest}
 * @phpstan-type ChunkedOutcome array{statusCode: int, headers: array<string, string>, chunks: list<string|\Throwable>}
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<HttpResponse|\Throwable|\Closure> */
    private array $queue = [];

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    /** @var list<array<string, mixed>>|null bound by recordInto() */
    private ?array $history = null;

    /**
     * @param list<HttpResponse|\Throwable|\Closure> $outcomes      initial queue, see queue()
     * @param array<string, string>                  $customHeaders default headers of every request
     */
    public function __construct(
        array $outcomes = [],
        private readonly array $customHeaders = [],
        private readonly float $timeout = 300.0,
    ) {
        $this->queue(...$outcomes);
    }

    /**
     * Appends outcomes to the queue, one per request.
     */
    public function queue(HttpResponse|\Throwable|\Closure ...$outcomes): self
    {
        foreach ($outcomes as $outcome) {
            $this->queue[] = $outcome;
        }

        return $this;
    }

    /**
     * Additionally appends every recorded request to $history (like
     * Guzzle's history middleware).
     *
     * @param list<array<string, mixed>> $history
     */
    public function recordInto(array &$history): self
    {
        $this->history = &$history;

        return $this;
    }

    /**
     * The recorded requests, oldest first.
     *
     * @return list<SentRequest>
     */
    public function sent(): array
    {
        /** @var list<SentRequest> */
        return $this->sent;
    }

    /**
     * Number of queued outcomes not used yet.
     */
    public function remaining(): int
    {
        return \count($this->queue);
    }

    /**
     * A header of a recorded request by case-insensitive name; null when
     * it was not sent.
     *
     * @param array<string, mixed> $sent a record of sent() or of the recordInto() array
     */
    public static function headerOf(array $sent, string $name): ?string
    {
        foreach ($sent['headers'] ?? [] as $header => $value) {
            if (0 === strcasecmp((string) $header, $name)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * An outcome that fails like a transport error of CurlHttpClient:
     * HttpException::networkError() without response.
     */
    public static function networkError(string $reason): \Closure
    {
        return static fn (HttpRequest $request): HttpException => HttpException::networkError($request, $reason);
    }

    /**
     * An outcome whose body stream() hands out in exactly these chunks; a
     * Throwable among them is thrown when reading reaches it. request()
     * gets the joined body (or the Throwable).
     *
     * @param list<string|\Throwable> $chunks
     * @param array<string, string>   $headers
     */
    public static function chunked(array $chunks, int $statusCode = 200, array $headers = []): \Closure
    {
        return static fn (HttpRequest $request): array => ['statusCode' => $statusCode, 'headers' => $headers, 'chunks' => $chunks];
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $outcome = $this->next($request, false);
        if (\is_array($outcome)) {
            $body = '';
            foreach ($outcome['chunks'] as $chunk) {
                if ($chunk instanceof \Throwable) {
                    throw $chunk;
                }
                $body .= $chunk;
            }
            $outcome = new HttpResponse($outcome['statusCode'], $body, $outcome['headers']);
        }

        if ($outcome->statusCode >= 400) {
            throw HttpException::statusError($request, $outcome);
        }

        return $outcome;
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        $outcome = $this->next($request, true);
        if ($outcome instanceof HttpResponse) {
            $outcome = ['statusCode' => $outcome->statusCode, 'headers' => $outcome->headers, 'chunks' => '' === $outcome->body ? [] : [$outcome->body]];
        }

        if ($outcome['statusCode'] >= 400) {
            // CurlHttpClient drains the body of an error response.
            $body = '';
            foreach ($outcome['chunks'] as $chunk) {
                if ($chunk instanceof \Throwable) {
                    throw $chunk;
                }
                $body .= $chunk;
            }

            throw HttpException::statusError($request, new HttpResponse($outcome['statusCode'], $body, $outcome['headers']));
        }

        return self::chunkStream($outcome['chunks']);
    }

    /**
     * Records $request and resolves its outcome.
     *
     * @return HttpResponse|ChunkedOutcome
     */
    private function next(HttpRequest $request, bool $stream): HttpResponse|array
    {
        $headers = $this->mergeHeaders($this->customHeaders, $request->headers);
        $body = $request->body;
        if (\is_array($body) && !$request->isMultipart()) {
            $body = json_encode($body, \JSON_THROW_ON_ERROR);
            if (null === self::headerOf(['headers' => $headers], 'Content-Type')) {
                $headers['Content-Type'] = 'application/json';
            }
        }
        foreach ($headers as $name => $value) {
            if (false !== strpbrk($name.$value, "\r\n\0")) {
                throw new \InvalidArgumentException(\sprintf('Header %s must not contain line breaks or NUL bytes', $name));
            }
        }

        $record = [
            'method' => $request->method->value,
            'url' => $request->uri,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $request->timeout ?? $this->timeout,
            'stream' => $stream,
            'request' => $request,
        ];
        $this->sent[] = $record;
        if (null !== $this->history) {
            $this->history[] = $record;
        }

        if (1 !== preg_match('~^https?://~i', $request->uri)) {
            throw HttpException::networkError($request, 'URL rejected: the request URL is not absolute');
        }

        $outcome = array_shift($this->queue);
        if (null === $outcome) {
            throw new \OutOfBoundsException(\sprintf('FakeHttpClient has no outcome queued for %s %s.', $request->method->value, $request->uri));
        }
        if ($outcome instanceof \Closure) {
            $outcome = $outcome($request);
        }
        if ($outcome instanceof \Throwable) {
            throw $outcome;
        }
        if (!$outcome instanceof HttpResponse && !\is_array($outcome)) {
            throw new \LogicException(\sprintf('FakeHttpClient outcome closures return an HttpResponse, a Throwable or a chunked() array, got %s.', get_debug_type($outcome)));
        }

        return $outcome;
    }

    /**
     * $defaults overridden by $overrides, names compared case-insensitively;
     * the override keeps its own spelling.
     *
     * @param array<string, string> $defaults
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function mergeHeaders(array $defaults, array $overrides): array
    {
        $headers = [];
        $names = [];
        foreach ([$defaults, $overrides] as $source) {
            foreach ($source as $name => $value) {
                $normalized = strtolower((string) $name);
                if (isset($names[$normalized])) {
                    unset($headers[$names[$normalized]]);
                }
                $names[$normalized] = (string) $name;
                $headers[(string) $name] = $value;
            }
        }

        return $headers;
    }

    /**
     * A pull stream over $chunks with the read semantics of CurlStream:
     * read() returns at most $length bytes of what has arrived (never more
     * than one chunk at a time), readLine() collects chunks up to a newline.
     *
     * @param list<string|\Throwable> $chunks
     */
    private static function chunkStream(array $chunks): StreamInterface
    {
        return new class($chunks) implements StreamInterface {
            private string $buffer = '';

            /**
             * @param list<string|\Throwable> $chunks
             */
            public function __construct(private array $chunks)
            {
            }

            public function eof(): bool
            {
                return '' === $this->buffer && [] === $this->chunks;
            }

            public function read(int $length): string
            {
                if ('' === $this->buffer) {
                    $this->pull();
                }
                $result = substr($this->buffer, 0, $length);
                $this->buffer = substr($this->buffer, \strlen($result));

                return $result;
            }

            public function readLine(): string
            {
                while (false === ($position = strpos($this->buffer, "\n"))) {
                    if ([] === $this->chunks) {
                        $line = $this->buffer;
                        $this->buffer = '';

                        return $line;
                    }
                    $this->pull();
                }
                $line = substr($this->buffer, 0, $position + 1);
                $this->buffer = substr($this->buffer, $position + 1);

                return $line;
            }

            public function close(): void
            {
                $this->buffer = '';
                $this->chunks = [];
            }

            private function pull(): void
            {
                $chunk = array_shift($this->chunks);
                if ($chunk instanceof \Throwable) {
                    $this->chunks = [];
                    throw $chunk;
                }
                $this->buffer .= $chunk ?? '';
            }
        };
    }
}
