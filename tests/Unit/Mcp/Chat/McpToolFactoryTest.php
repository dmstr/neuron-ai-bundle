<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:58:11 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp\Chat;

use Dmstr\NeuronAiBundle\Tests\Support\InMemoryCatalogueTrait;
use Dmstr\NeuronAiBundle\Mcp\McpToolFactory;
use Dmstr\NeuronAiBundle\Testing\ConfigSecretsFixture;
use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Kernel-free checks of the catalogue-to-tools factory: tools come from
 * the catalogue rows, one connector per connection, and nothing reaches a
 * server before a tool is executed.
 */
final class McpToolFactoryTest extends TestCase
{
    use InMemoryCatalogueTrait;

    private const string DOCS_URL = 'https://docs.example.org/mcp';
    private const string WIKI_URL = 'https://wiki.example.org/mcp';

    private const array SEARCH_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'query' => ['type' => 'string', 'description' => 'Search terms'],
            'limit' => ['type' => 'integer'],
        ],
        'required' => ['query'],
    ];

    private FakeMcpTransportFactory $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeMcpTransportFactory();
    }

    public function testToolsAreBuiltFromCatalogueRowsWithoutContactingTheServer(): void
    {
        $docs = $this->addConnection('docs', self::DOCS_URL);
        $wiki = $this->addConnection('wiki', self::WIKI_URL);
        $this->addTool($docs, 'search', 'Full-text search', self::SEARCH_SCHEMA);
        $this->addTool($docs, 'fetch', null, null);
        $this->addTool($wiki, 'page', 'Reads a wiki page', ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]]);

        $tools = $this->factory()->forConnections([$docs, $wiki]);

        self::assertSame(['fetch', 'search', 'page'], self::names($tools));
        self::assertNull($tools[0]->getDescription());
        self::assertSame('Full-text search', $tools[1]->getDescription());
        self::assertSame(['query', 'limit'], array_map(static fn (ToolPropertyInterface $p): string => $p->getName(), $tools[1]->getProperties()));
        self::assertSame(['query'], $tools[1]->getRequiredProperties());
        self::assertSame([], $tools[0]->getProperties());
        self::assertSame(['title'], array_map(static fn (ToolPropertyInterface $p): string => $p->getName(), $tools[2]->getProperties()));

        self::assertSame(0, $this->fake->getRequestCount());
    }

    public function testTransportGetsTheDecryptedToken(): void
    {
        $secrets = ConfigSecretsFixture::create();
        $docs = $this->addConnection('docs', self::DOCS_URL);
        // As stored since dmstr/api-configuration-bundle 0.5: the token is encrypted.
        $docs->setConfigJson($secrets->encrypt($docs->getConfigJson() + ['auth_type' => 'bearer', 'token' => 'clear-token']));
        $this->addTool($docs, 'search', null, self::SEARCH_SCHEMA);

        new McpToolFactory($this->entityManager(), $this->fake, null, $secrets)->forConnections([$docs]);

        self::assertSame('clear-token', $this->fake->getCreatedConfigs()[0]['token']);
    }

    public function testOneConnectorPerConnection(): void
    {
        $docs = $this->addConnection('docs', self::DOCS_URL);
        $wiki = $this->addConnection('wiki', self::WIKI_URL);
        $this->addTool($docs, 'search', null, self::SEARCH_SCHEMA);
        $this->addTool($docs, 'fetch');
        $this->addTool($wiki, 'page');

        $tools = $this->factory()->forConnections([$docs, $wiki]);

        self::assertSame(2, $this->fake->getTransportCount());
        self::assertSame([self::DOCS_URL, self::WIKI_URL], array_column($this->fake->getCreatedConfigs(), 'url'));

        // Both docs tools use the same connection: one handshake only.
        $this->fake->queueToolResult('hits', url: self::DOCS_URL);
        $this->fake->queueToolResult('content', url: self::DOCS_URL);
        $tools[1]->setInputs(['query' => 'mcp'])->execute();
        $tools[0]->setInputs([])->execute();

        self::assertSame(['initialize', 'notifications/initialized', 'tools/call', 'tools/call'], $this->fake->getMethods(self::DOCS_URL));
        self::assertSame([], $this->fake->getMethods(self::WIKI_URL));
    }

    public function testExecutionCallsTheToolOnItsOwnConnectionWithItsCredentials(): void
    {
        $docs = $this->addConnection('docs', self::DOCS_URL);
        $wiki = $this->addConnection('wiki', self::WIKI_URL);
        $wiki->setConfigJson(['auth_type' => 'bearer', 'token' => 'wiki-secret'] + $wiki->getConfigJson());
        $this->addTool($docs, 'search', null, self::SEARCH_SCHEMA);
        $this->addTool($wiki, 'page', null, ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]]);

        $tools = $this->factory()->forConnections([$docs, $wiki]);
        self::assertSame(0, $this->fake->getRequestCount());

        $this->fake->queueToolResult('Page body', url: self::WIKI_URL);
        $page = $tools[1];
        $page->setInputs(['title' => 'Home']);
        $page->execute();

        self::assertSame(['initialize', 'notifications/initialized', 'tools/call'], $this->fake->getMethods());
        self::assertSame(3, $this->fake->getRequestCount(self::WIKI_URL));
        $call = $this->fake->getRequests(self::WIKI_URL, 'tools/call')[0]['request'];
        self::assertSame(['name' => 'page', 'arguments' => ['title' => 'Home']], $call['params']);
        $result = $page->getResult();
        self::assertInstanceOf(ToolOutput::class, $result);
        self::assertSame('Page body', $result->getText());

        $wikiConfig = $this->fake->getCreatedConfigs()[1];
        self::assertSame(self::WIKI_URL, $wikiConfig['url']);
        self::assertSame('bearer', $wikiConfig['auth_type']);
        self::assertSame('wiki-secret', $wikiConfig['token']);
    }

    public function testRowsWithLastErrorAreOffered(): void
    {
        $docs = $this->addConnection('docs', self::DOCS_URL);
        $this->addTool($docs, 'search', 'Full-text search', self::SEARCH_SCHEMA)->setLastError('Tool no longer listed by the server');

        $tools = $this->factory()->forConnections([$docs]);

        self::assertSame(['search'], self::names($tools));

        // A stale entry surfaces as an error at call time.
        $this->fake->queueError('tools/call', -32602, 'Unknown tool: search');
        $this->expectException(\NeuronAI\MCP\McpException::class);
        $this->expectExceptionMessage('Unknown tool');

        $tools[0]->setInputs(['query' => 'x'])->execute();
    }

    public function testConnectionWithoutRowsYieldsNoToolsAndNoTransport(): void
    {
        $docs = $this->addConnection('docs', self::DOCS_URL);

        self::assertSame([], $this->factory()->forConnections([$docs]));
        self::assertSame([], $this->factory()->forConnections([]));
        self::assertSame(0, $this->fake->getTransportCount());
    }

    public function testToolNameOfferedByTwoConnectionsIsRejected(): void
    {
        $docs = $this->addConnection('docs', self::DOCS_URL);
        $wiki = $this->addConnection('wiki', self::WIKI_URL);
        $this->addTool($docs, 'search');
        $this->addTool($wiki, 'search');

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('MCP tool "search" is offered by both "docs" and "wiki"');

        $this->factory()->forConnections([$docs, $wiki]);
    }

    public function testRowWithUnsupportedSchemaIsSkippedWithAWarning(): void
    {
        $docs = $this->addConnection('docs', self::DOCS_URL);
        $this->addTool($docs, 'broken', null, ['type' => 'object', 'properties' => ['nothing' => ['type' => 'null']]]);
        $this->addTool($docs, 'search', null, self::SEARCH_SCHEMA);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('Skipping MCP tool'), self::callback(static fn (array $context): bool => 'broken' === $context['tool'] && 'docs' === $context['connection']));

        $tools = new McpToolFactory($this->entityManager(), $this->fake, $logger)->forConnections([$docs]);

        self::assertSame(['search'], self::names($tools));
    }

    /**
     * neuron-ai 4.x refuses a schema that tool properties cannot express (a
     * type list here; 3.x kept the first type): the row is skipped with a
     * warning, or rejected in strict mode, never fatal for the other rows.
     */
    public function testRowWithAStrictSchemaErrorIsSkippedOrRejected(): void
    {
        $docs = $this->addConnection('docs', self::DOCS_URL);
        $strict = $this->addTool($docs, 'union', null, ['type' => 'object', 'properties' => ['value' => ['type' => ['string', 'integer']]]]);
        $search = $this->addTool($docs, 'search', null, self::SEARCH_SCHEMA);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('Skipping MCP tool'), self::callback(static fn (array $context): bool => 'union' === $context['tool']));

        self::assertSame(['search'], self::names(new McpToolFactory($this->entityManager(), $this->fake, $logger)->forTools([$strict, $search])));

        try {
            $this->factory()->forToolsStrict([$search, $strict]);
            self::fail('UnexpectedValueException expected');
        } catch (\UnexpectedValueException $e) {
            self::assertSame(1, $e->getCode());
            self::assertInstanceOf(ToolException::class, $e->getPrevious());
        }
    }

    public function testForToolsKeepsTheGivenOrder(): void
    {
        $docs = $this->addConnection('docs', self::DOCS_URL);
        $search = $this->addTool($docs, 'search');
        $fetch = $this->addTool($docs, 'fetch');

        $tools = $this->factory()->forTools([$search, $fetch]);

        self::assertSame(['search', 'fetch'], self::names($tools));
        self::assertSame(1, $this->fake->getTransportCount());
    }

    private function factory(): McpToolFactory
    {
        return new McpToolFactory($this->entityManager(), $this->fake);
    }

    /**
     * @param list<ToolInterface> $tools
     *
     * @return list<string>
     */
    private static function names(array $tools): array
    {
        return array_map(static fn (ToolInterface $tool): string => $tool->getName(), $tools);
    }
}

// - revised 2026-10-14
