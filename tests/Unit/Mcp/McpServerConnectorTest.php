<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:22:22 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp;

use Dmstr\NeuronAiBundle\Mcp\McpProtocolException;
use Dmstr\NeuronAiBundle\Mcp\McpServerConnector;
use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\MCP\McpException;
use NeuronAI\MCP\McpTool;
use NeuronAI\Providers\Anthropic\ToolMapper;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the connector subclass: tools built from catalogue
 * data need no connection; the connection is made on the first execution.
 * neuron-ai 4.x: the tools are McpTools whose result is a ToolOutput, a
 * JSON-RPC error of `tools/call` is thrown, schemas are strict, and the
 * client hands the negotiated protocol revision to the transport.
 */
final class McpServerConnectorTest extends TestCase
{
    private const string URL = 'https://mcp.example.org/mcp';

    private const array INPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'query' => ['type' => 'string', 'description' => 'Search terms'],
            'limit' => ['type' => 'integer'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['query'],
    ];

    private FakeMcpTransportFactory $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeMcpTransportFactory();
    }

    public function testCatalogueToolIsBuiltWithoutContactingTheServer(): void
    {
        $tool = $this->connector()->createCatalogueTool('search', 'Full-text search', self::INPUT_SCHEMA);

        self::assertSame('search', $tool->getName());
        self::assertSame('Full-text search', $tool->getDescription());
        self::assertSame(['query', 'limit', 'tags'], array_map(static fn ($p) => $p->getName(), $tool->getProperties()));
        self::assertSame(['query'], $tool->getRequiredProperties());
        self::assertSame(0, $this->fake->getRequestCount());
    }

    public function testPropertyTypedOnlyByBranchesIsAnnouncedAsObject(): void
    {
        // `variables` of flowable_start_process (dmstr/flowable-bundle): no
        // top-level type; neuron-ai 4.x would refuse the branches.
        $schema = [
            'type' => 'object',
            'properties' => [
                'processDefinitionKey' => ['type' => 'string'],
                'variables' => [
                    'description' => 'Process variables',
                    'oneOf' => [
                        ['type' => 'object'],
                        ['type' => 'array', 'items' => ['type' => 'object']],
                    ],
                ],
                'note' => ['anyOf' => [['type' => 'null'], ['type' => 'string']]],
                'free' => ['description' => 'no type at all'],
            ],
        ];

        $tool = $this->connector()->createCatalogueTool('flowable_start_process', null, $schema);
        $payload = (new ToolMapper())->map([$tool])[0]['input_schema']['properties'];

        self::assertSame(['type' => 'object', 'description' => 'Process variables'], $payload['variables']);
        self::assertSame('string', $payload['note']['type']);
        self::assertSame('string', $payload['free']['type']);
        self::assertSame('{"type":"object","description":"Process variables"}', json_encode($payload['variables']));
    }

    public function testToolWithoutPropertiesIsSupported(): void
    {
        $tool = $this->connector()->createToolFromDefinition(['name' => 'ping']);

        self::assertSame('ping', $tool->getName());
        self::assertSame([], $tool->getProperties());
    }

    public function testExecutionConnectsAndCallsTheTool(): void
    {
        $this->fake->queueToolResult('three hits');
        $tool = $this->connector()->createCatalogueTool('search', null, self::INPUT_SCHEMA);

        $tool->setInputs(['query' => 'mcp', 'limit' => 3]);
        $tool->execute();

        self::assertSame(['initialize', 'notifications/initialized', 'tools/call'], $this->fake->getMethods());
        $call = $this->fake->getRequests(self::URL, 'tools/call')[0]['request'];
        self::assertSame(['name' => 'search', 'arguments' => ['query' => 'mcp', 'limit' => 3]], $call['params']);
        self::assertInstanceOf(McpTool::class, $tool);
        $result = $tool->getResult();
        self::assertInstanceOf(ToolOutput::class, $result);
        self::assertSame('three hits', $result->getText());
        self::assertFalse($result->isError());
    }

    public function testServerSideToolErrorIsAnErrorOutput(): void
    {
        $this->fake->queueToolResult('quota exceeded', isError: true);
        $tool = $this->connector()->createCatalogueTool('search', null, self::INPUT_SCHEMA);

        $tool->setInputs(['query' => 'mcp']);
        $tool->execute();

        $result = $tool->getResult();
        self::assertInstanceOf(ToolOutput::class, $result);
        self::assertTrue($result->isError());
        self::assertSame('quota exceeded', $result->getText());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function strictSchemas(): iterable
    {
        yield 'type list' => [['type' => ['string', 'integer']]];
        yield 'reference' => [['$ref' => '#/definitions/x']];
        yield 'branches next to a type' => [['type' => 'object', 'anyOf' => [['type' => 'object'], ['type' => 'null']]]];
        yield 'nested branches' => [['type' => 'object', 'properties' => ['inner' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]]]]];
    }

    /**
     * neuron-ai 4.x refuses what tool properties cannot express (3.x
     * simplified it silently); the tool factory skips or rejects such a row.
     *
     * @param array<string, mixed> $property
     */
    #[DataProvider('strictSchemas')]
    public function testUnrepresentableSchemaThrowsAToolException(array $property): void
    {
        $this->expectException(ToolException::class);

        $this->connector()->createCatalogueTool('strict', null, ['type' => 'object', 'properties' => ['arg' => $property]]);
    }

    public function testNegotiatedProtocolRevisionReachesTheTransport(): void
    {
        $transport = $this->fake->create(['url' => self::URL]);
        $this->fake->serveServerInfo('docs', '1.2.3', '2025-06-18');

        $connector = McpServerConnector::forTransport($transport);
        $connector->listTools();

        self::assertSame(FakeMcpTransportFactory::DEFAULT_PROTOCOL_VERSION, $this->fake->getRequests(self::URL, 'initialize')[0]['request']['params']['protocolVersion']);
        self::assertSame('2025-06-18', $transport->getProtocolVersion());

        // The client disconnects when it is released.
        unset($connector);
        self::assertNull($transport->getProtocolVersion());
    }

    public function testToolErrorIsRaised(): void
    {
        $this->fake->queueError('tools/call', -32602, 'Unknown tool');
        $tool = $this->connector()->createToolFromDefinition(['name' => 'gone']);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('Unknown tool');

        $tool->execute();
    }

    public function testListToolsAndInitializeResult(): void
    {
        $this->fake->serveServerInfo('docs', '1.2.3')->serveTools([['name' => 'a'], ['name' => 'b']]);
        $connector = $this->connector();

        self::assertSame([['name' => 'a'], ['name' => 'b']], $connector->listTools());
        $initialize = $connector->getInitializeResult();
        self::assertSame(FakeMcpTransportFactory::DEFAULT_PROTOCOL_VERSION, $initialize['protocolVersion']);
        self::assertSame(['name' => 'docs', 'version' => '1.2.3'], $initialize['serverInfo']);
        self::assertSame(1, $this->fake->getRequestCount(null, 'initialize'));
    }

    public function testMalformedListingRaisesProtocolException(): void
    {
        $this->fake->queueResult('tools/list', ['tools' => 'nope']);

        $this->expectException(McpProtocolException::class);

        $this->connector()->listTools();
    }

    public function testTransportIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new McpServerConnector(['url' => self::URL]);
    }

    private function connector(): McpServerConnector
    {
        return McpServerConnector::forTransport($this->fake->create(['url' => self::URL]));
    }
}
// - revised 2026-10-09
// - revised 2026-10-14
