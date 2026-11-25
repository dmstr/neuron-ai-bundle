<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:30:19 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Conversation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Conversation\ForcedClose;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Conversation\RuleClose;
use Dmstr\NeuronAiBundle\Conversation\TerminalClose;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Stream\StreamEvent;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use Dmstr\NeuronAiBundle\Tests\Support\StreamTestSupport;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\Attributes\Group;

/**
 * Output templates on the turns of agent conversations:
 *
 * - frame: the final record of a framed turn carries `frame {prefix,
 *   suffix}` (the version's `frame` template rendered in the conversation's
 *   language and split at `{{answer}}`) while `content` and the provider
 *   payload stay unframed, so the next turn replays the unframed answer;
 *   the stream sends one `frame {messageId, prefix, suffix}` after the
 *   run's last event and before `done`, and the resume replays it
 *   identically; forced answers (`forced_bound`, `user_turn_limit`) and the
 *   fallback answer are framed; schema, terminal-tool, rule, error and
 *   aborted records are not, and their streams have no `frame`; a version
 *   without a `frame` template sends exactly today's events;
 * - limit texts: the `limitReason`/`turnLimitReason` templates replace the
 *   English reasons in the `limit` record and the `limit`/`turn_limit`
 *   event;
 * - fallback: a failed or empty forced close is answered with the
 *   `forcedCloseFallback` (a complete `text` record with the forced close
 *   reason and no `error` record; in the stream one `token` after `limit`,
 *   then `frame`, then `done` with status `complete`), and the turn is
 *   absent from the next turn's history.
 *
 * Fixtures: conversations of `test-admin` from {@see StreamTestSupport},
 * pinned to versions written through the entity manager (prefix of
 * {@see AgentFixtures}, removed by its cleanUp()); MCP tools served by
 * the fake transport. Needs the migrated test database (CI only).
 */
#[Group('database')]
final class OutputTemplatesTurnTest extends ApiTestCase
{
    use StreamTestSupport {
        tearDown as private streamTearDown;
    }

    protected static ?bool $alwaysBootKernel = true;

    private const string DOCS_URL = 'https://docs.output-templates.example.org/mcp';

    private const array FRAME_DE = ['prefix' => 'Rahmenbot sagt: ', 'suffix' => ' (Ende)'];
    private const array FRAME_EN = ['prefix' => 'Rahmenbot says: ', 'suffix' => ''];
    private const string FALLBACK = 'Rahmenbot konnte das leider nicht beantworten.';

    protected function tearDown(): void
    {
        foreach ($this->conversationIds as $id) {
            $this->dbal()->executeStatement('DELETE FROM dmstr_log_entries WHERE object_class = :class AND object_id = :id', ['class' => Conversation::class, 'id' => $id]);
        }
        $this->streamTearDown();
        AgentFixtures::cleanUp();
    }

    public function testFramedAnswerIsStoredUnframedAndAnnouncedBeforeDone(): void
    {
        $version = $this->publishedVersion('plain');

        // Synchronous: frame on the record, content unframed.
        $conversation = $this->agentConversation($version);
        $this->factory()->queueAnswer('Alle 500 Stunden.');
        $data = $this->turn($conversation, ['content' => 'Spindel?'])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Alle 500 Stunden.', $data['content']);
        self::assertSame(self::FRAME_DE, $data['frame'] ?? null);
        $final = $this->finalRecord($conversation);
        self::assertSame(self::FRAME_DE, $final->getFrame());
        self::assertSame('Alle 500 Stunden.', $final->getContent());
        $payload = json_encode($final->getProviderPayload()['message'] ?? null, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('Alle 500 Stunden.', $payload);
        self::assertStringNotContainsString(self::FRAME_DE['prefix'], $payload);

        // The next turn replays the unframed answer.
        $this->factory()->queueAnswer('Bitte.');
        $before = $this->recordedRequests();
        $this->turn($conversation, ['content' => 'Danke!']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['Spindel?', 'Alle 500 Stunden.', 'Danke!'], self::contents($this->requestAfter($before)));

        // Streamed, in English: tokens, then frame, then done.
        $streamed = $this->agentConversation($version, 'en');
        $this->factory()->queueAnswer('Every 500 hours.');
        $sent = $this->events($this->stream($streamed, ['content' => 'Spindle?']));
        self::assertIncreasingIds($sent);
        $types = self::typesOf($sent);
        self::assertSame(StreamEvent::SOURCES, $types[0]);
        self::assertSame([StreamEvent::FRAME, StreamEvent::DONE], \array_slice($types, -2));
        self::assertSame([StreamEvent::TOKEN], array_values(array_unique(\array_slice($types, 1, -2))));
        self::assertSame('Every 500 hours.', self::textOf($sent, StreamEvent::TOKEN));

        $record = $this->finalRecord($streamed);
        $messageId = (string) $record->getId();
        self::assertSame(['messageId' => $messageId] + self::FRAME_EN, $sent[\count($sent) - 2]['data']);
        self::assertSame(MessageStatus::COMPLETE, $sent[\count($sent) - 1]['data']['status']);
        self::assertSame(self::FRAME_EN, $record->getFrame());
        self::assertSame('Every 500 hours.', $record->getContent());

        // Resume: everything without the header, [frame, done] after the last token.
        self::assertSame($sent, $this->events($this->resume($streamed)));
        $lastToken = max(array_keys($types, StreamEvent::TOKEN, true));
        self::assertSame(\array_slice($sent, -2), $this->events($this->resume($streamed, (string) $sent[$lastToken]['id'])));
    }

    public function testForcedAnswersAreFramedAndUseTheLimitTexts(): void
    {
        $docs = $this->docs();
        $version = $this->publishedVersion('forced', [
            'maxToolCalls' => 1,
            'mcpTools' => [['connection' => (string) $docs->getId(), 'name' => 'search']],
        ]);

        foreach (['sync', 'stream'] as $mode) {
            // Bound: one call reaches it, the forced answer is framed.
            $conversation = $this->agentConversation($version);
            $this->mcpFake()->queueToolResult('Treffer', url: self::DOCS_URL);
            $this->factory()->queueToolCall('search', ['query' => 'Spindel'])->queueAnswer('Alle 500 Stunden.');
            if ('sync' === $mode) {
                $data = $this->turn($conversation, ['content' => 'Spindel?'])->toArray();
                self::assertResponseStatusCodeSame(201);
                self::assertSame([ForcedClose::REASON_BOUND, self::FRAME_DE], [$data['closeReason'], $data['frame'] ?? null], $mode);
            } else {
                $events = $this->events($this->stream($conversation, ['content' => 'Spindel?']));
                $types = self::typesOf($events);
                self::assertSame([StreamEvent::FRAME, StreamEvent::DONE], \array_slice($types, -2), $mode);
                self::assertLessThan(array_search(StreamEvent::FRAME, $types, true), array_search(StreamEvent::LIMIT, $types, true), $mode);
                self::assertSame('1 von 1 Werkzeugaufrufen für Rahmenbot.', $events[array_search(StreamEvent::LIMIT, $types, true)]['data']['reason'], $mode);
                self::assertSame(ForcedClose::REASON_BOUND, $events[array_key_last($events)]['data']['closeReason'], $mode);
            }
            $final = $this->finalRecord($conversation);
            self::assertSame([ForcedClose::REASON_BOUND, self::FRAME_DE, 'Alle 500 Stunden.'], [$final->getCloseReason(), $final->getFrame(), $final->getContent()], $mode);
            self::assertSame(['1 von 1 Werkzeugaufrufen für Rahmenbot.'], $this->limitContents($conversation), $mode);

            // Last allowed user turn: forced from the first inference, framed.
            $last = $this->agentConversation($version, maxUserTurns: 1);
            $this->factory()->queueAnswer('Letzte Antwort.');
            if ('sync' === $mode) {
                $data = $this->turn($last, ['content' => 'Noch was?'])->toArray();
                self::assertResponseStatusCodeSame(201);
                self::assertSame([ForcedClose::REASON_TURN_LIMIT, self::FRAME_DE], [$data['closeReason'], $data['frame'] ?? null], $mode);
            } else {
                $events = $this->events($this->stream($last, ['content' => 'Noch was?']));
                $types = self::typesOf($events);
                self::assertSame([StreamEvent::SOURCES, StreamEvent::TURN_LIMIT], \array_slice($types, 0, 2), $mode);
                self::assertSame([StreamEvent::FRAME, StreamEvent::DONE], \array_slice($types, -2), $mode);
                self::assertSame('Runde 1 von 1 für Rahmenbot.', $events[1]['data']['reason'], $mode);
                self::assertSame(ForcedClose::REASON_TURN_LIMIT, $events[array_key_last($events)]['data']['closeReason'], $mode);
            }
            $final = $this->finalRecord($last);
            self::assertSame([ForcedClose::REASON_TURN_LIMIT, self::FRAME_DE], [$final->getCloseReason(), $final->getFrame()], $mode);
            self::assertSame(['Runde 1 von 1 für Rahmenbot.'], $this->limitContents($last), $mode);
        }
    }

    public function testFallbackAnswerIsFramedSentAsOneTokenAndNotReplayed(): void
    {
        $docs = $this->docs();
        $version = $this->publishedVersion('fallback', [
            'maxToolCalls' => 1,
            'mcpTools' => [['connection' => (string) $docs->getId(), 'name' => 'search']],
        ]);

        // Synchronous, failed forced close: the forced step calls a tool again.
        $conversation = $this->agentConversation($version);
        $this->factory()->queueAnswer('Erste Antwort.');
        $this->turn($conversation, ['content' => 'Erste Frage']);
        self::assertResponseStatusCodeSame(201);

        $this->mcpFake()->queueToolResult('Treffer', url: self::DOCS_URL);
        $this->factory()->queueToolCall('search', ['query' => 'Spindel']);
        // The forced step's call is its own response: consecutive
        // queueToolCall() calls would form one parallel batch, and the
        // forced step would then find the script empty (a provider error).
        // The guard fails the call before any tool runs, so the tool
        // instance is never executed.
        $this->factory()->getChatProvider()->addResponses(new ToolCallMessage(null, [
            ToolCall::make(name: 'search', callId: 'call_forced', inputs: ['query' => 'nochmal'], description: 'Forced search'),
        ]));
        $data = $this->turn($conversation, ['content' => 'Spindel?'])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame([MessageKind::TEXT, MessageStatus::COMPLETE, self::FALLBACK, ForcedClose::REASON_BOUND, self::FRAME_DE], [
            $data['kind'], $data['status'], $data['content'], $data['closeReason'], $data['frame'] ?? null,
        ]);
        $records = $this->records($conversation);
        self::assertSame([], array_values(array_filter($records, static fn (Message $m): bool => MessageStatus::ERROR === $m->getStatus())), 'no error record');
        $final = $records[array_key_last($records)];
        self::assertSame('fallback', $final->getProviderPayload()['part'] ?? null);
        self::assertSame(self::FRAME_DE, $final->getFrame());

        // The fallback turn is not replayed.
        $this->factory()->queueAnswer('Dritte Antwort.');
        $before = $this->recordedRequests();
        $this->turn($conversation, ['content' => 'Dritte Frage']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['Erste Frage', 'Erste Antwort.', 'Dritte Frage'], self::contents($this->requestAfter($before)));

        // Streamed, empty forced answer: limit, the fallback token, frame, done.
        $streamed = $this->agentConversation($version);
        $this->mcpFake()->queueToolResult('Treffer', url: self::DOCS_URL);
        $this->factory()->queueToolCall('search', ['query' => 'Spindel'])->queueAnswer('');
        $sent = $this->events($this->stream($streamed, ['content' => 'Spindel?']));
        self::assertIncreasingIds($sent);
        self::assertSame([StreamEvent::LIMIT, StreamEvent::TOKEN, StreamEvent::FRAME, StreamEvent::DONE], \array_slice(self::typesOf($sent), -4));
        self::assertSame(1, \count(array_keys(self::typesOf($sent), StreamEvent::TOKEN, true)));
        self::assertSame(['text' => self::FALLBACK], $sent[\count($sent) - 3]['data']);
        $record = $this->finalRecord($streamed);
        self::assertSame(['messageId' => (string) $record->getId()] + self::FRAME_DE, $sent[\count($sent) - 2]['data']);
        self::assertSame([MessageStatus::COMPLETE, ForcedClose::REASON_BOUND], [$sent[\count($sent) - 1]['data']['status'], $sent[\count($sent) - 1]['data']['closeReason']]);
        self::assertSame([MessageKind::TEXT, MessageStatus::COMPLETE, self::FALLBACK, self::FRAME_DE], [$record->getKind(), $record->getStatus(), $record->getContent(), $record->getFrame()]);
        self::assertSame($sent, $this->events($this->resume($streamed)));
    }

    public function testFallbackOfTheLastUserTurnIsSentAfterTheTurnLimit(): void
    {
        $conversation = $this->agentConversation($this->publishedVersion('fallback-last'), maxUserTurns: 1);
        $this->factory()->queueAnswer('');

        $sent = $this->events($this->stream($conversation, ['content' => 'Noch was?']));

        self::assertSame([StreamEvent::SOURCES, StreamEvent::TURN_LIMIT, StreamEvent::TOKEN, StreamEvent::FRAME, StreamEvent::DONE], self::typesOf($sent));
        self::assertSame(self::FALLBACK, $sent[2]['data']['text']);
        self::assertSame([MessageStatus::COMPLETE, ForcedClose::REASON_TURN_LIMIT], [$sent[4]['data']['status'], $sent[4]['data']['closeReason']]);
        $record = $this->finalRecord($conversation);
        self::assertSame([self::FALLBACK, ForcedClose::REASON_TURN_LIMIT, self::FRAME_DE], [$record->getContent(), $record->getCloseReason(), $record->getFrame()]);
    }

    public function testSchemaTerminalAndRuleRecordsAreNotFramed(): void
    {
        // Schema answer.
        $schema = ['type' => 'object', 'properties' => ['color' => ['type' => 'string']], 'required' => ['color']];
        $schemaVersion = $this->publishedVersion('schema', ['responseSchema' => $schema]);
        foreach (['sync', 'stream'] as $mode) {
            $conversation = $this->agentConversation($schemaVersion);
            $this->factory()->queueAnswer('{"color": "blue"}');
            $this->runTurn($conversation, $mode, 'Farbe?');
            $final = $this->finalRecord($conversation);
            self::assertSame([MessageKind::SCHEMA, MessageStatus::COMPLETE, null], [$final->getKind(), $final->getStatus(), $final->getFrame()], 'schema '.$mode);
        }

        // Terminal tool answer (forced to the terminal tool on the last turn).
        $docs = $this->docs(['search', 'submit_answer']);
        $terminalVersion = $this->publishedVersion('terminal', ['mcpTools' => [
            ['connection' => (string) $docs->getId(), 'name' => 'search'],
            ['connection' => (string) $docs->getId(), 'name' => 'submit_answer', 'terminal' => true],
        ]]);
        foreach (['sync', 'stream'] as $mode) {
            $conversation = $this->agentConversation($terminalVersion, maxUserTurns: 1);
            $this->mcpFake()->queueToolResult('Alle 500 Stunden.', url: self::DOCS_URL);
            $this->factory()->queueToolCall('submit_answer', ['text' => 'alle 500 Stunden'], 'call-'.$mode);
            $types = $this->runTurn($conversation, $mode, 'Spindel?');
            if ('stream' === $mode) {
                self::assertContains(StreamEvent::TERMINAL_TOOL, $types);
            }
            $final = $this->finalRecord($conversation);
            self::assertSame([TerminalClose::REASON, null], [$final->getCloseReason(), $final->getFrame()], 'terminal '.$mode);
        }

        // Rule refusal.
        $ruleVersion = $this->publishedVersion('rule', [
            'rules' => [['name' => 'secrets', 'match' => ['type' => 'keywords', 'keywords' => ['passwort']], 'outcome' => 'refuse', 'template' => 'refusal']],
            'templates' => self::templates() + ['refusal' => ['de' => 'Dazu keine Auskunft.']],
        ]);
        foreach (['sync', 'stream'] as $mode) {
            $conversation = $this->agentConversation($ruleVersion);
            $types = $this->runTurn($conversation, $mode, 'Passwort?');
            if ('stream' === $mode) {
                self::assertSame([StreamEvent::SOURCES, StreamEvent::RULE, StreamEvent::DONE], $types);
            }
            $final = $this->finalRecord($conversation);
            self::assertSame([MessageKind::RULE_REFUSAL, RuleClose::REASON_REFUSAL, null], [$final->getKind(), $final->getCloseReason(), $final->getFrame()], 'rule '.$mode);
        }
    }

    public function testErrorAndAbortedRecordsAreNotFramed(): void
    {
        $version = $this->publishedVersion('failures');

        // Provider failure, synchronous and streamed.
        $failed = $this->agentConversation($version);
        $this->factory()->queueFailure('Provider down');
        $data = $this->turn($failed, ['content' => 'Spindel?'])->toArray();
        self::assertSame(MessageStatus::ERROR, $data['status']);
        self::assertNull($data['frame'] ?? null);
        self::assertNull($this->finalRecord($failed)->getFrame());

        $streamFailed = $this->agentConversation($version);
        $this->factory()->queueStreamFailure('Provider down', 1, 'Alle 500 Stunden.');
        $events = $this->events($this->stream($streamFailed, ['content' => 'Spindel?']));
        self::assertSame(StreamEvent::ERROR, $events[array_key_last($events)]['event']);
        self::assertNotContains(StreamEvent::FRAME, self::typesOf($events));
        self::assertNull($this->finalRecord($streamFailed)->getFrame());

        // Cancelled while streaming: aborted, no frame.
        $cancelled = $this->agentConversation($version);
        $this->factory()->queueAnswer('Eins zwei drei vier fuenf.')->setChunkDelayMs(300)->setChunkHook(function (int $index) use ($cancelled): void {
            if (2 === $index) {
                $this->requestCancel($cancelled);
            }
        });
        $events = $this->events($this->stream($cancelled, ['content' => 'Zählen?']));
        $this->factory()->setChunkDelayMs(0)->setChunkHook(null);
        self::assertSame(StreamEvent::DONE, $events[array_key_last($events)]['event']);
        self::assertSame(MessageStatus::ABORTED, $events[array_key_last($events)]['data']['status']);
        self::assertNotContains(StreamEvent::FRAME, self::typesOf($events));
        $final = $this->finalRecord($cancelled);
        self::assertSame([MessageStatus::ABORTED, null], [$final->getStatus(), $final->getFrame()]);
    }

    public function testVersionWithoutFrameTemplateSendsTodaysEvents(): void
    {
        $version = $this->publishedVersion('no-frame', ['templates' => ['forcedCloseFallback' => ['de' => self::FALLBACK]]]);
        $conversation = $this->agentConversation($version);

        $this->factory()->queueAnswer('Alle 500 Stunden.');
        $data = $this->turn($conversation, ['content' => 'Spindel?'])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertNull($data['frame'] ?? null);

        $this->factory()->queueAnswer('Immer noch 500 Stunden.');
        $events = $this->events($this->stream($conversation, ['content' => 'Und jetzt?']));
        $types = self::typesOf($events);
        self::assertSame([StreamEvent::SOURCES, StreamEvent::TOKEN, StreamEvent::DONE], array_values(array_unique($types)));
        self::assertSame(StreamEvent::DONE, $types[array_key_last($types)]);
        foreach ($this->records($conversation) as $record) {
            self::assertNull($record->getFrame());
        }
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * The output templates of the fixture versions (languages de, en;
     * default de): a frame per language, German limit reasons and a German
     * fallback only (the English turns fall back to them).
     *
     * @return array<string, array<string, string>>
     */
    private static function templates(): array
    {
        return [
            'frame' => ['de' => '{{agentName}} sagt: {{answer}} (Ende)', 'en' => '{{agentName}} says: {{ answer }}'],
            'limitReason' => ['de' => '{{toolCalls}} von {{maxToolCalls}} Werkzeugaufrufen für {{agentName}}.'],
            'turnLimitReason' => ['de' => 'Runde {{turn}} von {{maxUserTurns}} für {{agentName}}.'],
            'forcedCloseFallback' => ['de' => '{{agentName}} konnte das leider nicht beantworten.'],
        ];
    }

    /**
     * An enabled agent `Rahmenbot` with version 1 (candidate defaults, the
     * output templates, plus $overrides) as its current version, written
     * through the entity manager.
     *
     * @param array<string, mixed> $overrides
     */
    private function publishedVersion(string $slug, array $overrides = []): AgentVersion
    {
        $agent = new Agent()->setName('Rahmenbot')->setSlug(AgentFixtures::PREFIX.'output-'.$slug)->setEnabled(true);
        $this->entityManager()->persist($agent);
        $this->entityManager()->flush();

        $configuration = $overrides + [
            'instructions' => ['de' => 'Du bist {{agentName}}.', 'en' => 'You are {{agentName}}.'],
            'languages' => ['de', 'en'],
            'defaultLanguage' => 'de',
            'templates' => self::templates(),
        ] + Agent::candidateDefaults();
        $version = new AgentVersion($agent, 1, ConversationConnections::withConnection($configuration), 'test-admin');
        $this->entityManager()->persist($version);
        $agent->setCurrentVersion($version);
        $this->entityManager()->flush();

        return $version;
    }

    /**
     * A conversation of `test-admin` pinned to $version (looked up again,
     * it may be detached), as the creation stores it.
     */
    private function agentConversation(AgentVersion $version, string $language = 'de', ?int $maxUserTurns = null): Conversation
    {
        $managed = $this->entityManager()->find(AgentVersion::class, $version->getId());
        self::assertInstanceOf(AgentVersion::class, $managed);

        $conversation = $this->conversation()
            ->setAgentVersion($managed)
            ->setLanguage($language)
            ->setVariables([])
            ->setMaxUserTurns($maxUserTurns);
        $this->entityManager()->flush();

        return $conversation;
    }

    /**
     * An MCP connection served by the fake transport at {@see DOCS_URL}
     * with the catalogue rows $tools.
     *
     * @param non-empty-list<string> $tools
     */
    private function docs(array $tools = ['search']): ApiConfiguration
    {
        $connection = $this->mcpConnection('output-templates-docs', $tools[0], self::DOCS_URL);
        foreach (\array_slice($tools, 1) as $tool) {
            AgentFixtures::tool($connection, $tool);
        }

        return $connection;
    }

    /**
     * One turn on $mode (`sync` answers 201, `stream` without a `frame`
     * event); the stream's event types, [] for a synchronous turn.
     *
     * @return list<string>
     */
    private function runTurn(Conversation $conversation, string $mode, string $content): array
    {
        if ('sync' === $mode) {
            $data = $this->turn($conversation, ['content' => $content])->toArray();
            self::assertResponseStatusCodeSame(201);
            self::assertNull($data['frame'] ?? null);

            return [];
        }

        $types = self::typesOf($this->events($this->stream($conversation, ['content' => $content])));
        self::assertNotContains(StreamEvent::FRAME, $types);
        self::assertSame(StreamEvent::DONE, $types[array_key_last($types)]);

        return $types;
    }

    private function finalRecord(Conversation $conversation): Message
    {
        $records = $this->records($conversation);
        self::assertNotEmpty($records);

        return $records[array_key_last($records)];
    }

    /**
     * @return list<?string> contents of the conversation's `limit` records
     */
    private function limitContents(Conversation $conversation): array
    {
        return array_values(array_map(
            static fn (Message $m): ?string => $m->getContent(),
            array_filter($this->records($conversation), static fn (Message $m): bool => MessageKind::LIMIT === $m->getKind()),
        ));
    }

    private function recordedRequests(): int
    {
        return \count($this->factory()->getChatProvider()->getRecorded());
    }

    /**
     * The one provider request recorded after the first $skip.
     */
    private function requestAfter(int $skip): RequestRecord
    {
        $records = \array_slice(array_values($this->factory()->getChatProvider()->getRecorded()), $skip);
        self::assertCount(1, $records);
        self::assertInstanceOf(RequestRecord::class, $records[0]);

        return $records[0];
    }

    /**
     * @return list<string> the text contents of the request's messages
     */
    private static function contents(RequestRecord $record): array
    {
        return array_values(array_map(static fn (NeuronMessage $m): string => (string) $m->getContent(), $record->messages));
    }
}
