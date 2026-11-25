<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Testing;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Provider\ProviderType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds provider connections (`ollama`, also with bearer or client
 * credentials authentication, and `anthropic`) for tests, valid against
 * the connection schemas. Builders return unsaved entities;
 * persist() saves them (the `onFlush` listener encrypts the placeholder
 * secrets, so the test kernel needs its encryption key). Names are taken as
 * given: put the suite's cleanup prefix in front.
 *
 *     $ollama = ProviderConnectionFixtures::ollama('test-agent-'.FakeProviderFactory::OLLAMA_CONNECTION);
 *     $anthropic = ProviderConnectionFixtures::anthropic('test-agent-'.FakeProviderFactory::ANTHROPIC_CONNECTION, active: false);
 *     $keycloak = ProviderConnectionFixtures::ollamaClientCredentials('test-agent-keycloak');
 *     ProviderConnectionFixtures::persist($entityManager, $ollama, $anthropic);
 */
final class ProviderConnectionFixtures
{
    public const string OLLAMA_URL = 'http://ollama.fake.test:11434/api';

    /** Placeholder key; tests assert it never shows up in responses or logs. */
    public const string PLACEHOLDER_API_KEY = 'sk-ant-test-placeholder-key-0000';

    /** Placeholder bearer token of an `ollama` connection (`auth_type` bearer). */
    public const string PLACEHOLDER_TOKEN = 'ollama-test-placeholder-token-0000';

    /** Token endpoint of an `ollama` connection (`auth_type` client_credentials); never contacted. */
    public const string TOKEN_URL = 'https://auth.example.org/realms/example/protocol/openid-connect/token';

    public const string CLIENT_ID = 'ollama-test-client';

    /** Placeholder client secret; tests assert it never shows up in responses or logs. */
    public const string PLACEHOLDER_CLIENT_SECRET = 'ollama-test-placeholder-secret-0000';

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $options further config keys (`timeout`)
     */
    public static function ollama(string $name = FakeProviderFactory::OLLAMA_CONNECTION, bool $active = true, string $url = self::OLLAMA_URL, array $options = []): ApiConfiguration
    {
        return self::connection($name, self::ollamaConfig($url, $options), $active);
    }

    /**
     * An `ollama` connection with a static bearer token.
     *
     * @param array<string, mixed> $options further config keys (`timeout`)
     */
    public static function ollamaBearer(string $name = FakeProviderFactory::OLLAMA_CONNECTION, bool $active = true, string $token = self::PLACEHOLDER_TOKEN, array $options = []): ApiConfiguration
    {
        return self::connection($name, self::ollamaBearerConfig($token, options: $options), $active);
    }

    /**
     * An `ollama` connection authenticating with OAuth2 client credentials.
     *
     * @param array<string, mixed> $options further config keys (`scope`, `timeout`)
     */
    public static function ollamaClientCredentials(string $name = FakeProviderFactory::OLLAMA_CONNECTION, bool $active = true, string $clientSecret = self::PLACEHOLDER_CLIENT_SECRET, array $options = []): ApiConfiguration
    {
        return self::connection($name, self::ollamaClientCredentialsConfig($clientSecret, options: $options), $active);
    }

    /**
     * @param array<string, mixed> $options further config keys (`base_url`, `max_tokens`, `timeout`)
     */
    public static function anthropic(string $name = FakeProviderFactory::ANTHROPIC_CONNECTION, bool $active = true, string $apiKey = self::PLACEHOLDER_API_KEY, array $options = []): ApiConfiguration
    {
        return self::connection($name, self::anthropicConfig($apiKey, $options), $active);
    }

    /**
     * The complete configJson of an `ollama` connection.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function ollamaConfig(string $url = self::OLLAMA_URL, array $options = []): array
    {
        return [ProviderType::OLLAMA => true, 'type' => ProviderType::OLLAMA, 'endpoint_type' => 'rest', 'url' => $url] + $options;
    }

    /**
     * The complete configJson of an `ollama` connection with `auth_type` bearer.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function ollamaBearerConfig(string $token = self::PLACEHOLDER_TOKEN, string $url = self::OLLAMA_URL, array $options = []): array
    {
        return self::ollamaConfig($url, ['auth_type' => 'bearer', 'token' => $token] + $options);
    }

    /**
     * The complete configJson of an `ollama` connection with `auth_type`
     * client_credentials (without `scope` unless given in $options).
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function ollamaClientCredentialsConfig(string $clientSecret = self::PLACEHOLDER_CLIENT_SECRET, string $url = self::OLLAMA_URL, array $options = []): array
    {
        return self::ollamaConfig($url, ['auth_type' => 'client_credentials', 'token_url' => self::TOKEN_URL, 'client_id' => self::CLIENT_ID, 'client_secret' => $clientSecret] + $options);
    }

    /**
     * The complete configJson of an `anthropic` connection.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function anthropicConfig(string $apiKey = self::PLACEHOLDER_API_KEY, array $options = []): array
    {
        return [ProviderType::ANTHROPIC => true, 'type' => ProviderType::ANTHROPIC, 'endpoint_type' => 'rest', 'api_key' => $apiKey] + $options;
    }

    /**
     * Persists and flushes the connections.
     */
    public static function persist(EntityManagerInterface $entityManager, ApiConfiguration ...$connections): void
    {
        foreach ($connections as $connection) {
            $entityManager->persist($connection);
        }
        $entityManager->flush();
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function connection(string $name, array $config, bool $active): ApiConfiguration
    {
        return new ApiConfiguration()
            ->setName($name)
            ->setConfigJson($config)
            ->setActive($active);
    }
}

// - revised 2026-10-07
