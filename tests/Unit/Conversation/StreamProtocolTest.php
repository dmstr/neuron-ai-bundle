<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:12 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use Dmstr\NeuronAiBundle\Conversation\ForcedClose;
use Dmstr\NeuronAiBundle\Conversation\ForcedCloseFailedException;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Conversation\StructuredAnswerValidator;
use Dmstr\NeuronAiBundle\Conversation\TurnCancelledException;
use Dmstr\NeuronAiBundle\Conversation\TurnDeadlineExceededException;
use Dmstr\NeuronAiBundle\Conversation\TurnRecorder;
use Dmstr\NeuronAiBundle\Conversation\TurnRequest;
use Dmstr\NeuronAiBundle\Conversation\TurnResult;
use Dmstr\NeuronAiBundle\Conversation\TurnRunner;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Stream\StreamEvent;
use Dmstr\NeuronAiBundle\Testing\CallbackTool;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Testing\RecordingStreamSink;
use Dmstr\NeuronAiBundle\Testing\ToolChoiceRequestRecord;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the stream protocol of TurnRunner::stream() with
 * the bundle fake and a recording sink (no database): event order and
 * content, batching, mid-stream failure and cancellation, the
 * `limit` event of a bounded tool loop on every exit path and
 * text before a tool call. The runner sends no `sources`, `done` or `error`
 * events; those belong to the streaming turn service (StreamingTurnTest,
 * CI).
 */
final class StreamProtocolTest extends TestCase
{
    private const string MESSAGE_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    private FakeProviderFactory $factory;

    private RecordingStreamSink $sink;

    private bool $toolRan = false;

    /** Executions of the `search` tool, whichever response asked for it. */
    private int $searchRuns = 0;

    protected function setUp(): void
    {
        $this->factory = self::newFactory();
        $this->sink = new RecordingStreamSink();
    }

    protected function tearDown(): void
    {
        // No case may send the limit twice.
        self::assertLessThanOrEqual(1, \count(array_keys($this->sink->getTypes(), StreamEvent::LIMIT, true)), 'The limit event was sent more than once.');
    }

    public function testPlainAnswerIsSentAsTokensOnly(): void
    {
        $this->factory->queueAnswer('Alle 500 Stunden.');

        $result = $this->stream();

        self::assertFalse($result->isFailed());
        self::assertSame('Alle 500 Stunden.', $result->answer);
        self::assertSame([StreamEvent::TOKEN], array_values(array_unique($this->sink->getTypes())));
        self::assertSame('Alle 500 Stunden.', $this->sink->textOf(StreamEvent::TOKEN));
        foreach ($this->sink->getEvents() as $event) {
            self::assertNull($event->id, 'The runner does not store events.');
        }
        $this->factory->getChatProvider()->assertMethodCallCount('stream', 1);
    }

    public function testReasoningPrecedesTheTextAndIsNeverMergedIntoTokens(): void
    {
        $this->factory->queueReasoning('Kurz nachdenken.', 'Blau.', 'sig-x');

        $result = $this->stream();

        self::assertFalse($result->isFailed());
        self::assertSame([StreamEvent::REASONING, StreamEvent::TOKEN], $this->sink->getTypes());
        self::assertSame('Kurz nachdenken.', $this->sink->textOf(StreamEvent::REASONING));
        self::assertSame('Blau.', $this->sink->textOf(StreamEvent::TOKEN));
        // The records equal those of the non-streaming run.
        self::assertSame([[MessageKind::REASONING, 1], [MessageKind::TEXT, 1]], $this->layout($result));
    }

    public function testToolCallTurnSendsCallResultThenTheTokensOfTheFinalStep(): void
    {
        $this->factory
            ->queueToolCall('search', ['query' => 'Wartung'])
            ->queueToolCall('broken', []);
        $this->factory->queueAnswer('Alle 500 Stunden.');

        $result = $this->stream();

        self::assertFalse($result->isFailed());
        self::assertSame(
            [StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::TOKEN],
            $this->sink->getTypes(),
        );
        [$call, $callResult, $broken, $brokenResult] = $this->sink->getEvents();
        self::assertSame(['name' => 'search', 'callId' => 'call_1', 'input' => ['query' => 'Wartung']], $call->payload);
        self::assertSame('search', $callResult->payload['name']);
        self::assertSame('call_1', $callResult->payload['callId']);
        self::assertSame('Treffer', $callResult->payload['result']);
        self::assertFalse($callResult->payload['error']);
        self::assertIsInt($callResult->payload['durationMs']);
        // An empty input is a JSON object.
        self::assertSame('{}', json_encode($broken->payload['input']));
        self::assertTrue($brokenResult->payload['error']);
        self::assertSame('Index offline', $brokenResult->payload['result']);
        self::assertSame('Alle 500 Stunden.', $this->sink->textOf(StreamEvent::TOKEN));
        self::assertSame([[MessageKind::TOOL_CALL, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::TEXT, 2]], $this->layout($result));
    }

    public function testFastDeltasAreBatchedIntoOneEvent(): void
    {
        $this->factory->setChunkSize(5)->queueAnswer('Die Spindel läuft rund.');

        $this->stream();

        self::assertSame(23, mb_strlen('Die Spindel läuft rund.'));
        self::assertCount(1, $this->sink->getEvents());
        self::assertSame(['text' => 'Die Spindel läuft rund.'], $this->sink->getEvents()[0]->payload);
    }

    public function testABufferIsFlushedAt200Characters(): void
    {
        $text = str_repeat('0123456789', 45);
        $this->factory->setChunkSize(50)->queueAnswer($text);

        $this->stream();

        self::assertSame([200, 200, 50], array_map(static fn (StreamEvent $e): int => mb_strlen($e->payload['text']), $this->sink->getEvents()));
        self::assertSame($text, $this->sink->textOf(StreamEvent::TOKEN));
    }

    public function testPacedDeltasAreSentOneEventPerChunk(): void
    {
        $this->factory->setChunkSize(5)->setChunkDelayMs(150)->queueAnswer('Eins zwei drei ');

        $this->stream();

        self::assertSame(['Eins ', 'zwei ', 'drei '], array_map(static fn (StreamEvent $e): string => $e->payload['text'], $this->sink->getEvents()));
    }

    public function testMidStreamFailureKeepsExactlyTheSentText(): void
    {
        $this->factory->setChunkSize(5)->queueStreamFailure('Stream broke', 3, 'Eins zwei drei vier fünf');

        $result = $this->stream();

        self::assertSame([StreamEvent::TOKEN], array_values(array_unique($this->sink->getTypes())));
        self::assertSame('Eins zwei drei ', $this->sink->textOf(StreamEvent::TOKEN));
        self::assertInstanceOf(ProviderException::class, $result->failure);
        self::assertSame('Stream broke', $result->failure->getMessage());
        self::assertFalse($result->isAborted());
        self::assertSame('Eins zwei drei ', $result->partialText);

        $final = $this->final($result);
        self::assertSame(MessageStatus::ERROR, $final->getStatus());
        self::assertSame('Stream broke', $final->getError());
        self::assertSame('Eins zwei drei ', $final->getContent());
    }

    public function testCancellationDuringDeltasKeepsTheSentText(): void
    {
        $this->factory->setChunkSize(5)->setChunkDelayMs(150)->queueAnswer('Eins zwei drei vier fünf');
        $sink = $this->sink;
        $isCancelled = static fn (): bool => \count(array_filter($sink->getTypes(), static fn (string $type): bool => StreamEvent::TOKEN === $type)) >= 2;

        // neuron-ai 4.x keeps the observers on the agent instance of the run
        // (no global event bus), so an abandoned stream cannot leak them.
        $result = $this->stream($isCancelled);

        self::assertSame('Eins zwei ', $this->sink->textOf(StreamEvent::TOKEN));
        self::assertSame([StreamEvent::TOKEN, StreamEvent::TOKEN], $this->sink->getTypes());
        self::assertInstanceOf(TurnCancelledException::class, $result->failure);
        self::assertTrue($result->isAborted());
        self::assertSame('Eins zwei ', $result->partialText);
        self::assertSame([], $result->steps);

        $final = $this->final($result);
        self::assertSame(MessageStatus::ABORTED, $final->getStatus());
        self::assertSame('Eins zwei ', $final->getContent());
        self::assertNull($final->getError());
        self::assertSame(['step' => 1, 'part' => 'aborted'], $final->getProviderPayload());
    }

    public function testCancellationAtToolCalledKeepsTheCompletedToolCall(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'Wartung']);
        $this->factory->queueAnswer('Wird nicht mehr gefragt.');

        $result = $this->stream(fn (): bool => $this->toolRan);

        self::assertTrue($result->isAborted());
        self::assertSame([StreamEvent::TOOL_CALL], $this->sink->getTypes());
        self::assertCount(1, $result->steps);
        self::assertCount(1, $result->steps[0]->getToolCalls());
        self::assertSame('Treffer', $result->steps[0]->getToolCalls()[0]->result);
        self::assertNull($result->partialText);
        $this->factory->getChatProvider()->assertCallCount(1);

        $records = TurnRecorder::assistantRecords(new Conversation(), $result);
        self::assertSame(
            [[MessageKind::TOOL_CALL, MessageStatus::COMPLETE, 1], [MessageKind::TEXT, MessageStatus::ABORTED, 2]],
            array_map(static fn (Message $r): array => [$r->getKind(), $r->getStatus(), $r->getStep()], $records),
        );
    }

    public function testCancellationBeforeTheFirstInferenceSendsNothing(): void
    {
        $this->factory->queueAnswer('Nie gesendet.');

        $result = $this->stream(static fn (): bool => true);

        self::assertTrue($result->isAborted());
        self::assertSame([], $this->sink->getEvents());
        self::assertNull($result->partialText);
        $this->factory->getChatProvider()->assertNothingSent();
    }

    public function testBoundReachedSendsOneLimitAfterTheBatchAndBeforeTheForcedTokens(): void
    {
        $this->factory
            ->queueToolCall('search', ['query' => 'Wartung'])
            ->queueToolCall('broken', []);
        $this->factory->queueAnswer('Alle 500 Stunden.');

        $result = $this->stream(maxToolCalls: 1, messageId: self::MESSAGE_ID);

        self::assertFalse($result->isFailed());
        self::assertTrue($result->forced);
        self::assertSame(
            [StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::LIMIT, StreamEvent::TOKEN],
            self::collapseTokens($this->sink->getTypes()),
        );
        self::assertSame(2, $result->limitStep);
        self::assertSame([
            'messageId' => self::MESSAGE_ID,
            'step' => $result->limitStep,
            'reason' => ForcedClose::limitReason(2, 1),
            'maxToolCalls' => 1,
            'toolCalls' => 2,
            'mode' => ForcedClose::MODE_TOOL_CHOICE,
        ], $this->limitEvent()->payload);
        self::assertSame($result->limitReason, $this->limitEvent()->payload['reason']);
        self::assertNull($this->limitEvent()->id);
        self::assertSame('Alle 500 Stunden.', $this->sink->textOf(StreamEvent::TOKEN));
        self::assertSame([[MessageKind::TOOL_CALL, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::LIMIT, 2], [MessageKind::TEXT, 2]], $this->layout($result));
    }

    public function testBoundAboveTheCallsSendsExactlyTheEventsOfTheUnboundedRun(): void
    {
        $script = static function (FakeProviderFactory $factory): void {
            $factory->queueToolCall('search', ['query' => 'Wartung'])->queueToolCall('broken', []);
            $factory->queueAnswer('Alle 500 Stunden.');
        };

        $script($this->factory);
        $unbounded = $this->stream();
        $unboundedEvents = self::comparable($this->sink->getEvents());

        $this->factory = self::newFactory();
        $this->sink = new RecordingStreamSink();
        $script($this->factory);
        $bounded = $this->stream(maxToolCalls: 5, messageId: self::MESSAGE_ID);

        self::assertFalse($bounded->isFailed());
        self::assertFalse($bounded->limitReached);
        self::assertSame([null, null], [$bounded->limitCalls, $bounded->limitBound]);
        self::assertNotContains(StreamEvent::LIMIT, $this->sink->getTypes());
        self::assertSame($unboundedEvents, self::comparable($this->sink->getEvents()));
        self::assertSame($this->layout($unbounded), $this->layout($bounded));
    }

    public function testFallbackModeIsReportedAndTheForcedRequestCarriesNoTools(): void
    {
        $this->factory->declineToolChoice();
        $this->factory->queueToolCall('search', ['query' => 'Wartung'])->queueAnswer('Alle 500 Stunden.');

        $result = $this->stream(maxToolCalls: 1, messageId: self::MESSAGE_ID);

        self::assertTrue($result->forced);
        self::assertSame([StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::LIMIT, StreamEvent::TOKEN], self::collapseTokens($this->sink->getTypes()));
        self::assertSame(ForcedClose::MODE_FALLBACK, $this->limitEvent()->payload['mode']);
        self::assertSame([1, 1], [$this->limitEvent()->payload['toolCalls'], $this->limitEvent()->payload['maxToolCalls']]);

        $requests = $this->requests();
        self::assertCount(2, $requests);
        self::assertNotSame([], $requests[0]->tools);
        self::assertSame([], $requests[1]->tools);
        self::assertStringEndsWith(ForcedClose::INSTRUCTION, (string) $requests[1]->systemPrompt?->getContent());
        self::assertNull($requests[1]->toolChoice);
    }

    public function testFailedForcedCloseWithoutTextSendsTheLimitAndNoCallForTheDiscardedTool(): void
    {
        $this->queueFirstSearch();
        $this->factory->queueToolCall('search', ['query' => 'noch einmal'], 'call_forced');

        $result = $this->stream(maxToolCalls: 1, messageId: self::MESSAGE_ID);

        self::assertInstanceOf(ForcedCloseFailedException::class, $result->failure);
        self::assertFalse($result->isAborted());
        self::assertFalse($result->forced);
        self::assertSame([StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::LIMIT], $this->sink->getTypes());
        self::assertSame('call_first', $this->sink->getEvents()[0]->payload['callId']);
        self::assertSame(['messageId' => self::MESSAGE_ID, 'step' => 2, 'maxToolCalls' => 1, 'toolCalls' => 1], array_intersect_key($this->limitEvent()->payload, array_flip(['messageId', 'step', 'toolCalls', 'maxToolCalls'])));
        // The forced tool call never ran.
        self::assertSame(1, $this->searchRuns);
        self::assertNull($result->partialText);

        self::assertSame(
            [[MessageKind::TOOL_CALL, MessageStatus::COMPLETE, 1], [MessageKind::LIMIT, MessageStatus::COMPLETE, 2], [MessageKind::TEXT, MessageStatus::ERROR, 3]],
            $this->statusLayout($result),
        );
    }

    public function testFailedForcedCloseWithLeadingTextSendsItsTokensAfterTheLimit(): void
    {
        $this->queueFirstSearch();
        $this->factory->queueToolCall('search', ['query' => 'noch einmal'], 'call_forced', text: 'Ich suche noch einmal.');

        $result = $this->stream(maxToolCalls: 1, messageId: self::MESSAGE_ID);

        self::assertInstanceOf(ForcedCloseFailedException::class, $result->failure);
        self::assertSame([StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::LIMIT, StreamEvent::TOKEN], self::collapseTokens($this->sink->getTypes()));
        self::assertSame('Ich suche noch einmal.', $this->sink->textOf(StreamEvent::TOKEN));
        self::assertNotContains('call_forced', array_map(static fn (StreamEvent $e): mixed => $e->payload['callId'] ?? null, $this->sink->getEvents()));
        self::assertSame(1, $this->searchRuns);
        // The forced step completed: its text is a record of its own, not
        // the error record's partial content.
        self::assertNull($result->partialText);

        $records = TurnRecorder::assistantRecords(new Conversation(), $result);
        self::assertSame(
            [[MessageKind::TOOL_CALL, MessageStatus::COMPLETE, 1], [MessageKind::LIMIT, MessageStatus::COMPLETE, 2], [MessageKind::TEXT, MessageStatus::COMPLETE, 2], [MessageKind::TEXT, MessageStatus::ERROR, 3]],
            $this->statusLayout($result),
        );
        self::assertSame('Ich suche noch einmal.', $records[2]->getContent());
        self::assertNull($records[2]->getCloseReason());
        self::assertNull($records[3]->getContent());
        self::assertSame(new ForcedCloseFailedException()->getMessage(), $records[3]->getError());
    }

    public function testDeadlineAtTheForcedStepSendsTheLimit(): void
    {
        $this->factory->queueToolCall('slow')->queueAnswer('zu spät');

        $result = $this->stream(maxToolCalls: 1, deadlineSeconds: 1, messageId: self::MESSAGE_ID);

        self::assertInstanceOf(TurnDeadlineExceededException::class, $result->failure);
        self::assertTrue($result->isFailed());
        self::assertFalse($result->isAborted());
        self::assertSame([StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::LIMIT], $this->sink->getTypes());
        self::assertSame(2, $this->limitEvent()->payload['step']);
        $this->factory->getChatProvider()->assertCallCount(1);
        self::assertSame(
            [[MessageKind::TOOL_CALL, MessageStatus::COMPLETE, 1], [MessageKind::LIMIT, MessageStatus::COMPLETE, 2], [MessageKind::TEXT, MessageStatus::ERROR, 2]],
            $this->statusLayout($result),
        );
    }

    public function testCancellationBeforeTheForcedInferenceSendsTheLimit(): void
    {
        $cancel = false;
        $this->sink->onSend(static function (StreamEvent $event) use (&$cancel): void {
            $cancel = $cancel || StreamEvent::TOOL_RESULT === $event->type;
        });
        $this->factory->queueToolCall('search', ['query' => 'Wartung'])->queueAnswer('Nie gesendet.');

        $result = $this->stream(static function () use (&$cancel): bool {
            return $cancel;
        }, maxToolCalls: 1, messageId: self::MESSAGE_ID);

        self::assertTrue($result->isAborted());
        self::assertSame([StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::LIMIT], $this->sink->getTypes());
        self::assertNull($result->partialText);
        $this->factory->getChatProvider()->assertCallCount(1);
        self::assertSame(
            [[MessageKind::TOOL_CALL, MessageStatus::COMPLETE, 1], [MessageKind::LIMIT, MessageStatus::COMPLETE, 2], [MessageKind::TEXT, MessageStatus::ABORTED, 2]],
            $this->statusLayout($result),
        );
    }

    public function testCancellationDuringTheForcedStepSendsTheLimitThenTheSentTokens(): void
    {
        $cancel = false;
        $this->factory->setChunkSize(5)->setChunkDelayMs(150)->setChunkHook(static function (int $index) use (&$cancel): void {
            // Only the forced step streams chunks; cancel at its third.
            $cancel = $cancel || $index >= 2;
        });
        $this->factory->queueToolCall('search', ['query' => 'Wartung'])->queueAnswer('Eins zwei drei vier fünf');

        $result = $this->stream(static function () use (&$cancel): bool {
            return $cancel;
        }, maxToolCalls: 1, messageId: self::MESSAGE_ID);

        self::assertTrue($result->isAborted());
        self::assertSame([StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::LIMIT, StreamEvent::TOKEN, StreamEvent::TOKEN], $this->sink->getTypes());
        self::assertSame('Eins zwei ', $this->sink->textOf(StreamEvent::TOKEN));
        // The limit event is not part of the partial text.
        self::assertSame('Eins zwei ', $result->partialText);
        $final = $this->final($result);
        self::assertSame([MessageStatus::ABORTED, 'Eins zwei ', 2], [$final->getStatus(), $final->getContent(), $final->getStep()]);
        self::assertSame(
            [[MessageKind::TOOL_CALL, MessageStatus::COMPLETE, 1], [MessageKind::LIMIT, MessageStatus::COMPLETE, 2], [MessageKind::TEXT, MessageStatus::ABORTED, 2]],
            $this->statusLayout($result),
        );
    }

    public function testEmptyForcedAnswerStillSendsTheLimit(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'Wartung'])->queueAnswer('');

        $result = $this->stream(maxToolCalls: 1, messageId: self::MESSAGE_ID);

        self::assertTrue($result->limitReached);
        self::assertSame([StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::LIMIT], $this->sink->getTypes());
        self::assertSame(self::MESSAGE_ID, $this->limitEvent()->payload['messageId']);
        self::assertSame('', $this->sink->textOf(StreamEvent::TOKEN));
    }

    public function testTextBeforeAToolCallIsFlushedBeforeTheToolEvents(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'Wartung'], text: 'Ich suche.');
        $this->factory->queueAnswer('Alle 500 Stunden.');

        $result = $this->stream();

        self::assertFalse($result->isFailed());
        self::assertSame([StreamEvent::TOKEN, StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::TOKEN], $this->sink->getTypes());
        self::assertSame(['text' => 'Ich suche.'], $this->sink->getEvents()[0]->payload);
        self::assertSame(['text' => 'Alle 500 Stunden.'], $this->sink->getEvents()[3]->payload);
        self::assertNotContains(StreamEvent::LIMIT, $this->sink->getTypes());

        $records = TurnRecorder::assistantRecords(new Conversation(), $result);
        self::assertSame([[MessageKind::TEXT, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::TEXT, 2]], $this->layout($result));
        self::assertSame([MessageStatus::COMPLETE, 'Ich suche.'], [$records[0]->getStatus(), $records[0]->getContent()]);
    }

    private static function newFactory(): FakeProviderFactory
    {
        return new FakeProviderFactory(new EmbeddingModelRegistry([
            'nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768],
        ]));
    }

    /**
     * @param (\Closure(): bool)|null $isCancelled
     */
    private function stream(?\Closure $isCancelled = null, ?int $maxToolCalls = null, int $deadlineSeconds = 120, ?string $messageId = null): TurnResult
    {
        $tools = [
            $this->searchTool(),
            new CallbackTool('broken', 'Always fails', static fn (): never => throw new \RuntimeException('Index offline')),
            new CallbackTool('slow', 'Passes a one-second deadline', static function (): string {
                usleep(1_100_000);

                return 'Langsam';
            }),
        ];

        return new TurnRunner($this->factory, new StructuredAnswerValidator(), deadlineSeconds: $deadlineSeconds)->stream(new TurnRequest(
            provider: 'anthropic',
            connection: ProviderConnectionFixtures::anthropic(),
            model: FakeProviderFactory::ANTHROPIC_MODEL,
            content: 'Frage',
            tools: $tools,
            maxToolCalls: $maxToolCalls,
        ), $this->sink, $isCancelled, $messageId);
    }

    private function searchTool(): CallbackTool
    {
        return new CallbackTool('search', 'Full-text search', function (): string {
            $this->toolRan = true;
            ++$this->searchRuns;

            return 'Treffer';
        });
    }

    /**
     * A first response with one `search` call, as a prepared message, so
     * that a following queueToolCall() starts a response of its own (the
     * forced step).
     */
    private function queueFirstSearch(): void
    {
        $this->factory->getChatProvider()->addResponses(new ToolCallMessage(null, [
            ToolCall::make(name: 'search', callId: 'call_first', inputs: ['query' => 'Wartung'], description: 'Full-text search'),
        ]));
    }

    private function limitEvent(): StreamEvent
    {
        $limits = array_values(array_filter($this->sink->getEvents(), static fn (StreamEvent $e): bool => StreamEvent::LIMIT === $e->type));
        self::assertCount(1, $limits);

        return $limits[0];
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
     * Events without what differs per run anyway (tool durations).
     *
     * @param list<StreamEvent> $events
     *
     * @return list<array{string, string}>
     */
    private static function comparable(array $events): array
    {
        return array_map(static function (StreamEvent $e): array {
            $payload = $e->payload;
            unset($payload['durationMs']);

            return [$e->type, (string) json_encode($payload)];
        }, $events);
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
     * @return list<array{string, string, int}>
     */
    private function statusLayout(TurnResult $result): array
    {
        return array_map(static fn (Message $r): array => [$r->getKind(), $r->getStatus(), $r->getStep()], TurnRecorder::assistantRecords(new Conversation(), $result));
    }

    /**
     * @return list<array{string, int}>
     */
    private function layout(TurnResult $result): array
    {
        return array_map(static fn (Message $r): array => [$r->getKind(), $r->getStep()], TurnRecorder::assistantRecords(new Conversation(), $result));
    }

    private function final(TurnResult $result): Message
    {
        $records = TurnRecorder::assistantRecords(new Conversation(), $result);

        return $records[array_key_last($records)];
    }
}

// - revised 2026-10-14
