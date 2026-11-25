<?php

// file generated with AI assistance: Claude Code - 2026-10-06 17:20:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Provider\Auth\AuthType;
use Dmstr\NeuronAiBundle\Provider\Auth\OllamaHttpClientFactory;
use Dmstr\NeuronAiBundle\Provider\Auth\ProviderAuthenticationException;
use NeuronAI\HttpClient\HttpClientInterface;

/**
 * The request context of an `ollama` connection shared by
 * {@see OllamaModelLister} and {@see OllamaModelDescriber}: the base URL,
 * the secrets to mask in failure texts and the client of one call, built
 * once per connection and used for every request of that call.
 *
 * The client authenticates like the connection's chat and embeddings
 * calls: {@see OllamaHttpClientFactory} wraps the shared client according
 * to `auth_type` (`bearer`, `client_credentials`); the wrapper adds the
 * token to each request, never to the shared client, so no header stays
 * behind for the next connection; `none` uses the shared client as is. An unusable auth configuration is CONFIGURATION.
 *
 * The using class extends {@see AbstractProviderHttpClient} and has a
 * property `?OllamaHttpClientFactory $authClients`.
 */
trait OllamaAuthenticatedClientTrait
{
    /**
     * @return array{url: string, secrets: list<string>, client: HttpClientInterface} url without a trailing slash
     *
     * @throws ModelListingException CONFIGURATION for a missing url or an unusable auth configuration
     */
    private function ollamaContext(ApiConfiguration $connection): array
    {
        $config = $this->configOf($connection, ProviderType::OLLAMA);
        $url = $config['url'] ?? null;
        if (!\is_string($url) || '' === $url) {
            throw new ModelListingException(\sprintf('Ollama connection "%s" has no "url".', ProviderType::nameOf($connection)), ModelListingException::CONFIGURATION);
        }

        // A password in the URL's user info and the auth secrets are masked
        // in failure texts.
        $secrets = [];
        $password = parse_url($url, \PHP_URL_PASS);
        if (\is_string($password) && '' !== $password) {
            $secrets = [rawurldecode($password), $password];
        }
        foreach (['token', 'client_secret'] as $key) {
            if (\is_string($config[$key] ?? null) && '' !== $config[$key]) {
                $secrets[] = $config[$key];
            }
        }

        return ['url' => rtrim($url, '/'), 'secrets' => $secrets, 'client' => $this->authenticatedClient($connection, $config, $secrets)];
    }

    /**
     * The entries of `GET {url}/tags`.
     *
     * @param array{url: string, secrets: list<string>, client: HttpClientInterface} $context
     *
     * @return list<mixed>
     *
     * @throws ModelListingException
     */
    private function tagEntries(ApiConfiguration $connection, #[\SensitiveParameter] array $context): array
    {
        $data = $this->getJson($connection, $context['url'].'/tags', [], $context['secrets'], $context['client']);
        if (!\is_array($data['models'] ?? null)) {
            throw new ModelListingException(\sprintf('Ollama connection "%s" answered without a "models" list.', ProviderType::nameOf($connection)), ModelListingException::PROTOCOL);
        }

        return array_values($data['models']);
    }

    /**
     * The name of a `/tags` entry (`name`, else `model`), null when it has none.
     */
    private static function tagName(mixed $entry): ?string
    {
        $name = \is_array($entry) ? ($entry['name'] ?? $entry['model'] ?? null) : null;

        return \is_string($name) && '' !== $name ? $name : null;
    }

    /**
     * The client of this call: the shared one for `none`, else the shared
     * one wrapped by the factory.
     *
     * @param array<string, mixed> $config  the decrypted configuration
     * @param list<string>         $secrets
     *
     * @throws ModelListingException CONFIGURATION for an unusable auth configuration
     */
    private function authenticatedClient(ApiConfiguration $connection, #[\SensitiveParameter] array $config, #[\SensitiveParameter] array $secrets): HttpClientInterface
    {
        $authType = $config['auth_type'] ?? null;
        if (null === $authType || '' === $authType || AuthType::NONE->value === $authType) {
            return $this->httpClient();
        }
        if (null === $this->authClients) {
            throw new ModelListingException(\sprintf('Ollama connection "%s" uses auth_type "%s", but no OllamaHttpClientFactory is configured.', ProviderType::nameOf($connection), \is_string($authType) ? $authType : '?'), ModelListingException::CONFIGURATION);
        }

        try {
            return $this->authClients->for($config, $this->httpClient(), ProviderType::nameOf($connection));
        } catch (ProviderAuthenticationException $e) {
            // Raised before any request: the auth configuration is unusable.
            throw new ModelListingException(self::redact($e->getMessage(), $secrets), ModelListingException::CONFIGURATION);
        }
    }
}

// - revised 2026-10-14
