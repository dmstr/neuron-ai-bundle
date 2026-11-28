<?php

// file generated with AI assistance: Claude Code - 2026-11-27 22:48:58 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Chat;

use ApiPlatform\Metadata\IriConverterInterface;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Agent\TurnConfiguration;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Chat\AgentChatResult;
use Dmstr\NeuronAiBundle\Chat\AgentRunner;
use Dmstr\NeuronAiBundle\Chat\AgentRunOptions;
use Dmstr\NeuronAiBundle\Chat\ChatService;
use Dmstr\NeuronAiBundle\Chat\GuardedTool;
use Dmstr\NeuronAiBundle\Chat\ToolArgumentGuard;
use Dmstr\NeuronAiBundle\Conversation\ContextRetriever;
use Dmstr\NeuronAiBundle\Conversation\ProviderSelection;
use Dmstr\NeuronAiBundle\Conversation\StructuredAnswerValidator;
use Dmstr\NeuronAiBundle\Conversation\TurnRunner;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingsProviderLocator;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\Mcp\McpServerConnector;
use Dmstr\NeuronAiBundle\Mcp\McpToolFactory;
use Dmstr\NeuronAiBundle\Provider\ModelAvailabilityCheck;
use Dmstr\NeuronAiBundle\Provider\ProviderModelResolver;
use Dmstr\NeuronAiBundle\Retrieval\CollectionRetriever;
use Dmstr\NeuronAiBundle\Testing\CallbackTool;
use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use Dmstr\NeuronAiBundle\Testing\FakeModelLister;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronPgvectorStore\PgVectorStore;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free runs of {@see AgentRunner} under a {@see ToolArgumentGuard}
 * (the real turn runner, the bundle's FakeProviderFactory with the scripted
 * model and the scripted fake MCP transport): a refused call gets a tool
 * error and never reaches the transport, not even the handshake; an allowed
 * call does handshake and `tools/call`; the run goes on after a refusal;
 * every tool of the configuration is guarded, MCP or not; without a guard
 * the run is unchanged.
 */
final class AgentRunnerToolGuardTest extends TestCase
{
    private const string URL = 'http://tracker-mcp/mcp';

    private const array HANDSHAKE_AND_CALL = ['initialize', 'notifications/initialized', 'tools/call'];

    private const array ARGUMENTS = [
        'list_issues' => 'project',
        'get_issue' => 'project_id',
        'submit_report' => 'project',
        'local_note' => 'project',
        'search_all' => 'tenant',
    ];

    private const array ALLOWED = ['acme/app', 'acme/platform/backend', 42];

    private FakeProviderFactory $factory;

    private FakeMcpTransportFactory $fake;

    /** @var list<array<string, mixed>> arguments the non-MCP tool ran with */
    private array $localCalls = [];

    protected function setUp(): void
    {
        $this->fake = new FakeMcpTransportFactory();
        $this->factory = new FakeProviderFactory(new EmbeddingModelRegistry([
            'nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768],
        ]));
    }

    public function testForeignValueAndToolWithoutTheArgumentGetToolErrorsWithoutAnyMcpTraffic(): void
    {
        $this->factory
            ->queueToolCall('list_issues', ['project' => 'other/app'])
            ->queueAnswer('Nothing found.')
            ->queueToolCall('server_info')
            ->queueAnswer('No info.');

        $first = $this->guardedRun('Issues of other/app?');

        self::assertSame('Nothing found.', $first->answer);
        self::assertSame([['list_issues', 'Tool "list_issues" is refused: the value of the argument "project" is not allowed in this run.', true]], $this->toolResults(1));
        self::assertSame([], $this->fake->getMethods());

        $second = $this->guardedRun('Server info?');

        self::assertSame('No info.', $second->answer);
        self::assertSame([['server_info', 'Tool "server_info" is refused: it is not available in this run.', true]], $this->toolResults(3));
        self::assertSame([], $this->fake->getMethods());
    }

    public function testAllowedValueReachesTheTransport(): void
    {
        $this->fake->queueToolResult('3 open issues');
        $this->factory->queueToolCall('list_issues', ['project' => 'acme/app'])->queueAnswer('There are 3.');

        $result = $this->guardedRun('Issues of acme/app?');

        self::assertSame('There are 3.', $result->answer);
        self::assertSame(self::HANDSHAKE_AND_CALL, $this->fake->getMethods());
        self::assertSame(['name' => 'list_issues', 'arguments' => ['project' => 'acme/app']], $this->fake->getRequests(null, 'tools/call')[0]['request']['params']);
        self::assertSame([['list_issues', '3 open issues', false]], $this->toolResults(1));
    }

    /**
     * @param array<string, mixed> $inputs
     */
    #[DataProvider('values')]
    public function testValueDecidesWhetherTheCallReachesTheTransport(string $tool, array $inputs, bool $reaches): void
    {
        $this->fake->queueToolResult('done');
        $this->factory->queueToolCall($tool, $inputs)->queueAnswer('Answer.');

        $result = $this->guardedRun('Go.');

        self::assertSame('Answer.', $result->answer);
        self::assertSame($reaches ? self::HANDSHAKE_AND_CALL : [], $this->fake->getMethods());
        [[$name, $text, $error]] = $this->toolResults(1);
        self::assertSame($tool, $name);
        self::assertSame(!$reaches, $error);
        if (!$reaches) {
            self::assertStringStartsWith(\sprintf('Tool "%s" is refused', $tool), $text);
            self::assertStringNotContainsString('acme/platform/backend', $text, 'A refusal does not echo the allowed values.');
        }
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, bool}>
     */
    public static function values(): iterable
    {
        yield 'foreign id' => ['get_issue', ['project_id' => 7, 'iid' => 1], false];
        yield 'foreign path' => ['list_issues', ['project' => 'other/app'], false];
        yield 'allowed id as int' => ['get_issue', ['project_id' => 42, 'iid' => 1], true];
        yield 'allowed id as string, cast to int' => ['get_issue', ['project_id' => '42', 'iid' => 1], true];
        yield 'allowed id as int into a string property' => ['list_issues', ['project' => 42], true];
        yield 'allowed path' => ['list_issues', ['project' => 'acme/app'], true];
        yield 'allowed path in another letter case' => ['list_issues', ['project' => 'Acme/App'], false];
        yield 'url-encoded path' => ['list_issues', ['project' => 'acme%2Fapp'], false];
        yield 'nested group path' => ['list_issues', ['project' => 'acme/platform/backend'], true];
        yield 'parent of a nested group path' => ['list_issues', ['project' => 'acme/platform'], false];
        yield 'empty string' => ['list_issues', ['project' => ''], false];
        yield 'whitespace' => ['list_issues', ['project' => '   '], false];
        yield 'padded allowed path' => ['list_issues', ['project' => ' acme/app'], false];
        yield 'null' => ['list_issues', ['project' => null], false];
        yield 'missing key' => ['list_issues', ['state' => 'open'], false];
        yield 'array' => ['list_issues', ['project' => ['acme/app']], false];
        yield 'object' => ['list_issues', ['project' => ['path' => 'acme/app']], false];
        yield 'bool' => ['list_issues', ['project' => true], false];
        yield 'float' => ['get_issue', ['project_id' => 42.5, 'iid' => 1], false];
        yield 'float into a string property' => ['list_issues', ['project' => 42.5], false];
        yield 'float 42.0 into a string property, cast to "42"' => ['list_issues', ['project' => 42.0], true];
        yield 'extra keys next to an allowed value' => ['list_issues', ['project' => 'acme/app', 'tenant' => 'other', 'project_id' => 7], true];
    }

    public function testExtraKeysAreNotSent(): void
    {
        $this->fake->queueToolResult('done');
        $this->factory->queueToolCall('list_issues', ['project' => 'acme/app', 'project_id' => 7, 'tenant' => 'other'])->queueAnswer('Answer.');

        $this->guardedRun('Go.');

        self::assertSame(['project' => 'acme/app'], $this->fake->getRequests(null, 'tools/call')[0]['request']['params']['arguments']);
    }

    /**
     * A tool name that is not offered never becomes a tool of the run: the
     * provider layer of neuron-ai refuses the response that asks for it
     * (`HandleWithTools::newToolCall()`), so the turn fails with the
     * provider error before any tool runs, guarded or not.
     */
    public function testToolNotOfferedNeverReachesTheTransport(): void
    {
        $this->factory->queueToolCall('delete_project', ['project' => 'acme/app'])->queueAnswer('Cannot.');

        try {
            $this->guardedRun('Delete acme/app.');
            self::fail('Expected the provider error.');
        } catch (ProviderException $e) {
            self::assertStringContainsString('delete_project', $e->getMessage());
        }

        self::assertSame([], $this->fake->getMethods());
        self::assertSame(0, $this->fake->getRequestCount());
    }

    public function testArgumentTheSchemaDoesNotDeclareIsRefused(): void
    {
        $this->factory->queueToolCall('search_all', ['query' => 'x', 'tenant' => 'acme/app'])->queueAnswer('Answer.');

        $this->guardedRun('Search.');

        self::assertSame([['search_all', 'Tool "search_all" is refused: it does not declare the argument "tenant".', true]], $this->toolResults(1));
        self::assertSame([], $this->fake->getMethods());
    }

    public function testAllowedValueWithAnInvalidOtherArgumentStopsAtTheFramework(): void
    {
        $this->factory->queueToolCall('get_issue', ['project_id' => 42])->queueAnswer('Answer.');

        $this->guardedRun('Go.');

        [[, $text, $error]] = $this->toolResults(1);
        self::assertTrue($error);
        self::assertStringContainsString('"iid"', $text);
        self::assertSame([], $this->fake->getMethods());
    }

    public function testParallelCallsAreDecidedOneByOne(): void
    {
        $this->fake->queueToolResult('issue 1');
        $this->factory
            ->queueToolCall('get_issue', ['project_id' => 42, 'iid' => 1])
            ->queueToolCall('get_issue', ['project_id' => 7, 'iid' => 1])
            ->queueAnswer('One of two.');

        $result = $this->guardedRun('Both issues.');

        self::assertSame('One of two.', $result->answer);
        self::assertSame(self::HANDSHAKE_AND_CALL, $this->fake->getMethods());
        self::assertSame(['project_id' => 42, 'iid' => 1], $this->fake->getRequests(null, 'tools/call')[0]['request']['params']['arguments']);
        self::assertSame([
            ['get_issue', 'issue 1', false],
            ['get_issue', 'Tool "get_issue" is refused: the value of the argument "project_id" is not allowed in this run.', true],
        ], $this->toolResults(1));
    }

    public function testNonMcpToolIsGuardedToo(): void
    {
        $this->factory
            ->queueToolCall('local_note', ['project' => 'other/app'])
            ->queueToolCall('local_note', ['project' => 'acme/app'])
            ->queueAnswer('Noted.');

        $this->guardedRun('Note.');

        self::assertSame([['project' => 'acme/app']], $this->localCalls);
        self::assertSame([
            ['local_note', 'Tool "local_note" is refused: the value of the argument "project" is not allowed in this run.', true],
            ['local_note', 'noted acme/app', false],
        ], $this->toolResults(1));
    }

    public function testRunGoesOnAfterARefusedTerminalCall(): void
    {
        $this->factory
            ->queueToolCall('submit_report', ['project' => 'other/app'])
            ->queueAnswer('Cannot file it.');

        $result = $this->guardedRun('Report.', terminalTools: ['submit_report']);

        self::assertSame('Cannot file it.', $result->answer);
        self::assertNull($result->terminalTool);
        self::assertSame([], $this->fake->getMethods());
        self::assertSame([['submit_report', 'Tool "submit_report" is refused: the value of the argument "project" is not allowed in this run.', true]], $this->toolResults(1));
    }

    public function testRefusedCallsCountAgainstTheToolCallBound(): void
    {
        $this->factory
            ->queueToolCall('list_issues', ['project' => 'other/app'])
            ->queueAnswer('Forced answer.');

        $result = $this->guardedRun('Go.', maxToolCalls: 1);

        self::assertTrue($result->limitReached);
        self::assertSame('Forced answer.', $result->answer);
        self::assertSame([], $this->fake->getMethods());
    }

    public function testEveryToolOfTheConfigurationIsReplacedByName(): void
    {
        $configuration = $this->configuration();
        $this->factory->queueAnswer('Hi.');

        $run = $this->runner()->executeConfigured($configuration, self::ask('Hi'), new AgentRunOptions(toolGuard: new ToolArgumentGuard(self::ARGUMENTS, self::ALLOWED)));

        self::assertNotSame($configuration, $run->configuration);
        self::assertContainsOnlyInstancesOf(GuardedTool::class, $run->configuration->tools);
        self::assertSame(self::names($configuration->tools), self::names($run->configuration->tools));
        self::assertSame($configuration->mcpConnections, $run->configuration->mcpConnections);
        self::assertSame($configuration->terminalTools, $run->configuration->terminalTools);
        self::assertSame($configuration->version, $run->configuration->version);
        self::assertSame($configuration->getConnection(), $run->configuration->getConnection());
        self::assertEquals(array_map(static fn (ToolInterface $tool): array => $tool->getInputSchema(), $configuration->tools), array_map(static fn (ToolInterface $tool): array => $tool->getInputSchema(), $run->configuration->tools));
    }

    public function testEmptyAllowedSetRefusesEveryCall(): void
    {
        $this->factory->queueToolCall('list_issues', ['project' => ''])->queueToolCall('get_issue', ['project_id' => 0, 'iid' => 1])->queueAnswer('Answer.');

        $this->guardedRun('Go.', guard: new ToolArgumentGuard(self::ARGUMENTS, []));

        self::assertSame([true, true], array_column($this->toolResults(1), 2));
        self::assertSame([], $this->fake->getMethods());
    }

    public function testWithoutAGuardTheRunIsUnchanged(): void
    {
        $configuration = $this->configuration();
        $this->fake->queueToolResult('issues of other/app')->queueToolResult('tracker 1.0');
        $this->factory->queueToolCall('list_issues', ['project' => 'other/app'])->queueToolCall('server_info')->queueAnswer('Answer.');

        $run = $this->runner()->executeConfigured($configuration, self::ask('Go.'));

        self::assertSame($configuration, $run->configuration);
        self::assertSame('Answer.', $run->chat['answer'] ?? null);
        self::assertSame(['initialize', 'notifications/initialized', 'tools/call', 'tools/call'], $this->fake->getMethods());
        self::assertSame([['list_issues', 'issues of other/app', false], ['server_info', 'tracker 1.0', false]], $this->toolResults(1));
        self::assertNull(new AgentRunOptions()->toolGuard);
    }

    /**
     * @param list<string> $terminalTools
     */
    private function guardedRun(string $question, array $terminalTools = [], ?int $maxToolCalls = null, ?ToolArgumentGuard $guard = null): AgentChatResult
    {
        return $this->runner()->runConfigured(
            $this->configuration($terminalTools, $maxToolCalls),
            self::ask($question),
            new AgentRunOptions(toolGuard: $guard ?? new ToolArgumentGuard(self::ARGUMENTS, self::ALLOWED)),
        );
    }

    /**
     * Name, text and error flag of every tool result the model saw in the
     * $index-th provider request (0-based, counting all requests of the
     * test).
     *
     * @return list<array{string, string, bool}>
     */
    private function toolResults(int $index): array
    {
        $record = array_values($this->factory->getChatProvider()->getRecorded())[$index];
        $last = $record->messages[array_key_last($record->messages)];
        self::assertInstanceOf(ToolResultMessage::class, $last);

        return array_map(static function (ToolCall $call): array {
            $result = $call->getResult();

            return $result instanceof ToolOutput ? [$call->getName(), $result->getText(), $result->isError()] : [$call->getName(), $result, false];
        }, $last->getToolCalls());
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

    private function runner(): AgentRunner
    {
        return new AgentRunner(new \ReflectionClass(VersionTurnMapper::class)->newInstanceWithoutConstructor(), $this->chatService());
    }

    /**
     * The real chat service without collections, as in {@see AgentRunnerTest}.
     */
    private function chatService(): ChatService
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $iriConverter = $this->createStub(IriConverterInterface::class);
        $uuidResolver = $this->createStub(UuidResolver::class);
        $uuidResolver->method('findByPartialUuid')->willReturn(null);
        $registry = new EmbeddingModelRegistry(['nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768]]);

        return new ChatService(
            new TurnRunner($this->factory, new StructuredAnswerValidator()),
            $this->factory,
            new McpConnectionResolver($entityManager, $iriConverter, $uuidResolver),
            new McpToolFactory($entityManager, $this->fake),
            new ContextRetriever(new CollectionRetriever(
                new EmbeddingsProviderLocator($this->factory, $registry),
                $registry,
                new PgVectorStore(new \PDO('sqlite::memory:')),
                $entityManager,
            ), $entityManager, 8, 6000),
            new ProviderSelection(
                new ProviderModelResolver($iriConverter, $uuidResolver),
                new ModelAvailabilityCheck(new FakeModelLister()),
            ),
        );
    }

    /**
     * MCP tools of one tracker connection (one connector, so one handshake)
     * plus a non-MCP tool:
     *  - `list_issues(project: string, state?: string)`
     *  - `get_issue(project_id: integer, iid: integer)`, both required
     *  - `submit_report(project: string)`
     *  - `search_all(query: string)`: guarded by `tenant`, which it does not declare
     *  - `server_info()`: no arguments, not in the map
     *  - `local_note(project: string)`: a {@see CallbackTool}
     *
     * @param list<string> $terminalTools
     */
    private function configuration(array $terminalTools = [], ?int $maxToolCalls = null): TurnConfiguration
    {
        $connector = McpServerConnector::forTransport($this->fake->create(['url' => self::URL]));
        $string = ['type' => 'string'];
        $integer = ['type' => 'integer'];
        $tools = [
            $connector->createCatalogueTool('list_issues', 'Lists the issues of a project.', ['type' => 'object', 'properties' => ['project' => $string, 'state' => $string]]),
            $connector->createCatalogueTool('get_issue', 'Reads one issue.', ['type' => 'object', 'properties' => ['project_id' => $integer, 'iid' => $integer], 'required' => ['project_id', 'iid']]),
            $connector->createCatalogueTool('submit_report', 'Files the report.', ['type' => 'object', 'properties' => ['project' => $string], 'required' => ['project']]),
            $connector->createCatalogueTool('search_all', 'Searches everything.', ['type' => 'object', 'properties' => ['query' => $string], 'required' => ['query']]),
            $connector->createCatalogueTool('server_info', 'Describes the server.', ['type' => 'object']),
            new CallbackTool('local_note', 'Notes something.', function (?string $project = null): string {
                $this->localCalls[] = ['project' => $project];

                return 'noted '.$project;
            })->addProperty(new ToolProperty('project', PropertyType::STRING, 'Project path', true)),
        ];

        $agent = new Agent()->setName('Tracker Bot')->setSlug('tracker');

        return new TurnConfiguration(
            version: new AgentVersion($agent, 1, Agent::candidateDefaults(), 'alice'),
            provider: 'anthropic',
            model: FakeProviderFactory::ANTHROPIC_MODEL,
            instructions: 'You help with the tracker.',
            responseSchema: null,
            collections: [],
            retrievalDepth: 4,
            tools: $tools,
            mcpConnections: ['tracker'],
            terminalTools: $terminalTools,
            maxToolCalls: $maxToolCalls,
            maxUserTurns: null,
            modelOptions: null,
            language: 'en',
            connection: ProviderConnectionFixtures::anthropic(),
        );
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private static function ask(string $question): array
    {
        return [['role' => 'user', 'content' => $question]];
    }
}
