<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:22:22 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp;

use Dmstr\NeuronAiBundle\Mcp\StreamableHttpTransportFactory;
use NeuronAI\MCP\StreamableHttpTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the mapping from connection configuration to
 * Neuron's StreamableHttpTransport (no request is sent).
 */
final class StreamableHttpTransportFactoryTest extends TestCase
{
    private const string URL = 'https://mcp.example.org/mcp';

    public function testNoAuthSendsNoCredentials(): void
    {
        self::assertSame(
            ['url' => self::URL],
            (new StreamableHttpTransportFactory())->transportConfig(['url' => self::URL, 'transport' => 'streamable-http', 'auth_type' => 'none', 'token' => 'ignored']),
        );
    }

    public function testMissingAuthTypeMeansNone(): void
    {
        self::assertSame(['url' => self::URL], (new StreamableHttpTransportFactory())->transportConfig(['url' => self::URL]));
    }

    public function testBearerMapsToToken(): void
    {
        self::assertSame(
            ['url' => self::URL, 'token' => 'secret'],
            (new StreamableHttpTransportFactory())->transportConfig(['url' => self::URL, 'auth_type' => 'bearer', 'token' => 'secret']),
        );
    }

    public function testHeaderMapsToCustomHeader(): void
    {
        self::assertSame(
            ['url' => self::URL, 'timeout' => 5, 'headers' => ['X-Api-Key' => 'secret']],
            (new StreamableHttpTransportFactory())->transportConfig(['url' => self::URL, 'timeout' => 5, 'auth_type' => 'header', 'header_name' => 'X-Api-Key', 'token' => 'secret', '_apiConfigurationId' => 'x']),
        );
    }

    public function testCreateReturnsStreamableHttpTransport(): void
    {
        self::assertInstanceOf(StreamableHttpTransport::class, (new StreamableHttpTransportFactory())->create(['url' => self::URL]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unusable(): iterable
    {
        yield 'missing url' => [['auth_type' => 'none']];
        yield 'other transport' => [['url' => self::URL, 'transport' => 'sse']];
        yield 'bearer without token' => [['url' => self::URL, 'auth_type' => 'bearer']];
        yield 'header without name' => [['url' => self::URL, 'auth_type' => 'header', 'token' => 'secret']];
        yield 'unknown auth type' => [['url' => self::URL, 'auth_type' => 'basic']];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('unusable')]
    public function testUnusableConfigurationIsRejected(array $config): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new StreamableHttpTransportFactory())->create($config);
    }
}
