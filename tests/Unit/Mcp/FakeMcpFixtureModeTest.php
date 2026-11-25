<?php

// file generated with AI assistance: Claude Code - 2026-10-03 18:37:15 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp;

use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use Dmstr\NeuronAiBundle\Testing\FixtureSet;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the fixture mode of the fake MCP transport:
 * `tools/call` answered by tool name and argument hash, ahead of the
 * one-shot queue and the unscripted error.
 */
final class FakeMcpFixtureModeTest extends TestCase
{
    private const string URL = 'https://mcp.example.org/mcp';
    private const string OTHER_URL = 'https://other.example.org/mcp';
    private const string MCP_TOOLS = __DIR__.'/../../Fixtures/evaluation/mcp-tools.json';

    private FakeMcpTransportFactory $fake;

    private int $ids = 0;

    protected function setUp(): void
    {
        $this->fake = new FakeMcpTransportFactory();
    }

    public function testNameAndHashBeatsTheBareName(): void
    {
        $arguments = ['query' => 'Wartung', 'limit' => 3];
        $this->fake->useFixtures([
            'search' => 'bare',
            'search#'.FakeMcpTransportFactory::argumentsHash($arguments) => 'exact',
        ]);

        self::assertSame('exact', $this->text($this->call('search', $arguments)));
        self::assertSame('bare', $this->text($this->call('search', ['query' => 'other'])));
        self::assertSame('exact', $this->text($this->call('search', ['limit' => 3, 'query' => 'Wartung'])), 'Fixtures are not consumed.');
        self::assertSame(3, $this->fake->getRequestCount(self::URL, 'tools/call'));
    }

    public function testHashIsStableUnderKeyOrder(): void
    {
        $a = ['query' => 'Wartung', 'filter' => ['year' => 2026, 'tags' => ['b', 'a']], 'limit' => 3];
        $b = ['limit' => 3, 'filter' => ['tags' => ['b', 'a'], 'year' => 2026], 'query' => 'Wartung'];

        self::assertSame(FakeMcpTransportFactory::argumentsHash($a), FakeMcpTransportFactory::argumentsHash($b));
        // List order matters.
        $c = $a;
        $c['filter']['tags'] = ['a', 'b'];
        self::assertNotSame(FakeMcpTransportFactory::argumentsHash($a), FakeMcpTransportFactory::argumentsHash($c));
        // Canonical JSON: sorted keys, unescaped slashes and unicode.
        self::assertSame(sha1('{"path":"/docs/ä","q":"x"}'), FakeMcpTransportFactory::argumentsHash(['q' => 'x', 'path' => '/docs/ä']));
        self::assertSame(sha1('[]'), FakeMcpTransportFactory::argumentsHash([]));

        $this->fake->useFixtures(['search#'.FakeMcpTransportFactory::argumentsHash($a) => 'exact']);
        self::assertSame('exact', $this->text($this->call('search', $b)));
    }

    public function testUnmatchedCallFallsBackToTheQueueAndThenTheError(): void
    {
        $this->fake
            ->useFixtures(['search' => 'fixture'])
            ->queueToolResult('queued');

        self::assertSame('fixture', $this->text($this->call('search', ['query' => 'x'])));
        self::assertSame('queued', $this->text($this->call('fetch', ['id' => 1])));

        $response = $this->call('fetch', ['id' => 1]);
        self::assertArrayNotHasKey('result', $response);
        self::assertSame(-32603, $response['error']['code']);
        self::assertStringContainsString('"fetch"', $response['error']['message']);
    }

    public function testUrlSpecificFixturesWinAndClearFixturesForgetsAll(): void
    {
        $this->fake
            ->useFixtures(['search' => 'global'])
            ->useFixtures(['search' => 'specific'], self::URL);

        self::assertSame('specific', $this->text($this->call('search', [])));
        self::assertSame('global', $this->text($this->call('search', [], self::OTHER_URL)));

        $this->fake->clearFixtures()->queueToolResult('queued');
        self::assertSame('queued', $this->text($this->call('search', [])));
    }

    public function testIsErrorAndContentListFixtures(): void
    {
        $this->fake->useFixtures([
            'broken' => ['content' => 'Upstream timeout', 'isError' => true],
            'multi' => ['content' => [['type' => 'text', 'text' => 'one'], ['type' => 'text', 'text' => 'two']]],
        ]);

        $broken = $this->call('broken', []);
        self::assertTrue($broken['result']['isError']);
        self::assertSame([['type' => 'text', 'text' => 'Upstream timeout']], $broken['result']['content']);

        $multi = $this->call('multi', []);
        self::assertFalse($multi['result']['isError']);
        self::assertSame(['one', 'two'], array_column($multi['result']['content'], 'text'));
    }

    public function testFailWithStillWinsOverFixtures(): void
    {
        $this->fake->useFixtures(['search' => 'fixture'])->failWith(new \RuntimeException('down'));

        $this->expectException(\RuntimeException::class);
        $this->call('search', []);
    }

    public function testMalformedFixtureIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->fake->useFixtures(['search' => ['isError' => true]]);
    }

    public function testLoadsTheEvaluationMcpTools(): void
    {
        $fixtures = FixtureSet::loadMcp(self::MCP_TOOLS);
        self::assertCount(2, $fixtures);
        $this->fake->useFixtures($fixtures);

        $exact = $this->call('search_documents', ['limit' => 3, 'query' => 'Wartungsintervall Hydraulikpumpe']);
        self::assertStringContainsString('Kapitel 4.2', $this->text($exact));
        self::assertFalse($exact['result']['isError']);

        self::assertSame('Keine passenden Dokumente gefunden.', $this->text($this->call('search_documents', ['query' => 'Garantie'])));
    }

    /**
     * Sends a `tools/call` request through a fresh transport.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> the JSON-RPC response
     */
    private function call(string $name, array $arguments, string $url = self::URL): array
    {
        $transport = $this->fake->create(['url' => $url]);
        $transport->connect();
        $transport->send([
            'jsonrpc' => '2.0',
            'id' => ++$this->ids,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);

        return $transport->receive();
    }

    /**
     * @param array<string, mixed> $response
     */
    private function text(array $response): string
    {
        self::assertArrayHasKey('result', $response, 'Expected a JSON-RPC result.');

        return implode("\n", array_column($response['result']['content'], 'text'));
    }
}
