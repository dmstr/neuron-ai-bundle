<?php

// file generated with AI assistance: Claude Code - 2026-10-02 16:00:57 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Stream;

use Dmstr\NeuronAiBundle\Stream\StreamEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the stream vocabulary: the fourteen types of the
 * contract are known, only `done` and `error` end a stream,
 * `terminal_tool`, `turn_limit`, `rule`, `frame`, `gate` and `follow_ups`
 * are sent types, and nothing is reserved any more.
 */
final class StreamEventTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}> type => [type, terminal]
     */
    public static function types(): iterable
    {
        yield 'sources' => [StreamEvent::SOURCES, false];
        yield 'reasoning' => [StreamEvent::REASONING, false];
        yield 'token' => [StreamEvent::TOKEN, false];
        yield 'tool_call' => [StreamEvent::TOOL_CALL, false];
        yield 'tool_result' => [StreamEvent::TOOL_RESULT, false];
        yield 'limit' => [StreamEvent::LIMIT, false];
        yield 'done' => [StreamEvent::DONE, true];
        yield 'error' => [StreamEvent::ERROR, true];
        yield 'terminal_tool' => [StreamEvent::TERMINAL_TOOL, false];
        yield 'turn_limit' => [StreamEvent::TURN_LIMIT, false];
        yield 'rule' => [StreamEvent::RULE, false];
        yield 'frame' => [StreamEvent::FRAME, false];
        yield 'gate' => [StreamEvent::GATE, false];
        yield 'follow_ups' => [StreamEvent::FOLLOW_UPS, false];
    }

    #[DataProvider('types')]
    public function testTypeIsKnownAndTerminalOnlyForDoneAndError(string $type, bool $terminal): void
    {
        self::assertTrue(StreamEvent::isKnownType($type));
        self::assertSame($terminal, new StreamEvent($type, [])->isTerminal());
    }

    public function testConstantValues(): void
    {
        self::assertSame(
            ['sources', 'reasoning', 'token', 'tool_call', 'tool_result', 'limit', 'done', 'error', 'terminal_tool', 'turn_limit', 'rule', 'frame', 'gate', 'follow_ups'],
            [
                StreamEvent::SOURCES, StreamEvent::REASONING, StreamEvent::TOKEN, StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT,
                StreamEvent::LIMIT, StreamEvent::DONE, StreamEvent::ERROR, StreamEvent::TERMINAL_TOOL, StreamEvent::TURN_LIMIT,
                StreamEvent::RULE, StreamEvent::FRAME, StreamEvent::GATE, StreamEvent::FOLLOW_UPS,
            ],
        );
    }

    /**
     * The vocabulary is exactly the fourteen types of {@see types()}, no more.
     */
    public function testVocabularyHasFourteenTypes(): void
    {
        $known = new \ReflectionClassConstant(StreamEvent::class, 'TYPES')->getValue();
        $expected = array_map(static fn (array $row): string => $row[0], iterator_to_array(self::types()));

        self::assertCount(14, $known);
        self::assertEqualsCanonicalizing(array_values($expected), $known);
    }

    public function testFrameIsASentNonTerminalType(): void
    {
        self::assertNotContains(StreamEvent::FRAME, StreamEvent::RESERVED);
        self::assertTrue(StreamEvent::isKnownType(StreamEvent::FRAME));

        $event = new StreamEvent(StreamEvent::FRAME, [
            'messageId' => '00000000-0000-0000-0000-000000000000',
            'prefix' => 'Answer: ',
            'suffix' => '',
        ]);
        self::assertFalse($event->isTerminal());
        self::assertSame(9, $event->withId(9)->id);
        self::assertSame($event->payload, $event->withId(9)->payload);
    }

    public function testNothingIsReserved(): void
    {
        self::assertSame([], StreamEvent::RESERVED);
    }

    public function testFollowUpsIsASentNonTerminalTypeAndItsFactoryCarriesEveryKey(): void
    {
        self::assertNotContains(StreamEvent::FOLLOW_UPS, StreamEvent::RESERVED);
        self::assertTrue(StreamEvent::isKnownType(StreamEvent::FOLLOW_UPS));

        $event = StreamEvent::followUps('00000000-0000-0000-0000-000000000000', ['Was kostet eine Wartung?', 'Wer wartet?']);

        self::assertSame(StreamEvent::FOLLOW_UPS, $event->type);
        self::assertSame([
            'messageId' => '00000000-0000-0000-0000-000000000000',
            'followUps' => ['Was kostet eine Wartung?', 'Wer wartet?'],
        ], $event->payload);
        self::assertFalse($event->isTerminal());
        self::assertNull($event->id);
        self::assertSame(6, $event->withId(6)->id);
        self::assertSame($event->payload, $event->withId(6)->payload);
    }

    public function testGateFactoryCarriesEveryKey(): void
    {
        $event = StreamEvent::gate('00000000-0000-0000-0000-000000000000', 'injection', 'de', 'Dazu kann ich nichts sagen.');

        self::assertSame(StreamEvent::GATE, $event->type);
        self::assertSame([
            'messageId' => '00000000-0000-0000-0000-000000000000',
            'gate' => 'injection',
            'language' => 'de',
            'message' => 'Dazu kann ich nichts sagen.',
        ], $event->payload);
        self::assertFalse($event->isTerminal());
        self::assertNull($event->id);
        self::assertSame(5, $event->withId(5)->id);
    }

    public function testNewTypeNamesFitTheColumn(): void
    {
        self::assertLessThanOrEqual(20, \strlen(StreamEvent::GATE));
        self::assertLessThanOrEqual(20, \strlen(StreamEvent::FOLLOW_UPS));
    }

    public function testRuleIsASentNonTerminalType(): void
    {
        self::assertNotContains(StreamEvent::RULE, StreamEvent::RESERVED);
        self::assertTrue(StreamEvent::isKnownType(StreamEvent::RULE));

        $event = new StreamEvent(StreamEvent::RULE, [
            'messageId' => '00000000-0000-0000-0000-000000000000',
            'index' => 0,
            'rule' => 'off-topic',
            'match' => 'keywords',
            'outcome' => 'refuse',
            'template' => 'refusal',
            'language' => 'en',
            'message' => 'I cannot help with that.',
        ]);
        self::assertFalse($event->isTerminal());
        self::assertSame(3, $event->withId(3)->id);
        self::assertSame($event->payload, $event->withId(3)->payload);
    }

    public function testTerminalToolIsASentNonTerminalType(): void
    {
        self::assertNotContains(StreamEvent::TERMINAL_TOOL, StreamEvent::RESERVED);
        self::assertTrue(StreamEvent::isKnownType(StreamEvent::TERMINAL_TOOL));

        $event = new StreamEvent(StreamEvent::TERMINAL_TOOL, [
            'messageId' => '00000000-0000-0000-0000-000000000000',
            'step' => 0,
            'name' => 'answer',
            'callId' => 'call-1',
            'kind' => 'text',
            'answer' => 'done',
            'structured' => null,
            'schemaErrors' => null,
        ]);
        self::assertFalse($event->isTerminal());
        self::assertSame(7, $event->withId(7)->id);
        self::assertSame($event->payload, $event->withId(7)->payload);
    }

    public function testTurnLimitIsASentNonTerminalType(): void
    {
        self::assertNotContains(StreamEvent::TURN_LIMIT, StreamEvent::RESERVED);
        self::assertTrue(StreamEvent::isKnownType(StreamEvent::TURN_LIMIT));

        $event = new StreamEvent(StreamEvent::TURN_LIMIT, [
            'messageId' => '00000000-0000-0000-0000-000000000000',
            'step' => 1,
            'reason' => 'User-turn limit of 3 reached: this is the last turn of the conversation; the model was asked to answer.',
            'maxUserTurns' => 3,
            'turn' => 3,
            'mode' => 'tool_choice',
        ]);
        self::assertFalse($event->isTerminal());
        self::assertSame(4, $event->withId(4)->id);
        self::assertSame($event->payload, $event->withId(4)->payload);
    }

    public function testUnknownTypeIsNotKnown(): void
    {
        self::assertFalse(StreamEvent::isKnownType('terminal'));
        self::assertFalse(StreamEvent::isKnownType(''));
    }
}
