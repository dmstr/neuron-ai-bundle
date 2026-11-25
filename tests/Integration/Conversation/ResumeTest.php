<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:21:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Conversation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Conversation\ForcedClose;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Conversation\StreamResumer;
use Dmstr\NeuronAiBundle\Conversation\TerminalClose;
use Dmstr\NeuronAiBundle\Conversation\TurnLock;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Stream\StreamEvent;
use Dmstr\NeuronAiBundle\Testing\RecordingStreamSink;
use Dmstr\NeuronAiBundle\Tests\Support\StreamTestSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Resumption of a conversation's stream:
 * `GET /api/conversations/{id}/stream` replays the stored events with an id
 * above `Last-Event-ID` (all without the header or for a stale id), answers
 * 400 for a malformed header, 403 to a non-reader (a holder of
 * `neuronai:conversation:read_any` may read), 404 without stored events and running
 * turn, and ends a stream whose worker died with a synthetic, unstored
 * `error` event. A resume after the last `tool_result` of a bounded turn
 * replays the `limit` event first; a resume after the last
 * `tool_result` of a turn a terminal tool ended replays the
 * stored `terminal_tool` event, then `done`.
 *
 * The live tail of a running turn is tested on StreamResumer directly,
 * built on the default connection (the "running" turn is a second database
 * session holding the lock, and the test writes the events through the
 * store): over HTTP the kernel
 * browser only returns once the stream ended, so nothing could release the
 * lock or append the terminal event while the request runs.
 *
 * Needs the migrated test database (CI only).
 */
#[Group('database')]
final class ResumeTest extends ApiTestCase
{
    use StreamTestSupport;

    protected static ?bool $alwaysBootKernel = true;

    private const string DOCS_URL = 'https://docs.example.org/mcp';

    public function testResumeAfterAnEventIdReplaysExactlyTheLaterEvents(): void
    {
        [$conversation, $sent] = $this->completedTurn();

        $events = $this->events($this->resume($conversation, (string) $sent[1]['id']));

        self::assertSame(\array_slice($sent, 2), $events);
        self::assertSame(StreamEvent::DONE, $events[array_key_last($events)]['event']);
    }

    public function testResumeAfterTheLastToolResultOfABoundedTurnStartsWithTheLimit(): void
    {
        $docs = $this->mcpConnection('docs', 'search', self::DOCS_URL);
        $conversation = $this->conversation(mcpConnections: [(string) $docs->getId()]);
        $this->mcpFake()->queueToolResult('Spindel: alle 500 Stunden.', url: self::DOCS_URL);
        $this->factory()->queueToolCall('search', ['query' => 'Spindel'], 'call_r1')->queueAnswer('Die Spindel muss alle 500 Betriebsstunden gewartet werden.');
        $sent = $this->events($this->stream($conversation, ['content' => 'Spindel?', 'maxToolCalls' => 1]));
        self::assertIncreasingIds($sent);

        $lastResult = max(array_keys(self::typesOf($sent), StreamEvent::TOOL_RESULT, true));
        $events = $this->events($this->resume($conversation, (string) $sent[$lastResult]['id']));

        self::assertSame(StreamEvent::LIMIT, $events[0]['event']);
        self::assertSame(\array_slice($sent, $lastResult + 1), $events);
        self::assertSame($sent[0]['data']['messageId'], $events[0]['data']['messageId']);
        self::assertSame(StreamEvent::DONE, $events[array_key_last($events)]['event']);
        self::assertSame(ForcedClose::REASON_BOUND, $events[array_key_last($events)]['data']['closeReason']);
        // The ids increase across the whole stream: what the client had
        // before the resume, then the replay.
        self::assertIncreasingIds([...\array_slice($sent, 0, $lastResult + 1), ...$events]);
    }

    public function testResumeAfterTheToolResultOfATerminalTurnReplaysTheTerminalToolEvent(): void
    {
        $docs = $this->mcpConnection('docs', 'search', self::DOCS_URL);
        $conversation = $this->conversation(mcpConnections: [(string) $docs->getId()]);
        $this->mcpFake()->queueToolResult('Die Spindel wird alle 500 Stunden gewartet.', url: self::DOCS_URL);
        $this->factory()->queueToolCall('search', ['query' => 'Spindel'], 'call_r2');
        $sent = $this->events($this->stream($conversation, ['content' => 'Spindel?', 'terminalTools' => ['search']]));
        self::assertIncreasingIds($sent);

        $lastResult = max(array_keys(self::typesOf($sent), StreamEvent::TOOL_RESULT, true));
        $events = $this->events($this->resume($conversation, (string) $sent[$lastResult]['id']));

        self::assertSame([StreamEvent::TERMINAL_TOOL, StreamEvent::DONE], self::typesOf($events));
        self::assertSame(\array_slice($sent, $lastResult + 1), $events);
        self::assertSame($sent[0]['data']['messageId'], $events[0]['data']['messageId']);
        self::assertSame('search', $events[0]['data']['name']);
        self::assertSame('call_r2', $events[0]['data']['callId']);
        self::assertSame('Die Spindel wird alle 500 Stunden gewartet.', $events[0]['data']['answer']);
        self::assertSame(TerminalClose::REASON, $events[1]['data']['closeReason']);

        // Without the header the whole stream is replayed, terminal_tool included.
        self::assertSame($sent, $this->events($this->resume($conversation)));
    }

    public function testResumeWithoutTheHeaderReplaysAllEvents(): void
    {
        [$conversation, $sent] = $this->completedTurn();

        self::assertSame($sent, $this->events($this->resume($conversation)));
    }

    public function testStaleIdReplaysAllEvents(): void
    {
        [$conversation, $sent] = $this->completedTurn();

        self::assertSame($sent, $this->events($this->resume($conversation, '0')));
        self::assertSame($sent, $this->events($this->resume($conversation, (string) ($sent[0]['id'] - 1))));
    }

    #[DataProvider('malformedIds')]
    public function testMalformedLastEventIdAnswers400(string $lastEventId): void
    {
        [$conversation] = $this->completedTurn();

        $this->resume($conversation, $lastEventId);

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedIds(): iterable
    {
        yield 'letters' => ['abc'];
        yield 'negative' => ['-1'];
    }

    public function testNonOwnerAnswers403(): void
    {
        [$conversation] = $this->completedTurn();

        $this->resume($conversation, null, ['X-Test-Roles' => 'ROLE_USER', 'X-Test-User' => AccessTest::USER_B]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testReadAnyHolderMayResume(): void
    {
        [$conversation, $sent] = $this->completedTurn();

        $events = $this->events($this->resume($conversation, null, ['X-Test-Roles' => 'ROLE_ADMIN', 'X-Test-User' => AccessTest::ADMIN]));

        self::assertSame($sent, $events);
    }

    public function testUnknownConversationAnswers404(): void
    {
        $this->resume((string) Uuid::v4());

        self::assertResponseStatusCodeSame(404);
    }

    public function testConversationWithoutEventsAndTurnAnswers404(): void
    {
        $conversation = $this->conversation();

        $this->resume($conversation);

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeadWorkerEndsWithASyntheticError(): void
    {
        $conversation = $this->conversation();
        $messageId = Uuid::v7();
        $ids = [
            $this->eventStore()->append($conversation->getId(), $messageId, StreamEvent::SOURCES, ['messageId' => (string) $messageId, 'sources' => []]),
            $this->eventStore()->append($conversation->getId(), $messageId, StreamEvent::TOKEN, ['text' => 'Halb']),
        ];

        $events = $this->events($this->resume($conversation));

        self::assertSame([StreamEvent::SOURCES, StreamEvent::TOKEN, StreamEvent::ERROR], self::typesOf($events));
        self::assertSame([...$ids, null], array_column($events, 'id'));
        self::assertSame(['messageId' => null, 'status' => MessageStatus::ERROR, 'message' => StreamResumer::ENDED_WITHOUT_RESULT], $events[2]['data']);
        // The synthetic event is not stored.
        self::assertSame([[$ids[0], StreamEvent::SOURCES], [$ids[1], StreamEvent::TOKEN]], $this->storedEvents($conversation));
    }

    public function testLiveTailFollowsTheRunningTurnUntilDone(): void
    {
        $conversation = $this->conversation();
        $messageId = Uuid::v7();
        $first = $this->eventStore()->append($conversation->getId(), $messageId, StreamEvent::SOURCES, ['messageId' => (string) $messageId, 'sources' => []]);
        $this->eventStore()->append($conversation->getId(), $messageId, StreamEvent::TOKEN, ['text' => 'Alle ']);
        $this->holdLock($conversation);

        // Built here with the default intervals: the container's instance is
        // private and inlined into the controller.
        $resumer = new StreamResumer($this->eventStore(), new TurnLock($this->dbal()));
        $sink = new RecordingStreamSink();
        $finished = false;
        $sink->onSend(function (StreamEvent $event) use ($conversation, $messageId, &$finished): void {
            if (!$finished && StreamEvent::TOKEN === $event->type) {
                $finished = true;
                $this->eventStore()->append($conversation->getId(), $messageId, StreamEvent::DONE, $this->donePayload($messageId));
                $this->releaseLock($conversation);
            }
        });

        $resumer->resume($conversation, $first, $sink);

        self::assertSame([StreamEvent::TOKEN, StreamEvent::DONE], $sink->getTypes());
        self::assertNotNull($sink->getEvents()[1]->id);
        self::assertSame(MessageStatus::COMPLETE, $sink->getEvents()[1]->payload['status']);
    }

    public function testLiveTailPollsAndPingsWhileTheTurnRuns(): void
    {
        $conversation = $this->conversation();
        $messageId = Uuid::v7();
        $this->eventStore()->append($conversation->getId(), $messageId, StreamEvent::SOURCES, ['messageId' => (string) $messageId, 'sources' => []]);
        $this->holdLock($conversation);

        // Each poll interval "sleeps" by writing what the running turn
        // would have stored meanwhile.
        $sleeps = 0;
        $sleep = function (int $ms) use ($conversation, $messageId, &$sleeps): void {
            ++$sleeps;
            match ($sleeps) {
                1 => $this->eventStore()->append($conversation->getId(), $messageId, StreamEvent::TOKEN, ['text' => 'Alle ']),
                2 => null,
                default => (function () use ($conversation, $messageId): void {
                    $this->eventStore()->append($conversation->getId(), $messageId, StreamEvent::DONE, $this->donePayload($messageId));
                    $this->releaseLock($conversation);
                })(),
            };
        };
        $resumer = new StreamResumer($this->eventStore(), new TurnLock($this->dbal()), pollIntervalMs: 200, pingIntervalMs: 400, sleep: $sleep);
        $sink = new RecordingStreamSink();

        $resumer->resume($conversation, null, $sink);

        self::assertSame([StreamEvent::SOURCES, StreamEvent::TOKEN, StreamEvent::DONE], $sink->getTypes());
        self::assertSame(3, $sleeps);
        // Two intervals of 200 ms passed before the third poll: one ping.
        self::assertSame(1, $sink->pings());
    }

    /**
     * A conversation with one completed streamed turn and the events of
     * its stream.
     *
     * @return array{Conversation, list<array{id: ?int, event: string, data: array<string, mixed>}>}
     */
    private function completedTurn(): array
    {
        $conversation = $this->conversation();
        $this->factory()->queueAnswer('Die Spindel muss alle 500 Betriebsstunden gewartet werden.');
        $sent = $this->events($this->stream($conversation, ['content' => 'Spindel?']));
        self::assertGreaterThanOrEqual(3, \count($sent));
        self::assertIncreasingIds($sent);

        return [$conversation, $sent];
    }

    /**
     * @return array<string, mixed>
     */
    private function donePayload(Uuid $messageId): array
    {
        return ['messageId' => (string) $messageId, 'status' => MessageStatus::COMPLETE, 'provider' => 'anthropic', 'model' => 'fake', 'usage' => null, 'closeReason' => null];
    }
}
