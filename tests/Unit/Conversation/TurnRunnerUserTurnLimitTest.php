<?php

// file generated with AI assistance: Claude Code - 2026-10-03 06:59:14 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use Dmstr\NeuronAiBundle\Agent\Template\TemplateSet;
use Dmstr\NeuronAiBundle\Conversation\ForcedClose;
use Dmstr\NeuronAiBundle\Conversation\ForcedCloseFailedException;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Conversation\StructuredAnswerValidator;
use Dmstr\NeuronAiBundle\Conversation\TerminalClose;
use Dmstr\NeuronAiBundle\Conversation\TurnRecorder;
use Dmstr\NeuronAiBundle\Conversation\TurnRequest;
use Dmstr\NeuronAiBundle\Conversation\TurnResult;
use Dmstr\NeuronAiBundle\Conversation\TurnRunner;
use Dmstr\NeuronAiBundle\Conversation\TurnTexts;
use Dmstr\NeuronAiBundle\Conversation\UserTurnLimit;
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
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the user-turn limit in the runner with the
 * bundle fake: the last allowed user turn is forced from its
 * first inference (tool choice `tool:<first terminal>` or `none` where
 * supported, else no tools plus {@see ForcedClose::TURN_LIMIT_INSTRUCTION}),
 * with or without a tool-call bound; the `limit` record with
 * `limit: user_turns` precedes step 1, the text answer carries
 * {@see ForcedClose::REASON_TURN_LIMIT}, a terminal answer keeps
 * {@see TerminalClose::REASON}, a non-terminal call fails the turn before
 * any tool runs, and the stream sends one `turn_limit` event before the
 * first model event on every path. Also the value object's rule.
 */
final class TurnRunnerUserTurnLimitTest extends TestCase
{
    private FakeProviderFactory $factory;

    private Conversation $conversation;

    /** @var list<string> names of the tools that ran, in order */
    private array $ran = [];

    protected function setUp(): void
    {
        $this->factory = new FakeProviderFactory(new EmbeddingModelRegistry([
            'nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768],
        ]));
        $this->conversation = new Conversation();
    }

    public function testFallbackDropsTheToolsAndAppendsTheTurnLimitInstructionOnce(): void
    {
        $this->factory->queueAnswer('Antwort');

        $result = $this->runner()->run($this->request(provider: 'ollama', instructions: 'Sei knapp.'));

        self::assertFalse($result->isFailed());
        self::assertSame('Antwort', $result->answer);
        self::assertEquals(new UserTurnLimit(3, 3), $result->turnLimit);
        self::assertSame([ForcedClose::turnLimitReason(3), ForcedClose::MODE_FALLBACK, 1], [$result->turnLimitReason, $result->turnLimitMode, $result->turnLimitStep]);
        self::assertFalse($result->limitReached);
        self::assertFalse($result->forced);
        self::assertNull($result->limitReason);
        self::assertNull($result->forcedMode);

        $requests = $this->requests();
        self::assertCount(1, $requests);
        self::assertSame([], $requests[0]->tools);
        self::assertNull($requests[0]->toolChoice);
        $prompt = (string) $requests[0]->systemPrompt?->getContent();
        self::assertStringStartsWith('Sei knapp.', $prompt);
        self::assertStringEndsWith(ForcedClose::TURN_LIMIT_INSTRUCTION, $prompt);
        self::assertSame(1, substr_count($prompt, ForcedClose::TURN_LIMIT_INSTRUCTION));
        self::assertStringNotContainsString(ForcedClose::INSTRUCTION, $prompt);

        $records = $this->records($result);
        self::assertSame([[MessageKind::LIMIT, 1], [MessageKind::TEXT, 1]], $this->layout($result));
        $this->assertTurnLimitRecord($records[0], 1, ForcedClose::turnLimitReason(3));
        self::assertSame(['ollama', FakeProviderFactory::OLLAMA_MODEL], [$records[0]->getProvider(), $records[0]->getModel()]);
        self::assertSame([MessageStatus::COMPLETE, 'Antwort', ForcedClose::REASON_TURN_LIMIT], [$records[1]->getStatus(), $records[1]->getContent(), $records[1]->getCloseReason()]);
    }

    public function testProviderSupportingNoneKeepsTheToolsAndResetsTheChoice(): void
    {
        $this->factory->queueAnswer('Antwort');

        $result = $this->runner()->run($this->request());

        self::assertFalse($result->isFailed());
        self::assertSame(ForcedClose::MODE_TOOL_CHOICE, $result->turnLimitMode);
        $requests = $this->requests();
        self::assertCount(1, $requests);
        self::assertTrue(ToolChoice::none()->equals($requests[0]->toolChoice));
        self::assertSame(['search', 'fetch', 'submit', 'finish', 'explode'], self::toolNames($requests[0]));
        self::assertStringContainsString(ForcedClose::TURN_LIMIT_INSTRUCTION, (string) $requests[0]->systemPrompt?->getContent());
        self::assertSame(ForcedClose::REASON_TURN_LIMIT, $this->records($result)[1]->getCloseReason());
        // The choice does not outlive the run.
        self::assertNull($this->factory->getChatProvider()->getToolChoice());

        // The next turn on the same provider is not forced.
        $this->factory->queueToolCall('search', ['query' => 'a'])->queueAnswer('Weiter');
        $next = $this->runner()->run($this->request(limit: null));
        self::assertFalse($next->isFailed());
        self::assertNull($next->turnLimit);
        self::assertSame([[MessageKind::TOOL_CALL, 1], [MessageKind::TEXT, 2]], $this->layout($next));
        self::assertNull($this->records($next)[1]->getCloseReason());
        self::assertSame([null, null], [$this->requests()[1]->toolChoice, $this->requests()[2]->toolChoice]);
    }

    public function testTerminalToolIsTargetedFromTheFirstInference(): void
    {
        $this->queueResponse('finish', 'call_finish');

        $result = $this->runner()->run($this->request(terminalTools: ['finish', 'submit']));

        self::assertFalse($result->isFailed());
        self::assertSame(ForcedClose::MODE_TERMINAL_TOOL, $result->turnLimitMode);
        self::assertSame('finish', $result->terminalCall?->name);
        self::assertSame('Fertig', $result->answer);
        self::assertSame(['finish'], $this->ran);
        $requests = $this->requests();
        self::assertCount(1, $requests);
        self::assertTrue(ToolChoice::tool('finish')->equals($requests[0]->toolChoice));

        $records = $this->records($result);
        self::assertSame([[MessageKind::LIMIT, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::TEXT, 1]], $this->layout($result));
        $this->assertTurnLimitRecord($records[0], 1, ForcedClose::turnLimitReason(3));
        self::assertNull($records[1]->getCloseReason());
        self::assertSame(TerminalClose::REASON, $records[2]->getCloseReason());
        self::assertNull($this->factory->getChatProvider()->getToolChoice());
    }

    /**
     * With a bound of 1 the terminal batch would reach it, but it ends the
     * turn in the terminal tool node, so the bound neither arms nor reports.
     */
    public function testStreamedTerminalAnswerWithABoundSendsNoLimit(): void
    {
        $this->queueResponse('submit', 'call_submit');
        $sink = new RecordingStreamSink();

        $result = $this->runner()->stream($this->request(maxToolCalls: 1, terminalTools: ['submit']), $sink, messageId: 'msg-final');

        self::assertFalse($result->isFailed());
        self::assertFalse($result->limitReached);
        self::assertFalse($result->forced);
        self::assertNull($result->limitReason);
        self::assertSame(ForcedClose::MODE_TERMINAL_TOOL, $result->turnLimitMode);
        self::assertSame([StreamEvent::TURN_LIMIT, StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::TERMINAL_TOOL], $sink->getTypes());
        self::assertSame([
            'messageId' => 'msg-final',
            'step' => 1,
            'reason' => ForcedClose::turnLimitReason(3),
            'maxUserTurns' => 3,
            'turn' => 3,
            'mode' => ForcedClose::MODE_TERMINAL_TOOL,
        ], $sink->getEvents()[0]->payload);
        self::assertSame(['msg-final', 1, 'submit'], [$sink->getEvents()[3]->payload['messageId'], $sink->getEvents()[3]->payload['step'], $sink->getEvents()[3]->payload['name']]);

        $placeholder = TurnRecorder::placeholderRecord($this->conversation, 'anthropic', FakeProviderFactory::ANTHROPIC_MODEL);
        $records = TurnRecorder::assistantRecords($this->conversation, $result, $placeholder);
        self::assertSame([[MessageKind::LIMIT, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::TEXT, 1]], array_map(static fn (Message $r): array => [$r->getKind(), $r->getStep()], $records));
        self::assertSame($placeholder, $records[2]);
        self::assertSame(TerminalClose::REASON, $placeholder->getCloseReason());
        self::assertNull($this->factory->getChatProvider()->getToolChoice());
    }

    /**
     * @return iterable<string, array{string, int|null, list<string>}>
     */
    public static function nonTerminalCalls(): iterable
    {
        yield 'fallback' => ['ollama', null, []];
        yield 'tool choice' => ['anthropic', null, []];
        yield 'tool choice with a bound' => ['anthropic', 1, []];
        yield 'terminal tool with a bound' => ['anthropic', 1, ['submit']];
    }

    /**
     * @param list<string> $terminalTools
     */
    #[DataProvider('nonTerminalCalls')]
    public function testForcedTurnAnsweringWithANonTerminalCallFailsBeforeTheToolRuns(string $provider, ?int $maxToolCalls, array $terminalTools): void
    {
        $this->queueResponse('search', 'call_search');

        $result = $this->runner()->run($this->request(provider: $provider, maxToolCalls: $maxToolCalls, terminalTools: $terminalTools));

        self::assertInstanceOf(ForcedCloseFailedException::class, $result->failure);
        self::assertSame(new ForcedCloseFailedException()->getMessage(), $result->failure->getMessage());
        self::assertSame([], $this->ran);
        self::assertNotNull($result->turnLimit);
        // The turn-limit guard throws before the bound counts the batch.
        self::assertFalse($result->limitReached);
        $this->factory->getChatProvider()->assertCallCount(1);

        $records = $this->records($result);
        self::assertSame(
            [[MessageKind::LIMIT, MessageStatus::COMPLETE, 1], [MessageKind::TEXT, MessageStatus::ERROR, 2]],
            array_map(static fn (Message $r): array => [$r->getKind(), $r->getStatus(), $r->getStep()], $records),
        );
        $this->assertTurnLimitRecord($records[0], 1, ForcedClose::turnLimitReason(3));
        self::assertNull($records[1]->getCloseReason());
        self::assertNull($this->factory->getChatProvider()->getToolChoice());
    }

    /**
     * A failed terminal call hands an inference event back: the turn-limit
     * guard fails the turn before the bound's after() could arm.
     */
    public function testFailedTerminalCallFailsTheTurnBeforeTheBoundArms(): void
    {
        $this->queueResponse('explode', 'call_explode');

        $result = $this->runner()->run($this->request(maxToolCalls: 1, terminalTools: ['explode']));

        self::assertInstanceOf(ForcedCloseFailedException::class, $result->failure);
        self::assertSame('The forced close did not produce an answer: the terminal tool call failed.', $result->failure->getMessage());
        self::assertSame(['explode'], $this->ran);
        self::assertFalse($result->limitReached);
        self::assertNull($result->forcedMode);
        $this->factory->getChatProvider()->assertCallCount(1);
        self::assertSame(
            [[MessageKind::LIMIT, MessageStatus::COMPLETE, 1], [MessageKind::TOOL_CALL, MessageStatus::COMPLETE, 1], [MessageKind::TEXT, MessageStatus::ERROR, 2]],
            array_map(static fn (Message $r): array => [$r->getKind(), $r->getStatus(), $r->getStep()], $this->records($result)),
        );
        self::assertNull($this->factory->getChatProvider()->getToolChoice());
    }

    public function testStreamSendsTheTurnLimitOnceBeforeTheFirstModelEvent(): void
    {
        $this->factory->queueReasoning('Ich überlege.', 'Alle 500 Stunden.');
        $sink = new RecordingStreamSink();

        $result = $this->runner()->stream($this->request(maxToolCalls: 2), $sink, messageId: 'msg-final');

        self::assertFalse($result->isFailed());
        $types = $sink->getTypes();
        self::assertSame(StreamEvent::TURN_LIMIT, $types[0]);
        self::assertSame(1, \count(array_keys($types, StreamEvent::TURN_LIMIT, true)));
        self::assertNotContains(StreamEvent::LIMIT, $types);
        self::assertContains(StreamEvent::REASONING, $types);
        self::assertSame('Alle 500 Stunden.', $sink->textOf(StreamEvent::TOKEN));
        self::assertSame([
            'messageId' => 'msg-final',
            'step' => 1,
            'reason' => ForcedClose::turnLimitReason(3),
            'maxUserTurns' => 3,
            'turn' => 3,
            'mode' => ForcedClose::MODE_TOOL_CHOICE,
        ], $sink->getEvents()[0]->payload);
        self::assertNull($result->partialText);

        $placeholder = TurnRecorder::placeholderRecord($this->conversation, 'anthropic', FakeProviderFactory::ANTHROPIC_MODEL);
        $records = TurnRecorder::assistantRecords($this->conversation, $result, $placeholder);
        self::assertSame([[MessageKind::LIMIT, 1], [MessageKind::REASONING, 1], [MessageKind::TEXT, 1]], array_map(static fn (Message $r): array => [$r->getKind(), $r->getStep()], $records));
        self::assertSame([MessageStatus::COMPLETE, 'Alle 500 Stunden.', ForcedClose::REASON_TURN_LIMIT], [$placeholder->getStatus(), $placeholder->getContent(), $placeholder->getCloseReason()]);
    }

    public function testStreamSendsTheTurnLimitForAnEmptyForcedAnswer(): void
    {
        $this->factory->queueAnswer('');
        $sink = new RecordingStreamSink();

        $result = $this->runner()->stream($this->request(), $sink, messageId: 'msg-final');

        self::assertFalse($result->isFailed());
        self::assertSame([StreamEvent::TURN_LIMIT], $sink->getTypes());
    }

    public function testStreamSendsTheTurnLimitWhenCancelledAtTheForcedStep(): void
    {
        $this->factory->queueAnswer('Zu spät');
        $sink = new RecordingStreamSink();

        $result = $this->runner()->stream($this->request(), $sink, static fn (): bool => true, 'msg-final');

        self::assertTrue($result->isAborted());
        self::assertNull($result->partialText);
        self::assertSame([StreamEvent::TURN_LIMIT], $sink->getTypes());
        $records = TurnRecorder::assistantRecords($this->conversation, $result);
        self::assertSame(
            [[MessageKind::LIMIT, MessageStatus::COMPLETE, 1], [MessageKind::TEXT, MessageStatus::ABORTED, 1]],
            array_map(static fn (Message $r): array => [$r->getKind(), $r->getStatus(), $r->getStep()], $records),
        );
        self::assertNull($records[1]->getCloseReason());
        self::assertNull($this->factory->getChatProvider()->getToolChoice());
    }

    public function testStreamSendsTheTurnLimitBeforeAFailedForcedClose(): void
    {
        $this->queueResponse('search', 'call_search');
        $sink = new RecordingStreamSink();

        $result = $this->runner()->stream($this->request(maxToolCalls: 1), $sink, messageId: 'msg-final');

        self::assertInstanceOf(ForcedCloseFailedException::class, $result->failure);
        self::assertSame([StreamEvent::TURN_LIMIT], $sink->getTypes());
    }

    /**
     * With a `turnLimitReason` template the
     * `limit` record, the result and the `turn_limit` event carry the
     * rendered text (default language when the effective one has none).
     */
    public function testTurnLimitReasonTemplateIsRenderedInRecordResultAndEvent(): void
    {
        $this->factory->queueAnswer('Antwort');
        $sink = new RecordingStreamSink();
        $texts = new TurnTexts(new TemplateSet(['turnLimitReason' => [
            'de' => 'Letzte Runde ({{turn}}/{{maxUserTurns}}).',
        ]], 'de'), 'en');
        $request = $this->request(limit: new UserTurnLimit(5, 5));
        $request = new TurnRequest($request->provider, $request->model, $request->content, tools: $request->tools, userTurnLimit: $request->userTurnLimit, texts: $texts, connection: $request->connection);

        $result = $this->runner()->stream($request, $sink, messageId: 'msg-final');

        self::assertSame('Letzte Runde (5/5).', $result->turnLimitReason);
        $this->assertTurnLimitRecord($this->records($result)[0], 1, 'Letzte Runde (5/5).');
        self::assertSame(StreamEvent::TURN_LIMIT, $sink->getEvents()[0]->type);
        self::assertSame('Letzte Runde (5/5).', $sink->getEvents()[0]->payload['reason']);
    }

    public function testToolLessTurnUsesNoneWhereSupported(): void
    {
        $this->factory->queueAnswer('Antwort');

        $result = $this->runner()->run($this->request(tools: false));

        self::assertSame(ForcedClose::MODE_TOOL_CHOICE, $result->turnLimitMode);
        self::assertTrue(ToolChoice::none()->equals($this->requests()[0]->toolChoice));
        self::assertSame(ForcedClose::REASON_TURN_LIMIT, $this->records($result)[1]->getCloseReason());
    }

    public function testToolLessTurnWithoutInstructionsGetsTheFallbackInstruction(): void
    {
        $this->factory->queueAnswer('Antwort');

        $result = $this->runner()->run($this->request(provider: 'ollama', tools: false));

        self::assertFalse($result->isFailed());
        self::assertSame(ForcedClose::MODE_FALLBACK, $result->turnLimitMode);
        $prompt = (string) $this->requests()[0]->systemPrompt?->getContent();
        self::assertStringEndsWith(ForcedClose::TURN_LIMIT_INSTRUCTION, $prompt);
        self::assertSame(1, substr_count($prompt, ForcedClose::TURN_LIMIT_INSTRUCTION));
    }

    public function testRequestWithoutALimitIsNotForced(): void
    {
        $this->factory->queueToolCall('search', ['query' => 'a'])->queueAnswer('Antwort');
        $sink = new RecordingStreamSink();

        $result = $this->runner()->stream($this->request(limit: null), $sink, messageId: 'msg-final');

        self::assertFalse($result->isFailed());
        self::assertNull($result->turnLimit);
        self::assertNull($result->turnLimitReason);
        self::assertNull($result->turnLimitMode);
        self::assertNull($result->turnLimitStep);
        self::assertSame(['search'], $this->ran);
        self::assertNotContains(StreamEvent::TURN_LIMIT, $sink->getTypes());
        self::assertSame([null, null], array_map(static fn (ToolChoiceRequestRecord $r): ?ToolChoice => $r->toolChoice, $this->requests()));
    }

    public function testArmAppendsTheGivenInstructionOnce(): void
    {
        $provider = $this->factory->chatProvider(ProviderConnectionFixtures::ollama(), FakeProviderFactory::OLLAMA_MODEL);
        $request = new InferenceRequest('Sei knapp.');
        $sent = $request->instructions;
        $tools = new ToolRegistry([new CallbackTool('search', 'Search', static fn (): string => '')]);

        $first = ForcedClose::arm($request, $tools, $provider, instruction: ForcedClose::TURN_LIMIT_INSTRUCTION);
        $second = ForcedClose::arm($request, $tools, $provider, instruction: ForcedClose::TURN_LIMIT_INSTRUCTION);

        self::assertSame([ForcedClose::MODE_FALLBACK, ForcedClose::MODE_FALLBACK], [$first, $second]);
        self::assertSame("Sei knapp.\n\n".ForcedClose::TURN_LIMIT_INSTRUCTION, $request->instructions->getContent());
        self::assertSame([], $tools->all());
        // The instructions an earlier inference sent stay unchanged.
        self::assertSame('Sei knapp.', $sent->getContent());

        // The default stays the bound's instruction.
        $bound = new InferenceRequest('');
        ForcedClose::arm($bound, new ToolRegistry(), $provider);
        self::assertSame(ForcedClose::INSTRUCTION, $bound->instructions->getContent());
    }

    public function testConstantValues(): void
    {
        self::assertSame('user_turn_limit', ForcedClose::REASON_TURN_LIMIT);
        self::assertSame('This is the last turn of this conversation; the user cannot reply again. Do not call any tool; give a complete, final answer now.', ForcedClose::TURN_LIMIT_INSTRUCTION);
        self::assertSame('User-turn limit of 4 reached: this is the last turn of the conversation; the model was asked to answer.', ForcedClose::turnLimitReason(4));
    }

    public function testWithContextKeepsTheUserTurnLimit(): void
    {
        $limit = new UserTurnLimit(2, 2);
        self::assertSame($limit, $this->request(limit: $limit)->withContext([])->userTurnLimit);
        self::assertNull($this->request(limit: null)->withContext([])->userTurnLimit);
        self::assertNull(new TurnRequest('anthropic', FakeProviderFactory::ANTHROPIC_MODEL, 'Frage')->userTurnLimit);
    }

    /**
     * @return iterable<string, array{int|null, int, array{int, int}|null}>
     */
    public static function nextTurns(): iterable
    {
        yield 'last of three' => [3, 2, [3, 3]];
        yield 'one left after this' => [3, 1, null];
        yield 'single turn' => [1, 0, [1, 1]];
        yield 'unbounded' => [null, 5, null];
        yield 'over the limit' => [2, 5, [2, 6]];
    }

    /**
     * @param array{int, int}|null $expected [max, turn]
     */
    #[DataProvider('nextTurns')]
    public function testForNextTurn(?int $maxUserTurns, int $turnCount, ?array $expected): void
    {
        $limit = UserTurnLimit::forNextTurn($maxUserTurns, $turnCount);

        self::assertSame($expected, null !== $limit ? [$limit->max, $limit->turn] : null);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalidLimits(): iterable
    {
        yield 'max zero' => [0, 1];
        yield 'max negative' => [-1, 1];
        yield 'turn zero' => [3, 0];
    }

    #[DataProvider('invalidLimits')]
    public function testConstructorRejectsValuesBelowOne(int $max, int $turn): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new UserTurnLimit($max, $turn);
    }

    private function runner(): TurnRunner
    {
        return new TurnRunner($this->factory, new StructuredAnswerValidator());
    }

    /**
     * Tools: `search`, `fetch` (plain), `submit` (`Antwort`), `finish`
     * (`Fertig`), `explode` (throws); $tools false = none. $limit defaults
     * to the last of three turns.
     *
     * @param list<string> $terminalTools
     */
    private function request(
        string $provider = 'anthropic',
        ?int $maxToolCalls = null,
        array $terminalTools = [],
        ?string $instructions = null,
        bool $tools = true,
        ?UserTurnLimit $limit = new UserTurnLimit(3, 3),
    ): TurnRequest {
        return new TurnRequest(
            provider: $provider,
            connection: 'ollama' === $provider ? ProviderConnectionFixtures::ollama() : ProviderConnectionFixtures::anthropic(),
            model: 'ollama' === $provider ? FakeProviderFactory::OLLAMA_MODEL : FakeProviderFactory::ANTHROPIC_MODEL,
            content: 'Frage',
            tools: $tools ? array_map($this->tool(...), ['search', 'fetch', 'submit', 'finish', 'explode']) : [],
            instructions: $instructions,
            maxToolCalls: $maxToolCalls,
            terminalTools: $terminalTools,
            userTurnLimit: $limit,
        );
    }

    private function tool(string $name): CallbackTool
    {
        return new CallbackTool($name, 'Test tool '.$name, function () use ($name): string {
            $this->ran[] = $name;

            return match ($name) {
                'submit' => 'Antwort',
                'finish' => 'Fertig',
                'explode' => throw new \RuntimeException('Kaputt'),
                default => 'Ergebnis '.$name,
            };
        });
    }

    /**
     * Queues a provider response with one call of $name; independent of
     * the tools configured at call time (the fallback drops them).
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

    private function assertTurnLimitRecord(Message $record, int $step, string $reason): void
    {
        self::assertSame(
            [Message::SPEAKER_ASSISTANT, MessageKind::LIMIT, MessageStatus::COMPLETE, $step, $reason],
            [$record->getRole(), $record->getKind(), $record->getStatus(), $record->getStep(), $record->getContent()],
        );
        self::assertSame(['step' => $step, 'part' => 'limit', 'limit' => 'user_turns'], $record->getProviderPayload());
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
}

// - revised 2026-10-14
