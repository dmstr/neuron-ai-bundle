<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:35:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use Dmstr\NeuronAiBundle\Agent\Rule\RuleOutcome;
use Dmstr\NeuronAiBundle\Agent\Template\TemplateSet;
use Dmstr\NeuronAiBundle\Conversation\ContextChunk;
use Dmstr\NeuronAiBundle\Conversation\ConversationTurnService;
use Dmstr\NeuronAiBundle\Conversation\ForcedClose;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Conversation\RuleClose;
use Dmstr\NeuronAiBundle\Conversation\StructuredAnswerValidator;
use Dmstr\NeuronAiBundle\Conversation\TerminalClose;
use Dmstr\NeuronAiBundle\Conversation\TurnCancelledException;
use Dmstr\NeuronAiBundle\Conversation\TurnDeadlineExceededException;
use Dmstr\NeuronAiBundle\Conversation\TurnDeadlineObserver;
use Dmstr\NeuronAiBundle\Conversation\TurnLock;
use Dmstr\NeuronAiBundle\Conversation\TurnRecorder;
use Dmstr\NeuronAiBundle\Conversation\TurnRequest;
use Dmstr\NeuronAiBundle\Conversation\TurnResult;
use Dmstr\NeuronAiBundle\Conversation\TurnRunner;
use Dmstr\NeuronAiBundle\Conversation\TurnTexts;
use Dmstr\NeuronAiBundle\Conversation\UserTurnLimit;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Testing\CallbackTool;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Kernel-free checks of the records a turn produces (TurnRunner plus
 * TurnRecorder with the bundle fake), the deadline, the lock key and the
 * title rule. The persisted path runs in TurnTest (CI); the tool-call
 * bound and the `limit` record in TurnRunnerBoundTest; terminal tools in
 * TurnRunnerTerminalTest (the record shape of a terminal turn here).
 */
final class TurnRecorderTest extends TestCase
{
    private FakeProviderFactory $factory;

    private Conversation $conversation;

    protected function setUp(): void
    {
        $this->factory = new FakeProviderFactory(new EmbeddingModelRegistry([
            'nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768],
        ]));
        $this->conversation = new Conversation();
    }

    public function testStepsGroupReasoningTextAndParallelToolCalls(): void
    {
        $this->factory
            ->queueToolCall('search', ['query' => 'Wartung'], reasoning: 'Erst suchen.', signature: 'sig-1')
            ->queueToolCall('broken', ['id' => 7]);
        $this->factory->getChatProvider()->addResponses(new AssistantMessage('Alle 500 Stunden.')->setUsage(new Usage(50, 10)));

        $records = $this->records($this->runTurn());

        self::assertSame(
            [[MessageKind::REASONING, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::TEXT, 2]],
            array_map(static fn (Message $r): array => [$r->getKind(), $r->getStep()], $records),
        );
        foreach ($records as $record) {
            self::assertSame(Message::SPEAKER_ASSISTANT, $record->getRole());
            self::assertSame(MessageStatus::COMPLETE, $record->getStatus());
            self::assertSame('anthropic', $record->getProvider());
            self::assertSame(FakeProviderFactory::ANTHROPIC_MODEL, $record->getModel());
            self::assertIsArray($record->getProviderPayload());
        }

        [$reasoning, $search, $broken, $answer] = $records;
        self::assertSame('Erst suchen.', $reasoning->getContent());
        self::assertSame(['step' => 1, 'part' => 'reasoning', 'signature' => 'sig-1'], $reasoning->getProviderPayload());

        self::assertSame('search', $search->getToolName());
        self::assertSame('call_1', $search->getToolCallId());
        self::assertSame(['query' => 'Wartung'], $search->getToolInput());
        self::assertSame('Treffer', $search->getToolResult());
        self::assertFalse($search->isToolError());
        self::assertGreaterThanOrEqual(0, $search->getDurationMs());
        // The first tool call is the anchor of step 1 (no text in that response).
        self::assertSame('tool_call', $search->getProviderPayload()['message']['type']);

        self::assertSame('call_2', $broken->getToolCallId());
        self::assertTrue($broken->isToolError());
        self::assertSame('Index offline', $broken->getToolResult());
        self::assertArrayNotHasKey('message', $broken->getProviderPayload());

        self::assertSame('Alle 500 Stunden.', $answer->getContent());
        self::assertSame(['input_tokens' => 50, 'output_tokens' => 10, 'cached_input_tokens' => 0, 'reasoning_tokens' => 0], $answer->getUsage());
        self::assertSame([], $answer->getSources());
        $this->factory->getChatProvider()->assertCallCount(2);
    }

    public function testReasoningAndAnswerShareOneStep(): void
    {
        $this->factory->queueReasoning('Kurz nachdenken.', 'Blau.', 'sig-x');

        $records = $this->records($this->runTurn());

        self::assertSame([[MessageKind::REASONING, 1], [MessageKind::TEXT, 1]], array_map(static fn (Message $r): array => [$r->getKind(), $r->getStep()], $records));
        self::assertSame('Blau.', $records[1]->getContent());
    }

    public function testSchemaTurnStoresTheParsedAnswer(): void
    {
        $this->factory->queueAnswer('{"color": "blue"}');
        $schema = ['type' => 'object', 'properties' => ['color' => ['type' => 'string']], 'required' => ['color']];

        $final = $this->final($this->runTurn(responseSchema: $schema));

        self::assertSame(MessageKind::SCHEMA, $final->getKind());
        self::assertSame(MessageStatus::COMPLETE, $final->getStatus());
        self::assertSame(['color' => 'blue'], $final->getStructured());
        self::assertSame($schema, $final->getResponseSchema());
        self::assertNull($final->getError());
    }

    public function testSchemaViolationIsReportedOnTheRecord(): void
    {
        $this->factory->queueAnswer('{"color": 7}');

        $final = $this->final($this->runTurn(responseSchema: ['type' => 'object', 'properties' => ['color' => ['type' => 'string']]]));

        self::assertSame(MessageKind::SCHEMA, $final->getKind());
        self::assertNull($final->getStructured());
        self::assertStringStartsWith('The answer does not match the response schema', (string) $final->getError());
        self::assertArrayHasKey('/color', $final->getProviderPayload()['schema_errors']);
    }

    public function testProviderFailureAfterAToolCallKeepsTheCompletedStep(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'Wartung'])->queueFailure('Overloaded');
        $context = [new ContextChunk('Spindel: 500 Stunden.', 0.9, sourceUrl: 'https://example.org/1')];

        $records = $this->records($this->runTurn(context: $context));

        self::assertSame([[MessageKind::TOOL_CALL, MessageStatus::COMPLETE, 1], [MessageKind::TEXT, MessageStatus::ERROR, 2]], array_map(static fn (Message $r): array => [$r->getKind(), $r->getStatus(), $r->getStep()], $records));
        $error = $records[1];
        self::assertSame('Overloaded', $error->getError());
        self::assertNull($error->getContent());
        self::assertSame([$context[0]->toArray()], $error->getSources());
    }

    public function testAssistantRecordsCarryTheCatalogueEntryOfTheRequest(): void
    {
        $connection = ProviderConnectionFixtures::anthropic();
        $entry = new ProviderModel($connection, FakeProviderFactory::ANTHROPIC_MODEL);
        $this->factory->queueToolCall('search', ['query' => 'Wartung'])->queueAnswer('Alle 500 Stunden.');
        $request = new TurnRequest(
            provider: 'anthropic',
            model: FakeProviderFactory::ANTHROPIC_MODEL,
            content: 'Frage',
            tools: [new CallbackTool('search', 'Full-text search', static fn (): string => 'Treffer')],
            connection: $connection,
            providerModel: $entry,
        );
        self::assertSame($entry, $request->withContext([])->providerModel, 'withContext() keeps the entry');

        $result = new TurnRunner($this->factory, new StructuredAnswerValidator())->run($request);
        $placeholder = TurnRecorder::placeholderRecord($this->conversation, 'anthropic', FakeProviderFactory::ANTHROPIC_MODEL, connection: $connection, providerModel: $entry);
        self::assertSame($entry, $placeholder->getProviderModel());

        foreach ([$this->records($result), TurnRecorder::assistantRecords($this->conversation, $result, $placeholder)] as $records) {
            foreach ($records as $record) {
                self::assertSame($entry, $record->getProviderModel());
                self::assertSame($entry->iri(), $record->getModelReference());
                self::assertSame(FakeProviderFactory::ANTHROPIC_MODEL, $record->getModel(), 'the name stays next to the entry');
                self::assertSame($connection->getId()->toRfc4122(), $record->getConnectionId());
            }
        }

        $user = TurnRecorder::userRecord($this->conversation, 'Frage');
        self::assertNull($user->getProviderModel());
        self::assertNull($user->getModelReference());
    }

    public function testRecordsWithoutAnEntryHaveNoModelReference(): void
    {
        $this->factory->queueAnswer('Alle 500 Stunden.');

        foreach ($this->records($this->runTurn()) as $record) {
            self::assertNull($record->getProviderModel());
            self::assertNull($record->getModelReference());
            self::assertSame(FakeProviderFactory::ANTHROPIC_MODEL, $record->getModel());
        }
    }

    public function testPassedDeadlineEndsTheTurnWithAnErrorRecord(): void
    {
        $this->factory->queueAnswer('zu spät');

        $result = new TurnRunner($this->factory, new StructuredAnswerValidator(), deadlineSeconds: -1)
            ->run(new TurnRequest('anthropic', FakeProviderFactory::ANTHROPIC_MODEL, 'Frage', connection: ProviderConnectionFixtures::anthropic()));

        self::assertInstanceOf(TurnDeadlineExceededException::class, $result->failure);
        $final = $this->final($result);
        self::assertSame(MessageStatus::ERROR, $final->getStatus());
        self::assertStringContainsString('deadline', (string) $final->getError());
        $this->factory->getChatProvider()->assertNothingSent();
    }

    public function testDeadlineObserverChecksOnlyBeforeProviderAndToolCalls(): void
    {
        $now = 1000.0;
        $observer = new TurnDeadlineObserver(10, static function () use (&$now): float {
            return $now;
        });

        $now = 1011.0;
        $observer->onEvent('inference-stop', $this);
        $observer->onEvent('message-saved', $this);

        $this->expectException(TurnDeadlineExceededException::class);
        $observer->onEvent('tool-calling', $this);
    }

    public function testPlaceholderBecomesTheFinalRecordInPlace(): void
    {
        $this->factory
            ->queueToolCall('search', ['query' => 'Wartung'], reasoning: 'Erst suchen.', signature: 'sig-1');
        $this->factory->getChatProvider()->addResponses(new AssistantMessage('Alle 500 Stunden.')->setUsage(new Usage(50, 10)));
        $result = $this->runTurn(context: [new ContextChunk('Spindel: 500 Stunden.', 0.9)]);

        $placeholder = TurnRecorder::placeholderRecord($this->conversation, 'anthropic', FakeProviderFactory::ANTHROPIC_MODEL);
        self::assertSame([Message::SPEAKER_ASSISTANT, MessageKind::TEXT, MessageStatus::STREAMING], [$placeholder->getRole(), $placeholder->getKind(), $placeholder->getStatus()]);
        $id = $placeholder->getId();

        $withPlaceholder = TurnRecorder::assistantRecords($this->conversation, $result, $placeholder);
        $without = $this->records($result);

        // Same layout as without a placeholder; the placeholder is the last
        // record, filled in place, with its id kept.
        self::assertSame($this->describe($without), $this->describe($withPlaceholder));
        self::assertSame($placeholder, $withPlaceholder[array_key_last($withPlaceholder)]);
        self::assertSame($id, $placeholder->getId());
        self::assertSame(MessageStatus::COMPLETE, $placeholder->getStatus());
        self::assertSame('Alle 500 Stunden.', $placeholder->getContent());
        self::assertSame(2, $placeholder->getStep());
        self::assertSame(['input_tokens' => 50, 'output_tokens' => 10, 'cached_input_tokens' => 0, 'reasoning_tokens' => 0], $placeholder->getUsage());
        self::assertCount(1, (array) $placeholder->getSources());
        self::assertSame($without[2]->getProviderPayload(), $placeholder->getProviderPayload());
        self::assertSame($without[2]->getDurationMs(), $placeholder->getDurationMs());
        foreach (\array_slice($withPlaceholder, 0, -1) as $record) {
            self::assertNotSame($placeholder, $record);
        }
    }

    public function testPlaceholderOfAForcedAnswerCarriesTheCloseReason(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'Wartung'])->queueAnswer('Alle 500 Stunden.');
        $result = new TurnRunner($this->factory, new StructuredAnswerValidator())->run(new TurnRequest(
            provider: 'anthropic',
            connection: ProviderConnectionFixtures::anthropic(),
            model: FakeProviderFactory::ANTHROPIC_MODEL,
            content: 'Frage',
            tools: [new CallbackTool('search', 'Full-text search', static fn (): string => 'Treffer')],
            maxToolCalls: 1,
        ));
        $placeholder = TurnRecorder::placeholderRecord($this->conversation, 'anthropic', FakeProviderFactory::ANTHROPIC_MODEL);

        $records = TurnRecorder::assistantRecords($this->conversation, $result, $placeholder);

        self::assertSame([[MessageKind::TOOL_CALL, 1], [MessageKind::LIMIT, 2], [MessageKind::TEXT, 2]], array_map(static fn (Message $r): array => [$r->getKind(), $r->getStep()], $records));
        self::assertSame($placeholder, $records[2]);
        self::assertSame([MessageStatus::COMPLETE, ForcedClose::REASON_BOUND], [$placeholder->getStatus(), $placeholder->getCloseReason()]);
        self::assertNull($records[1]->getCloseReason());
    }

    /**
     * The limit reasons go through the recorder's texts; the
     * default constructor keeps ForcedClose's English reasons.
     */
    public function testLimitReasonsAreRenderedThroughTheTexts(): void
    {
        $default = new TurnRecorder();
        $default->limitReached(3, 2, ForcedClose::MODE_TOOL_CHOICE);
        $default->turnLimitReached(new UserTurnLimit(4, 4), ForcedClose::MODE_FALLBACK);

        self::assertSame(ForcedClose::limitReason(3, 2), $default->getLimitReason());
        self::assertSame(ForcedClose::turnLimitReason(4), $default->getTurnLimitReason());

        $templated = new TurnRecorder(new TurnTexts(new TemplateSet([
            'limitReason' => ['de' => '{{toolCalls}}/{{maxToolCalls}} Aufrufe'],
            'turnLimitReason' => ['de' => 'Runde {{turn}} von {{maxUserTurns}}'],
        ], 'de'), 'de'));
        $templated->limitReached(3, 2, ForcedClose::MODE_TOOL_CHOICE);
        $templated->turnLimitReached(new UserTurnLimit(5, 4), ForcedClose::MODE_FALLBACK);

        self::assertSame('3/2 Aufrufe', $templated->getLimitReason());
        self::assertSame('Runde 4 von 5', $templated->getTurnLimitReason());
    }

    public function testPlaceholderOfASchemaTurnCarriesTheParsedAnswer(): void
    {
        $this->factory->queueAnswer('{"color": "blue"}');
        $schema = ['type' => 'object', 'properties' => ['color' => ['type' => 'string']], 'required' => ['color']];
        $placeholder = TurnRecorder::placeholderRecord($this->conversation, 'anthropic', FakeProviderFactory::ANTHROPIC_MODEL, $schema);
        self::assertSame(MessageKind::SCHEMA, $placeholder->getKind());

        $records = TurnRecorder::assistantRecords($this->conversation, $this->runTurn(responseSchema: $schema), $placeholder);

        self::assertSame([$placeholder], $records);
        self::assertSame([MessageKind::SCHEMA, MessageStatus::COMPLETE, ['color' => 'blue'], $schema], [$placeholder->getKind(), $placeholder->getStatus(), $placeholder->getStructured(), $placeholder->getResponseSchema()]);
    }

    public function testAbortedRunEndsWithAnAbortedRecordWithThePartialText(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'Wartung'])->queueAnswer('egal');
        $completed = $this->runTurn();
        $result = new TurnResult($completed->request, '', [$completed->steps[0]], [], [], new TurnCancelledException('The turn was cancelled.'), partialText: 'Die Spin');

        self::assertTrue($result->isAborted());
        $records = $this->records($result);

        self::assertSame(
            [[MessageKind::TOOL_CALL, MessageStatus::COMPLETE, 1], [MessageKind::TEXT, MessageStatus::ABORTED, 2]],
            array_map(static fn (Message $r): array => [$r->getKind(), $r->getStatus(), $r->getStep()], $records),
        );
        $aborted = $records[1];
        self::assertSame('Die Spin', $aborted->getContent());
        self::assertNull($aborted->getError());
        self::assertSame(['step' => 2, 'part' => 'aborted'], $aborted->getProviderPayload());
        self::assertSame([], $aborted->getSources());
    }

    public function testAbortedPlaceholderWithoutStepsIsTheOnlyRecord(): void
    {
        $request = new TurnRequest('anthropic', FakeProviderFactory::ANTHROPIC_MODEL, 'Frage');
        $placeholder = TurnRecorder::placeholderRecord($this->conversation, 'anthropic', FakeProviderFactory::ANTHROPIC_MODEL);

        $records = TurnRecorder::assistantRecords($this->conversation, new TurnResult($request, '', [], [], [], new TurnCancelledException(), partialText: null), $placeholder);

        self::assertSame([$placeholder], $records);
        self::assertSame([MessageStatus::ABORTED, null, 1, null], [$placeholder->getStatus(), $placeholder->getContent(), $placeholder->getStep(), $placeholder->getError()]);
    }

    public function testErrorRecordKeepsThePartialTextOfAStreamedRun(): void
    {
        $request = new TurnRequest('anthropic', FakeProviderFactory::ANTHROPIC_MODEL, 'Frage');
        $result = new TurnResult($request, '', [], [], [], new ProviderException('Stream broke'), partialText: 'Eins zwei ');

        self::assertFalse($result->isAborted());
        $final = $this->final($result);

        self::assertSame([MessageStatus::ERROR, 'Stream broke', 'Eins zwei ', 1], [$final->getStatus(), $final->getError(), $final->getContent(), $final->getStep()]);
        self::assertSame(['step' => 1, 'part' => 'error', 'exception' => ProviderException::class], $final->getProviderPayload());
    }

    public function testTerminalTurnEndsWithTheTerminalRecordAtTheToolStep(): void
    {
        $schema = ['type' => 'object', 'properties' => ['color' => ['type' => 'string']], 'required' => ['color']];
        $this->factory->getChatProvider()->addResponses(new ToolCallMessage(
            [new ReasoningContent('Jetzt einreichen.', 'sig-1')],
            [
                ToolCall::make(name: 'search', callId: 'call_search', inputs: ['query' => 'Farbe'], description: 'Full-text search'),
                ToolCall::make(name: 'submit', callId: 'call_submit', inputs: [], description: 'Submits the answer'),
            ],
        )->setUsage(new Usage(30, 5)));
        $context = [new ContextChunk('Blau.', 0.9)];

        $result = $this->runTurn(responseSchema: $schema, context: $context, terminalTools: ['submit']);
        $records = $this->records($result);

        self::assertSame(
            [[MessageKind::REASONING, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::SCHEMA, 1]],
            array_map(static fn (Message $r): array => [$r->getKind(), $r->getStep()], $records),
        );
        [, $search, $submit, $final] = $records;

        // The first tool call stays the anchor with the message and usage.
        self::assertSame('tool_call', $search->getProviderPayload()['message']['type']);
        self::assertSame(['input_tokens' => 30, 'output_tokens' => 5, 'cached_input_tokens' => 0, 'reasoning_tokens' => 0], $search->getUsage());
        self::assertNull($search->getCloseReason());
        // The tool-call record keeps the raw MCP result.
        self::assertSame('[{"type":"text","text":"{\"color\":\"blue\"}"}]', $submit->getToolResult());
        self::assertNull($submit->getCloseReason());

        self::assertSame(
            [Message::SPEAKER_ASSISTANT, MessageKind::SCHEMA, MessageStatus::COMPLETE, 1, '{"color":"blue"}', ['color' => 'blue'], null, $schema, TerminalClose::REASON, 'anthropic', FakeProviderFactory::ANTHROPIC_MODEL],
            [$final->getRole(), $final->getKind(), $final->getStatus(), $final->getStep(), $final->getContent(), $final->getStructured(), $final->getError(), $final->getResponseSchema(), $final->getCloseReason(), $final->getProvider(), $final->getModel()],
        );
        self::assertSame(['step' => 1, 'part' => 'terminal', 'tool' => ['name' => 'submit', 'callId' => 'call_submit'], 'schema_errors' => null], $final->getProviderPayload());
        self::assertSame($submit->getDurationMs(), $final->getDurationMs());
        self::assertNull($final->getUsage());
        self::assertSame([$context[0]->toArray()], $final->getSources());
        self::assertNull($final->getToolName());
        $this->factory->getChatProvider()->assertCallCount(1);
    }

    public function testTerminalRecordOfATextTurnHasNoSchemaFields(): void
    {
        $this->factory->queueToolCall('submit', [], 'call_submit');

        $final = $this->final($this->runTurn(terminalTools: ['submit']));

        self::assertSame([MessageKind::TEXT, '{"color":"blue"}', ['color' => 'blue'], null, null], [$final->getKind(), $final->getContent(), $final->getStructured(), $final->getError(), $final->getResponseSchema()]);
        self::assertSame(['step' => 1, 'part' => 'terminal', 'tool' => ['name' => 'submit', 'callId' => 'call_submit']], $final->getProviderPayload());
    }

    public function testPlaceholderBecomesTheTerminalRecordInPlace(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'Farbe'], 'call_search', text: 'Ich sehe nach.')->queueToolCall('submit', [], 'call_submit');
        $result = $this->runTurn(terminalTools: ['submit']);
        $placeholder = TurnRecorder::placeholderRecord($this->conversation, 'anthropic', FakeProviderFactory::ANTHROPIC_MODEL);

        $withPlaceholder = TurnRecorder::assistantRecords($this->conversation, $result, $placeholder);
        $without = $this->records($result);

        self::assertSame($this->describe($without), $this->describe($withPlaceholder));
        self::assertSame([[MessageKind::TEXT, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::TEXT, 1]], array_map(static fn (Message $r): array => [$r->getKind(), $r->getStep()], $withPlaceholder));
        self::assertSame($placeholder, $withPlaceholder[3]);
        self::assertSame([MessageStatus::COMPLETE, 1, TerminalClose::REASON, ['color' => 'blue']], [$placeholder->getStatus(), $placeholder->getStep(), $placeholder->getCloseReason(), $placeholder->getStructured()]);
        self::assertArrayNotHasKey('message', $placeholder->getProviderPayload());
        // The model text before the call is the anchor.
        self::assertSame('Ich sehe nach.', $withPlaceholder[0]->getContent());
        self::assertArrayHasKey('message', $withPlaceholder[0]->getProviderPayload());
    }

    public function testForcedTerminalTurnKeepsTheLimitBeforeTheStep(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'Farbe']);
        $this->factory->getChatProvider()->addResponses(new ToolCallMessage(null, [ToolCall::make(name: 'submit', callId: 'call_submit', description: 'Submits the answer')]));
        $result = new TurnRunner($this->factory, new StructuredAnswerValidator())->run(new TurnRequest(
            provider: 'anthropic',
            connection: ProviderConnectionFixtures::anthropic(),
            model: FakeProviderFactory::ANTHROPIC_MODEL,
            content: 'Frage',
            tools: [new CallbackTool('search', 'Full-text search', static fn (): string => 'Treffer'), self::submitTool()],
            maxToolCalls: 1,
            terminalTools: ['submit'],
        ));

        $records = $this->records($result);

        self::assertTrue($result->forced);
        self::assertSame([[MessageKind::TOOL_CALL, 1], [MessageKind::LIMIT, 2], [MessageKind::TOOL_CALL, 2], [MessageKind::TEXT, 2]], array_map(static fn (Message $r): array => [$r->getKind(), $r->getStep()], $records));
        self::assertNull($records[1]->getCloseReason());
        self::assertSame(TerminalClose::REASON, $records[3]->getCloseReason());
    }

    public function testRecordsWithoutATerminalCallAreTheRecordsOfToday(): void
    {
        $script = function (): void {
            $this->factory->queueToolCall('search', ['query' => 'Wartung'], 'call_search', reasoning: 'Erst suchen.', signature: 'sig-1')->queueToolCall('broken', ['id' => 7], 'call_broken');
            $this->factory->getChatProvider()->addResponses(new AssistantMessage('Alle 500 Stunden.')->setUsage(new Usage(50, 10)));
        };
        $script();
        $named = $this->records($this->runTurn(terminalTools: ['submit']));
        $script();
        $plain = $this->records($this->runTurn());

        $full = static fn (Message $r): array => [
            $r->getRole(), $r->getKind(), $r->getStatus(), $r->getStep(), $r->getContent(), $r->getToolName(), $r->getToolCallId(), $r->getToolInput(),
            $r->getToolResult(), $r->isToolError(), $r->getUsage(), $r->getSources(), $r->getError(), $r->getCloseReason(), $r->getStructured(),
            $r->getResponseSchema(), array_diff_key($r->getProviderPayload() ?? [], ['message' => true]), array_diff_key($r->getProviderPayload()['message'] ?? [], ['__id' => true]),
        ];
        self::assertSame(array_map($full, $plain), array_map($full, $named));
    }

    public function testRefusedTurnIsOneRuleRefusalRecordWithoutMessage(): void
    {
        $result = TurnResult::ruled(self::ruleRequest(), self::outcome(RuleOutcome::REFUSE, 'Dazu kann ich nichts sagen.'));

        self::assertTrue($result->isRuled());
        self::assertFalse($result->isFailed());
        self::assertSame([], $result->steps);
        self::assertNull($result->totalUsage());
        self::assertSame('Dazu kann ich nichts sagen.', $result->answer);

        $records = $this->records($result);
        self::assertCount(1, $records);
        [$record] = $records;
        self::assertSame(
            [Message::SPEAKER_ASSISTANT, MessageKind::RULE_REFUSAL, MessageStatus::COMPLETE, 1, 'Dazu kann ich nichts sagen.', RuleClose::REASON_REFUSAL],
            [$record->getRole(), $record->getKind(), $record->getStatus(), $record->getStep(), $record->getContent(), $record->getCloseReason()],
        );
        self::assertSame(['anthropic', FakeProviderFactory::ANTHROPIC_MODEL], [$record->getProvider(), $record->getModel()]);
        self::assertSame([], $record->getSources());
        self::assertNull($record->getUsage());
        self::assertNull($record->getError());
        self::assertSame([
            'step' => 1,
            'part' => 'rule',
            'rule' => ['index' => 2, 'name' => 'off-topic', 'match' => 'keywords', 'outcome' => 'refuse', 'template' => 'refusal', 'language' => 'de'],
        ], $record->getProviderPayload());
    }

    public function testFixedAnswerRecordCarriesTheMessageAnchor(): void
    {
        $result = TurnResult::ruled(self::ruleRequest(), self::outcome(RuleOutcome::ANSWER, 'Unsere Öffnungszeiten: 9–17 Uhr.'));

        [$record] = $this->records($result);
        self::assertSame([MessageKind::RULE_ANSWER, RuleClose::REASON_ANSWER, 'Unsere Öffnungszeiten: 9–17 Uhr.'], [$record->getKind(), $record->getCloseReason(), $record->getContent()]);
        $payload = (array) $record->getProviderPayload();
        self::assertSame(['step', 'part', 'rule', 'message'], array_keys($payload));
        self::assertSame('answer', $payload['rule']['outcome']);
        // The serialization carries a random message id (`__id`); the rest is fixed.
        $expected = TurnRecorder::normalize(new AssistantMessage('Unsere Öffnungszeiten: 9–17 Uhr.'));
        unset($expected['__id'], $payload['message']['__id']);
        self::assertSame($expected, $payload['message']);
        self::assertSame('assistant', $payload['message']['role']);
    }

    public function testPlaceholderBecomesTheRuleRecordInPlace(): void
    {
        $result = TurnResult::ruled(self::ruleRequest(), self::outcome(RuleOutcome::REFUSE, 'Nein.'));
        $placeholder = TurnRecorder::placeholderRecord($this->conversation, 'anthropic', FakeProviderFactory::ANTHROPIC_MODEL, ['type' => 'object']);
        $id = $placeholder->getId();

        $withPlaceholder = TurnRecorder::assistantRecords($this->conversation, $result, $placeholder);

        self::assertSame([$placeholder], $withPlaceholder);
        self::assertSame($id, $placeholder->getId());
        self::assertSame($this->describe($this->records($result)), $this->describe($withPlaceholder));
        self::assertSame([MessageKind::RULE_REFUSAL, MessageStatus::COMPLETE, RuleClose::REASON_REFUSAL], [$placeholder->getKind(), $placeholder->getStatus(), $placeholder->getCloseReason()]);
        self::assertNull($placeholder->getResponseSchema());
        self::assertSame([], $placeholder->getSources());
    }

    public function testRuleKindsAndCloseReasons(): void
    {
        self::assertContains(MessageKind::RULE_ANSWER, MessageKind::all());
        self::assertContains(MessageKind::RULE_REFUSAL, MessageKind::all());
        self::assertSame(['rule_answer', 'rule_refusal'], [RuleClose::REASON_ANSWER, RuleClose::REASON_REFUSAL]);
        self::assertSame(MessageKind::RULE_ANSWER, RuleClose::REASON_ANSWER);
        self::assertSame(MessageKind::RULE_REFUSAL, RuleClose::REASON_REFUSAL);
        foreach (MessageKind::all() as $kind) {
            self::assertLessThanOrEqual(20, \strlen($kind));
        }
    }

    public function testUserRecord(): void
    {
        $record = TurnRecorder::userRecord($this->conversation, 'Frage', ['type' => 'object']);

        self::assertSame([Message::SPEAKER_USER, MessageKind::TEXT, MessageStatus::COMPLETE, 0, 'Frage'], [$record->getRole(), $record->getKind(), $record->getStatus(), $record->getStep(), $record->getContent()]);
        self::assertSame(['type' => 'object'], $record->getResponseSchema());
        self::assertSame('user', $record->getProviderPayload()['message']['role']);
    }

    public function testLockKeyIsTheSignedFirstEightBytes(): void
    {
        self::assertSame(1, TurnLock::keyFor(Uuid::fromString('00000000-0000-0001-8000-000000000000')));
        self::assertSame(-1, TurnLock::keyFor(Uuid::fromString('ffffffff-ffff-ffff-8000-000000000000')));
        self::assertSame(\PHP_INT_MIN, TurnLock::keyFor(Uuid::fromString('80000000-0000-0000-8000-000000000000')));
        self::assertSame(0x0123456789ABCDEF, TurnLock::keyFor(Uuid::fromString('01234567-89ab-cdef-8000-000000000000')));
    }

    public function testTitleIsCutAtAWordBoundary(): void
    {
        self::assertSame('Kurze Frage', ConversationTurnService::titleFrom("  Kurze \n Frage "));

        $long = str_repeat('Wort ', 30);
        $title = ConversationTurnService::titleFrom($long);
        self::assertLessThanOrEqual(80, mb_strlen($title));
        self::assertStringEndsWith('Wort', $title);

        self::assertSame(80, mb_strlen(ConversationTurnService::titleFrom(str_repeat('x', 200))));
    }

    /**
     * @param array<string, mixed>|null $responseSchema
     * @param list<ContextChunk>        $context
     * @param list<string>              $terminalTools
     */
    private function runTurn(?array $responseSchema = null, array $context = [], array $terminalTools = []): TurnResult
    {
        $tools = [
            new CallbackTool('search', 'Full-text search', static fn (): string => 'Treffer'),
            new CallbackTool('broken', 'Always fails', static fn (): never => throw new \RuntimeException('Index offline')),
            self::submitTool(),
        ];

        return new TurnRunner($this->factory, new StructuredAnswerValidator())->run(new TurnRequest(
            provider: 'anthropic',
            connection: ProviderConnectionFixtures::anthropic(),
            model: FakeProviderFactory::ANTHROPIC_MODEL,
            content: 'Frage',
            tools: $tools,
            context: $context,
            responseSchema: $responseSchema,
            terminalTools: $terminalTools,
        ));
    }

    private static function ruleRequest(): TurnRequest
    {
        return new TurnRequest(provider: 'anthropic', model: FakeProviderFactory::ANTHROPIC_MODEL, content: 'Frage');
    }

    private static function outcome(string $outcome, string $text): RuleOutcome
    {
        return new RuleOutcome(2, 'off-topic', 'keywords', $outcome, RuleOutcome::REFUSE === $outcome ? 'refusal' : 'hours', $text, 'de');
    }

    /**
     * A tool returning an MCP content list (JSON-encoded by neuron-ai).
     */
    private static function submitTool(): CallbackTool
    {
        return new CallbackTool('submit', 'Submits the answer', static fn (): array => [['type' => 'text', 'text' => '{"color":"blue"}']]);
    }

    /**
     * @return non-empty-list<Message>
     */
    private function records(TurnResult $result): array
    {
        return TurnRecorder::assistantRecords($this->conversation, $result);
    }

    /**
     * @param list<Message> $records
     *
     * @return list<array{string, string, int, ?string, mixed}>
     */
    private function describe(array $records): array
    {
        return array_map(static fn (Message $r): array => [$r->getKind(), $r->getStatus(), $r->getStep(), $r->getContent(), $r->getProviderPayload()], $records);
    }

    private function final(TurnResult $result): Message
    {
        $records = $this->records($result);

        return $records[array_key_last($records)];
    }
}

// - revised 2026-10-14
