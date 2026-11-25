<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:21:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Conversation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Anonymous\ConversationCloser;
use Dmstr\NeuronAiBundle\Anonymous\ConversationLimitException;
use Dmstr\NeuronAiBundle\Conversation\ConversationStatus;
use Dmstr\NeuronAiBundle\Conversation\ForcedClose;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Conversation\TerminalClose;
use Dmstr\NeuronAiBundle\Conversation\TurnPersistence;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingsProviderLocator;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Stream\StreamEvent;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use Dmstr\NeuronAiBundle\Tests\Support\StreamTestSupport;
use Dmstr\NeuronPgvectorStore\PgVectorStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\RAG\Document;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Functional test of the streamed turn `POST /api/conversations/{id}/stream`
 *: protocol order and content of plain, reasoning, tool
 * and schema turns, the mid-stream failure, the in-flight `streaming`
 * placeholder, a record layout equal to the non-streaming turn, the stored
 * events and their cleanup by the next turn, the orphan sweep, sources,
 * and the client errors answered before the stream starts.
 *
 * The tool-call bound from the body (`maxToolCalls`) sends one
 * `limit` event between the tool events of the bounded batch and the
 * forced tokens, with the final record's id as `messageId`; `done` always
 * carries `closeReason` (`forced_bound` for a forced answer, else null);
 * the records equal those of a bounded `POST .../messages`; without
 * `maxToolCalls` the configured default applies (wired through
 * TurnRunner; unset in the test kernel, so unbounded); invalid bounds are
 * refused with 422 before the stream; no stored event uses a reserved
 * type; the DBAL fallback (TurnPersistence::markFinal()) clears the close
 * reason on row and entity.
 *
 * A call of a tool named in `terminalTools` ends the stream
 * with `sources, tool_call, tool_result, terminal_tool, done` (no tokens,
 * no further model call), `done.closeReason` `terminal_tool`, and the
 * placeholder becomes the final record at the tool-call step; an unknown,
 * duplicate, non-string or empty terminal tool name is refused with 422
 * before the stream.
 *
 * Needs the migrated test database (CI only). The kernel browser captures
 * the streamed body, so incremental delivery is not visible here (stack
 * suite).
 */
#[Group('database')]
final class StreamingTurnTest extends ApiTestCase
{
    use StreamTestSupport;

    protected static ?bool $alwaysBootKernel = true;

    private const string DOCS_URL = 'https://docs.example.org/mcp';

    /** The MCP connection of {@see boundedConversation()}, created once per test. */
    private ?ApiConfiguration $docs = null;

    public function testPlainAnswerStreamsSourcesTokensAndDone(): void
    {
        $conversation = $this->conversation();
        $this->factory()->getChatProvider()->addResponses(new AssistantMessage('Alle 500 Stunden.')->setUsage(new Usage(30, 4, 10, 0)));

        $events = $this->events($this->stream($conversation, ['content' => 'Wann muss die Spindel gewartet werden?']));

        $types = self::typesOf($events);
        self::assertSame(StreamEvent::SOURCES, $types[0]);
        self::assertSame(StreamEvent::DONE, $types[array_key_last($types)]);
        self::assertSame([StreamEvent::TOKEN], array_values(array_unique(\array_slice($types, 1, -1))));
        self::assertSame('Alle 500 Stunden.', self::textOf($events, StreamEvent::TOKEN));
        self::assertIncreasingIds($events);

        $sources = $events[0]['data'];
        self::assertSame([], $sources['sources']);
        $done = $events[array_key_last($events)]['data'];
        self::assertSame($sources['messageId'], $done['messageId']);
        self::assertSame(MessageStatus::COMPLETE, $done['status']);
        self::assertSame('anthropic', $done['provider']);
        self::assertSame(FakeProviderFactory::ANTHROPIC_MODEL, $done['model']);
        self::assertSame(ConversationConnections::ANTHROPIC, $done['connection']);
        self::assertSame(['input_tokens' => 30, 'output_tokens' => 4, 'cached_input_tokens' => 10, 'reasoning_tokens' => 0], $done['usage']);
        self::assertSame(['messageId', 'status', 'provider', 'model', 'connection', 'usage', 'closeReason'], array_keys($done));
        self::assertNull($done['closeReason']);
        self::assertNotContains(StreamEvent::LIMIT, $types);

        $records = $this->records($conversation);
        self::assertSame([[1, MessageKind::TEXT, 0, MessageStatus::COMPLETE], [2, MessageKind::TEXT, 1, MessageStatus::COMPLETE]], self::layout($records));
        self::assertSame($done['messageId'], (string) $records[1]->getId());
        self::assertSame('Alle 500 Stunden.', $records[1]->getContent());
        self::assertSame(Message::SPEAKER_ASSISTANT, $records[1]->getRole());
        self::assertSame(3, $this->reload($conversation)->getNextSequence());
        self::assertSame('Wann muss die Spindel gewartet werden?', $this->reload($conversation)->getTitle());
    }

    public function testPlaceholderIsStreamingWhileTheTurnRuns(): void
    {
        $conversation = $this->conversation();
        $seen = [];
        $this->factory()->queueAnswer('Alle 500 Stunden.')->setChunkHook(function (int $index) use ($conversation, &$seen): void {
            if (0 === $index) {
                $seen = array_map(static fn (array $row): array => [(int) $row[0], (string) $row[1]], $this->dbal()->fetchAllNumeric(
                    'SELECT sequence, status FROM neuron_message WHERE conversation_id = :id AND role = :role',
                    ['id' => (string) $conversation->getId(), 'role' => Message::SPEAKER_ASSISTANT],
                ));
            }
        });

        $events = $this->events($this->stream($conversation, ['content' => 'Spindel?']));

        self::assertSame([[2, MessageStatus::STREAMING]], $seen);
        self::assertSame(StreamEvent::DONE, $events[array_key_last($events)]['event']);
        self::assertSame([], array_filter($this->records($conversation), static fn (Message $m): bool => MessageStatus::STREAMING === $m->getStatus()));
    }

    public function testReasoningIsSentBeforeTheTokensAndNeverMerged(): void
    {
        $conversation = $this->conversation();
        $this->factory()->queueReasoning('Kurz nachdenken.', 'Blau.', 'sig-1');

        $events = $this->events($this->stream($conversation, ['content' => 'Farbe?']));

        $types = self::typesOf($events);
        $lastReasoning = max(array_keys($types, StreamEvent::REASONING, true));
        $firstToken = min(array_keys($types, StreamEvent::TOKEN, true));
        self::assertLessThan($firstToken, $lastReasoning);
        self::assertSame('Kurz nachdenken.', self::textOf($events, StreamEvent::REASONING));
        self::assertSame('Blau.', self::textOf($events, StreamEvent::TOKEN));
        self::assertSame(
            [[1, MessageKind::TEXT, 0, MessageStatus::COMPLETE], [2, MessageKind::REASONING, 1, MessageStatus::COMPLETE], [3, MessageKind::TEXT, 1, MessageStatus::COMPLETE]],
            self::layout($this->records($conversation)),
        );
    }

    public function testToolCallAndResultPrecedeTheFinalTokens(): void
    {
        $docs = $this->mcpConnection('docs', 'search', self::DOCS_URL);
        $conversation = $this->conversation(mcpConnections: [(string) $docs->getId()]);
        $this->mcpFake()->queueToolResult('Spindel: alle 500 Stunden.', url: self::DOCS_URL);
        $this->factory()->queueToolCall('search', ['query' => 'Spindel'], 'call_s1')->queueAnswer('Alle 500 Stunden.');

        $events = $this->events($this->stream($conversation, ['content' => 'Spindel?']));

        $types = self::typesOf($events);
        $call = array_search(StreamEvent::TOOL_CALL, $types, true);
        $result = array_search(StreamEvent::TOOL_RESULT, $types, true);
        self::assertIsInt($call);
        self::assertIsInt($result);
        self::assertLessThan($result, $call);
        self::assertLessThan(min(array_keys($types, StreamEvent::TOKEN, true)), $result);
        self::assertSame(StreamEvent::DONE, $types[array_key_last($types)]);

        self::assertSame(['name' => 'search', 'callId' => 'call_s1', 'input' => ['query' => 'Spindel']], $events[$call]['data']);
        self::assertSame('search', $events[$result]['data']['name']);
        self::assertSame('call_s1', $events[$result]['data']['callId']);
        self::assertFalse($events[$result]['data']['error']);
        self::assertStringContainsString('alle 500 Stunden', (string) $events[$result]['data']['result']);
        self::assertSame('Alle 500 Stunden.', self::textOf($events, StreamEvent::TOKEN));
        // Unbounded: no limit, and the answer was not forced.
        self::assertNotContains(StreamEvent::LIMIT, $types);
        self::assertArrayHasKey('closeReason', $events[array_key_last($events)]['data']);
        self::assertNull($events[array_key_last($events)]['data']['closeReason']);

        $records = $this->records($conversation);
        self::assertSame(
            [[1, MessageKind::TEXT, 0, MessageStatus::COMPLETE], [2, MessageKind::TOOL_CALL, 1, MessageStatus::COMPLETE], [3, MessageKind::TEXT, 2, MessageStatus::COMPLETE]],
            self::layout($records),
        );
        self::assertNull($records[2]->getCloseReason());
        self::assertSame('search', $records[1]->getToolName());
        self::assertSame('call_s1', $records[1]->getToolCallId());
        self::assertSame($events[0]['data']['messageId'], (string) $records[2]->getId());
    }

    public function testMidStreamFailureSendsTheSentTokensThenAnError(): void
    {
        $conversation = $this->conversation();
        $this->factory()->queueStreamFailure('boom', 2, 'Hello world, this is long.');

        $events = $this->events($this->stream($conversation, ['content' => 'Frage']));

        $types = self::typesOf($events);
        self::assertSame(StreamEvent::SOURCES, $types[0]);
        self::assertSame(StreamEvent::ERROR, $types[array_key_last($types)]);
        self::assertNotContains(StreamEvent::DONE, $types);
        self::assertSame([StreamEvent::TOKEN], array_values(array_unique(\array_slice($types, 1, -1))));
        // Chunk size 5: exactly the first two chunks were sent.
        self::assertSame('Hello worl', self::textOf($events, StreamEvent::TOKEN));
        $error = $events[array_key_last($events)]['data'];
        self::assertSame(MessageStatus::ERROR, $error['status']);
        self::assertStringContainsString('boom', $error['message']);
        self::assertSame($events[0]['data']['messageId'], $error['messageId']);

        $records = $this->records($conversation);
        self::assertSame([[1, MessageKind::TEXT, 0, MessageStatus::COMPLETE], [2, MessageKind::TEXT, 1, MessageStatus::ERROR]], self::layout($records));
        self::assertSame(Message::SPEAKER_USER, $records[0]->getRole());
        self::assertSame('Hello worl', $records[1]->getContent());
        self::assertStringContainsString('boom', (string) $records[1]->getError());
        self::assertSame($error['messageId'], (string) $records[1]->getId());
        self::assertSame(3, $this->reload($conversation)->getNextSequence());
    }

    public function testRecordLayoutEqualsTheNonStreamingTurn(): void
    {
        $docs = $this->mcpConnection('docs', 'search', self::DOCS_URL);
        $streamed = $this->conversation(mcpConnections: [(string) $docs->getId()]);
        $plain = $this->conversation(mcpConnections: [(string) $docs->getId()]);
        $script = function (): void {
            $this->mcpFake()->queueToolResult('Spindel: alle 500 Stunden.', url: self::DOCS_URL);
            $this->factory()
                ->queueToolCall('search', ['query' => 'Spindel'], reasoning: 'Erst suchen.')
                ->queueReasoning('Gefunden.', 'Alle 500 Stunden.');
        };

        $script();
        $events = $this->events($this->stream($streamed, ['content' => 'Spindel?']));
        $script();
        $this->turn($plain, ['content' => 'Spindel?']);
        self::assertResponseStatusCodeSame(201);

        $streamedRecords = $this->records($streamed);
        self::assertSame(self::layout($this->records($plain)), self::layout($streamedRecords));
        self::assertSame($this->reload($plain)->getNextSequence(), $this->reload($streamed)->getNextSequence());
        self::assertSame(\count($streamedRecords) + 1, $this->reload($streamed)->getNextSequence());

        $final = $streamedRecords[array_key_last($streamedRecords)];
        $done = $events[array_key_last($events)]['data'];
        self::assertSame(StreamEvent::DONE, $events[array_key_last($events)]['event']);
        self::assertSame((string) $final->getId(), $done['messageId']);
        self::assertSame($events[0]['data']['messageId'], $done['messageId']);
        self::assertSame(MessageStatus::COMPLETE, $final->getStatus());
        self::assertSame('Alle 500 Stunden.', $final->getContent());
    }

    public function testBoundFromTheBodySendsTheLimitBeforeTheForcedTokens(): void
    {
        $conversation = $this->boundedConversation();
        $this->queueBoundedScript();

        $events = $this->events($this->stream($conversation, ['content' => 'Spindel?', 'maxToolCalls' => 1]));

        self::assertSame(
            [StreamEvent::SOURCES, StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::LIMIT, StreamEvent::TOKEN, StreamEvent::DONE],
            self::collapseTokens(self::typesOf($events)),
        );
        self::assertSame(1, \count(array_keys(self::typesOf($events), StreamEvent::LIMIT, true)));
        self::assertIncreasingIds($events);
        self::assertSame('Alle 500 Stunden.', self::textOf($events, StreamEvent::TOKEN));

        $messageId = $events[0]['data']['messageId'];
        $limit = $events[3]['data'];
        self::assertSame([
            'messageId' => $messageId,
            'step' => 2,
            'reason' => ForcedClose::limitReason(1, 1),
            'maxToolCalls' => 1,
            'toolCalls' => 1,
            'mode' => ForcedClose::MODE_TOOL_CHOICE,
        ], $limit);

        $done = $events[array_key_last($events)]['data'];
        self::assertSame($messageId, $done['messageId']);
        self::assertSame(MessageStatus::COMPLETE, $done['status']);
        self::assertSame(ForcedClose::REASON_BOUND, $done['closeReason']);

        $records = $this->records($conversation);
        self::assertSame(
            [
                [1, MessageKind::TEXT, 0, MessageStatus::COMPLETE],
                [2, MessageKind::TOOL_CALL, 1, MessageStatus::COMPLETE],
                [3, MessageKind::LIMIT, 2, MessageStatus::COMPLETE],
                [4, MessageKind::TEXT, 2, MessageStatus::COMPLETE],
            ],
            self::layout($records),
        );
        self::assertSame($limit['reason'], $records[2]->getContent());
        self::assertNull($records[2]->getCloseReason());
        self::assertSame($messageId, (string) $records[3]->getId());
        self::assertSame('Alle 500 Stunden.', $records[3]->getContent());
        self::assertSame(ForcedClose::REASON_BOUND, $records[3]->getCloseReason());
        self::assertSame(5, $this->reload($conversation)->getNextSequence());
    }

    public function testBoundedRecordsEqualTheBoundedNonStreamingTurn(): void
    {
        $streamed = $this->boundedConversation();
        $plain = $this->boundedConversation();

        $this->queueBoundedScript();
        $events = $this->events($this->stream($streamed, ['content' => 'Spindel?', 'maxToolCalls' => 1]));
        $this->queueBoundedScript();
        $this->turn($plain, ['content' => 'Spindel?', 'maxToolCalls' => 1]);
        self::assertResponseStatusCodeSame(201);

        $streamedRecords = $this->records($streamed);
        $plainRecords = $this->records($plain);
        self::assertSame(self::layout($plainRecords), self::layout($streamedRecords));
        self::assertContains(MessageKind::LIMIT, array_map(static fn (Message $m): string => $m->getKind(), $streamedRecords));
        self::assertSame(
            array_map(static fn (Message $m): array => [$m->getContent(), $m->getCloseReason()], $plainRecords),
            array_map(static fn (Message $m): array => [$m->getContent(), $m->getCloseReason()], $streamedRecords),
        );
        self::assertSame($this->reload($plain)->getNextSequence(), $this->reload($streamed)->getNextSequence());

        $final = $streamedRecords[array_key_last($streamedRecords)];
        $done = $events[array_key_last($events)]['data'];
        self::assertSame((string) $final->getId(), $done['messageId']);
        self::assertSame($final->getCloseReason(), $done['closeReason']);
    }

    public function testWithoutABoundInTheBodyTheConfiguredDefaultApplies(): void
    {
        // Wired through TurnRunner (`turn.max_tool_calls_default`); the test
        // kernel leaves it unset, so the turn is unbounded.
        $default = static::getContainer()->getParameter('dmstr_neuron_ai.turn.max_tool_calls_default');
        $conversation = $this->boundedConversation();
        $calls = null === $default ? 1 : (int) $default;
        for ($i = 0; $i < $calls; ++$i) {
            $this->mcpFake()->queueToolResult('Spindel: alle 500 Stunden.', url: self::DOCS_URL);
            $this->factory()->queueToolCall('search', ['query' => 'Spindel '.$i]);
        }
        $this->factory()->queueAnswer('Alle 500 Stunden.');

        $events = $this->events($this->stream($conversation, ['content' => 'Spindel?']));

        $types = self::typesOf($events);
        $done = $events[array_key_last($events)];
        self::assertSame(StreamEvent::DONE, $done['event']);
        self::assertSame($calls, \count(array_keys($types, StreamEvent::TOOL_RESULT, true)));
        if (null === $default) {
            self::assertNotContains(StreamEvent::LIMIT, $types);
            self::assertNull($done['data']['closeReason']);
            self::assertNotContains(MessageKind::LIMIT, array_map(static fn (Message $m): string => $m->getKind(), $this->records($conversation)));

            return;
        }
        $limit = $events[array_search(StreamEvent::LIMIT, $types, true)]['data'];
        self::assertSame([$calls, $calls], [$limit['maxToolCalls'], $limit['toolCalls']]);
        self::assertSame(ForcedClose::REASON_BOUND, $done['data']['closeReason']);
    }

    public function testNoStoredEventUsesAReservedType(): void
    {
        $conversation = $this->boundedConversation();
        $this->queueBoundedScript();

        $events = $this->events($this->stream($conversation, ['content' => 'Spindel?', 'maxToolCalls' => 1]));

        self::assertContains(StreamEvent::LIMIT, self::typesOf($events));
        $ofConversation = $this->dbal()->fetchFirstColumn('SELECT DISTINCT type FROM neuron_stream_event WHERE conversation_id = :id', ['id' => (string) $conversation->getId()]);
        self::assertContains(StreamEvent::LIMIT, $ofConversation);
        self::assertSame([], array_values(array_intersect($ofConversation, StreamEvent::RESERVED)));
        self::assertSame([], array_values(array_intersect($this->dbal()->fetchFirstColumn('SELECT DISTINCT type FROM neuron_stream_event'), StreamEvent::RESERVED)));
    }

    public function testMarkFinalClearsTheCloseReasonOnRowAndEntity(): void
    {
        $conversation = $this->boundedConversation();
        $this->queueBoundedScript();
        $this->events($this->stream($conversation, ['content' => 'Spindel?', 'maxToolCalls' => 1]));
        $turnCount = $this->reload($conversation)->getTurnCount();
        $records = $this->records($conversation);
        $final = $records[array_key_last($records)];
        // The stored answer of the forced step carries the close reason.
        self::assertSame(ForcedClose::REASON_BOUND, $final->getCloseReason());

        $persistence = new TurnPersistence($this->entityManager(), $this->dbal(), $this->eventStore(), new ConversationCloser(), new MockClock());
        $persistence->markFinal($final, MessageStatus::ABORTED, null, 'Alle 500');

        self::assertNull($final->getCloseReason());
        self::assertSame([MessageStatus::ABORTED, null, 'Alle 500'], [$final->getStatus(), $final->getError(), $final->getContent()]);
        self::assertSame(
            ['status' => MessageStatus::ABORTED, 'error' => null, 'content' => 'Alle 500', 'close_reason' => null],
            $this->dbal()->fetchAssociative('SELECT status, error, content, close_reason FROM neuron_message WHERE id = :id', ['id' => (string) $final->getId()]),
        );
        // Without a result the turn is not counted again.
        self::assertSame($turnCount, $this->reload($conversation)->getTurnCount());
    }

    public function testTerminalToolEndsTheStreamWithATerminalToolEvent(): void
    {
        $conversation = $this->boundedConversation();
        $this->mcpFake()->queueToolResult('Die Spindel wird alle 500 Stunden gewartet.', url: self::DOCS_URL);
        $this->factory()->queueToolCall('search', ['query' => 'Spindel'], 'call_t1');

        $events = $this->events($this->stream($conversation, ['content' => 'Spindel?', 'terminalTools' => ['search']]));

        self::assertSame(
            [StreamEvent::SOURCES, StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::TERMINAL_TOOL, StreamEvent::DONE],
            self::typesOf($events),
        );
        self::assertIncreasingIds($events);
        // No further model call after the terminal tool ran.
        $this->factory()->getChatProvider()->assertCallCount(1);

        $messageId = $events[0]['data']['messageId'];
        self::assertSame([
            'messageId' => $messageId,
            'step' => 1,
            'name' => 'search',
            'callId' => 'call_t1',
            'kind' => MessageKind::TEXT,
            'answer' => 'Die Spindel wird alle 500 Stunden gewartet.',
            'structured' => null,
            'schemaErrors' => null,
        ], $events[3]['data']);

        $done = $events[array_key_last($events)]['data'];
        self::assertSame($messageId, $done['messageId']);
        self::assertSame(MessageStatus::COMPLETE, $done['status']);
        self::assertSame(TerminalClose::REASON, $done['closeReason']);

        // The placeholder became the final record at the tool-call step.
        $records = $this->records($conversation);
        self::assertSame(
            [[1, MessageKind::TEXT, 0, MessageStatus::COMPLETE], [2, MessageKind::TOOL_CALL, 1, MessageStatus::COMPLETE], [3, MessageKind::TEXT, 1, MessageStatus::COMPLETE]],
            self::layout($records),
        );
        self::assertSame('search', $records[1]->getToolName());
        self::assertStringContainsString('alle 500 Stunden', (string) $records[1]->getToolResult());
        self::assertSame($messageId, (string) $records[2]->getId());
        self::assertSame('Die Spindel wird alle 500 Stunden gewartet.', $records[2]->getContent());
        self::assertSame(TerminalClose::REASON, $records[2]->getCloseReason());

        // The terminal_tool event is stored for a resume.
        self::assertContains(StreamEvent::TERMINAL_TOOL, array_column($this->storedEvents($conversation), 1));
    }

    public function testTerminalRecordsEqualTheNonStreamingTurn(): void
    {
        $streamed = $this->boundedConversation();
        $plain = $this->boundedConversation();
        $script = function (): void {
            $this->mcpFake()->queueToolResult('Alle 500 Stunden.', url: self::DOCS_URL);
            $this->factory()->queueToolCall('search', ['query' => 'Spindel'], 'call_t2');
        };

        $script();
        $this->events($this->stream($streamed, ['content' => 'Spindel?', 'terminalTools' => ['search']]));
        $script();
        $this->turn($plain, ['content' => 'Spindel?', 'terminalTools' => ['search']]);
        self::assertResponseStatusCodeSame(201);

        $describe = static fn (array $records): array => array_map(
            static fn (Message $m): array => [$m->getKind(), $m->getStep(), $m->getStatus(), $m->getContent(), $m->getCloseReason()],
            $records,
        );
        self::assertSame($describe($this->records($plain)), $describe($this->records($streamed)));
    }

    public function testUnknownTerminalToolAnswers422BeforeTheStream(): void
    {
        $conversation = $this->boundedConversation();

        $response = $this->stream($conversation, ['content' => 'Frage', 'terminalTools' => ['page']]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString('event:', $response->getContent(false));
        self::assertSame([], $this->records($conversation));
        self::assertSame([], $this->storedEvents($conversation));
        $this->factory()->getChatProvider()->assertNothingSent();
        self::assertSame(0, $this->mcpFake()->getRequestCount());
    }

    public function testEventsAreStoredAndDeletedByTheNextTurn(): void
    {
        $conversation = $this->conversation();
        $this->factory()->queueAnswer('Alle 500 Stunden.')->queueAnswer('Alle 1000 Stunden.')->queueAnswer('Links.');

        $first = $this->events($this->stream($conversation, ['content' => 'Spindel?']));

        $stored = $this->storedEvents($conversation);
        self::assertSame(array_map(static fn (array $e): array => [$e['id'], $e['event']], $first), $stored);

        // A streamed next turn replaces the rows with its own.
        $second = $this->events($this->stream($conversation, ['content' => 'Getriebe?']));
        $stored = $this->storedEvents($conversation);
        self::assertSame(array_map(static fn (array $e): array => [$e['id'], $e['event']], $second), $stored);
        self::assertGreaterThan(max(array_column($first, 'id')), $stored[0][0]);

        // A non-streaming next turn deletes them.
        $this->turn($conversation, ['content' => 'Hauptschalter?']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame([], $this->storedEvents($conversation));
        self::assertSame(7, $this->reload($conversation)->getNextSequence());
    }

    public function testOrphanSweepMarksAPreInsertedStreamingRecordAborted(): void
    {
        $conversation = $this->conversation();
        $orphan = new Message()
            ->setConversation($conversation)
            ->setSequence(1)
            ->setRole(Message::SPEAKER_ASSISTANT)
            ->setKind(MessageKind::TEXT)
            ->setStatus(MessageStatus::STREAMING);
        $conversation->setNextSequence(2);
        $this->entityManager()->persist($orphan);
        $this->entityManager()->flush();
        $this->eventStore()->append($conversation->getId(), $orphan->getId(), StreamEvent::TOKEN, ['text' => 'Halb']);
        $this->factory()->queueAnswer('Antwort');

        $events = $this->events($this->stream($conversation, ['content' => 'Frage']));

        self::assertSame(
            [[1, MessageStatus::ABORTED], [2, MessageStatus::COMPLETE], [3, MessageStatus::COMPLETE]],
            array_map(static fn (Message $m): array => [$m->getSequence(), $m->getStatus()], $this->records($conversation)),
        );
        self::assertSame(array_map(static fn (array $e): array => [$e['id'], $e['event']], $events), $this->storedEvents($conversation));
    }

    public function testSchemaTurnEndsWithAStructuredRecord(): void
    {
        $conversation = $this->conversation();
        $this->factory()->queueAnswer('{"color": "blue"}');
        $schema = ['type' => 'object', 'properties' => ['color' => ['type' => 'string']], 'required' => ['color']];

        $events = $this->events($this->stream($conversation, ['content' => 'Farbe?', 'responseSchema' => $schema]));

        $done = $events[array_key_last($events)];
        self::assertSame(StreamEvent::DONE, $done['event']);
        self::assertSame(MessageStatus::COMPLETE, $done['data']['status']);
        self::assertSame('{"color": "blue"}', self::textOf($events, StreamEvent::TOKEN));

        $records = $this->records($conversation);
        $final = $records[array_key_last($records)];
        self::assertSame($done['data']['messageId'], (string) $final->getId());
        self::assertSame(MessageKind::SCHEMA, $final->getKind());
        self::assertSame(['color' => 'blue'], $final->getStructured());
        self::assertSame($schema, $final->getResponseSchema());
        self::assertNull($final->getError());
    }

    public function testRetrievedChunksLeadTheStreamAsSources(): void
    {
        $this->usesStore = true;
        $handbook = new Collection()->setName('handbuch')->setEmbeddingModel('nomic-embed-text')->setEmbeddingProviderModel(ConversationConnections::model('ollama', 'nomic-embed-text:latest'));
        $this->entityManager()->persist($handbook);
        $this->entityManager()->flush();
        $this->addChunks($handbook, ['Die Spindel muss alle 500 Stunden gewartet werden.', 'Der Hauptschalter sitzt links.']);
        $conversation = $this->conversation(collections: [(string) $handbook->getId()]);
        $this->factory()->queueAnswer('Alle 500 Stunden [1].');

        $events = $this->events($this->stream($conversation, ['content' => 'Wann wird die Spindel gewartet?']));

        self::assertSame(StreamEvent::SOURCES, $events[0]['event']);
        $sources = $events[0]['data']['sources'];
        self::assertNotEmpty($sources);
        foreach ($sources as $source) {
            self::assertSame((string) $handbook->getId(), $source['collectionId']);
            self::assertSame('handbuch', $source['collectionName']);
        }
        $records = $this->records($conversation);
        self::assertSame(array_column($sources, 'content'), array_column($records[array_key_last($records)]->getSources() ?? [], 'content'));
    }

    public function testMalformedJsonAnswers400(): void
    {
        $conversation = $this->conversation();

        $this->stream($conversation, '{"content": ');

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->records($conversation));
    }

    public function testUnknownConversationAnswers404(): void
    {
        $this->stream((string) Uuid::v4(), ['content' => 'Frage']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonOwnerAnswers403(): void
    {
        $conversation = $this->conversation();

        $this->stream($conversation, ['content' => 'Frage'], ['X-Test-User' => AccessTest::USER_B]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->records($conversation));
    }

    public function testHeldLockAnswers409WithoutStoringAnything(): void
    {
        $conversation = $this->conversation();
        $this->holdLock($conversation);

        $this->stream($conversation, ['content' => 'Frage']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame([], $this->records($conversation));
        self::assertSame([], $this->storedEvents($conversation));
        $this->factory()->getChatProvider()->assertNothingSent();
    }

    public function testArchivedConversationAnswers422(): void
    {
        $conversation = $this->conversation(status: ConversationStatus::ARCHIVED);

        $response = $this->stream($conversation, ['content' => 'Frage']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ConversationLimitException::CODE_CLOSED, $response->toArray(false)['code'] ?? null);
        self::assertSame([], $this->records($conversation));
        self::assertSame([], $this->storedEvents($conversation));
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testSchemaViolationOfTheBodyAnswers422(array $body): void
    {
        $conversation = $this->conversation();

        $response = $this->stream($conversation, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString('event:', $response->getContent(false));
        self::assertSame([], $this->records($conversation));
        self::assertSame([], $this->storedEvents($conversation));
        $this->factory()->getChatProvider()->assertNothingSent();
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'empty content' => [['content' => '']];
        yield 'maxToolCalls zero' => [['content' => 'Frage', 'maxToolCalls' => 0]];
        yield 'maxToolCalls a string' => [['content' => 'Frage', 'maxToolCalls' => '3']];
        yield 'maxToolCalls a fraction' => [['content' => 'Frage', 'maxToolCalls' => 2.5]];
        yield 'terminalTools not a list' => [['content' => 'Frage', 'terminalTools' => 'search']];
        yield 'terminalTools duplicate name' => [['content' => 'Frage', 'terminalTools' => ['search', 'search']]];
        yield 'terminalTools non-string entry' => [['content' => 'Frage', 'terminalTools' => ['search', 42]]];
        yield 'terminalTools empty name' => [['content' => 'Frage', 'terminalTools' => ['']]];
    }

    /**
     * A conversation with the MCP connection `docs` (tool `search`).
     */
    private function boundedConversation(): Conversation
    {
        $docs = $this->docs ??= $this->mcpConnection('docs', 'search', self::DOCS_URL);

        return $this->conversation(mcpConnections: [(string) $docs->getId()]);
    }

    /**
     * One `search` call (the fake MCP tool answers), then the answer; with
     * `maxToolCalls: 1` the second inference is the forced step.
     */
    private function queueBoundedScript(): void
    {
        $this->mcpFake()->queueToolResult('Spindel: alle 500 Stunden.', url: self::DOCS_URL);
        $this->factory()->queueToolCall('search', ['query' => 'Spindel'], 'call_b1')->queueAnswer('Alle 500 Stunden.');
    }

    /**
     * Consecutive `token` events collapsed into one.
     *
     * @param list<string> $types
     *
     * @return list<string>
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
     * @param list<string> $contents
     */
    private function addChunks(Collection $collection, array $contents): void
    {
        $container = static::getContainer();
        $documents = [];
        foreach ($contents as $number => $content) {
            $document = (new Document($content))
                ->setMetadata([
                    'collection_id' => (string) $collection->getId(),
                    'topic' => 'wartung',
                    'source_url' => 'https://example.org/handbuch/'.$number,
                    'chunk_number' => $number,
                ]);
            $documents[] = $document;
        }

        /** @var EmbeddingModelRegistry $registry */
        $registry = $container->get(EmbeddingModelRegistry::class);
        /** @var EmbeddingsProviderLocator $locator */
        $locator = $container->get(EmbeddingsProviderLocator::class);
        /** @var PgVectorStore $store */
        $store = $container->get(PgVectorStore::class);
        $store->forModel('nomic-embed-text', $registry->get('nomic-embed-text')->dimension)
            ->addDocuments($locator->for(ConversationConnections::ollama(), 'nomic-embed-text')->embedDocuments($documents));
    }
}
