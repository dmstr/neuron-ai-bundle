<?php

// file generated with AI assistance: Claude Code - 2026-10-03 07:09:45 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Conversation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Anonymous\CapabilityToken;
use Dmstr\NeuronAiBundle\Anonymous\ConversationLimitException;
use Dmstr\NeuronAiBundle\Conversation\ConversationStatus;
use Dmstr\NeuronAiBundle\Conversation\ForcedClose;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Conversation\TerminalClose;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Provider\ToolChoice;
use Dmstr\NeuronAiBundle\Stream\StreamEvent;
use Dmstr\NeuronAiBundle\Testing\ToolChoiceRequestRecord;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\AnonymousFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use Dmstr\NeuronAiBundle\Tests\Support\StreamTestSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * The user-turn limit in the turn: a conversation with
 * `maxUserTurns` answers its turns as before until the last allowed one,
 * which runs forced from its first inference (a `limit` record with
 * `limit: user_turns` before step 1, the answer with close reason
 * `user_turn_limit`, a `turn_limit` stream event before the model events)
 * and closes the conversation with `user_turn_limit`; every further turn,
 * streamed or not, answers 422 with that code. Error and aborted turns
 * count. A forced step that answers with a non-terminal tool call fails the
 * turn, which still counts and closes. A terminal tool is targeted from the
 * first inference and keeps `terminal_tool`. The gate closes a conversation
 * whose counter reached the limit without a close (the `markFinal()`
 * fallback path). For anonymous conversations the smaller of the two limits
 * wins, a tie is `user_turn_limit`. An agent conversation bounds tool calls
 * by its version (the request may only lower it; its tools, provider, model
 * and instructions come from the version too, not from the columns) and
 * keeps turning when the agent is disabled later. Conversations without `maxUserTurns` are
 * unchanged.
 *
 * Fixtures: conversations of the default test identity `test-admin` and
 * MCP connections from {@see StreamTestSupport}; agents and versions
 * written through the entity manager with the prefix of
 * {@see AgentFixtures} (the creation path is covered by
 * {@see ConversationAgentCreationTest}); anonymous conversations and the
 * `anonymous_conversations` settings as in
 * {@see Anonymous\AnonymousLimitsTest}. Counters and limits that the API
 * does not set are written through DBAL, then the entity manager is
 * cleared. Needs the migrated test database (CI only).
 */
#[Group('database')]
final class UserTurnLimitTest extends ApiTestCase
{
    use StreamTestSupport {
        tearDown as private streamTearDown;
    }

    protected static ?bool $alwaysBootKernel = true;

    private const string DOCS_URL = 'https://docs.example.org/mcp';

    private bool $usesAgents = false;

    private bool $usesAnonymous = false;

    protected function tearDown(): void
    {
        foreach ($this->conversationIds as $id) {
            $this->dbal()->executeStatement('DELETE FROM dmstr_log_entries WHERE object_class = :class AND object_id = :id', ['class' => Conversation::class, 'id' => $id]);
        }
        $this->streamTearDown();
        if ($this->usesAgents) {
            AgentFixtures::cleanUp();
        }
        if ($this->usesAnonymous) {
            AnonymousFixtures::cleanUp();
        }
    }

    public function testLastAllowedTurnIsForcedAndClosesTheConversation(): void
    {
        $docs = $this->mcpConnection('docs', 'search', self::DOCS_URL);
        $conversation = $this->limited(3, [(string) $docs->getId()]);
        $this->factory()->queueAnswer('Eins')->queueAnswer('Zwei');

        foreach (['Erste Frage', 'Zweite Frage'] as $content) {
            $data = $this->turn($conversation, ['content' => $content])->toArray();
            self::assertResponseStatusCodeSame(201, $content);
            self::assertNull($data['closeReason'] ?? null, $content);
        }
        self::assertNotContains(MessageKind::LIMIT, self::kinds($this->records($conversation)));
        self::assertSame([2, ConversationStatus::ACTIVE, null], $this->counters($conversation));

        // The model would call a tool; the forced step offers none.
        $before = $this->providerCalls();
        $this->factory()->queueAnswer('Drei');
        $data = $this->turn($conversation, ['content' => 'Dritte Frage'])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Drei', $data['content']);
        self::assertSame(ForcedClose::REASON_TURN_LIMIT, $data['closeReason']);
        $requests = $this->providerRequests($before);
        self::assertCount(1, $requests);
        self::assertTrue(ToolChoice::none()->equals($requests[0]->toolChoice));
        self::assertSame([], $this->mcpFake()->getRequests(self::DOCS_URL, 'tools/call'));

        $records = \array_slice($this->records($conversation), 4);
        self::assertSame(
            [[MessageKind::TEXT, 0, null], [MessageKind::LIMIT, 1, null], [MessageKind::TEXT, 1, ForcedClose::REASON_TURN_LIMIT]],
            array_map(static fn (Message $m): array => [$m->getKind(), $m->getStep(), $m->getCloseReason()], $records),
        );
        self::assertSame(ForcedClose::turnLimitReason(3), $records[1]->getContent());
        self::assertSame(['step' => 1, 'part' => 'limit', 'limit' => 'user_turns'], $records[1]->getProviderPayload());
        self::assertSame([3, ConversationStatus::CLOSED, Conversation::CLOSE_REASON_USER_TURN_LIMIT], $this->counters($conversation));

        $this->assertRefused($conversation);
    }

    public function testStreamOfTheLastTurnSendsTurnLimitAndReplaysIt(): void
    {
        $conversation = $this->limited(3);
        $this->setCounters($conversation, 2);
        $this->factory()->queueAnswer('Die letzte Antwort.');

        $events = $this->events($this->stream($conversation, ['content' => 'Letzte Frage']));

        $types = self::typesOf($events);
        self::assertSame([StreamEvent::SOURCES, StreamEvent::TURN_LIMIT], \array_slice($types, 0, 2));
        self::assertSame(StreamEvent::DONE, $types[array_key_last($types)]);
        self::assertSame([StreamEvent::TOKEN], array_values(array_unique(\array_slice($types, 2, -1))));
        self::assertSame(1, \count(array_keys($types, StreamEvent::TURN_LIMIT, true)));
        self::assertNotContains(StreamEvent::LIMIT, $types);
        self::assertSame('Die letzte Antwort.', self::textOf($events, StreamEvent::TOKEN));
        self::assertIncreasingIds($events);

        $messageId = $events[0]['data']['messageId'];
        self::assertSame([
            'messageId' => $messageId,
            'step' => 1,
            'reason' => ForcedClose::turnLimitReason(3),
            'maxUserTurns' => 3,
            'turn' => 3,
            'mode' => ForcedClose::MODE_TOOL_CHOICE,
        ], $events[1]['data']);
        $done = $events[array_key_last($events)]['data'];
        self::assertSame($messageId, $done['messageId']);
        self::assertSame(MessageStatus::COMPLETE, $done['status']);
        self::assertSame(ForcedClose::REASON_TURN_LIMIT, $done['closeReason']);

        self::assertSame(
            [[1, MessageKind::TEXT, 0, MessageStatus::COMPLETE], [2, MessageKind::LIMIT, 1, MessageStatus::COMPLETE], [3, MessageKind::TEXT, 1, MessageStatus::COMPLETE]],
            self::layout($this->records($conversation)),
        );
        self::assertSame([3, ConversationStatus::CLOSED, Conversation::CLOSE_REASON_USER_TURN_LIMIT], $this->counters($conversation));

        // The closed conversation's stream is still replayed.
        self::assertSame($events, $this->events($this->resume($conversation)));
        self::assertSame(\array_slice($events, 2), $this->events($this->resume($conversation, (string) $events[1]['id'])));

        $this->assertRefused($conversation);
    }

    public function testLimitOfOneForcesTheFirstTurn(): void
    {
        $conversation = $this->limited(1);
        $this->factory()->queueAnswer('Einzige Antwort');

        $data = $this->turn($conversation, ['content' => 'Einzige Frage'])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame(ForcedClose::REASON_TURN_LIMIT, $data['closeReason']);
        self::assertSame(
            [[1, MessageKind::TEXT, 0, MessageStatus::COMPLETE], [2, MessageKind::LIMIT, 1, MessageStatus::COMPLETE], [3, MessageKind::TEXT, 1, MessageStatus::COMPLETE]],
            self::layout($this->records($conversation)),
        );
        self::assertSame([1, ConversationStatus::CLOSED, Conversation::CLOSE_REASON_USER_TURN_LIMIT], $this->counters($conversation));
        $this->assertRefused($conversation);
    }

    public function testErrorAndAbortedTurnsCount(): void
    {
        $conversation = $this->limited(3);

        // Turn 1: provider failure → `error`.
        $this->factory()->queueFailure('boom');
        $this->turn($conversation, ['content' => 'Erste Frage']);
        self::assertSame(MessageStatus::ERROR, $this->lastRecord($conversation)->getStatus());
        self::assertSame([1, ConversationStatus::ACTIVE, null], $this->counters($conversation));

        // Turn 2: cancelled mid-stream → `aborted`.
        $this->factory()->queueAnswer('Eins zwei drei vier fuenf.')->setChunkDelayMs(300)->setChunkHook(function (int $index) use ($conversation): void {
            if (2 === $index) {
                $this->requestCancel($conversation);
            }
        });
        $events = $this->events($this->stream($conversation, ['content' => 'Zweite Frage']));
        self::assertSame(MessageStatus::ABORTED, $events[array_key_last($events)]['data']['status']);
        self::assertNotContains(StreamEvent::TURN_LIMIT, self::typesOf($events));
        self::assertSame(MessageStatus::ABORTED, $this->lastRecord($conversation)->getStatus());
        self::assertSame([2, ConversationStatus::ACTIVE, null], $this->counters($conversation));

        // Turn 3 is still the last one and forced.
        $this->factory()->setChunkDelayMs(0)->setChunkHook(null)->queueAnswer('Drei');
        $data = $this->turn($conversation, ['content' => 'Dritte Frage'])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame(ForcedClose::REASON_TURN_LIMIT, $data['closeReason']);
        $records = $this->records($conversation);
        self::assertSame([MessageKind::LIMIT, MessageKind::TEXT], self::kinds(\array_slice($records, -2)));
        self::assertSame([3, ConversationStatus::CLOSED, Conversation::CLOSE_REASON_USER_TURN_LIMIT], $this->counters($conversation));
    }

    public function testForcedStepAnsweringWithANonTerminalToolCallFailsAndCloses(): void
    {
        $docs = $this->mcpConnection('docs', 'search', self::DOCS_URL);
        $conversation = $this->limited(2, [(string) $docs->getId()]);
        $this->factory()->queueAnswer('Eins');
        $this->turn($conversation, ['content' => 'Erste Frage']);
        self::assertResponseStatusCodeSame(201);

        $this->factory()->queueToolCall('search', ['query' => 'Spindel'], 'call_forced');
        $data = $this->turn($conversation, ['content' => 'Zweite Frage'])->toArray(false);

        self::assertSame(MessageStatus::ERROR, $data['status'] ?? null);
        self::assertNull($data['closeReason'] ?? null);
        self::assertSame(
            [[MessageKind::TEXT, 0, MessageStatus::COMPLETE], [MessageKind::LIMIT, 1, MessageStatus::COMPLETE], [MessageKind::TEXT, 2, MessageStatus::ERROR]],
            array_map(static fn (Message $m): array => [$m->getKind(), $m->getStep(), $m->getStatus()], \array_slice($this->records($conversation), 2)),
        );
        self::assertSame([], $this->mcpFake()->getRequests(self::DOCS_URL, 'tools/call'), 'the tool never ran');
        self::assertSame([2, ConversationStatus::CLOSED, Conversation::CLOSE_REASON_USER_TURN_LIMIT], $this->counters($conversation));
        $this->assertRefused($conversation);
    }

    public function testTerminalToolIsTargetedFromTheFirstInferenceOfTheLastTurn(): void
    {
        $docs = $this->mcpConnection('docs', 'submit_answer', self::DOCS_URL);
        $conversation = $this->limited(1, [(string) $docs->getId()]);
        $this->mcpFake()->queueToolResult('Alle 500 Stunden.', url: self::DOCS_URL);
        $this->factory()->queueToolCall('submit_answer', ['text' => 'alle 500 Stunden'], 'call-terminal');
        $before = $this->providerCalls();

        $data = $this->turn($conversation, ['content' => 'Spindel?', 'terminalTools' => ['submit_answer']])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Alle 500 Stunden.', $data['content']);
        self::assertSame(TerminalClose::REASON, $data['closeReason']);
        $requests = $this->providerRequests($before);
        self::assertCount(1, $requests);
        self::assertTrue(ToolChoice::tool('submit_answer')->equals($requests[0]->toolChoice));
        self::assertSame(
            [[MessageKind::TEXT, 0, null], [MessageKind::LIMIT, 1, null], [MessageKind::TOOL_CALL, 1, null], [MessageKind::TEXT, 1, TerminalClose::REASON]],
            array_map(static fn (Message $m): array => [$m->getKind(), $m->getStep(), $m->getCloseReason()], $this->records($conversation)),
        );
        self::assertSame([1, ConversationStatus::CLOSED, Conversation::CLOSE_REASON_USER_TURN_LIMIT], $this->counters($conversation));
    }

    public function testGateClosesAConversationWhoseCounterReachedTheLimit(): void
    {
        foreach (['sync', 'stream'] as $mode) {
            $conversation = $this->limited(2);
            $this->setCounters($conversation, 2);
            $calls = $this->providerCalls();

            $response = 'sync' === $mode
                ? $this->turn($conversation, ['content' => 'Noch eine?'])
                : $this->stream($conversation, ['content' => 'Noch eine?']);

            self::assertSame(422, $response->getStatusCode(), $mode.': '.$response->getContent(false));
            self::assertSame(ConversationLimitException::CODE_USER_TURN_LIMIT, $response->toArray(false)['code'] ?? null, $mode);
            self::assertStringNotContainsString('event:', $response->getContent(false), $mode);
            self::assertSame($calls, $this->providerCalls(), $mode.': no provider call');
            self::assertSame([], $this->records($conversation), $mode);
            self::assertSame([2, ConversationStatus::CLOSED, Conversation::CLOSE_REASON_USER_TURN_LIMIT], $this->counters($conversation), $mode);
        }
    }

    /**
     * @return iterable<string, array{int, int, string, bool}>
     */
    public static function anonymousLimits(): iterable
    {
        // policy turn limit, maxUserTurns => close reason after two turns, second turn forced
        yield 'equal: user_turn_limit wins the tie' => [2, 2, Conversation::CLOSE_REASON_USER_TURN_LIMIT, true];
        yield 'larger: the policy closes first' => [2, 3, Conversation::CLOSE_REASON_TURN_LIMIT, false];
        yield 'smaller: maxUserTurns closes first' => [3, 2, Conversation::CLOSE_REASON_USER_TURN_LIMIT, true];
    }

    #[DataProvider('anonymousLimits')]
    public function testAnonymousConversationClosesAtTheSmallerLimit(int $policyTurns, int $maxUserTurns, string $reason, bool $forced): void
    {
        AnonymousFixtures::configure(['max_turns_per_conversation' => $policyTurns]);
        [$id, $token] = $this->anonymousConversation($maxUserTurns);
        $this->factory()->queueAnswer('Eins')->queueAnswer('Zwei');

        foreach (['Erste Frage', 'Zweite Frage'] as $content) {
            $this->client->request('POST', '/api/conversations/'.$id.'/messages', ['headers' => AnonymousFixtures::tokenHeaders($token), 'json' => ['content' => $content]]);
            self::assertResponseStatusCodeSame(201, $content);
        }

        $row = AnonymousFixtures::row($id);
        self::assertSame([2, ConversationStatus::CLOSED, $reason], [(int) $row['turn_count'], $row['status'], $row['close_reason']]);
        $records = $this->recordsOf($id);
        self::assertSame($forced, \in_array(MessageKind::LIMIT, self::kinds($records), true));
        self::assertSame($forced ? ForcedClose::REASON_TURN_LIMIT : null, $records[array_key_last($records)]->getCloseReason());

        $refused = $this->client->request('POST', '/api/conversations/'.$id.'/messages', ['headers' => AnonymousFixtures::tokenHeaders($token), 'json' => ['content' => 'Dritte Frage']]);
        self::assertSame(422, $refused->getStatusCode());
        $expected = Conversation::CLOSE_REASON_USER_TURN_LIMIT === $reason ? ConversationLimitException::CODE_USER_TURN_LIMIT : ConversationLimitException::CODE_TURN_LIMIT;
        self::assertSame($expected, $refused->toArray(false)['code'] ?? null);
    }

    public function testAgentVersionBoundsToolCallsAndTheRequestOnlyLowers(): void
    {
        $docs = $this->mcpConnection('docs', 'search', self::DOCS_URL);
        // The agent conversation's tool comes from its version,
        // not from the mcpConnections column.
        $agentConversation = $this->limited(null, [], $this->agentVersion('bound', 2, ['mcpTools' => [['connection' => (string) $docs->getId(), 'name' => 'search']]]));
        $plainConversation = $this->limited(null, [(string) $docs->getId()]);

        // Agent conversation: version 2, request 5 → bound 2.
        $this->queueTwoCallsThenAnswer();
        $data = $this->turn($agentConversation, ['content' => 'Spindel?', 'maxToolCalls' => 5])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame(ForcedClose::REASON_BOUND, $data['closeReason']);
        $limits = array_values(array_filter($this->records($agentConversation), static fn (Message $m): bool => MessageKind::LIMIT === $m->getKind()));
        self::assertCount(1, $limits);
        self::assertSame(ForcedClose::limitReason(2, 2), $limits[0]->getContent());
        self::assertStringStartsWith('Tool-call limit of 2 ', (string) $limits[0]->getContent());

        // Without an agent the request applies as before: 5, not reached.
        $this->queueTwoCallsThenAnswer();
        $data = $this->turn($plainConversation, ['content' => 'Spindel?', 'maxToolCalls' => 5])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertNull($data['closeReason'] ?? null);
        self::assertNotContains(MessageKind::LIMIT, self::kinds($this->records($plainConversation)));
    }

    public function testDisablingTheAgentLaterKeepsTheConversationTurning(): void
    {
        $version = $this->agentVersion('disabled', null);
        $conversation = $this->limited(2, version: $version);
        $this->dbal()->executeStatement('UPDATE neuron_agent SET enabled = false WHERE id = :id', ['id' => $version->getAgent()->getId()->toRfc4122()]);
        $this->entityManager()->clear();
        $this->factory()->queueAnswer('Trotzdem');

        $this->turn($conversation, ['content' => 'Noch da?']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame([1, ConversationStatus::ACTIVE, null], $this->counters($conversation));
    }

    public function testConversationWithoutLimitIsUnchanged(): void
    {
        $conversation = $this->limited(null);
        $this->factory()->queueAnswer('Eins')->queueAnswer('Zwei');

        $this->turn($conversation, ['content' => 'Erste Frage']);
        self::assertResponseStatusCodeSame(201);
        $events = $this->events($this->stream($conversation, ['content' => 'Zweite Frage']));

        self::assertNotContains(StreamEvent::TURN_LIMIT, self::typesOf($events));
        self::assertNull($events[array_key_last($events)]['data']['closeReason']);
        self::assertNotContains(MessageKind::LIMIT, self::kinds($this->records($conversation)));
        self::assertSame([2, ConversationStatus::ACTIVE, null], $this->counters($conversation));
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * A conversation of `test-admin` with $maxUserTurns (null = unlimited)
     * and optionally a pinned agent version.
     *
     * @param list<string> $mcpConnections
     */
    private function limited(?int $maxUserTurns, array $mcpConnections = [], ?AgentVersion $version = null): Conversation
    {
        $conversation = $this->conversation(mcpConnections: $mcpConnections)
            ->setMaxUserTurns($maxUserTurns)
            ->setAgentVersion($version);
        $this->entityManager()->flush();

        return $conversation;
    }

    /**
     * An agent with one version whose `maxToolCalls` is $maxToolCalls,
     * plus further configuration $overrides over the candidate defaults (e.g.
     * `mcpTools`: a turn of an agent conversation offers the version's
     * tools only), written through the entity manager (removed by
     * {@see AgentFixtures::cleanUp()}).
     *
     * @param array<string, mixed> $overrides
     */
    private function agentVersion(string $slug, ?int $maxToolCalls, array $overrides = []): AgentVersion
    {
        $this->usesAgents = true;
        $agent = new Agent()->setName('Agent '.$slug)->setSlug(AgentFixtures::PREFIX.'utl-'.$slug)->setEnabled(true);
        $this->entityManager()->persist($agent);
        $this->entityManager()->flush();
        $version = new AgentVersion($agent, 1, ['maxToolCalls' => $maxToolCalls] + $overrides + ['model' => ConversationConnections::modelId()] + Agent::candidateDefaults(), 'test-admin');
        $this->entityManager()->persist($version);
        $this->entityManager()->flush();

        return $version;
    }

    /**
     * An anonymous conversation with a known capability token and
     * `max_user_turns` written through DBAL.
     *
     * @return array{string, string} conversation id and token
     */
    private function anonymousConversation(int $maxUserTurns): array
    {
        $this->usesAnonymous = true;
        $token = (new CapabilityToken())->generate();
        $conversation = new Conversation()
            ->setAnonymous(true)
            ->setTokenHash(CapabilityToken::hash($token))
            ->setAddressHash(str_repeat('a', 64))
            ->setProviderModel(ConversationConnections::model())
            ->setRetrievalDepth(4);
        $conversation->setOwnerSubject($conversation->anonymousOwnerSubject());
        $this->entityManager()->persist($conversation);
        $this->entityManager()->flush();
        $id = (string) $conversation->getId();
        $this->conversationIds[] = $id;

        $this->dbal()->executeStatement('UPDATE neuron_conversation SET max_user_turns = :max WHERE id = :id', ['max' => $maxUserTurns, 'id' => $id]);
        $this->entityManager()->clear();

        return [$id, $token];
    }

    /**
     * Sets `turn_count` through DBAL (as the counting fallback leaves it)
     * and clears the entity manager, so the next request loads the row.
     */
    private function setCounters(Conversation $conversation, int $turnCount): void
    {
        $this->dbal()->executeStatement('UPDATE neuron_conversation SET turn_count = :count WHERE id = :id', ['count' => $turnCount, 'id' => (string) $conversation->getId()]);
        $this->entityManager()->clear();
    }

    /**
     * Two parallel `search` calls in one response, then the answer; the
     * fake MCP tool answers both.
     */
    private function queueTwoCallsThenAnswer(): void
    {
        $this->mcpFake()
            ->queueToolResult('Spindel: alle 500 Stunden.', url: self::DOCS_URL)
            ->queueToolResult('Dokument 7: Wartungsplan.', url: self::DOCS_URL);
        $this->factory()
            ->queueToolCall('search', ['query' => 'Spindel'])
            ->queueToolCall('search', ['query' => 'Wartung'])
            ->queueAnswer('Alle 500 Stunden.');
    }

    /**
     * `turnCount`, `status` and `closeReason` as `GET /conversations/{id}` shows them.
     *
     * @return array{int, string, ?string}
     */
    private function counters(Conversation $conversation): array
    {
        $response = $this->client->request('GET', '/api/conversations/'.$conversation->getId(), ['headers' => ['Accept' => 'application/ld+json']]);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        $data = $response->toArray();

        return [$data['turnCount'], $data['status'], $data['closeReason'] ?? null];
    }

    /**
     * The next turn is refused with 422 `user_turn_limit`, without a
     * provider call or a new record: as a problem document on
     * `POST .../messages` and with the same fields as
     * `application/problem+json` on `POST .../stream`, without an event.
     */
    private function assertRefused(Conversation $conversation): void
    {
        $calls = $this->providerCalls();
        $count = \count($this->records($conversation));
        $fields = ['type', 'title', 'detail', 'status', 'code'];

        $sync = $this->turn($conversation, ['content' => 'Noch eine Frage']);
        self::assertSame(422, $sync->getStatusCode(), $sync->getContent(false));
        $problem = $sync->toArray(false);
        self::assertSame(ConversationLimitException::CODE_USER_TURN_LIMIT, $problem['code'] ?? null);
        self::assertSame('/conversation_errors/user_turn_limit', $problem['type'] ?? null);
        self::assertSame('The conversation reached its user-turn limit.', $problem['title'] ?? null);
        self::assertSame('The conversation reached its user-turn limit. It accepts no further turn.', $problem['detail'] ?? null);

        $streamed = $this->stream($conversation, ['content' => 'Noch eine Frage']);
        self::assertSame(422, $streamed->getStatusCode());
        self::assertStringStartsWith('application/problem+json', $streamed->getHeaders(false)['content-type'][0] ?? '');
        $body = $streamed->getContent(false);
        self::assertStringNotContainsString('event:', $body);
        self::assertSame(
            array_intersect_key($problem, array_flip($fields)),
            array_intersect_key(json_decode($body, true, 512, \JSON_THROW_ON_ERROR), array_flip($fields)),
        );

        self::assertSame($calls, $this->providerCalls(), 'no provider call');
        self::assertCount($count, $this->records($conversation));
    }

    /**
     * The records of a conversation given by id (anonymous fixtures).
     *
     * @return list<Message> ordered by sequence, fresh from the database
     */
    private function recordsOf(string $id): array
    {
        $this->entityManager()->clear();

        /** @var list<Message> $records */
        $records = $this->entityManager()->getRepository(Message::class)->findBy(['conversation' => Uuid::fromString($id)], ['sequence' => 'ASC']);

        return $records;
    }

    /**
     * The conversation's last record, fresh from the database.
     */
    private function lastRecord(Conversation $conversation): Message
    {
        $records = $this->records($conversation);
        self::assertNotEmpty($records);

        return $records[array_key_last($records)];
    }

    /**
     * @param list<Message> $records
     *
     * @return list<string>
     */
    private static function kinds(array $records): array
    {
        return array_map(static fn (Message $m): string => $m->getKind(), $records);
    }

    private function providerCalls(): int
    {
        return $this->factory()->getChatProvider()->getCallCount();
    }

    /**
     * The provider requests recorded after the first $skip.
     *
     * @return list<ToolChoiceRequestRecord>
     */
    private function providerRequests(int $skip): array
    {
        $records = \array_slice(array_values($this->factory()->getChatProvider()->getRecorded()), $skip);
        self::assertContainsOnlyInstancesOf(ToolChoiceRequestRecord::class, $records);

        return $records;
    }
}
