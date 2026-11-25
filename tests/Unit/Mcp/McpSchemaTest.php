<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:22:22 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp;

use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\NeuronAiBundle\Mcp\McpExtension;
use Dmstr\NeuronAiBundle\Testing\ConfigSecretsFixture;
use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the `mcp` branch of ApiConfiguration.configJson,
 * validated like ApiConfigurationValidator does (opis, after
 * json_decode(json_encode())).
 */
final class McpSchemaTest extends TestCase
{
    private const array BASE = [
        'mcp' => true,
        'type' => 'mcp',
        'endpoint_type' => 'rest',
        'url' => 'https://mcp.example.org/mcp',
        'transport' => 'streamable-http',
    ];

    private const array CLIENT_CREDENTIALS = [
        'auth_type' => 'client_credentials',
        'token_url' => 'https://sso.example.org/realms/example/protocol/openid-connect/token',
        'client_id' => 'mcp-agent',
        'client_secret' => 'secret',
    ];

    private const array FORWARD_JWT = [
        'auth_type' => 'forward_jwt',
        'scan_token_url' => 'https://sso.example.org/realms/example/protocol/openid-connect/token',
        'scan_client_id' => 'mcp-scan',
        'scan_client_secret' => 'secret',
    ];

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function validConfigs(): iterable
    {
        yield 'auth none' => [self::BASE + ['auth_type' => 'none']];
        yield 'auth omitted' => [self::BASE];
        yield 'bearer' => [self::BASE + ['auth_type' => 'bearer', 'token' => 'secret']];
        yield 'header' => [self::BASE + ['auth_type' => 'header', 'header_name' => 'X-Api-Key', 'token' => 'secret']];
        yield 'http and timeout' => [['url' => 'http://openapi-mcp:8787/mcp', 'timeout' => 10] + self::BASE];
        yield 'restricted without auth' => [self::BASE + ['auth_type' => 'none', 'restricted' => true]];
        yield 'unrestricted bearer' => [self::BASE + ['auth_type' => 'bearer', 'token' => 'secret', 'restricted' => false]];
        yield 'client credentials' => [self::BASE + self::CLIENT_CREDENTIALS];
        yield 'client credentials, restricted and scope' => [self::BASE + self::CLIENT_CREDENTIALS + ['restricted' => true, 'scope' => 'mcp']];
        yield 'forward jwt' => [self::BASE + self::FORWARD_JWT];
        yield 'forward jwt, unrestricted' => [self::BASE + self::FORWARD_JWT + ['restricted' => false]];
        yield 'forward jwt, restricted and scan scope' => [self::BASE + self::FORWARD_JWT + ['restricted' => true, 'scan_scope' => 'mcp']];
        yield 'client credentials with http token url' => [self::BASE + ['token_url' => 'http://sso.example.org/realms/example/protocol/openid-connect/token'] + self::CLIENT_CREDENTIALS];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('validConfigs')]
    public function testValidConfigurationsPass(array $config): void
    {
        self::assertTrue($this->validate($config), json_encode($config));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidConfigs(): iterable
    {
        $withoutUrl = self::BASE;
        unset($withoutUrl['url']);

        yield 'missing url' => [$withoutUrl];
        // Missing discriminators are not tested: opis fills them from their
        // defaults before checking `required`, as in the platform validator.
        yield 'discriminator false' => [['mcp' => false] + self::BASE];
        yield 'wrong type' => [['type' => 'github'] + self::BASE];
        yield 'wrong endpoint type' => [['endpoint_type' => 'file'] + self::BASE];
        yield 'wrong transport' => [['transport' => 'sse'] + self::BASE];
        yield 'non-http url' => [['url' => 'ftp://mcp.example.org/mcp'] + self::BASE];
        yield 'relative url' => [['url' => 'mcp.example.org/mcp'] + self::BASE];
        yield 'unknown auth type' => [self::BASE + ['auth_type' => 'basic']];
        yield 'bearer without token' => [self::BASE + ['auth_type' => 'bearer']];
        yield 'bearer with empty token' => [self::BASE + ['auth_type' => 'bearer', 'token' => '']];
        yield 'header without header_name' => [self::BASE + ['auth_type' => 'header', 'token' => 'secret']];
        yield 'header without token' => [self::BASE + ['auth_type' => 'header', 'header_name' => 'X-Api-Key']];
        yield 'header name with space' => [self::BASE + ['auth_type' => 'header', 'header_name' => 'X Api Key', 'token' => 'secret']];
        yield 'timeout as string' => [self::BASE + ['timeout' => '10']];
        yield 'timeout zero' => [self::BASE + ['timeout' => 0]];
        yield 'unknown property' => [self::BASE + ['headers' => ['X-Api-Key' => 'secret']]];
        yield 'restricted as string' => [self::BASE + ['restricted' => 'true']];
        yield 'client credentials, unrestricted' => [self::BASE + self::CLIENT_CREDENTIALS + ['restricted' => false]];
        foreach (['token_url', 'client_id', 'client_secret'] as $key) {
            $config = self::BASE + self::CLIENT_CREDENTIALS;
            unset($config[$key]);
            yield 'client credentials without '.$key => [$config];
        }
        yield 'client credentials with empty secret' => [self::BASE + ['client_secret' => ''] + self::CLIENT_CREDENTIALS];
        yield 'client credentials with relative token url' => [self::BASE + ['token_url' => 'keycloak/token'] + self::CLIENT_CREDENTIALS];
        foreach (['scan_token_url', 'scan_client_id', 'scan_client_secret'] as $key) {
            $config = self::BASE + self::FORWARD_JWT;
            unset($config[$key]);
            yield 'forward jwt without '.$key => [$config];
        }
        yield 'forward jwt with empty scan secret' => [self::BASE + ['scan_client_secret' => ''] + self::FORWARD_JWT];
        yield 'forward jwt with relative scan token url' => [self::BASE + ['scan_token_url' => 'keycloak/token'] + self::FORWARD_JWT];
        yield 'forward jwt with client credentials keys only' => [self::BASE + ['auth_type' => 'forward_jwt'] + self::CLIENT_CREDENTIALS];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigs')]
    public function testInvalidConfigurationsAreRejected(array $config): void
    {
        self::assertFalse($this->validate($config), json_encode($config));
    }

    public function testBranchFollowsTheDiscriminatorConvention(): void
    {
        $schema = $this->schema();

        self::assertSame('MCP server', $schema['title']);
        self::assertFalse($schema['additionalProperties']);
        foreach (['mcp' => true, 'type' => 'mcp', 'endpoint_type' => 'rest'] as $field => $value) {
            self::assertContains($field, $schema['required']);
            self::assertSame($value, $schema['properties'][$field]['const'], $field);
            self::assertSame($value, $schema['properties'][$field]['default'], $field);
            self::assertTrue($schema['properties'][$field]['readOnly'], $field);
        }
        self::assertSame('uri', $schema['properties']['url']['format']);
        self::assertSame('^https?://', $schema['properties']['url']['pattern']);
    }

    public function testClientSecretIsASecretLikeTheToken(): void
    {
        $properties = $this->schema()['properties'];

        self::assertTrue($properties['token']['writeOnly']);
        self::assertTrue($properties['client_secret']['writeOnly']);
        self::assertArrayNotHasKey('writeOnly', $properties['client_id']);
        self::assertArrayNotHasKey('writeOnly', $properties['token_url']);
        self::assertSame(false, $properties['restricted']['default']);
    }

    public function testClientSecretIsStoredEncryptedAndReturnedMasked(): void
    {
        $secrets = ConfigSecretsFixture::create();
        $config = self::BASE + self::CLIENT_CREDENTIALS + ['restricted' => true];

        $stored = $secrets->encrypt($config);
        self::assertTrue(ConfigSecrets::isEncrypted($stored['client_secret']));
        self::assertSame($config['client_id'], $stored['client_id']);
        self::assertSame($config['token_url'], $stored['token_url']);
        self::assertSame(ConfigSecrets::MASK, $secrets->mask($stored)['client_secret']);
        self::assertSame($config, $secrets->decrypt($stored));
    }

    public function testScanClientSecretIsStoredEncryptedAndReturnedMasked(): void
    {
        $properties = $this->schema()['properties'];
        self::assertTrue($properties['scan_client_secret']['writeOnly']);
        self::assertArrayNotHasKey('writeOnly', $properties['scan_client_id']);
        self::assertArrayNotHasKey('writeOnly', $properties['scan_token_url']);

        $secrets = ConfigSecretsFixture::create();
        $config = self::BASE + self::FORWARD_JWT;

        $stored = $secrets->encrypt($config);
        self::assertTrue(ConfigSecrets::isEncrypted($stored['scan_client_secret']));
        self::assertSame($config['scan_client_id'], $stored['scan_client_id']);
        self::assertSame(ConfigSecrets::MASK, $secrets->mask($stored)['scan_client_secret']);
        self::assertSame($config, $secrets->decrypt($stored));
    }

    public function testExtensionPointsAtTheSchema(): void
    {
        $extension = new McpExtension(new FakeMcpTransportFactory());

        self::assertSame('mcp', $extension->getName());
        self::assertSame('rest', $extension->getType());
        self::assertFileExists($extension->getSchemaPath());
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $path = (new McpExtension(new FakeMcpTransportFactory()))->getSchemaPath();

        return json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function validate(array $config): bool
    {
        $data = json_decode(json_encode($config, \JSON_THROW_ON_ERROR));
        $schema = json_decode(json_encode($this->schema(), \JSON_THROW_ON_ERROR));

        return (new Validator())->validate($data, $schema)->isValid();
    }
}

// - revised 2026-10-09 06:42:28 UTC
// - revised 2026-10-09 13:43:23 UTC
