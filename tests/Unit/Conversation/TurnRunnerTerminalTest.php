<?php

// file generated with AI assistance: Claude Code - 2026-10-02 22:30:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use Dmstr\NeuronAiBundle\Conversation\ForcedClose;
use Dmstr\NeuronAiBundle\Conversation\ForcedCloseFailedException;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Conversation\StructuredAnswerValidator;
use Dmstr\NeuronAiBundle\Conversation\TerminalClose;
use Dmstr\NeuronAiBundle\Conversation\TerminalToolAgent;
use Dmstr\NeuronAiBundle\Conversation\TerminalToolNode;
use Dmstr\NeuronAiBundle\Conversation\TurnRecorder;
use Dmstr\NeuronAiBundle\Conversation\TurnRequest;
use Dmstr\NeuronAiBundle\Conversation\TurnResult;
use Dmstr\NeuronAiBundle\Conversation\TurnRunner;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Provider\ToolChoice;
use Dmstr\NeuronAiBundle\Stream\StreamEvent;
use Dmstr\NeuronAiBundle\Testing\CallbackTool;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Testing\RecordingStreamSink;
use Dmstr\NeuronAiBundle\Testing\ToolChoiceRequestRecord;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of terminal tools with the bundle fake: a
 * terminal call ends the turn after its batch without a further model
 * call, its result is the answer (MCP text items unwrapped, schema-validated
 * or decoded), the records and the `terminal_tool` stream event report it,
 * the forced close of the bound targets the first terminal tool where the
 * provider supports it, and a request without terminal tools behaves as
 * before.
 */
final class TurnRunnerTerminalTest extends TestCase
{
    private const array SCHEMA = ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'required' => ['a']];

    private FakeProviderFactory $factory;

    private Conversation $conversation;

    /** @var list<string> names of the tools that ran, in order */
    private array $ran = [];

    /** @var mixed the next result of the `submit` tool; null = `Antwort <n>` per call */
    private mixed $submitResult = null;

    private int $submitCalls = 0;

    protected function setUp(): void
    {
        $this->factory = self::newFactory();
        $this->conversation = new Conversation();
    }

    public function testTerminalNamesThatAreNotCalledChangeNothing(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a'])->queueToolCall('fetch', ['id' => 1])->queueAnswer('Antwort');
        $sink = new RecordingStreamSink();
        $withTerminal = $this->runner()->stream($this->request(terminalTools: ['submit']), $sink, messageId: 'msg-final');

        $reference = self::newFactory();
        $reference->queueToolCall('search', ['query' => 'a'])->queueToolCall('fetch', ['id' => 1])->queueAnswer('Antwort');
        $this->factory = $reference;
        $referenceSink = new RecordingStreamSink();
        $without = $this->runner()->stream($this->request(), $referenceSink, messageId: 'msg-final');

        self::assertFalse($withTerminal->isFailed());
        self::assertNull($withTerminal->terminalCall);
        self::assertNull($without->terminalCall);
        self::assertSame($this->describe($without), $this->describe($withTerminal));
        self::assertSame(self::eventsWithoutDurations($referenceSink), self::eventsWithoutDurations($sink));
        self::assertNotContains(StreamEvent::TERMINAL_TOOL, $sink->getTypes());
    }

    public function testTerminalCallInTheFirstResponseEndsTheTurn(): void
    {
        $this->factory->queueToolCall('submit', ['text' => 'x']);

        $result = $this->runTurn(terminalTools: ['submit']);

        self::assertFalse($result->isFailed());
        self::assertSame(['submit'], $this->ran);
        $this->factory->getChatProvider()->assertCallCount(1);
        self::assertSame('Antwort 1', $result->answer);
        self::assertNull($result->structured);
        self::assertNull($result->schemaErrors);
        self::assertNotNull($result->terminalCall);
        self::assertSame(['submit', 'call_1', 'Antwort 1', false], [$result->terminalCall->name, $result->terminalCall->callId, $result->terminalCall->result, $result->terminalCall->error]);
        self::assertFalse($result->limitReached);
        self::assertFalse($result->forced);

        // The tool results are committed to the history, so the transcript
        // ends with them.
        self::assertSame([UserMessage::class, ToolCallMessage::class, ToolResultMessage::class], array_map(static fn (object $m): string => $m::class, $result->transcript));

        $records = $this->records($result);
        self::assertSame([[MessageKind::TOOL_CALL, 1], [MessageKind::TEXT, 1]], $this->layout($result));
        self::assertSame('Antwort 1', $records[0]->getToolResult());
        self::assertArrayHasKey('message', $records[0]->getProviderPayload());
        self::assertSame([MessageStatus::COMPLETE, 'Antwort 1', TerminalClose::REASON], [$records[1]->getStatus(), $records[1]->getContent(), $records[1]->getCloseReason()]);
        self::assertSame(['step' => 1, 'part' => 'terminal', 'tool' => ['name' => 'submit', 'callId' => 'call_1']], $records[1]->getProviderPayload());
    }

    public function testModelTextBeforeTheTerminalCallKeepsTheAnchor(): void
    {
        $this->factory->queueToolCall('submit', text: 'Ich reiche ein.');

        $result = $this->runTurn(terminalTools: ['submit']);

        self::assertFalse($result->isFailed());
        $records = $this->records($result);
        self::assertSame([[MessageKind::TEXT, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::TEXT, 1]], $this->layout($result));
        [$text, $call, $final] = $records;
        self::assertSame('Ich reiche ein.', $text->getContent());
        self::assertSame('tool_call', $text->getProviderPayload()['message']['type']);
        self::assertNull($text->getCloseReason());
        self::assertArrayNotHasKey('message', $call->getProviderPayload());
        self::assertArrayNotHasKey('message', $final->getProviderPayload());
        self::assertSame(['Antwort 1', TerminalClose::REASON], [$final->getContent(), $final->getCloseReason()]);
    }

    public function testBatchRunsWholeAndTheFirstTerminalCallAnswers(): void
    {
        $this->factory
            ->queueToolCall('search', ['query' => 'a'])
            ->queueToolCall('submit', [], 'call_first')
            ->queueToolCall('submit', [], 'call_second');

        $result = $this->runTurn(terminalTools: ['submit']);

        self::assertFalse($result->isFailed());
        self::assertSame(['search', 'submit', 'submit'], $this->ran);
        $this->factory->getChatProvider()->assertCallCount(1);
        self::assertSame('call_first', $result->terminalCall?->callId);
        self::assertSame('Antwort 1', $result->answer);
        self::assertSame([[MessageKind::TOOL_CALL, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::TEXT, 1]], $this->layout($result));
        self::assertSame(['name' => 'submit', 'callId' => 'call_first'], $this->records($result)[3]->getProviderPayload()['tool']);
    }

    public function testTerminalCallInTheBatchCrossingTheBoundWins(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a'])->queueToolCall('submit');
        $sink = new RecordingStreamSink();

        $result = $this->runner()->stream($this->request(maxToolCalls: 1, terminalTools: ['submit']), $sink, messageId: 'msg-final');

        self::assertFalse($result->isFailed());
        self::assertFalse($result->limitReached);
        self::assertFalse($result->forced);
        self::assertNull($result->forcedMode);
        self::assertSame('submit', $result->terminalCall?->name);
        self::assertSame([[MessageKind::TOOL_CALL, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::TEXT, 1]], $this->layout($result));
        self::assertSame([StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::TERMINAL_TOOL], $sink->getTypes());
        $this->factory->getChatProvider()->assertCallCount(1);
        self::assertNull($this->factory->getChatProvider()->getToolChoice());
    }

    public function testForcedStepTargetsTheFirstTerminalToolInRequestOrder(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a']);
        $this->queueResponse('finish', 'call_finish');

        $result = $this->runTurn(maxToolCalls: 1, terminalTools: ['finish', 'submit']);

        self::assertFalse($result->isFailed());
        self::assertTrue($result->limitReached);
        self::assertTrue($result->forced);
        self::assertSame(ForcedClose::MODE_TERMINAL_TOOL, $result->forcedMode);
        self::assertSame(2, $result->limitStep);
        self::assertSame('finish', $result->terminalCall?->name);
        self::assertSame('Fertig', $result->answer);
        self::assertSame(['search', 'finish'], $this->ran);

        $records = $this->records($result);
        self::assertSame([[MessageKind::TOOL_CALL, 1], [MessageKind::LIMIT, 2], [MessageKind::TOOL_CALL, 2], [MessageKind::TEXT, 2]], $this->layout($result));
        self::assertSame(ForcedClose::limitReason(1, 1), $records[1]->getContent());
        self::assertNull($records[1]->getCloseReason());
        self::assertSame(TerminalClose::REASON, $records[3]->getCloseReason());

        $requests = $this->requests();
        self::assertCount(2, $requests);
        self::assertNull($requests[0]->toolChoice);
        self::assertTrue(ToolChoice::tool('finish')->equals($requests[1]->toolChoice));
        self::assertSame(['search', 'fetch', 'submit', 'finish', 'explode'], self::toolNames($requests[1]));
        self::assertStringNotContainsString(ForcedClose::INSTRUCTION, (string) $requests[1]->systemPrompt?->getContent());
        // The forced choice does not outlive the run.
        self::assertNull($this->factory->getChatProvider()->getToolChoice());
    }

    public function testNextRunOnTheSameProviderIsNotForcedAfterAForcedTerminalRun(): void
    {
        $runner = new TurnRunner($this->factory, new StructuredAnswerValidator(), defaultMaxToolCalls: 1);

        $this->factory->queueToolCall('search', ['query' => 'a']);
        $this->queueResponse('submit', 'call_submit');
        $forced = $runner->run($this->request(terminalTools: ['submit']));
        self::assertSame(ForcedClose::MODE_TERMINAL_TOOL, $forced->forcedMode);

        $this->factory->queueToolCall('search', ['query' => 'b'])->queueAnswer('Antwort');
        $next = $runner->run($this->request(maxToolCalls: 5));

        self::assertFalse($next->isFailed());
        $requests = $this->requests();
        self::assertCount(4, $requests);
        self::assertSame([null, null], [$requests[2]->toolChoice, $requests[3]->toolChoice]);
    }

    public function testProviderDecliningForcingChoicesGetsNoneAndAForcedText(): void
    {
        $this->factory->declineForcingChoices();
        $this->factory->queueToolCall('search', ['query' => 'a'])->queueAnswer('Antwort');

        $result = $this->runTurn(maxToolCalls: 1, terminalTools: ['submit']);

        self::assertFalse($result->isFailed());
        self::assertTrue($result->forced);
        self::assertSame(ForcedClose::MODE_TOOL_CHOICE, $result->forcedMode);
        self::assertNull($result->terminalCall);
        self::assertSame('Antwort', $result->answer);
        self::assertTrue(ToolChoice::none()->equals($this->requests()[1]->toolChoice));
        $records = $this->records($result);
        self::assertSame([[MessageKind::TOOL_CALL, 1], [MessageKind::LIMIT, 2], [MessageKind::TEXT, 2]], $this->layout($result));
        self::assertSame(ForcedClose::REASON_BOUND, $records[2]->getCloseReason());
    }

    public function testProviderDecliningEveryChoiceGetsTheFallback(): void
    {
        $this->factory->declineToolChoice();
        $this->factory->queueToolCall('search', ['query' => 'a'])->queueAnswer('Antwort');

        $result = $this->runTurn(maxToolCalls: 1, terminalTools: ['submit']);

        self::assertTrue($result->forced);
        self::assertSame(ForcedClose::MODE_FALLBACK, $result->forcedMode);
        $forced = $this->requests()[1];
        self::assertSame([], $forced->tools);
        self::assertNull($forced->toolChoice);
        self::assertStringEndsWith(ForcedClose::INSTRUCTION, (string) $forced->systemPrompt?->getContent());
        self::assertSame(ForcedClose::REASON_BOUND, $this->records($result)[2]->getCloseReason());
    }

    public function testForcedStepWithANonTerminalCallFailsBeforeTheToolRuns(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a']);
        $this->queueResponse('fetch', 'call_fetch');

        $result = $this->runTurn(maxToolCalls: 1, terminalTools: ['submit']);

        self::assertInstanceOf(ForcedCloseFailedException::class, $result->failure);
        self::assertSame(['search'], $this->ran);
        self::assertSame(ForcedClose::MODE_TERMINAL_TOOL, $result->forcedMode);
        self::assertFalse($result->forced);
        self::assertNull($result->terminalCall);
        self::assertSame(
            [[MessageKind::TOOL_CALL, MessageStatus::COMPLETE, 1], [MessageKind::LIMIT, MessageStatus::COMPLETE, 2], [MessageKind::TEXT, MessageStatus::ERROR, 3]],
            array_map(static fn (Message $r): array => [$r->getKind(), $r->getStatus(), $r->getStep()], $this->records($result)),
        );
        self::assertNull($this->factory->getChatProvider()->getToolChoice());
    }

    public function testFailedTerminalCallContinuesTheLoop(): void
    {
        $this->factory->queueToolCall('explode')->queueAnswer('Ging nicht, sorry.');

        $result = $this->runTurn(terminalTools: ['explode']);

        self::assertFalse($result->isFailed());
        self::assertNull($result->terminalCall);
        self::assertSame('Ging nicht, sorry.', $result->answer);
        $this->factory->getChatProvider()->assertCallCount(2);
        $records = $this->records($result);
        self::assertSame([[MessageKind::TOOL_CALL, 1], [MessageKind::TEXT, 2]], $this->layout($result));
        self::assertTrue($records[0]->isToolError());
        self::assertNull($records[1]->getCloseReason());
    }

    public function testFailedTerminalCallCountsTowardsTheBound(): void
    {
        $this->factory->queueToolCall('explode')->queueAnswer('Antwort');

        $result = $this->runTurn(maxToolCalls: 1, terminalTools: ['explode']);

        self::assertTrue($result->forced);
        self::assertSame(ForcedClose::limitReason(1, 1), $result->limitReason);
    }

    public function testFailedTerminalCallInTheForcedStepFailsTheTurn(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a']);
        $this->queueResponse('explode', 'call_explode');

        $result = $this->runTurn(maxToolCalls: 1, terminalTools: ['explode']);

        self::assertInstanceOf(ForcedCloseFailedException::class, $result->failure);
        self::assertSame(['search', 'explode'], $this->ran);
        self::assertNull($result->terminalCall);
        self::assertFalse($result->forced);
        $this->factory->getChatProvider()->assertCallCount(2);
        self::assertSame(
            [[MessageKind::TOOL_CALL, MessageStatus::COMPLETE, 1], [MessageKind::LIMIT, MessageStatus::COMPLETE, 2], [MessageKind::TOOL_CALL, MessageStatus::COMPLETE, 2], [MessageKind::TEXT, MessageStatus::ERROR, 3]],
            array_map(static fn (Message $r): array => [$r->getKind(), $r->getStatus(), $r->getStep()], $this->records($result)),
        );
        self::assertNull($this->factory->getChatProvider()->getToolChoice());
    }

    /**
     * @param array<string, mixed>|null $schema
     */
    #[DataProvider('answers')]
    public function testAnswerRule(mixed $toolResult, ?array $schema, string $answer, mixed $structured, ?string $schemaErrorKey): void
    {
        $this->submitResult = $toolResult;
        $this->factory->queueToolCall('submit');

        $result = $this->runTurn(terminalTools: ['submit'], responseSchema: $schema);

        self::assertFalse($result->isFailed());
        self::assertSame($answer, $result->answer);
        self::assertSame($structured, $result->structured);

        $records = $this->records($result);
        $final = $records[array_key_last($records)];
        self::assertSame(MessageStatus::COMPLETE, $final->getStatus());
        self::assertSame(null !== $schema ? MessageKind::SCHEMA : MessageKind::TEXT, $final->getKind());
        self::assertSame($answer, $final->getContent());
        self::assertSame($structured, $final->getStructured());
        // The tool-call record keeps the raw result.
        self::assertSame(\is_array($toolResult) ? json_encode($toolResult) : $toolResult, $records[0]->getToolResult());

        if (null === $schemaErrorKey) {
            self::assertNull($result->schemaErrors);
            self::assertNull($final->getError());
        } else {
            self::assertArrayHasKey($schemaErrorKey, (array) $result->schemaErrors);
            self::assertStringStartsWith('The answer does not match the response schema: ', (string) $final->getError());
            self::assertArrayHasKey($schemaErrorKey, $final->getProviderPayload()['schema_errors']);
        }
        if (null !== $schema) {
            self::assertSame($schema, $final->getResponseSchema());
            self::assertArrayHasKey('schema_errors', $final->getProviderPayload());
        } else {
            self::assertArrayNotHasKey('schema_errors', $final->getProviderPayload());
        }
    }

    /**
     * @return iterable<string, array{mixed, ?array<string, mixed>, string, mixed, ?string}>
     */
    public static function answers(): iterable
    {
        yield 'MCP content with schema' => [[['type' => 'text', 'text' => '{"a":1}']], self::SCHEMA, '{"a":1}', ['a' => 1], null];
        yield 'MCP content, two text items joined, other types ignored' => [[['type' => 'text', 'text' => 'eins'], ['type' => 'image', 'data' => 'AAA', 'mimeType' => 'image/png'], ['type' => 'text', 'text' => 'zwei']], null, "eins\n\nzwei", null, null];
        yield 'MCP content without text item' => [[['type' => 'image', 'data' => 'AAA', 'mimeType' => 'image/png']], null, '', null, null];
        yield 'MCP text with schema' => [[['type' => 'text', 'text' => 'Kein JSON']], self::SCHEMA, 'Kein JSON', null, 'invalid_json'];
        yield 'invalid JSON with schema' => ['{"a":', self::SCHEMA, '{"a":', null, 'invalid_json'];
        yield 'schema violation' => ['{"a":"x"}', self::SCHEMA, '{"a":"x"}', null, '/a'];
        yield 'raw string without schema' => ['Fertig.', null, 'Fertig.', null, null];
        yield 'JSON object without schema' => ['{"a":1,"b":[2]}', null, '{"a":1,"b":[2]}', ['a' => 1, 'b' => [2]], null];
        yield 'JSON scalar without schema' => ['42', null, '42', null, null];
        yield 'MCP JSON list without schema' => [[['type' => 'text', 'text' => '[1,2]']], null, '[1,2]', [1, 2], null];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rawResults(): iterable
    {
        yield 'plain text' => ['Hallo', 'Hallo'];
        yield 'not JSON' => ['{oops', '{oops'];
        yield 'JSON object' => ['{"type":"text","text":"x"}', '{"type":"text","text":"x"}'];
        yield 'empty list' => ['[]', '[]'];
        yield 'list of scalars' => ['[1,2]', '[1,2]'];
        yield 'list with an item without type' => ['[{"type":"text","text":"a"},{"text":"b"}]', '[{"type":"text","text":"a"},{"text":"b"}]'];
        yield 'text item without text' => ['[{"type":"text"}]', ''];
        yield 'unicode' => ['[{"type":"text","text":"Grüße"}]', 'Grüße'];
    }

    #[DataProvider('rawResults')]
    public function testAnswerTextOfRawResults(string $raw, string $expected): void
    {
        self::assertSame($expected, TerminalClose::answerText($raw));
    }

    public function testStreamSendsTheTerminalToolEventLast(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a'], text: 'Ich suche.')->queueToolCall('submit', [], 'call_submit');
        $sink = new RecordingStreamSink();

        $result = $this->runner()->stream($this->request(terminalTools: ['submit']), $sink, messageId: 'msg-final');

        self::assertFalse($result->isFailed());
        self::assertSame(
            [StreamEvent::TOKEN, StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::TERMINAL_TOOL],
            self::collapseTokens($sink->getTypes()),
        );
        $events = $sink->getEvents();
        self::assertSame([
            'messageId' => 'msg-final',
            'step' => 1,
            'name' => 'submit',
            'callId' => 'call_submit',
            'kind' => MessageKind::TEXT,
            'answer' => 'Antwort 1',
            'structured' => null,
            'schemaErrors' => null,
        ], $events[array_key_last($events)]->payload);
        self::assertFalse($events[array_key_last($events)]->isTerminal());
        $this->factory->getChatProvider()->assertCallCount(1);

        // The placeholder becomes the final record at the terminal step.
        $placeholder = TurnRecorder::placeholderRecord($this->conversation, 'anthropic', FakeProviderFactory::ANTHROPIC_MODEL);
        $records = TurnRecorder::assistantRecords($this->conversation, $result, $placeholder);
        self::assertSame([[MessageKind::TEXT, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::TEXT, 1]], array_map(static fn (Message $r): array => [$r->getKind(), $r->getStep()], $records));
        self::assertSame($placeholder, $records[3]);
        self::assertSame([MessageStatus::COMPLETE, 'Antwort 1', TerminalClose::REASON, 1], [$placeholder->getStatus(), $placeholder->getContent(), $placeholder->getCloseReason(), $placeholder->getStep()]);
        self::assertSame('terminal', $placeholder->getProviderPayload()['part']);
    }

    public function testStreamOfASchemaTurnSendsTheStructuredAnswer(): void
    {
        $this->submitResult = [['type' => 'text', 'text' => '{"a":"x"}']];
        $this->factory->queueToolCall('submit', [], 'call_submit');
        $sink = new RecordingStreamSink();

        $result = $this->runner()->stream($this->request(terminalTools: ['submit'], responseSchema: self::SCHEMA), $sink, messageId: 'msg-final');

        self::assertFalse($result->isFailed());
        $payload = $sink->getEvents()[array_key_last($sink->getEvents())]->payload;
        self::assertSame(StreamEvent::TERMINAL_TOOL, $sink->getTypes()[array_key_last($sink->getTypes())]);
        self::assertSame(['messageId', 'step', 'name', 'callId', 'kind', 'answer', 'structured', 'schemaErrors'], array_keys($payload));
        self::assertSame([MessageKind::SCHEMA, '{"a":"x"}', null], [$payload['kind'], $payload['answer'], $payload['structured']]);
        self::assertArrayHasKey('/a', $payload['schemaErrors']);
        self::assertSame($result->schemaErrors, $payload['schemaErrors']);
    }

    public function testStreamOfAForcedTerminalStep(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a']);
        $this->queueResponse('submit', 'call_submit');
        $sink = new RecordingStreamSink();

        $result = $this->runner()->stream($this->request(maxToolCalls: 1, terminalTools: ['submit']), $sink, messageId: 'msg-final');

        self::assertFalse($result->isFailed());
        self::assertTrue($result->forced);
        self::assertSame(
            [StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::LIMIT, StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::TERMINAL_TOOL],
            $sink->getTypes(),
        );
        $events = $sink->getEvents();
        self::assertSame(ForcedClose::MODE_TERMINAL_TOOL, $events[2]->payload['mode']);
        self::assertSame(['msg-final', 2, 'submit', 'call_submit'], [$events[5]->payload['messageId'], $events[5]->payload['step'], $events[5]->payload['name'], $events[5]->payload['callId']]);

        $requests = $this->requests();
        self::assertSame(['stream', 'stream'], array_map(static fn (ToolChoiceRequestRecord $r): string => $r->method, $requests));
        self::assertTrue(ToolChoice::tool('submit')->equals($requests[1]->toolChoice));
        self::assertNull($this->factory->getChatProvider()->getToolChoice());

        $placeholder = TurnRecorder::placeholderRecord($this->conversation, 'anthropic', FakeProviderFactory::ANTHROPIC_MODEL);
        $records = TurnRecorder::assistantRecords($this->conversation, $result, $placeholder);
        self::assertSame([[MessageKind::TOOL_CALL, 1], [MessageKind::LIMIT, 2], [MessageKind::TOOL_CALL, 2], [MessageKind::TEXT, 2]], array_map(static fn (Message $r): array => [$r->getKind(), $r->getStep()], $records));
        self::assertSame(TerminalClose::REASON, $placeholder->getCloseReason());
    }

    public function testCancellationAtTheTerminalBatchKeepsTheAbortedPath(): void
    {
        $this->factory->queueToolCall('submit');
        $cancel = false;
        $sink = new RecordingStreamSink();
        $sink->onSend(static function (StreamEvent $event) use (&$cancel): void {
            $cancel = $cancel || StreamEvent::TOOL_CALL === $event->type;
        });

        $result = $this->runner()->stream($this->request(terminalTools: ['submit']), $sink, static function () use (&$cancel): bool {
            return $cancel;
        }, 'msg-final');

        self::assertTrue($result->isAborted());
        self::assertNull($result->terminalCall);
        self::assertNull($result->answer);
        self::assertNotContains(StreamEvent::TERMINAL_TOOL, $sink->getTypes());
        $records = TurnRecorder::assistantRecords($this->conversation, $result);
        $final = $records[array_key_last($records)];
        self::assertSame(MessageStatus::ABORTED, $final->getStatus());
        self::assertNull($final->getCloseReason());
    }

    public function testHistoryWithAReplayedTerminalTurnIsAccepted(): void
    {
        $call = new ToolCallMessage(null, [ToolCall::make(name: 'submit', callId: 'call_old')]);
        $results = new ToolResultMessage([ToolCall::make(name: 'submit', callId: 'call_old')->setResult('Alt')]);
        $this->factory->queueAnswer('Neu');

        $result = $this->runner()->run(new TurnRequest(
            provider: 'anthropic',
            connection: ProviderConnectionFixtures::anthropic(),
            model: FakeProviderFactory::ANTHROPIC_MODEL,
            content: 'Weiter',
            history: [new UserMessage('Frage'), $call, $results],
        ));

        self::assertFalse($result->isFailed());
        self::assertSame('Neu', $result->answer);
        $sent = $this->requests()[0]->messages;
        self::assertSame([UserMessage::class, ToolCallMessage::class, ToolResultMessage::class, UserMessage::class], array_map(static fn (object $m): string => $m::class, $sent));
    }

    public function testHistoryWithBrokenAlternationIsStillRejected(): void
    {
        $this->factory->queueAnswer('Neu');

        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('expected role assistant');

        $this->runner()->run(new TurnRequest(
            provider: 'anthropic',
            connection: ProviderConnectionFixtures::anthropic(),
            model: FakeProviderFactory::ANTHROPIC_MODEL,
            content: 'Weiter',
            history: [new UserMessage('Frage'), new UserMessage('Noch eine')],
        ));
    }

    public function testTerminalAgentAndNodeCarryTheNames(): void
    {
        $agent = TerminalToolAgent::make()->setTerminalTools(['submit', 'finish']);
        self::assertSame(['submit', 'finish'], $agent->getTerminalTools());

        $node = new TerminalToolNode(3, null, ['submit']);
        self::assertSame(['submit'], $node->getTerminalTools());
    }

    public function testToolNamedLikeTheTerminalToolTwiceFailsBeforeTheProviderCall(): void
    {
        // neuron-ai 4.x refuses repeated tool names when the run starts
        // (neuron 4.x guide 11); 3.x sent both and ran the first.
        $this->factory->queueAnswer('Nie gesendet');
        $request = $this->request(terminalTools: ['submit']);
        $request = new TurnRequest(
            provider: $request->provider,
            connection: $request->connection,
            model: $request->model,
            content: $request->content,
            tools: [...$request->tools, new CallbackTool('submit', 'Second submit', static fn (): string => 'Zweites')],
            terminalTools: ['submit'],
        );

        $result = $this->runner()->run($request);

        self::assertTrue($result->isFailed());
        self::assertInstanceOf(ToolException::class, $result->failure);
        self::assertSame('Tool names must be unique: "submit" is registered twice.', $result->failure->getMessage());
        self::assertSame([], $this->requests());
        self::assertSame([], $this->ran);
    }

    public function testEveryRunOfAThreadBuildsItsOwnAgent(): void
    {
        // The conversation UUID is the thread of the agent; a bound agent is
        // never rebound, so two turns of one conversation run on two agents.
        $this->factory->queueAnswer('Eins')->queueAnswer('Zwei');
        $runner = $this->runner();
        $thread = '0199e3a5-7c1d-7d6a-9f3e-2b5c8a1d4e6f';
        $request = static fn (string $content): TurnRequest => new TurnRequest(
            provider: 'anthropic',
            connection: ProviderConnectionFixtures::anthropic(),
            model: FakeProviderFactory::ANTHROPIC_MODEL,
            content: $content,
            threadId: $thread,
        );

        $first = $runner->run($request('Frage 1'));
        $second = $runner->stream($request('Frage 2'), new RecordingStreamSink());

        self::assertSame(['Eins', 'Zwei'], [$first->answer, $second->answer]);
        self::assertSame($thread, $request('x')->withContext([])->threadId);
    }

    public function testWithContextKeepsTheTerminalTools(): void
    {
        self::assertSame(['submit', 'finish'], $this->request(terminalTools: ['submit', 'finish'])->withContext([])->terminalTools);
        self::assertSame([], $this->request()->withContext([])->terminalTools);
        self::assertSame([], new TurnRequest('anthropic', FakeProviderFactory::ANTHROPIC_MODEL, 'Frage')->terminalTools);
    }

    private static function newFactory(): FakeProviderFactory
    {
        return new FakeProviderFactory(new EmbeddingModelRegistry([
            'nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768],
        ]));
    }

    /**
     * @param list<string>              $terminalTools
     * @param array<string, mixed>|null $responseSchema
     */
    private function runTurn(?int $maxToolCalls = null, array $terminalTools = [], ?array $responseSchema = null): TurnResult
    {
        return $this->runner()->run($this->request($maxToolCalls, $terminalTools, $responseSchema));
    }

    private function runner(): TurnRunner
    {
        return new TurnRunner($this->factory, new StructuredAnswerValidator());
    }

    /**
     * Tools: `search`, `fetch` (plain), `submit` (`Antwort <n>` per call or
     * the prepared result), `finish` (`Fertig`), `explode` (throws).
     *
     * @param list<string>              $terminalTools
     * @param array<string, mixed>|null $responseSchema
     */
    private function request(?int $maxToolCalls = null, array $terminalTools = [], ?array $responseSchema = null): TurnRequest
    {
        return new TurnRequest(
            provider: 'anthropic',
            connection: ProviderConnectionFixtures::anthropic(),
            model: FakeProviderFactory::ANTHROPIC_MODEL,
            content: 'Frage',
            tools: array_map($this->tool(...), ['search', 'fetch', 'submit', 'finish', 'explode']),
            responseSchema: $responseSchema,
            maxToolCalls: $maxToolCalls,
            terminalTools: $terminalTools,
        );
    }

    /**
     * The test tool $name (see {@see request()}); it records its run.
     */
    private function tool(string $name): CallbackTool
    {
        return new CallbackTool($name, 'Test tool '.$name, function () use ($name): mixed {
            $this->ran[] = $name;

            return match ($name) {
                'submit' => $this->submitResult ?? 'Antwort '.++$this->submitCalls,
                'finish' => 'Fertig',
                'explode' => throw new \RuntimeException('Kaputt'),
                default => 'Ergebnis '.$name,
            };
        });
    }

    /**
     * Queues a provider response with one call of $name as a response of
     * its own (consecutive queueToolCall() calls form one response).
     */
    private function queueResponse(string $name, string $callId): void
    {
        $this->factory->getChatProvider()->addResponses(new ToolCallMessage(null, [ToolCall::make(name: $name, callId: $callId)]));
    }

    /**
     * @return list<ToolChoiceRequestRecord>
     */
    private function requests(): array
    {
        $records = $this->factory->getChatProvider()->getRecorded();
        self::assertContainsOnlyInstancesOf(ToolChoiceRequestRecord::class, $records);

        return array_values($records);
    }

    /**
     * @return list<string>
     */
    private static function toolNames(ToolChoiceRequestRecord $request): array
    {
        return array_values(array_map(static fn ($tool): string => $tool->getName(), $request->tools));
    }

    /**
     * @param list<string> $types
     *
     * @return list<string> consecutive token events as one
     */
    private static function collapseTokens(array $types): array
    {
        $collapsed = [];
        foreach ($types as $type) {
            if (StreamEvent::TOKEN === $type && StreamEvent::TOKEN === ($collapsed[array_key_last($collapsed) ?? -1] ?? null)) {
                continue;
            }
            $collapsed[] = $type;
        }

        return $collapsed;
    }

    /**
     * @return list<array{string, array<string, mixed>}>
     */
    private static function eventsWithoutDurations(RecordingStreamSink $sink): array
    {
        return array_map(static function (StreamEvent $event): array {
            $payload = $event->payload;
            unset($payload['durationMs']);

            return [$event->type, $payload];
        }, $sink->getEvents());
    }

    /**
     * @return non-empty-list<Message>
     */
    private function records(TurnResult $result): array
    {
        return TurnRecorder::assistantRecords($this->conversation, $result);
    }

    /**
     * @return list<array{string, int}>
     */
    private function layout(TurnResult $result): array
    {
        return array_map(static fn (Message $r): array => [$r->getKind(), $r->getStep()], $this->records($result));
    }

    /**
     * The records without what differs per run anyway (durations, the
     * random Neuron message id).
     *
     * @return list<list<mixed>>
     */
    private function describe(TurnResult $result): array
    {
        return array_map(static function (Message $r): array {
            $payload = $r->getProviderPayload();
            unset($payload['message']['__id']);

            return [
                $r->getKind(), $r->getStatus(), $r->getStep(), $r->getContent(), $r->getToolName(), $r->getToolCallId(),
                $r->getToolInput(), $r->getToolResult(), $payload, $r->getCloseReason(), $r->getUsage(), $r->getStructured(), $r->getError(),
            ];
        }, $this->records($result));
    }
}

// - revised 2026-10-14
