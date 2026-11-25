<?php

// file generated with AI assistance: Claude Code - 2026-10-06 17:20:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\NeuronAiBundle\Provider\Auth\ProviderAuthenticationException;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;

/**
 * Shared HTTP part of the classes that read a provider's model endpoints
 * (the model listers and the model describers): one request with a short
 * timeout of its own (default 5 s, independent of the turn deadline), JSON
 * decoding and the mapping of failures to {@see ModelListingException},
 * with the HTTP status of a failure in its `statusCode`.
 *
 * Failure texts never contain a credential: the secrets of the connection
 * are replaced by the mask, user info in URLs is removed, and a provider's
 * error text is cut to 200 characters. A ProviderAuthenticationException
 * of an authenticating client (token endpoint failure, token rejected)
 * becomes UNAUTHENTICATED with its status; its message is already free of
 * secrets and is masked all the same.
 */
abstract class AbstractProviderHttpClient
{
    public const float DEFAULT_TIMEOUT_SECONDS = 5.0;

    private const int MAX_DETAIL_LENGTH = 200;

    private readonly HttpClientInterface $httpClient;

    /**
     * @param HttpClientInterface|null $httpClient used as is (its own timeout applies); null = Neuron's CurlHttpClient with $timeoutSeconds
     */
    public function __construct(
        protected readonly ConfigSecrets $secrets,
        ?HttpClientInterface $httpClient = null,
        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
        $this->httpClient = $httpClient ?? new CurlHttpClient(timeout: $timeoutSeconds, connectTimeout: $timeoutSeconds);
    }

    /**
     * The shared client of this instance. It is never configured after
     * construction: every request carries its absolute URL and headers, so
     * a subclass may wrap it per connection without copying it.
     */
    protected function httpClient(): HttpClientInterface
    {
        return $this->httpClient;
    }

    /**
     * The connection's configJson with clear secrets, checked to be of the
     * expected type.
     *
     * @return array<string, mixed>
     *
     * @throws ModelListingException for a connection of another type
     */
    protected function configOf(ApiConfiguration $connection, string $type): array
    {
        $config = $connection->getConfigJson();
        if ($type !== ($config['type'] ?? null)) {
            throw new ModelListingException(\sprintf('Connection "%s" is not an %s connection.', ProviderType::nameOf($connection), $type), ModelListingException::CONFIGURATION);
        }

        try {
            return $this->secrets->decrypt($config);
        } catch (\RuntimeException $e) {
            // SecretEncryptionException: its message names the key setting, never a value.
            throw new ModelListingException(\sprintf('Connection "%s" cannot be used: %s', ProviderType::nameOf($connection), $e->getMessage()), ModelListingException::CONFIGURATION);
        }
    }

    /**
     * GETs $uri and returns the decoded JSON object.
     *
     * @param array<string, string>    $headers
     * @param list<string>             $secrets    values to mask in any failure text
     * @param HttpClientInterface|null $httpClient the client of this call; null = the instance's client
     *
     * @return array<mixed>
     *
     * @throws ModelListingException
     */
    protected function getJson(ApiConfiguration $connection, string $uri, array $headers = [], #[\SensitiveParameter] array $secrets = [], ?HttpClientInterface $httpClient = null): array
    {
        return $this->requestJson($connection, 'GET', $uri, $headers, null, $secrets, $httpClient);
    }

    /**
     * Sends $method $uri (a POST with $body as JSON) and returns the decoded
     * JSON object. A failure carries the HTTP status in `statusCode` when
     * the provider answered.
     *
     * @param 'GET'|'POST'              $method
     * @param array<string, string>     $headers
     * @param array<string, mixed>|null $body       JSON body of a POST
     * @param list<string>              $secrets    values to mask in any failure text
     * @param HttpClientInterface|null  $httpClient the client of this call; null = the instance's client
     *
     * @return array<mixed>
     *
     * @throws ModelListingException
     */
    protected function requestJson(ApiConfiguration $connection, string $method, string $uri, array $headers = [], ?array $body = null, #[\SensitiveParameter] array $secrets = [], ?HttpClientInterface $httpClient = null): array
    {
        $name = ProviderType::nameOf($connection);
        $shown = $method.' '.self::redact(self::withoutQuery($uri), $secrets);
        $request = 'POST' === $method ? HttpRequest::post($uri, $body ?? [], $headers) : new HttpRequest(HttpMethod::from($method), $uri, $headers);

        try {
            $response = ($httpClient ?? $this->httpClient)->request($request);
        } catch (ProviderAuthenticationException $e) {
            throw self::unauthenticated($connection, $e, $secrets, $shown);
        } catch (HttpException $e) {
            $status = $e->response?->statusCode;
            if (null === $status) {
                throw new ModelListingException(\sprintf('Provider of connection "%s" is unreachable (%s): %s', $name, $shown, self::detail(self::networkReason($e), $secrets)), ModelListingException::UNREACHABLE);
            }

            $reason = match (true) {
                401 === $status, 403 === $status => ModelListingException::UNAUTHENTICATED,
                $status >= 500 => ModelListingException::UNREACHABLE,
                default => ModelListingException::PROTOCOL,
            };
            $prefix = match ($reason) {
                ModelListingException::UNAUTHENTICATED => 'Provider of connection "%s" rejected the credentials',
                ModelListingException::UNREACHABLE => 'Provider of connection "%s" is unreachable',
                default => 'Provider of connection "%s" answered with an error',
            };
            $detail = self::errorText($e->response->body);

            throw new ModelListingException(\sprintf($prefix.' (HTTP %d from %s)%s', $name, $status, $shown, '' !== $detail ? ': '.self::detail($detail, $secrets) : ''), $reason, $status);
        } catch (\Throwable $e) {
            throw new ModelListingException(\sprintf('Provider of connection "%s" is unreachable (%s): %s', $name, $shown, self::detail($e->getMessage(), $secrets)), ModelListingException::UNREACHABLE);
        }

        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new ModelListingException(\sprintf('Provider of connection "%s" answered HTTP %d to %s.', $name, $response->statusCode, $shown), $response->statusCode >= 500 ? ModelListingException::UNREACHABLE : ModelListingException::PROTOCOL, $response->statusCode);
        }

        $data = json_decode($response->body, true);
        if (!\is_array($data)) {
            throw new ModelListingException(\sprintf('Provider of connection "%s" answered %s without a JSON object.', $name, $shown), ModelListingException::PROTOCOL, $response->statusCode);
        }

        return $data;
    }

    /**
     * UNAUTHENTICATED with the status of $e (the token endpoint's or the
     * provider's), built from its clean message; never chained.
     *
     * @param list<string> $secrets
     * @param string|null  $shownRequest e.g. `GET http://ollama:11434/api/tags`, already redacted
     */
    protected static function unauthenticated(ApiConfiguration $connection, ProviderAuthenticationException $e, #[\SensitiveParameter] array $secrets, ?string $shownRequest = null): ModelListingException
    {
        return new ModelListingException(
            \sprintf('Provider of connection "%s" could not authenticate%s: %s', ProviderType::nameOf($connection), null !== $shownRequest ? ' ('.$shownRequest.')' : '', self::detail($e->getMessage(), $secrets)),
            ModelListingException::UNAUTHENTICATED,
            $e->statusCode,
        );
    }

    /**
     * Masks secrets and removes URL user info.
     *
     * @param list<string> $secrets
     */
    protected static function redact(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ('' !== $secret) {
                $text = str_replace($secret, ConfigSecrets::MASK, $text);
            }
        }

        return (string) preg_replace('#([a-z][a-z0-9+.-]*://)[^/@\s]+@#i', '$1', $text);
    }

    /**
     * A non-empty string of a provider answer, else null.
     */
    protected static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * A positive integer of a provider answer, else null (`0` is a
     * placeholder in some answers and means "not reported").
     */
    protected static function positiveInt(mixed $value): ?int
    {
        return \is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * @param list<string> $secrets
     */
    private static function detail(string $text, #[\SensitiveParameter] array $secrets): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        if (mb_strlen($text) > self::MAX_DETAIL_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_DETAIL_LENGTH).'…';
        }

        return self::redact($text, $secrets);
    }

    /**
     * The provider's own error text of an error response: Anthropic
     * `error.message`, Ollama `error`; '' for other bodies.
     */
    private static function errorText(string $body): string
    {
        $data = json_decode($body, true);
        if (!\is_array($data)) {
            return '';
        }
        $error = $data['error'] ?? null;
        if (\is_string($error)) {
            return $error;
        }

        return \is_array($error) && \is_string($error['message'] ?? null) ? $error['message'] : '';
    }

    /**
     * The transport's reason without neuron's "Network error during GET <uri>:" prefix
     * (stripped from the message when an authenticating client dropped the
     * chained transport exception).
     */
    private static function networkReason(HttpException $e): string
    {
        $message = $e->getPrevious()?->getMessage() ?? (string) preg_replace('/^Network error during [A-Z]+ \S*: /', '', $e->getMessage());

        return '' !== $message ? $message : $e::class;
    }

    private static function withoutQuery(string $uri): string
    {
        $position = strpos($uri, '?');

        return false === $position ? $uri : substr($uri, 0, $position);
    }
}

// - revised 2026-10-14
