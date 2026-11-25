<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:21:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Conversation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Stream\StreamEvent;
use Dmstr\NeuronAiBundle\Tests\Support\StreamTestSupport;
use PHPUnit\Framework\Attributes\Group;

/**
 * Cancellation of a streamed turn: `POST /api/conversations/{id}/cancel`
 * sets `cancel_requested_at` only while a turn holds the lock and answers
 * 200 with `running` either way; the running turn polls the flag before
 * every batch flush (at most every 250 ms) and at inference start and
 * around tool calls, ends with `done` and `status: aborted`, keeps the
 * sent text and the completed steps, and the next turn clears the flag and
 * skips the aborted turn in its history. A turn cancelled after its
 * tool-call bound was reached still sends `limit`, then `done`
 * with `status: aborted` and no close reason.
 *
 * Mid-stream cancels are scripted with the fake's chunk hook (sleep, hook,
 * yield per chunk; index from 0 per provider call), which runs the cancel
 * UPDATE on the default connection; with 300 ms between chunks every chunk
 * is its own flush and every flush reads the flag. "Running" without a
 * turn is a second database session holding the advisory lock.
 *
 * Needs the migrated test database (CI only).
 */
#[Group('database')]
final class CancelTest extends ApiTestCase
{
    use StreamTestSupport;

    protected static ?bool $alwaysBootKernel = true;

    private const string DOCS_URL = 'https://docs.example.org/mcp';

    public function testCancelDuringDeltasKeepsTheSentText(): void
    {
        $conversation = $this->conversation();
        // Chunks of 5: "Eins ", "zwei ", "drei ", ...; the cancel lands
        // before chunk 2 is flushed.
        $this->factory()->queueAnswer('Eins zwei drei vier fuenf.')->setChunkDelayMs(300)->setChunkHook(function (int $index) use ($conversation): void {
            if (2 === $index) {
                $this->requestCancel($conversation);
            }
        });

        $events = $this->events($this->stream($conversation, ['content' => 'Zaehlen?']));

        self::assertSame([StreamEvent::SOURCES, StreamEvent::TOKEN, StreamEvent::TOKEN, StreamEvent::DONE], self::typesOf($events));
        self::assertSame('Eins zwei ', self::textOf($events, StreamEvent::TOKEN));
        $done = $events[3]['data'];
        self::assertSame(MessageStatus::ABORTED, $done['status']);
        self::assertSame($events[0]['data']['messageId'], $done['messageId']);

        $records = $this->records($conversation);
        self::assertSame([[1, MessageKind::TEXT, 0, MessageStatus::COMPLETE], [2, MessageKind::TEXT, 1, MessageStatus::ABORTED]], self::layout($records));
        self::assertSame($done['messageId'], (string) $records[1]->getId());
        self::assertSame('Eins zwei ', $records[1]->getContent());
        self::assertNull($records[1]->getError());
        self::assertSame(['step' => 1, 'part' => 'aborted'], $records[1]->getProviderPayload());
        self::assertSame(3, $this->reload($conversation)->getNextSequence());
        self::assertNotNull($this->cancelRequestedAt($conversation));

        // The next turn clears the flag and does not replay the aborted turn.
        $this->factory()->setChunkDelayMs(0)->setChunkHook(null)->queueAnswer('Neu.');
        $this->turn($conversation, ['content' => 'Nochmal']);
        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->cancelRequestedAt($conversation));
        $recorded = $this->factory()->getChatProvider()->getRecorded();
        self::assertCount(1, $recorded[array_key_last($recorded)]->messages);
    }

    public function testCancelBeforeTheFinalInferenceKeepsTheCompletedToolCall(): void
    {
        $docs = $this->mcpConnection('docs', 'search', self::DOCS_URL);
        $conversation = $this->conversation(mcpConnections: [(string) $docs->getId()]);
        $this->factory()->queueAnswer('Erste Antwort');
        $this->turn($conversation, ['content' => 'Erste Frage']);
        self::assertResponseStatusCodeSame(201);

        // Provider call 1 asks for the tool (no chunks), call 2 streams the
        // answer; the cancel lands before its first chunk is flushed.
        $provider = $this->factory()->getChatProvider();
        $callsBefore = \count($provider->getRecorded());
        $this->mcpFake()->queueToolResult('Spindel: alle 500 Stunden.', url: self::DOCS_URL);
        $this->factory()
            ->queueToolCall('search', ['query' => 'Spindel'], 'call_c1')
            ->queueAnswer('Alle 500 Stunden.')
            ->setChunkDelayMs(300)
            ->setChunkHook(function (int $index) use ($conversation, $provider, $callsBefore): void {
                if (0 === $index && $callsBefore + 2 === \count($provider->getRecorded())) {
                    $this->requestCancel($conversation);
                }
            });

        $events = $this->events($this->stream($conversation, ['content' => 'Spindel?']));

        self::assertSame([StreamEvent::SOURCES, StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::DONE], self::typesOf($events));
        self::assertSame(MessageStatus::ABORTED, $events[3]['data']['status']);

        $records = $this->records($conversation);
        self::assertSame(
            [
                [1, MessageKind::TEXT, 0, MessageStatus::COMPLETE],
                [2, MessageKind::TEXT, 1, MessageStatus::COMPLETE],
                [3, MessageKind::TEXT, 0, MessageStatus::COMPLETE],
                [4, MessageKind::TOOL_CALL, 1, MessageStatus::COMPLETE],
                [5, MessageKind::TEXT, 2, MessageStatus::ABORTED],
            ],
            self::layout($records),
        );
        self::assertSame('search', $records[3]->getToolName());
        self::assertStringContainsString('alle 500 Stunden', (string) $records[3]->getToolResult());
        self::assertNull($records[4]->getContent());
        self::assertSame($events[3]['data']['messageId'], (string) $records[4]->getId());
        self::assertSame(6, $this->reload($conversation)->getNextSequence());

        // The history of the next turn holds the first, complete turn only.
        $this->factory()->setChunkDelayMs(0)->setChunkHook(null)->queueAnswer('Dritte Antwort');
        $this->turn($conversation, ['content' => 'Dritte Frage']);
        self::assertResponseStatusCodeSame(201);
        $recorded = $provider->getRecorded();
        self::assertSame(
            ['Erste Frage', 'Erste Antwort', 'Dritte Frage'],
            array_map(static fn (object $m): ?string => $m->getContent(), $recorded[array_key_last($recorded)]->messages),
        );
    }

    public function testCancelBetweenTheBoundAndTheForcedStepSendsTheLimitThenDoneAborted(): void
    {
        $docs = $this->mcpConnection('docs', 'search', self::DOCS_URL);
        $conversation = $this->conversation(mcpConnections: [(string) $docs->getId()]);

        // Provider call 1 asks for the tool and reaches the bound of 1, call
        // 2 is the forced step; the cancel lands before its first chunk is
        // flushed, i.e. after `limit` and before any forced token.
        $provider = $this->factory()->getChatProvider();
        $callsBefore = \count($provider->getRecorded());
        $this->mcpFake()->queueToolResult('Spindel: alle 500 Stunden.', url: self::DOCS_URL);
        $this->factory()
            ->queueToolCall('search', ['query' => 'Spindel'], 'call_c2')
            ->queueAnswer('Alle 500 Stunden.')
            ->setChunkDelayMs(300)
            ->setChunkHook(function (int $index) use ($conversation, $provider, $callsBefore): void {
                if (0 === $index && $callsBefore + 2 === \count($provider->getRecorded())) {
                    $this->requestCancel($conversation);
                }
            });

        $events = $this->events($this->stream($conversation, ['content' => 'Spindel?', 'maxToolCalls' => 1]));

        self::assertSame([StreamEvent::SOURCES, StreamEvent::TOOL_CALL, StreamEvent::TOOL_RESULT, StreamEvent::LIMIT, StreamEvent::DONE], self::typesOf($events));
        self::assertIncreasingIds($events);
        $messageId = $events[0]['data']['messageId'];
        self::assertSame(['messageId' => $messageId, 'step' => 2, 'maxToolCalls' => 1, 'toolCalls' => 1], array_intersect_key($events[3]['data'], array_flip(['messageId', 'step', 'maxToolCalls', 'toolCalls'])));
        $done = $events[4]['data'];
        self::assertSame(MessageStatus::ABORTED, $done['status']);
        self::assertSame($messageId, $done['messageId']);
        self::assertArrayHasKey('closeReason', $done);
        self::assertNull($done['closeReason']);

        $records = $this->records($conversation);
        self::assertSame(
            [
                [1, MessageKind::TEXT, 0, MessageStatus::COMPLETE],
                [2, MessageKind::TOOL_CALL, 1, MessageStatus::COMPLETE],
                [3, MessageKind::LIMIT, 2, MessageStatus::COMPLETE],
                [4, MessageKind::TEXT, 2, MessageStatus::ABORTED],
            ],
            self::layout($records),
        );
        self::assertSame($messageId, (string) $records[3]->getId());
        self::assertNull($records[3]->getContent());
        self::assertNull($records[3]->getCloseReason());
        self::assertSame(5, $this->reload($conversation)->getNextSequence());
    }

    public function testCancelWithoutARunningTurnIsANoOp(): void
    {
        $conversation = $this->conversation();
        $this->factory()->queueAnswer('Fertig.');
        $this->events($this->stream($conversation, ['content' => 'Frage']));

        $data = $this->cancel($conversation)->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame((string) $conversation->getId(), $data['id']);
        self::assertFalse($data['running']);
        self::assertArrayNotHasKey('cancelRequestedAt', $data);
        self::assertNull($this->cancelRequestedAt($conversation));
        self::assertSame(MessageStatus::COMPLETE, $this->records($conversation)[1]->getStatus());
    }

    public function testCancelOfARunningTurnSetsTheFlagUntilTheNextTurn(): void
    {
        $conversation = $this->conversation();
        $this->holdLock($conversation);

        $data = $this->cancel($conversation)->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($data['running']);
        self::assertNotNull($this->cancelRequestedAt($conversation));

        $this->releaseLock($conversation);
        $this->factory()->queueAnswer('Antwort');
        $events = $this->events($this->stream($conversation, ['content' => 'Frage']));

        // The flag belonged to the earlier turn: the new one completes.
        self::assertSame(MessageStatus::COMPLETE, $events[array_key_last($events)]['data']['status']);
        self::assertNull($this->cancelRequestedAt($conversation));
    }

    public function testNonOwnerCannotCancel(): void
    {
        $conversation = $this->conversation();
        $this->holdLock($conversation);

        $this->cancel($conversation, ['X-Test-User' => AccessTest::USER_B]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->cancelRequestedAt($conversation));
    }

    public function testReadAnyHolderCannotCancelEither(): void
    {
        $conversation = $this->conversation();
        $this->holdLock($conversation);

        $this->cancel($conversation, ['X-Test-Roles' => 'ROLE_ADMIN', 'X-Test-User' => AccessTest::ADMIN]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->cancelRequestedAt($conversation));
    }

    public function testCancelRacingTheCompletionLeavesNoStreamingRecord(): void
    {
        $conversation = $this->conversation();
        $answer = 'Alle 500 Stunden.';
        $lastChunk = intdiv(mb_strlen($answer) - 1, 5);
        $this->factory()->queueAnswer($answer)->setChunkHook(function (int $index) use ($conversation, $lastChunk): void {
            if ($lastChunk === $index) {
                $this->requestCancel($conversation);
            }
        });

        $events = $this->events($this->stream($conversation, ['content' => 'Spindel?']));

        $done = $events[array_key_last($events)];
        self::assertSame(StreamEvent::DONE, $done['event']);
        self::assertContains($done['data']['status'], [MessageStatus::COMPLETE, MessageStatus::ABORTED]);

        $records = $this->records($conversation);
        self::assertSame([], array_values(array_filter($records, static fn (Message $m): bool => MessageStatus::STREAMING === $m->getStatus())));
        $final = $records[array_key_last($records)];
        self::assertSame($done['data']['messageId'], (string) $final->getId());
        self::assertSame($done['data']['status'], $final->getStatus());
    }
}
