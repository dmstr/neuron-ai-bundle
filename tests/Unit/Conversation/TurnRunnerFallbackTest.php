<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:23:32 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use Dmstr\NeuronAiBundle\Agent\Template\TemplateSet;
use Dmstr\NeuronAiBundle\Conversation\ForcedClose;
use Dmstr\NeuronAiBundle\Conversation\ForcedCloseFailedException;
use Dmstr\NeuronAiBundle\Conversation\HistoryReconstructor;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Conversation\StructuredAnswerValidator;
use Dmstr\NeuronAiBundle\Conversation\TurnDeadlineExceededException;
use Dmstr\NeuronAiBundle\Conversation\TurnRecorder;
use Dmstr\NeuronAiBundle\Conversation\TurnRequest;
use Dmstr\NeuronAiBundle\Conversation\TurnResult;
use Dmstr\NeuronAiBundle\Conversation\TurnRunner;
use Dmstr\NeuronAiBundle\Conversation\TurnTexts;
use Dmstr\NeuronAiBundle\Conversation\UserTurnLimit;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Stream\StreamEvent;
use Dmstr\NeuronAiBundle\Testing\CallbackTool;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Testing\RecordingStreamSink;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the forced-close fallback with the bundle
 * fake: a forced close that answers with a
 * non-terminal tool call ({@see ForcedCloseFailedException}) or with an
 * empty text is answered with the version's `forcedCloseFallback`: one
 * `text`/`complete` final record with the forced close reason and the
 * payload `{step, part: 'fallback', mode}` without `message`, no `error`
 * record, one `token` event after `limit`/`turn_limit` in the stream, and
 * the turn is not replayed. Without the template, and for any other
 * failure, nothing changes.
 */
final class TurnRunnerFallbackTest extends TestCase
{
    private const string FALLBACK = 'Leider konnte ich das nicht beantworten, Kim.';

    private FakeProviderFactory $factory;

    private Conversation $conversation;

    /** @var list<string> names of the tools that ran, in order */
    private array $ran = [];

    protected function setUp(): void
    {
        $this->factory = self::newFactory();
        $this->conversation = new Conversation();
    }

    public function testFailedForcedCloseOfTheBoundIsAnsweredByTheFallback(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a']);
        $this->queueForcedCall('fetch');

        $result = $this->runner()->run($this->request(maxToolCalls: 1));

        self::assertNull($result->failure);
        self::assertTrue($result->fallbackAnswer);
        self::assertSame(self::FALLBACK, $result->answer);
        self::assertTrue($result->limitReached);
        self::assertTrue($result->forced);
        self::assertSame(['search'], $this->ran);

        $records = $this->records($result);
        self::assertSame(
            [[MessageKind::TOOL_CALL, MessageStatus::COMPLETE, 1], [MessageKind::LIMIT, MessageStatus::COMPLETE, 2], [MessageKind::TEXT, MessageStatus::COMPLETE, 2]],
            array_map(static fn (Message $r): array => [$r->getKind(), $r->getStatus(), $r->getStep()], $records),
        );
        $this->assertFallbackRecord($records[2], 2, ForcedClose::REASON_BOUND, ForcedClose::MODE_TOOL_CHOICE);
    }

    public function testEmptyForcedAnswerOfTheBoundIsReplacedByTheFallback(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a'])->queueAnswer('');

        $result = $this->runner()->run($this->request(maxToolCalls: 1));

        self::assertFalse($result->isFailed());
        self::assertTrue($result->fallbackAnswer);
        self::assertTrue($result->forced);
        self::assertSame(self::FALLBACK, $result->answer);

        $records = $this->records($result);
        self::assertSame([[MessageKind::TOOL_CALL, 1], [MessageKind::LIMIT, 2], [MessageKind::TEXT, 2]], self::layout($records));
        $this->assertFallbackRecord($records[2], 2, ForcedClose::REASON_BOUND, ForcedClose::MODE_TOOL_CHOICE);
    }

    public function testAnAnswerCutOffWhileThinkingFailsInsteadOfBeingEmpty(): void
    {
        $this->factory->queueThinkingCutOff('Ich überlege …');

        $result = $this->runner()->run($this->request());

        self::assertTrue($result->isFailed());
        self::assertNull($result->answer);
        self::assertStringContainsString('reached maxTokens', $result->failure?->getMessage() ?? '');
    }

    public function testFailedForcedCloseOfTheLastUserTurnIsAnsweredByTheFallback(): void
    {
        $this->queueForcedCall('search');

        $result = $this->runner()->run($this->request(provider: 'ollama', limit: new UserTurnLimit(3, 3)));

        self::assertNull($result->failure);
        self::assertTrue($result->fallbackAnswer);
        self::assertNotNull($result->turnLimit);
        self::assertSame([], $this->ran);

        $records = $this->records($result);
        self::assertSame([[MessageKind::LIMIT, 1], [MessageKind::TEXT, 1]], self::layout($records));
        $this->assertFallbackRecord($records[1], 1, ForcedClose::REASON_TURN_LIMIT, ForcedClose::MODE_FALLBACK);
    }

    public function testEmptyForcedAnswerOfTheLastUserTurnIsReplacedByTheFallback(): void
    {
        $this->factory->queueAnswer('');

        $result = $this->runner()->run($this->request(limit: new UserTurnLimit(3, 3), maxToolCalls: 2));

        self::assertTrue($result->fallbackAnswer);
        $records = $this->records($result);
        self::assertSame([[MessageKind::LIMIT, 1], [MessageKind::TEXT, 1]], self::layout($records));
        $this->assertFallbackRecord($records[1], 1, ForcedClose::REASON_TURN_LIMIT, ForcedClose::MODE_TOOL_CHOICE);
    }

    /**
     * Without a `forcedCloseFallback` template (no templates, or templates
     * without the slot) the failed and the empty forced close are exactly
     * today's records.
     */
    public function testWithoutAFallbackTemplateNothingChanges(): void
    {
        foreach ([new TurnTexts(), new TurnTexts(new TemplateSet(['refusal' => ['de' => 'Nein.']], 'de'), 'de')] as $texts) {
            $this->factory = self::newFactory();
            $this->factory->queueToolCall('search', ['query' => 'a']);
            $this->queueForcedCall('fetch');
            $failed = $this->runner()->run($this->request(maxToolCalls: 1, texts: $texts));

            self::assertInstanceOf(ForcedCloseFailedException::class, $failed->failure);
            self::assertFalse($failed->fallbackAnswer);
            self::assertSame(
                [[MessageKind::TOOL_CALL, MessageStatus::COMPLETE, 1], [MessageKind::LIMIT, MessageStatus::COMPLETE, 2], [MessageKind::TEXT, MessageStatus::ERROR, 3]],
                array_map(static fn (Message $r): array => [$r->getKind(), $r->getStatus(), $r->getStep()], $this->records($failed)),
            );

            $this->factory->queueToolCall('search', ['query' => 'a'])->queueAnswer('');
            $empty = $this->runner()->run($this->request(maxToolCalls: 1, texts: $texts));

            self::assertFalse($empty->isFailed());
            self::assertFalse($empty->fallbackAnswer);
            self::assertSame('', $empty->answer);
            $final = $this->records($empty)[2];
            self::assertSame(['text', MessageStatus::COMPLETE, ForcedClose::REASON_BOUND], [$final->getProviderPayload()['part'], $final->getStatus(), $final->getCloseReason()]);
            self::assertArrayHasKey('message', $final->getProviderPayload());
        }
    }

    public function testOtherFailuresOfTheForcedStepKeepTheErrorPath(): void
    {
        $this->factory->queueToolCall('slow')->queueAnswer('zu spät');

        $result = $this->runner(deadlineSeconds: 1)->run($this->request(maxToolCalls: 1));

        self::assertInstanceOf(TurnDeadlineExceededException::class, $result->failure);
        self::assertFalse($result->fallbackAnswer);
        self::assertSame(MessageStatus::ERROR, $this->records($result)[2]->getStatus());
    }

    /**
     * Consecutive queueToolCall() calls form one response with parallel
     * calls: both run in the first batch, so the forced step finds the
     * script empty and the provider fails. That is a provider error, not a
     * failed forced close: it keeps the error path. A forced step that calls
     * a tool again must be queued as its own response
     * ({@see queueForcedCall()}).
     */
    public function testConsecutivelyQueuedCallsAreOneBatchAndAnEmptyScriptKeepsTheErrorPath(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a'])->queueToolCall('search', ['query' => 'b']);

        $result = $this->runner()->run($this->request(maxToolCalls: 1));

        self::assertSame(['search', 'search'], $this->ran);
        self::assertInstanceOf(ProviderException::class, $result->failure);
        self::assertStringContainsString('response queue is empty', $result->failure->getMessage());
        self::assertFalse($result->fallbackAnswer);
        self::assertTrue($result->limitReached);
        $records = $this->records($result);
        self::assertSame(MessageStatus::ERROR, $records[array_key_last($records)]->getStatus());
    }

    public function testUnforcedAnswersAreNotReplaced(): void
    {
        $this->factory->queueAnswer('');

        $result = $this->runner()->run($this->request());

        self::assertFalse($result->fallbackAnswer);
        self::assertSame('', $result->answer);
    }

    public function testStreamedFailedForcedCloseSendsTheFallbackAsOneTokenAfterTheLimit(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a']);
        $this->queueForcedCall('fetch');
        $sink = new RecordingStreamSink();

        $result = $this->runner()->stream($this->request(maxToolCalls: 1), $sink, messageId: 'msg-final');

        self::assertNull($result->failure);
        self::assertNull($result->partialText);
        self::assertSame([StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::LIMIT, StreamEvent::TOKEN], $sink->getTypes());
        self::assertSame(['text' => self::FALLBACK], $sink->getEvents()[3]->payload);

        $placeholder = TurnRecorder::placeholderRecord($this->conversation, 'anthropic', FakeProviderFactory::ANTHROPIC_MODEL);
        $records = TurnRecorder::assistantRecords($this->conversation, $result, $placeholder);
        self::assertSame([[MessageKind::TOOL_CALL, 1], [MessageKind::LIMIT, 2], [MessageKind::TEXT, 2]], self::layout($records));
        self::assertSame($placeholder, $records[2]);
        $this->assertFallbackRecord($placeholder, 2, ForcedClose::REASON_BOUND, ForcedClose::MODE_TOOL_CHOICE);
    }

    public function testStreamedEmptyForcedAnswerOfTheLastUserTurnSendsTheFallbackAfterTheTurnLimit(): void
    {
        $this->factory->queueAnswer('');
        $sink = new RecordingStreamSink();

        $result = $this->runner()->stream($this->request(limit: new UserTurnLimit(3, 3)), $sink, messageId: 'msg-final');

        self::assertTrue($result->fallbackAnswer);
        self::assertSame([StreamEvent::TURN_LIMIT, StreamEvent::TOKEN], $sink->getTypes());
        self::assertSame(self::FALLBACK, $sink->textOf(StreamEvent::TOKEN));
    }

    /**
     * Text the forced step streamed before its tool call stays where it
     * was sent; the fallback is the run's last event.
     */
    public function testStreamedTextBeforeAFailedForcedCallPrecedesTheFallback(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a'], text: 'Ich suche noch.');
        $sink = new RecordingStreamSink();

        $result = $this->runner()->stream($this->request(limit: new UserTurnLimit(2, 2)), $sink, messageId: 'msg-final');

        self::assertTrue($result->fallbackAnswer);
        $types = $sink->getTypes();
        self::assertSame(StreamEvent::TURN_LIMIT, $types[0]);
        self::assertSame(StreamEvent::TOKEN, $types[array_key_last($types)]);
        self::assertSame(['text' => self::FALLBACK], $sink->getEvents()[array_key_last($types)]->payload);
        self::assertSame('Ich suche noch.'.self::FALLBACK, $sink->textOf(StreamEvent::TOKEN));

        $records = $this->records($result);
        self::assertSame([[MessageKind::LIMIT, 1], [MessageKind::TEXT, 1], [MessageKind::TEXT, 1]], self::layout($records));
        self::assertSame('Ich suche noch.', $records[1]->getContent());
        $this->assertFallbackRecord($records[2], 1, ForcedClose::REASON_TURN_LIMIT, ForcedClose::MODE_TOOL_CHOICE);
        self::assertSame([], $this->replayed($result));
    }

    public function testStreamWithoutAFallbackTemplateSendsNoToken(): void
    {
        $this->factory->queueAnswer('');
        $sink = new RecordingStreamSink();

        $result = $this->runner()->stream($this->request(limit: new UserTurnLimit(3, 3), texts: new TurnTexts()), $sink, messageId: 'msg-final');

        self::assertFalse($result->fallbackAnswer);
        self::assertSame([StreamEvent::TURN_LIMIT], $sink->getTypes());
    }

    /**
     * A fallback-answered turn is not replayed to the model,
     * neither the failed nor the empty forced close; a normal turn before
     * it is.
     */
    public function testFallbackTurnsAreNotReplayed(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a']);
        $this->queueForcedCall('fetch');
        $failed = $this->runner()->run($this->request(maxToolCalls: 1));

        $this->factory->queueToolCall('search', ['query' => 'b'])->queueAnswer('');
        $empty = $this->runner()->run($this->request(maxToolCalls: 1));

        $this->factory->queueAnswer('Antwort');
        $normal = $this->runner()->run($this->request());

        self::assertSame([], $this->replayed($failed));
        self::assertSame([], $this->replayed($empty));
        $messages = $this->replayed($normal, $failed, $empty);
        self::assertSame([UserMessage::class, AssistantMessage::class], array_map(static fn (object $m): string => $m::class, $messages));
        self::assertSame('Antwort', $messages[1]->getContent());
    }

    public function testWithContextKeepsTheTexts(): void
    {
        $texts = self::texts();

        self::assertSame($texts, $this->request(texts: $texts)->withContext([])->texts);
        self::assertNull(new TurnRequest('anthropic', FakeProviderFactory::ANTHROPIC_MODEL, 'Frage')->texts->fallback());
    }

    private static function newFactory(): FakeProviderFactory
    {
        return new FakeProviderFactory(new EmbeddingModelRegistry([
            'nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768],
        ]));
    }

    private static function texts(): TurnTexts
    {
        return new TurnTexts(
            new TemplateSet(['forcedCloseFallback' => ['de' => 'Leider konnte ich das nicht beantworten, {{customer}}.']], 'de'),
            'de',
            ['customer' => 'Kim'],
        );
    }

    private function runner(int $deadlineSeconds = 120): TurnRunner
    {
        return new TurnRunner($this->factory, new StructuredAnswerValidator(), deadlineSeconds: $deadlineSeconds);
    }

    private function request(string $provider = 'anthropic', ?int $maxToolCalls = null, ?UserTurnLimit $limit = null, ?TurnTexts $texts = null): TurnRequest
    {
        return new TurnRequest(
            provider: $provider,
            connection: 'ollama' === $provider ? ProviderConnectionFixtures::ollama() : ProviderConnectionFixtures::anthropic(),
            model: 'ollama' === $provider ? FakeProviderFactory::OLLAMA_MODEL : FakeProviderFactory::ANTHROPIC_MODEL,
            content: 'Frage',
            tools: array_map($this->tool(...), ['search', 'fetch', 'slow']),
            maxToolCalls: $maxToolCalls,
            userTurnLimit: $limit,
            texts: $texts ?? self::texts(),
        );
    }

    private function tool(string $name): CallbackTool
    {
        return new CallbackTool($name, 'Test tool '.$name, function () use ($name): string {
            $this->ran[] = $name;
            if ('slow' === $name) {
                // Passes the one-second deadline before the forced step.
                usleep(1_100_000);
            }

            return 'Ergebnis '.$name;
        });
    }

    /**
     * Queues the forced step's response: one call of $name, as its own
     * response (independent of the tools configured at call time).
     */
    private function queueForcedCall(string $name): void
    {
        $this->factory->getChatProvider()->addResponses(new ToolCallMessage(null, [ToolCall::make(name: $name, callId: 'call_forced')]));
    }

    private function assertFallbackRecord(Message $record, int $step, string $closeReason, string $mode): void
    {
        self::assertSame(
            [Message::SPEAKER_ASSISTANT, MessageKind::TEXT, MessageStatus::COMPLETE, $step, self::FALLBACK, $closeReason, null],
            [$record->getRole(), $record->getKind(), $record->getStatus(), $record->getStep(), $record->getContent(), $record->getCloseReason(), $record->getError()],
        );
        self::assertSame(['step' => $step, 'part' => 'fallback', 'mode' => $mode], $record->getProviderPayload());
        self::assertSame([], $record->getSources());
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
     * @return list<array{string, int}>
     */
    private static function layout(array $records): array
    {
        return array_map(static fn (Message $r): array => [$r->getKind(), $r->getStep()], $records);
    }

    /**
     * The history the next turn would get after the turns of $results.
     *
     * @return list<\NeuronAI\Chat\Messages\Message>
     */
    private function replayed(TurnResult ...$results): array
    {
        $records = [];
        foreach ($results as $result) {
            $records[] = TurnRecorder::userRecord($this->conversation, $result->request->content);
            array_push($records, ...$this->records($result));
        }

        return new HistoryReconstructor()->reconstruct($records);
    }
}

// - revised 2026-10-14
