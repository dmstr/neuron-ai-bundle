<?php

// file generated with AI assistance: Claude Code - 2026-09-30 22:07:50 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp\Seed;

use Dmstr\NeuronAiBundle\Command\McpConnectionSeedCommand;
use Dmstr\NeuronAiBundle\Mcp\McpExtension;
use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the seed command's pure parts: building the
 * configJson, comparing it for the no-op and parsing --timeout. Built
 * configurations are validated against the `mcp` schema branch the way
 * ApiConfigurationValidator does (opis, after json_decode(json_encode())).
 */
final class McpConnectionSeedConfigTest extends TestCase
{
    private const string URL = 'https://mcp.example.org/mcp';
    private const string TOKEN_URL = 'http://sso.example.org/realms/example/protocol/openid-connect/token';

    public function testBuildsTheCompleteConfigWithDiscriminators(): void
    {
        self::assertSame([
            'mcp' => true,
            'type' => 'mcp',
            'endpoint_type' => 'rest',
            'url' => self::URL,
            'transport' => 'streamable-http',
            'auth_type' => 'none',
        ], McpConnectionSeedCommand::buildConfig(self::URL));
    }

    public function testBuildsTheAuthenticationVariants(): void
    {
        self::assertSame(
            ['auth_type' => 'bearer', 'token' => 'secret'],
            array_intersect_key(McpConnectionSeedCommand::buildConfig(self::URL, 'bearer', 'secret'), ['auth_type' => 0, 'token' => 0, 'header_name' => 0]),
        );
        self::assertSame(
            ['auth_type' => 'header', 'header_name' => 'X-Api-Key', 'token' => 'secret'],
            array_intersect_key(McpConnectionSeedCommand::buildConfig(self::URL, 'header', 'secret', 'X-Api-Key'), ['auth_type' => 0, 'token' => 0, 'header_name' => 0]),
        );
        self::assertSame(10, McpConnectionSeedCommand::buildConfig(self::URL, timeout: 10)['timeout']);
    }

    public function testBuildsClientCredentialsAndRestricted(): void
    {
        $config = McpConnectionSeedCommand::buildConfig(
            self::URL,
            'client_credentials',
            tokenUrl: self::TOKEN_URL,
            clientId: 'mcp-agent',
            clientSecret: 'secret',
            scope: 'mcp',
            restricted: true,
        );

        self::assertSame(
            ['auth_type' => 'client_credentials', 'token_url' => self::TOKEN_URL, 'client_id' => 'mcp-agent', 'client_secret' => 'secret', 'scope' => 'mcp', 'restricted' => true],
            array_intersect_key($config, array_flip(['auth_type', 'token_url', 'client_id', 'client_secret', 'scope', 'restricted', 'token'])),
        );
        self::assertSame(true, McpConnectionSeedCommand::buildConfig(self::URL, restricted: true)['restricted']);
        self::assertArrayNotHasKey('restricted', McpConnectionSeedCommand::buildConfig(self::URL));
    }

    public function testBuildsForwardJwtWithTheScanServiceAccount(): void
    {
        $config = McpConnectionSeedCommand::buildConfig(
            self::URL,
            'forward_jwt',
            scanTokenUrl: self::TOKEN_URL,
            scanClientId: 'mcp-scan',
            scanClientSecret: 'secret',
            scanScope: 'mcp',
        );

        self::assertSame(
            ['auth_type' => 'forward_jwt', 'scan_token_url' => self::TOKEN_URL, 'scan_client_id' => 'mcp-scan', 'scan_client_secret' => 'secret', 'scan_scope' => 'mcp'],
            array_intersect_key($config, array_flip(['auth_type', 'token_url', 'client_id', 'client_secret', 'scope', 'scan_token_url', 'scan_client_id', 'scan_client_secret', 'scan_scope', 'restricted', 'token'])),
        );
        self::assertSame(true, McpConnectionSeedCommand::buildConfig(self::URL, 'forward_jwt', restricted: true, scanTokenUrl: self::TOKEN_URL, scanClientId: 'a', scanClientSecret: 's')['restricted']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function misplacedClientCredentials(): iterable
    {
        yield 'token url without client_credentials' => [['tokenUrl' => self::TOKEN_URL], '--token-url'];
        yield 'client id with bearer' => [['auth' => 'bearer', 'token' => 'x', 'clientId' => 'a'], '--client-id'];
        yield 'client secret with header' => [['auth' => 'header', 'token' => 'x', 'headerName' => 'X-Key', 'clientSecret' => 's'], '--client-secret'];
        yield 'scope without auth' => [['scope' => 'mcp'], '--scope'];
        yield 'token with client_credentials' => [['auth' => 'client_credentials', 'token' => 'x'], '--token'];
        yield 'scan token url with client_credentials' => [['auth' => 'client_credentials', 'scanTokenUrl' => self::TOKEN_URL], '--scan-token-url'];
        yield 'scan client id without auth' => [['scanClientId' => 'a'], '--scan-client-id'];
        yield 'scan client secret with bearer' => [['auth' => 'bearer', 'token' => 'x', 'scanClientSecret' => 's'], '--scan-client-secret'];
        yield 'scan scope without auth' => [['scanScope' => 'mcp'], '--scan-scope'];
        yield 'client id with forward_jwt' => [['auth' => 'forward_jwt', 'clientId' => 'a'], '--client-id'];
        yield 'token with forward_jwt' => [['auth' => 'forward_jwt', 'token' => 'x'], '--token'];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('misplacedClientCredentials')]
    public function testClientCredentialsOptionsRequireTheirAuth(array $arguments, string $option): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($option);

        McpConnectionSeedCommand::buildConfig(self::URL, ...$arguments);
    }

    public function testEmptyOptionsAreTreatedAsOmitted(): void
    {
        $config = McpConnectionSeedCommand::buildConfig(self::URL, 'none', '', '');

        self::assertArrayNotHasKey('token', $config);
        self::assertArrayNotHasKey('header_name', $config);
    }

    public function testTokenWithoutMatchingAuthIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--token');

        McpConnectionSeedCommand::buildConfig(self::URL, 'none', 'secret');
    }

    public function testHeaderNameWithoutHeaderAuthIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--header-name');

        McpConnectionSeedCommand::buildConfig(self::URL, 'bearer', 'secret', 'X-Api-Key');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function validSeeds(): iterable
    {
        yield 'none' => [McpConnectionSeedCommand::buildConfig(self::URL)];
        yield 'development sidecar' => [McpConnectionSeedCommand::buildConfig('http://openapi-mcp:8787/mcp')];
        yield 'bearer' => [McpConnectionSeedCommand::buildConfig(self::URL, 'bearer', 'secret')];
        yield 'header with timeout' => [McpConnectionSeedCommand::buildConfig(self::URL, 'header', 'secret', 'X-Api-Key', timeout: 10)];
        yield 'restricted bearer' => [McpConnectionSeedCommand::buildConfig(self::URL, 'bearer', 'secret', restricted: true)];
        yield 'client credentials' => [McpConnectionSeedCommand::buildConfig('http://mcp.example.org/mcp/flowable', 'client_credentials', tokenUrl: self::TOKEN_URL, clientId: 'mcp-agent', clientSecret: 'mcp-agent-dev-secret', restricted: true)];
        yield 'forward jwt' => [McpConnectionSeedCommand::buildConfig('http://mcp.example.org/mcp/flowable', 'forward_jwt', scanTokenUrl: self::TOKEN_URL, scanClientId: 'mcp-scan', scanClientSecret: 'secret')];
        yield 'forward jwt, restricted and scan scope' => [McpConnectionSeedCommand::buildConfig(self::URL, 'forward_jwt', restricted: true, scanTokenUrl: self::TOKEN_URL, scanClientId: 'mcp-scan', scanClientSecret: 'secret', scanScope: 'mcp')];
        yield 'client credentials without --restricted' => [McpConnectionSeedCommand::buildConfig(self::URL, 'client_credentials', tokenUrl: self::TOKEN_URL, clientId: 'mcp-agent', clientSecret: 'secret', scope: 'mcp')];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('validSeeds')]
    public function testBuiltConfigsMatchTheSchema(array $config): void
    {
        self::assertTrue($this->validate($config), json_encode($config, \JSON_THROW_ON_ERROR));
    }

    /**
     * Left to the schema on purpose, so seed and API report the same
     * violations.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidSeeds(): iterable
    {
        yield 'bearer without token' => [McpConnectionSeedCommand::buildConfig(self::URL, 'bearer')];
        yield 'header without header name' => [McpConnectionSeedCommand::buildConfig(self::URL, 'header', 'secret')];
        yield 'non-http url' => [McpConnectionSeedCommand::buildConfig('ftp://mcp.example.org/mcp')];
        yield 'unknown auth' => [McpConnectionSeedCommand::buildConfig(self::URL, 'basic')];
        yield 'other transport' => [McpConnectionSeedCommand::buildConfig(self::URL, transport: 'stdio')];
        yield 'timeout out of range' => [McpConnectionSeedCommand::buildConfig(self::URL, timeout: 0)];
        yield 'client credentials without secret' => [McpConnectionSeedCommand::buildConfig(self::URL, 'client_credentials', tokenUrl: self::TOKEN_URL, clientId: 'mcp-agent', restricted: true)];
        yield 'client credentials without token url' => [McpConnectionSeedCommand::buildConfig(self::URL, 'client_credentials', clientId: 'mcp-agent', clientSecret: 'secret')];
        yield 'client credentials with relative token url' => [McpConnectionSeedCommand::buildConfig(self::URL, 'client_credentials', tokenUrl: 'keycloak/token', clientId: 'mcp-agent', clientSecret: 'secret')];
        yield 'forward jwt without scan secret' => [McpConnectionSeedCommand::buildConfig(self::URL, 'forward_jwt', scanTokenUrl: self::TOKEN_URL, scanClientId: 'mcp-scan')];
        yield 'forward jwt without scan token url' => [McpConnectionSeedCommand::buildConfig(self::URL, 'forward_jwt', scanClientId: 'mcp-scan', scanClientSecret: 'secret')];
        yield 'forward jwt with relative scan token url' => [McpConnectionSeedCommand::buildConfig(self::URL, 'forward_jwt', scanTokenUrl: 'keycloak/token', scanClientId: 'mcp-scan', scanClientSecret: 'secret')];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidSeeds')]
    public function testInvalidSeedsViolateTheSchema(array $config): void
    {
        self::assertFalse($this->validate($config), json_encode($config, \JSON_THROW_ON_ERROR));
    }

    public function testSameConfigIgnoresKeyOrder(): void
    {
        $desired = McpConnectionSeedCommand::buildConfig(self::URL, 'header', 'secret', 'X-Api-Key', timeout: 10);
        $stored = array_reverse($desired, true);

        self::assertTrue(McpConnectionSeedCommand::isSameConfig($stored, $desired));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function drifted(): iterable
    {
        $desired = McpConnectionSeedCommand::buildConfig(self::URL, 'bearer', 'secret');

        yield 'other url' => [['url' => 'https://other.example.org/mcp'] + $desired];
        yield 'other token' => [['token' => 'rotated'] + $desired];
        yield 'extra field' => [$desired + ['timeout' => 10]];
        yield 'missing field' => [array_diff_key($desired, ['token' => 0])];
        yield 'loosely equal value' => [['mcp' => 1] + $desired];
    }

    /**
     * @param array<string, mixed> $stored
     */
    #[DataProvider('drifted')]
    public function testDriftIsDetected(array $stored): void
    {
        $desired = McpConnectionSeedCommand::buildConfig(self::URL, 'bearer', 'secret');

        self::assertFalse(McpConnectionSeedCommand::isSameConfig($stored, $desired));
    }

    public function testParseTimeout(): void
    {
        self::assertNull(McpConnectionSeedCommand::parseTimeout(null));
        self::assertNull(McpConnectionSeedCommand::parseTimeout(''));
        self::assertSame(15, McpConnectionSeedCommand::parseTimeout('15'));
        self::assertSame(-1, McpConnectionSeedCommand::parseTimeout('-1'));
    }

    public function testParseTimeoutRejectsNonIntegers(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        McpConnectionSeedCommand::parseTimeout('10s');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function validate(array $config): bool
    {
        $path = (new McpExtension(new FakeMcpTransportFactory()))->getSchemaPath();
        $schema = json_decode((string) file_get_contents($path), false, 512, \JSON_THROW_ON_ERROR);
        $data = json_decode(json_encode($config, \JSON_THROW_ON_ERROR));

        return (new Validator())->validate($data, $schema)->isValid();
    }
}

// - revised 2026-10-09 06:42:28 UTC
// - revised 2026-10-09 13:43:23 UTC
