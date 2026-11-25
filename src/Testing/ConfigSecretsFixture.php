<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Testing;

use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\ApiConfiguration\Security\SecretSchemaResolver;
use Dmstr\ApiConfiguration\Service\ApiExtensionRegistry;
use Dmstr\ApiPlatformUtils\Service\CredentialEncryption;
use Dmstr\NeuronAiBundle\Mcp\McpExtension;
use Dmstr\NeuronAiBundle\Provider\Connection\AnthropicConnectionType;
use Dmstr\NeuronAiBundle\Provider\Connection\OllamaConnectionType;
use Dmstr\OpenApiJsonSchema\Interface\SchemaProviderInterface;
use Dmstr\OpenApiJsonSchema\Service\SchemaRegistry;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * A real {@see ConfigSecrets} for kernel-free tests, with the insecure test
 * key of the app's `when@test` configuration.
 *
 * Without arguments it knows the schemas of this bundle (`mcp`, `ollama`,
 * `anthropic`); other types pass their schema files by type:
 *
 *     $secrets = ConfigSecretsFixture::create();
 *     $secrets = ConfigSecretsFixture::create(['firecrawl' => $firecrawlSchemaPath]);
 *     $stored = $secrets->encrypt($config);      // what the database holds
 */
final class ConfigSecretsFixture
{
    /**
     * INSECURE, PUBLICLY KNOWN TEST KEY (32 bytes); tests only. Its base64
     * form is the value an application configures as the `when@test`
     * fallback of `dmstr_api_platform_utils.insecure_test_key`.
     */
    public const string INSECURE_TEST_KEY = 'neuron-ai-INSECURE-test-key-32b!';

    private function __construct()
    {
    }

    /**
     * @param array<string, string>|null $schemaPaths type => schema.json; null = mcp, ollama and anthropic
     */
    public static function create(?array $schemaPaths = null): ConfigSecrets
    {
        $registry = new SchemaRegistry(new ArrayAdapter(), new NullLogger());
        foreach ($schemaPaths ?? self::bundleSchemas() as $type => $path) {
            $registry->register(new class($type, $path) implements SchemaProviderInterface {
                public function __construct(private readonly string $name, private readonly string $path)
                {
                }

                public function getName(): string
                {
                    return $this->name;
                }

                public function getSchemaPath(): string
                {
                    return $this->path;
                }
            });
        }

        return new ConfigSecrets(
            new SecretSchemaResolver($registry, new ApiExtensionRegistry()),
            static fn (): CredentialEncryption => new CredentialEncryption(self::INSECURE_TEST_KEY),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function bundleSchemas(): array
    {
        $src = \dirname(__DIR__);

        return [
            McpExtension::NAME => $src.'/Mcp/schema.json',
            OllamaConnectionType::NAME => $src.'/Provider/Connection/ollama.schema.json',
            AnthropicConnectionType::NAME => $src.'/Provider/Connection/anthropic.schema.json',
        ];
    }
}
