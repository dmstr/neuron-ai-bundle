<?php

// file generated with AI assistance: Claude Code - 2026-10-02 06:02:17 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use Dmstr\NeuronAiBundle\Agent\Template\TemplateSet;
use Dmstr\NeuronAiBundle\Conversation\ForcedClose;
use Dmstr\NeuronAiBundle\Conversation\ForcedCloseFailedException;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Conversation\StructuredAnswerValidator;
use Dmstr\NeuronAiBundle\Conversation\ToolCallBoundMiddleware;
use Dmstr\NeuronAiBundle\Conversation\TurnDeadlineExceededException;
use Dmstr\NeuronAiBundle\Conversation\TurnRecorder;
use Dmstr\NeuronAiBundle\Conversation\TurnRequest;
use Dmstr\NeuronAiBundle\Conversation\TurnResult;
use Dmstr\NeuronAiBundle\Conversation\TurnRunner;
use Dmstr\NeuronAiBundle\Conversation\TurnTexts;
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
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the bounded tool loop with the bundle
 * fake: the bound counts executed calls including parallel ones, the batch
 * that crosses it runs whole, the next inference is forced (tool choice
 * `none` on a capable provider, no tools plus the instruction otherwise),
 * the `limit` record precedes the forced step, and the forced answer
 * carries the close reason. A forced step that calls a tool again fails
 * the turn; without a bound nothing changes.
 */
final class TurnRunnerBoundTest extends TestCase
{
    private FakeProviderFactory $factory;

    private Conversation $conversation;

    /** @var list<string> names of the tools that ran, in order */
    private array $ran = [];

    protected function setUp(): void
    {
        $this->factory = self::newFactory();
        $this->conversation = new Conversation();
    }

    public function testUnboundedTurnKeepsTheRecordsOfToday(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a'])->queueToolCall('fetch', ['id' => 1])->queueAnswer('Antwort');
        $unbounded = $this->runTurn();

        $reference = self::newFactory();
        $reference->queueToolCall('search', ['query' => 'a'])->queueToolCall('fetch', ['id' => 1])->queueAnswer('Antwort');
        $this->factory = $reference;
        $notReached = $this->runTurn(maxToolCalls: 99);

        self::assertFalse($unbounded->isFailed());
        self::assertSame($this->describe($notReached), $this->describe($unbounded));
        self::assertSame([[MessageKind::TOOL_CALL, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::TEXT, 2]], $this->layout($unbounded));
        foreach ($this->records($unbounded) as $record) {
            self::assertNull($record->getCloseReason());
        }
        self::assertFalse($unbounded->limitReached);
        self::assertFalse($unbounded->forced);
        self::assertNull($unbounded->limitReason);
        self::assertNull($unbounded->forcedMode);
        self::assertNull($unbounded->limitStep);
        self::assertNull($unbounded->limitCalls);
        self::assertNull($unbounded->limitBound);
        self::assertSame([null, null], array_map(static fn (ToolChoiceRequestRecord $r): ?ToolChoice => $r->toolChoice, $this->requests()));
    }

    public function testBatchCrossingTheBoundRunsWholeAndTheNextStepIsForced(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a'])->queueToolCall('fetch', ['id' => 1])->queueAnswer('Antwort');

        $result = $this->runTurn(maxToolCalls: 1);

        self::assertFalse($result->isFailed());
        self::assertSame(['search', 'fetch'], $this->ran);
        self::assertTrue($result->limitReached);
        self::assertTrue($result->forced);
        self::assertSame(ForcedClose::MODE_TOOL_CHOICE, $result->forcedMode);
        self::assertSame(2, $result->limitStep);
        self::assertSame(ForcedClose::limitReason(2, 1), $result->limitReason);
        self::assertSame([2, 1], [$result->limitCalls, $result->limitBound]);

        $records = $this->records($result);
        self::assertSame([[MessageKind::TOOL_CALL, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::LIMIT, 2], [MessageKind::TEXT, 2]], $this->layout($result));
        $this->assertLimitRecord($records[2], 2, 'Tool-call limit of 1 reached after 2 calls; the model was asked to answer.');
        self::assertSame([MessageStatus::COMPLETE, 'Antwort', ForcedClose::REASON_BOUND], [$records[3]->getStatus(), $records[3]->getContent(), $records[3]->getCloseReason()]);

        $requests = $this->requests();
        self::assertCount(2, $requests);
        self::assertNull($requests[0]->toolChoice);
        self::assertTrue(ToolChoice::none()->equals($requests[1]->toolChoice));
        // The tools stay in the forced request; the choice changes and the instruction is added.
        self::assertSame(['search', 'fetch', 'slow'], self::toolNames($requests[1]));
        self::assertStringContainsString(ForcedClose::INSTRUCTION, (string) $requests[1]->systemPrompt?->getContent());
        // The choice does not outlive the run.
        self::assertNull($this->factory->getChatProvider()->getToolChoice());
    }

    public function testBoundReachedOnTheFirstResponse(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a'])->queueAnswer('Antwort');

        $result = $this->runTurn(maxToolCalls: 1);

        self::assertTrue($result->forced);
        self::assertSame(['search'], $this->ran);
        self::assertSame([[MessageKind::TOOL_CALL, 1], [MessageKind::LIMIT, 2], [MessageKind::TEXT, 2]], $this->layout($result));
        $records = $this->records($result);
        $this->assertLimitRecord($records[1], 2, ForcedClose::limitReason(1, 1));
        self::assertSame(ForcedClose::REASON_BOUND, $records[2]->getCloseReason());
        self::assertTrue(ToolChoice::none()->equals($this->requests()[1]->toolChoice));
    }

    public function testBoundAboveTheCallsChangesNothing(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a'])->queueToolCall('fetch', ['id' => 1])->queueAnswer('Antwort');

        $result = $this->runTurn(maxToolCalls: 5);

        self::assertFalse($result->isFailed());
        self::assertFalse($result->limitReached);
        self::assertFalse($result->forced);
        self::assertSame([[MessageKind::TOOL_CALL, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::TEXT, 2]], $this->layout($result));
        foreach ($this->records($result) as $record) {
            self::assertNull($record->getCloseReason());
        }
        self::assertSame([null, null], array_map(static fn (ToolChoiceRequestRecord $r): ?ToolChoice => $r->toolChoice, $this->requests()));
    }

    public function testFallbackDropsTheToolsAndAppendsTheInstructionOnce(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a'])->queueAnswer('Antwort');

        $result = $this->runTurn(maxToolCalls: 1, provider: 'ollama');

        self::assertFalse($result->isFailed());
        self::assertTrue($result->forced);
        self::assertSame(ForcedClose::MODE_FALLBACK, $result->forcedMode);
        $records = $this->records($result);
        self::assertSame([[MessageKind::TOOL_CALL, 1], [MessageKind::LIMIT, 2], [MessageKind::TEXT, 2]], $this->layout($result));
        self::assertSame(['ollama', FakeProviderFactory::OLLAMA_MODEL], [$records[1]->getProvider(), $records[1]->getModel()]);
        self::assertSame(ForcedClose::REASON_BOUND, $records[2]->getCloseReason());

        [$first, $forced] = $this->requests();
        self::assertSame(['search', 'fetch', 'slow'], self::toolNames($first));
        self::assertStringNotContainsString(ForcedClose::INSTRUCTION, (string) $first->systemPrompt?->getContent());
        self::assertNull($first->toolChoice);

        self::assertSame([], $forced->tools);
        self::assertStringEndsWith(ForcedClose::INSTRUCTION, (string) $forced->systemPrompt?->getContent());
        self::assertSame(1, substr_count((string) $forced->systemPrompt?->getContent(), ForcedClose::INSTRUCTION));
        self::assertNull($forced->toolChoice);
    }

    public function testFallbackOnTheDefaultProviderWhenTheChoiceIsDeclined(): void
    {
        $this->factory->declineToolChoice();
        $this->factory->queueToolCall('search', ['query' => 'a'])->queueAnswer('Antwort');

        $result = $this->runTurn(maxToolCalls: 1);

        self::assertSame(ForcedClose::MODE_FALLBACK, $result->forcedMode);
        self::assertSame([], $this->requests()[1]->tools);
    }

    public function testForcedStepCallingAToolAgainFailsBeforeTheToolRuns(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a']);
        $this->factory->getChatProvider()->addResponses(new ToolCallMessage(null, [
            ToolCall::make(name: 'search', callId: 'call_forced', inputs: ['query' => 'b'], description: 'Full-text search'),
        ]));

        $result = $this->runTurn(maxToolCalls: 1);

        self::assertSame(['search'], $this->ran);
        self::assertInstanceOf(ForcedCloseFailedException::class, $result->failure);
        self::assertTrue($result->limitReached);
        self::assertFalse($result->forced);

        $records = $this->records($result);
        self::assertSame(
            [[MessageKind::TOOL_CALL, MessageStatus::COMPLETE, 1], [MessageKind::LIMIT, MessageStatus::COMPLETE, 2], [MessageKind::TEXT, MessageStatus::ERROR, 3]],
            array_map(static fn (Message $r): array => [$r->getKind(), $r->getStatus(), $r->getStep()], $records),
        );
        $this->assertLimitRecord($records[1], 2, ForcedClose::limitReason(1, 1));
        $error = $records[2];
        self::assertSame('The forced close did not produce an answer.', $error->getError());
        self::assertSame(new ForcedCloseFailedException()->getMessage(), $error->getError());
        foreach ($records as $record) {
            self::assertNull($record->getCloseReason());
        }
    }

    public function testPassedDeadlineBeforeAnyStepKeepsTheErrorPath(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a'])->queueAnswer('Antwort');

        $result = $this->runTurn(maxToolCalls: 1, deadlineSeconds: -1);

        self::assertInstanceOf(TurnDeadlineExceededException::class, $result->failure);
        self::assertFalse($result->limitReached);
        self::assertSame([[MessageKind::TEXT, 1]], $this->layout($result));
        self::assertSame(MessageStatus::ERROR, $this->records($result)[0]->getStatus());
        $this->factory->getChatProvider()->assertNothingSent();
    }

    public function testDeadlineAtTheForcedStepWritesTheLimitAndTheError(): void
    {
        $this->factory->queueToolCall('slow')->queueAnswer('zu spät');

        $result = $this->runTurn(maxToolCalls: 1, deadlineSeconds: 1);

        self::assertInstanceOf(TurnDeadlineExceededException::class, $result->failure);
        self::assertTrue($result->limitReached);
        self::assertFalse($result->forced);
        $records = $this->records($result);
        self::assertSame(
            [[MessageKind::TOOL_CALL, MessageStatus::COMPLETE, 1], [MessageKind::LIMIT, MessageStatus::COMPLETE, 2], [MessageKind::TEXT, MessageStatus::ERROR, 2]],
            array_map(static fn (Message $r): array => [$r->getKind(), $r->getStatus(), $r->getStep()], $records),
        );
        self::assertNull($records[2]->getCloseReason());
        $this->factory->getChatProvider()->assertCallCount(1);
    }

    /**
     * The middleware rejects a bound below 1; the runner returns that as the
     * run's failure before the provider is called.
     */
    #[DataProvider('invalidBounds')]
    public function testInvalidBoundFailsTheRun(int $bound): void
    {
        $this->factory->queueAnswer('Antwort');

        $result = $this->runTurn(maxToolCalls: $bound);

        self::assertInstanceOf(\InvalidArgumentException::class, $result->failure);
        self::assertFalse($result->limitReached);
        self::assertSame([[MessageKind::TEXT, 1]], $this->layout($result));
        $this->factory->getChatProvider()->assertNothingSent();
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidBounds(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-3];
    }

    public function testMiddlewareRejectsABoundBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ToolCallBoundMiddleware(0, $this->factory->getChatProvider(), new TurnRecorder());
    }

    public function testStreamedForcedAnswer(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a'])->queueToolCall('fetch', ['id' => 1])->queueAnswer('Alle 500 Stunden.');
        $sink = new RecordingStreamSink();

        $result = $this->runner()->stream($this->request(maxToolCalls: 1), $sink, messageId: 'msg-final');

        self::assertFalse($result->isFailed());
        self::assertTrue($result->forced);
        self::assertSame([2, 1], [$result->limitCalls, $result->limitBound]);
        self::assertSame([StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::LIMIT, StreamEvent::TOKEN], self::collapseTokens($sink->getTypes()));
        self::assertSame('Alle 500 Stunden.', $sink->textOf(StreamEvent::TOKEN));
        $limit = $sink->getEvents()[4];
        self::assertSame([
            'messageId' => 'msg-final',
            'step' => 2,
            'reason' => ForcedClose::limitReason(2, 1),
            'maxToolCalls' => 1,
            'toolCalls' => 2,
            'mode' => ForcedClose::MODE_TOOL_CHOICE,
        ], $limit->payload);

        $placeholder = TurnRecorder::placeholderRecord($this->conversation, 'anthropic', FakeProviderFactory::ANTHROPIC_MODEL);
        $records = TurnRecorder::assistantRecords($this->conversation, $result, $placeholder);
        self::assertSame([[MessageKind::TOOL_CALL, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::LIMIT, 2], [MessageKind::TEXT, 2]], array_map(static fn (Message $r): array => [$r->getKind(), $r->getStep()], $records));
        self::assertSame($placeholder, $records[3]);
        self::assertSame([MessageStatus::COMPLETE, 'Alle 500 Stunden.', ForcedClose::REASON_BOUND], [$placeholder->getStatus(), $placeholder->getContent(), $placeholder->getCloseReason()]);

        $requests = $this->requests();
        self::assertSame(['stream', 'stream'], array_map(static fn (ToolChoiceRequestRecord $r): string => $r->method, $requests));
        self::assertNull($requests[0]->toolChoice);
        self::assertTrue(ToolChoice::none()->equals($requests[1]->toolChoice));
    }

    /**
     * With a `limitReason` template the `limit`
     * record, the result and the event carry the rendered text in the
     * effective language.
     */
    public function testLimitReasonTemplateIsRenderedInRecordResultAndEvent(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a'])->queueToolCall('fetch', ['id' => 1])->queueAnswer('Antwort');
        $sink = new RecordingStreamSink();
        $texts = new TurnTexts(new TemplateSet(['limitReason' => [
            'de' => 'Grenze: {{toolCalls}} von {{maxToolCalls}}.',
            'en' => 'Limit: {{ toolCalls }} of {{maxToolCalls}}, {{customer}}.',
        ]], 'de'), 'en', ['customer' => 'Kim']);
        $request = $this->request(maxToolCalls: 1);
        $request = new TurnRequest($request->provider, $request->model, $request->content, tools: $request->tools, maxToolCalls: 1, texts: $texts, connection: $request->connection);

        $result = $this->runner()->stream($request, $sink, messageId: 'msg-final');

        self::assertSame('Limit: 2 of 1, Kim.', $result->limitReason);
        $this->assertLimitRecord($this->records($result)[2], 2, 'Limit: 2 of 1, Kim.');
        self::assertSame('Limit: 2 of 1, Kim.', $sink->getEvents()[4]->payload['reason']);
    }

    public function testDefaultBoundAppliesAndARequestBoundOverridesIt(): void
    {
        $runner = new TurnRunner($this->factory, new StructuredAnswerValidator(), defaultMaxToolCalls: 1);

        $this->factory->queueToolCall('search', ['query' => 'a'])->queueAnswer('Antwort');
        $defaulted = $runner->run($this->request());

        self::assertTrue($defaulted->forced);
        self::assertSame(ForcedClose::limitReason(1, 1), $defaulted->limitReason);

        // Same factory and provider: the forced choice of the first run is gone.
        $this->factory->queueToolCall('search', ['query' => 'b'])->queueAnswer('Antwort');
        $overridden = $runner->run($this->request(maxToolCalls: 5));

        self::assertFalse($overridden->isFailed());
        self::assertFalse($overridden->limitReached);
        self::assertSame([[MessageKind::TOOL_CALL, 1], [MessageKind::TEXT, 2]], $this->layout($overridden));
        $requests = $this->requests();
        self::assertCount(4, $requests);
        self::assertSame([null, null], [$requests[2]->toolChoice, $requests[3]->toolChoice]);
    }

    public function testWithContextKeepsTheBound(): void
    {
        self::assertSame(3, $this->request(maxToolCalls: 3)->withContext([])->maxToolCalls);
        self::assertNull($this->request()->withContext([])->maxToolCalls);
    }

    private static function newFactory(): FakeProviderFactory
    {
        return new FakeProviderFactory(new EmbeddingModelRegistry([
            'nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768],
        ]));
    }

    private function runTurn(?int $maxToolCalls = null, string $provider = 'anthropic', int $deadlineSeconds = 120): TurnResult
    {
        return $this->runner($deadlineSeconds)->run($this->request($maxToolCalls, $provider));
    }

    private function runner(int $deadlineSeconds = 120): TurnRunner
    {
        return new TurnRunner($this->factory, new StructuredAnswerValidator(), deadlineSeconds: $deadlineSeconds);
    }

    private function request(?int $maxToolCalls = null, string $provider = 'anthropic'): TurnRequest
    {
        $tool = function (string $name): CallbackTool {
            return new CallbackTool($name, 'Test tool '.$name, function () use ($name): string {
                $this->ran[] = $name;
                if ('slow' === $name) {
                    // Passes the one-second deadline before the forced step.
                    usleep(1_100_000);
                }

                return 'Ergebnis '.$name;
            });
        };

        return new TurnRequest(
            provider: $provider,
            connection: 'ollama' === $provider ? ProviderConnectionFixtures::ollama() : ProviderConnectionFixtures::anthropic(),
            model: 'ollama' === $provider ? FakeProviderFactory::OLLAMA_MODEL : FakeProviderFactory::ANTHROPIC_MODEL,
            content: 'Frage',
            tools: [$tool('search'), $tool('fetch'), $tool('slow')],
            maxToolCalls: $maxToolCalls,
        );
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

    private function assertLimitRecord(Message $record, int $step, string $reason): void
    {
        self::assertSame(
            [Message::SPEAKER_ASSISTANT, MessageKind::LIMIT, MessageStatus::COMPLETE, $step, $reason, 'anthropic' === $record->getProvider() ? FakeProviderFactory::ANTHROPIC_MODEL : FakeProviderFactory::OLLAMA_MODEL],
            [$record->getRole(), $record->getKind(), $record->getStatus(), $record->getStep(), $record->getContent(), $record->getModel()],
        );
        self::assertSame(['step' => $step, 'part' => 'limit'], $record->getProviderPayload());
        self::assertNull($record->getUsage());
        self::assertNull($record->getSources());
        self::assertNull($record->getDurationMs());
        self::assertNull($record->getCloseReason());
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
                $r->getToolInput(), $r->getToolResult(), $payload, $r->getCloseReason(), $r->getUsage(),
            ];
        }, $this->records($result));
    }
}

// - revised 2026-10-14
