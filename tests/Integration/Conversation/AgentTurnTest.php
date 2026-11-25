<?php

// file generated with AI assistance: Claude Code - 2026-10-03 13:33:50 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Conversation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Conversation\ForcedClose;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Conversation\TerminalClose;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingsProviderLocator;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Provider\ToolChoice;
use Dmstr\NeuronAiBundle\Stream\StreamEvent;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ToolChoiceRequestRecord;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use Dmstr\NeuronAiBundle\Tests\Support\StreamTestSupport;
use Dmstr\NeuronPgvectorStore\PgVectorStore;
use NeuronAI\RAG\Document;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Turns of an agent conversation: a conversation with
 * a pinned `agentVersion` runs every turn, synchronous and streamed, on
 * that version mapped with the stored variables and language: its
 * instructions with the placeholders filled (the same system prompt and
 * tools as the stateless `POST /agents/{id}/chat`), its tool subset (not
 * the `mcpConnections` column), its terminal tools, its response schema
 * (records of kind `schema`), its `maxToolCalls` (the request may only
 * lower it), its provider and model (not the columns) and its sampling.
 * Publishing or rolling back the agent after creation changes nothing, a
 * disabled agent keeps its running conversations turning, a collection of
 * the version deleted after creation refuses the turn with 422 and the
 * path. `responseSchema` and `terminalTools` (also `[]`) on either route
 * answer 422 naming the key, before any provider call. Conversations
 * without an agent are unchanged. A version stored before the
 * keys `rules` and `templates` existed runs exactly like one with empty
 * rules (same records, events and provider request); neither
 * has a `frame` event or a framed record. A version with
 * `hybrid` searches keyword plus vector on the synchronous turn, the
 * streamed turn and `agent_chat` alike (RRF scores in the sources); a
 * version without it keeps cosine scores.
 *
 * Fixtures: conversations of the default test identity `test-admin` and MCP
 * connections served by the fake transport from {@see StreamTestSupport};
 * agents, versions and collections written through the entity manager
 * with the prefix of {@see AgentFixtures} (removed by its cleanUp());
 * the agent conversations are written directly (`agentVersion`,
 * `language`, `variables`, `maxUserTurns`), their creation path is covered
 * by {@see ConversationAgentCreationTest}. Needs the migrated test
 * database (CI only).
 */
#[Group('database')]
final class AgentTurnTest extends ApiTestCase
{
    use StreamTestSupport {
        tearDown as private streamTearDown;
    }

    protected static ?bool $alwaysBootKernel = true;

    private const string DOCS_URL = 'https://docs.agent-turn.example.org/mcp';

    private const string SCHEMA_TEXT = 'Respond ONLY with a single JSON object';

    protected function tearDown(): void
    {
        foreach ($this->conversationIds as $id) {
            $this->dbal()->executeStatement('DELETE FROM dmstr_log_entries WHERE object_class = :class AND object_id = :id', ['class' => Conversation::class, 'id' => $id]);
        }
        $this->streamTearDown();
        AgentFixtures::cleanUp();
    }

    public function testSyncAndStreamedTurnsRunOnThePinnedVersionLikeTheStatelessCall(): void
    {
        $docs = $this->docs(['search', 'fetch']);
        $version = $this->publishedVersion('pinned', [
            'instructions' => [
                'de' => 'Du bist {{agentName}}. Kunde: {{customer}}.',
                'en' => 'You are {{agentName}} ({{language}}). Customer: {{customer}}.',
            ],
            'languages' => ['de', 'en'],
            'defaultLanguage' => 'de',
            'startSchema' => ['type' => 'object', 'properties' => ['customer' => ['type' => 'string']]],
            'mcpTools' => [['connection' => (string) $docs->getId(), 'name' => 'fetch']],
            'modelOptions' => ['temperature' => 0.3, 'maxTokens' => 512],
        ], 'Wartungsassistent');
        // The columns say ollama and offer the whole connection: the
        // version wins (its anthropic connection, the subset `fetch`).
        $conversation = $this->agentConversation($version, 'en', ['customer' => 'ACME']);
        $conversation->setProviderConnection(ConversationConnections::ollama())->setModel(FakeProviderFactory::OLLAMA_MODEL)->setMcpConnections([(string) $docs->getId()]);
        $this->entityManager()->flush();
        $expectedPrompt = 'You are Wartungsassistent (en). Customer: ACME.';
        $expectedProvider = ConversationConnections::requested('anthropic', temperature: 0.3, maxTokens: 512);

        // Synchronous turn.
        [$before, $providers] = $this->marks();
        $this->factory()->queueAnswer('Every 500 hours.');
        $data = $this->turn($conversation, ['content' => 'Spindel?'])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Every 500 hours.', $data['content']);
        self::assertSame('anthropic', $data['provider']);
        $sync = $this->onlyRequest($before);
        self::assertStringContainsString($expectedPrompt, (string) $sync->systemPrompt?->getContent());
        self::assertStringNotContainsString('{{', (string) $sync->systemPrompt?->getContent());
        self::assertSame(['fetch'], self::toolNames($sync));
        self::assertSame($expectedProvider, $this->requestedProviders($providers)[0]);

        // Streamed turn: same prompt, tools and sampling.
        [$before, $providers] = $this->marks();
        $this->factory()->queueAnswer('Every 500 hours, again.');
        $events = $this->events($this->stream($conversation, ['content' => 'Spindel, nochmal?']));
        self::assertSame(MessageStatus::COMPLETE, $events[array_key_last($events)]['data']['status']);
        $streamed = $this->onlyRequest($before);
        self::assertStringContainsString($expectedPrompt, (string) $streamed->systemPrompt?->getContent());
        self::assertSame(['fetch'], self::toolNames($streamed));
        self::assertSame($expectedProvider, $this->requestedProviders($providers)[0]);

        // The stateless call on the same version shows the same prompt and tools.
        [$before] = $this->marks();
        $this->factory()->queueAnswer('Every 500 hours.');
        $chat = AgentFixtures::send($this->client, 'POST', AgentFixtures::AGENTS.'/'.$version->getAgent()->getId().'/chat', AgentFixtures::USER, [
            'messages' => [['role' => 'user', 'content' => 'Spindel?']],
            'language' => 'en',
            'variables' => ['customer' => 'ACME'],
        ]);
        self::assertSame(200, $chat->getStatusCode(), $chat->getContent(false));
        $stateless = $this->onlyRequest($before);
        self::assertSame((string) $stateless->systemPrompt?->getContent(), (string) $sync->systemPrompt?->getContent());
        self::assertSame(self::toolNames($stateless), self::toolNames($sync));
    }

    public function testTerminalToolOfTheVersionIsTargetedOnBothRoutes(): void
    {
        $docs = $this->docs(['search', 'submit_answer']);
        $version = $this->publishedVersion('terminal', ['mcpTools' => [
            ['connection' => (string) $docs->getId(), 'name' => 'search'],
            ['connection' => (string) $docs->getId(), 'name' => 'submit_answer', 'terminal' => true],
        ]]);

        foreach (['sync', 'stream'] as $mode) {
            // The last allowed turn is forced from its first inference: the
            // forced step targets the version's terminal tool.
            $conversation = $this->agentConversation($version, maxUserTurns: 1);
            $this->mcpFake()->queueToolResult('Alle 500 Stunden.', url: self::DOCS_URL);
            $this->factory()->queueToolCall('submit_answer', ['text' => 'alle 500 Stunden'], 'call-'.$mode);
            [$before] = $this->marks();

            if ('sync' === $mode) {
                $data = $this->turn($conversation, ['content' => 'Spindel?'])->toArray();
                self::assertResponseStatusCodeSame(201);
                self::assertSame(TerminalClose::REASON, $data['closeReason']);
            } else {
                $events = $this->events($this->stream($conversation, ['content' => 'Spindel?']));
                self::assertSame(TerminalClose::REASON, $events[array_key_last($events)]['data']['closeReason']);
            }

            $request = $this->onlyRequest($before);
            self::assertSame(['search', 'submit_answer'], self::toolNames($request), $mode);
            self::assertTrue(ToolChoice::tool('submit_answer')->equals($request->toolChoice), $mode);
            $records = $this->records($conversation);
            self::assertSame(TerminalClose::REASON, $records[array_key_last($records)]->getCloseReason(), $mode);
        }
    }

    public function testVersionBoundsToolCallsAndTheRequestOnlyLowers(): void
    {
        $docs = $this->docs(['search']);
        $version = $this->publishedVersion('bound', [
            'maxToolCalls' => 2,
            'mcpTools' => [['connection' => (string) $docs->getId(), 'name' => 'search']],
        ]);

        // Request 5 is clamped to the version's 2: two parallel calls reach it.
        $raised = $this->agentConversation($version);
        $this->queueCalls(2);
        $data = $this->turn($raised, ['content' => 'Spindel?', 'maxToolCalls' => 5])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame(ForcedClose::REASON_BOUND, $data['closeReason']);
        self::assertSame([ForcedClose::limitReason(2, 2)], $this->limitContents($raised));

        // Request 1 lowers it: one call reaches it (streamed).
        $lowered = $this->agentConversation($version);
        $this->queueCalls(1);
        $events = $this->events($this->stream($lowered, ['content' => 'Spindel?', 'maxToolCalls' => 1]));
        self::assertContains(StreamEvent::LIMIT, self::typesOf($events));
        self::assertSame(ForcedClose::REASON_BOUND, $events[array_key_last($events)]['data']['closeReason']);
        self::assertSame([ForcedClose::limitReason(1, 1)], $this->limitContents($lowered));

        // Without a request value the version's 2 applies: one call does not reach it.
        $plain = $this->agentConversation($version);
        $this->queueCalls(1);
        $data = $this->turn($plain, ['content' => 'Spindel?'])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertNull($data['closeReason'] ?? null);
        self::assertSame([], $this->limitContents($plain));
    }

    public function testSchemaOfTheVersionMakesSchemaRecords(): void
    {
        $schema = ['type' => 'object', 'properties' => ['color' => ['type' => 'string']], 'required' => ['color']];
        $version = $this->publishedVersion('schema', ['responseSchema' => $schema]);

        // Synchronous.
        $conversation = $this->agentConversation($version);
        $this->factory()->queueAnswer('{"color": "blue"}');
        [$before] = $this->marks();
        $data = $this->turn($conversation, ['content' => 'Farbe?'])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame(MessageKind::SCHEMA, $data['kind']);
        self::assertSame(['color' => 'blue'], $data['structured']);
        self::assertStringContainsString(self::SCHEMA_TEXT, (string) $this->onlyRequest($before)->systemPrompt?->getContent());
        $this->assertSchemaRecords($conversation, $schema);

        // Streamed: the placeholder is a schema record from the start.
        $conversation = $this->agentConversation($version);
        $this->factory()->queueAnswer('{"color": "green"}');
        $events = $this->events($this->stream($conversation, ['content' => 'Farbe?']));
        self::assertSame(MessageStatus::COMPLETE, $events[array_key_last($events)]['data']['status']);
        $records = $this->assertSchemaRecords($conversation, $schema);
        self::assertSame(['color' => 'green'], $records[array_key_last($records)]->getStructured());
    }

    public function testPublishAndRollbackAfterCreationChangeNothing(): void
    {
        $first = $this->publishedVersion('versions', ['instructions' => ['de' => 'Anweisung der Version eins.']]);
        $agent = $first->getAgent();
        $onFirst = $this->agentConversation($first);

        // Publish version 2: the conversation pinned to version 1 stays there.
        $second = $this->version($agent, 2, ['instructions' => ['de' => 'Anweisung der Version zwei.']]);
        $agent->setCurrentVersion($second);
        $this->entityManager()->flush();
        $onSecond = $this->agentConversation($second);

        $this->factory()->queueAnswer('Eins');
        [$before] = $this->marks();
        $this->turn($onFirst, ['content' => 'Welche Version?']);
        self::assertResponseStatusCodeSame(201);
        $prompt = (string) $this->onlyRequest($before)->systemPrompt?->getContent();
        self::assertStringContainsString('Anweisung der Version eins.', $prompt);
        self::assertStringNotContainsString('Version zwei', $prompt);

        // Roll back to version 1: the conversation pinned to version 2 stays there.
        $this->dbal()->executeStatement('UPDATE neuron_agent SET current_version_id = :version WHERE id = :id', ['version' => $first->getId()->toRfc4122(), 'id' => $agent->getId()->toRfc4122()]);
        $this->entityManager()->clear();

        $this->factory()->queueAnswer('Zwei');
        [$before] = $this->marks();
        $this->events($this->stream($onSecond, ['content' => 'Welche Version?']));
        $prompt = (string) $this->onlyRequest($before)->systemPrompt?->getContent();
        self::assertStringContainsString('Anweisung der Version zwei.', $prompt);
        self::assertStringNotContainsString('Version eins', $prompt);
    }

    public function testVersionFixedKeysAreRejectedOnBothRoutes(): void
    {
        $docs = $this->docs(['submit_answer']);
        $version = $this->publishedVersion('fixed', ['mcpTools' => [['connection' => (string) $docs->getId(), 'name' => 'submit_answer', 'terminal' => true]]]);
        $conversation = $this->agentConversation($version);
        $calls = $this->providerCalls();

        $cases = [
            'responseSchema' => ['type' => 'object'],
            'terminalTools' => ['submit_answer'],
            'terminalTools []' => [],
        ];
        foreach ($cases as $case => $value) {
            $key = explode(' ', $case)[0];
            foreach (['sync', 'stream'] as $mode) {
                $body = ['content' => 'Spindel?', $key => $value];
                $response = 'sync' === $mode ? $this->turn($conversation, $body) : $this->stream($conversation, $body);

                self::assertSame(422, $response->getStatusCode(), $case.' '.$mode.': '.$response->getContent(false));
                self::assertSame(
                    \sprintf('"%s" is fixed by the agent version and cannot be set on a turn of an agent conversation.', $key),
                    $response->toArray(false)['detail'] ?? null,
                    $case.' '.$mode,
                );
                self::assertStringNotContainsString('event:', $response->getContent(false), $case.' '.$mode);
            }
        }

        self::assertSame($calls, $this->providerCalls(), 'no provider call');
        self::assertSame([], $this->records($conversation));
    }

    public function testStaleCollectionRefusesTheTurnWithItsPath(): void
    {
        $collection = AgentFixtures::collection('agent-turn');
        $collectionId = $collection->getId();
        $version = $this->publishedVersion('stale', ['collections' => [$collectionId->toRfc4122()]]);
        $conversation = $this->agentConversation($version);
        $conversation->setCollections([$collectionId->toRfc4122()]);
        $this->entityManager()->flush();

        $stale = $this->entityManager()->find(Collection::class, $collectionId);
        self::assertInstanceOf(Collection::class, $stale);
        $this->entityManager()->remove($stale);
        $this->entityManager()->flush();
        $calls = $this->providerCalls();

        foreach (['sync', 'stream'] as $mode) {
            $response = 'sync' === $mode
                ? $this->turn($conversation, ['content' => 'Spindel?'])
                : $this->stream($conversation, ['content' => 'Spindel?']);

            self::assertSame(422, $response->getStatusCode(), $mode.': '.$response->getContent(false));
            self::assertStringContainsString(
                'configuration/collections/0: Collection "'.$collectionId->toRfc4122().'" does not exist (any more).',
                (string) ($response->toArray(false)['detail'] ?? ''),
                $mode,
            );
        }

        self::assertSame($calls, $this->providerCalls(), 'no provider call');
        self::assertSame([], $this->records($conversation), 'no record stored');
    }

    public function testTurnsRunOnTheVersionsConnectionAndADeactivatedOneRefusesWithItsPath(): void
    {
        $remote = AgentFixtures::providerConnection('agent-turn-remote', 'ollama');
        $entry = AgentFixtures::catalogueModel(AgentFixtures::entityManager(), $remote, FakeProviderFactory::OLLAMA_MODEL);
        $version = $this->publishedVersion('remote', ['model' => $entry->getId()->toRfc4122()]);
        $conversation = $this->agentConversation($version);

        [$before, $providers] = $this->marks();
        $this->factory()->queueAnswer('Alle 500 Stunden.');
        $data = $this->turn($conversation, ['content' => 'Spindel?'])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame('ollama', $data['provider']);
        self::assertSame($entry->iri(), $data['model'], 'the record references the version\'s catalogue entry');
        self::assertSame(FakeProviderFactory::OLLAMA_MODEL, $data['modelName']);
        self::assertSame($entry->getId()->toRfc4122(), $this->records($conversation)[1]->getProviderModel()?->getId()->toRfc4122());
        self::assertSame(['provider' => 'ollama', 'connection' => AgentFixtures::PREFIX.'agent-turn-remote', 'model' => FakeProviderFactory::OLLAMA_MODEL, 'temperature' => null, 'maxTokens' => null], $this->requestedProviders($providers)[0]);
        self::assertSame($remote->getId()->toRfc4122(), $this->records($conversation)[1]->getConnectionId());

        $managed = $this->entityManager()->find(ApiConfiguration::class, $remote->getId());
        self::assertInstanceOf(ApiConfiguration::class, $managed);
        $managed->setActive(false);
        $this->entityManager()->flush();
        $calls = $this->providerCalls();
        $stored = \count($this->records($conversation));

        foreach (['sync', 'stream'] as $mode) {
            $response = 'sync' === $mode
                ? $this->turn($conversation, ['content' => 'Spindel?'])
                : $this->stream($conversation, ['content' => 'Spindel?']);

            self::assertSame(422, $response->getStatusCode(), $mode.': '.$response->getContent(false));
            $detail = (string) ($response->toArray(false)['detail'] ?? '');
            self::assertStringContainsString('configuration/model: ', $detail, $mode);
            self::assertStringContainsString('inactive', $detail, $mode);
        }

        self::assertSame($calls, $this->providerCalls(), 'no provider call');
        self::assertCount($stored, $this->records($conversation), 'no record stored');
    }

    public function testDisablingTheAgentLaterKeepsTheConversationRunning(): void
    {
        $version = $this->publishedVersion('disabled', ['instructions' => ['de' => 'Anweisung des abgeschalteten Agenten.']]);
        $conversation = $this->agentConversation($version);
        $this->dbal()->executeStatement('UPDATE neuron_agent SET enabled = false WHERE id = :id', ['id' => $version->getAgent()->getId()->toRfc4122()]);
        $this->entityManager()->clear();

        foreach (['sync', 'stream'] as $mode) {
            $this->factory()->queueAnswer('Trotzdem');
            [$before] = $this->marks();
            if ('sync' === $mode) {
                $this->turn($conversation, ['content' => 'Noch da?']);
                self::assertResponseStatusCodeSame(201);
            } else {
                $events = $this->events($this->stream($conversation, ['content' => 'Noch da?']));
                self::assertSame(MessageStatus::COMPLETE, $events[array_key_last($events)]['data']['status']);
            }
            self::assertStringContainsString('Anweisung des abgeschalteten Agenten.', (string) $this->onlyRequest($before)->systemPrompt?->getContent(), $mode);
        }
    }

    public function testVersionWithoutRuleKeysRunsLikeOneWithEmptyRules(): void
    {
        $empty = $this->publishedVersion('empty-rules', ['rules' => [], 'templates' => []]);
        $legacyConfiguration = ['model' => ConversationConnections::modelId()] + Agent::candidateDefaults();
        unset($legacyConfiguration['rules'], $legacyConfiguration['templates']);
        $legacyAgent = new Agent()->setName('Agent empty-rules')->setSlug(AgentFixtures::PREFIX.'turn-legacy')->setEnabled(true);
        $this->entityManager()->persist($legacyAgent);
        $this->entityManager()->flush();
        $legacy = new AgentVersion($legacyAgent, 1, $legacyConfiguration, 'test-admin');
        $this->entityManager()->persist($legacy);
        $legacyAgent->setCurrentVersion($legacy);
        $this->entityManager()->flush();
        self::assertArrayNotHasKey('rules', $legacy->getConfiguration());

        $runs = [];
        foreach (['empty' => $empty, 'legacy' => $legacy] as $name => $version) {
            $conversation = $this->agentConversation($version);
            [$before] = $this->marks();
            $this->factory()->queueAnswer('Alle 500 Stunden.')->queueAnswer('Immer noch 500 Stunden.');
            $this->turn($conversation, ['content' => 'Passwort und Öffnungszeiten?']);
            self::assertResponseStatusCodeSame(201);
            $events = $this->events($this->stream($conversation, ['content' => 'Und die Spindel?']));
            $requests = \array_slice(array_values($this->factory()->getChatProvider()->getRecorded()), $before);

            $runs[$name] = [
                'records' => array_map(static fn (Message $m): array => self::withoutIds([
                    $m->getRole(), $m->getKind(), $m->getStatus(), $m->getStep(), $m->getContent(), $m->getCloseReason(),
                    $m->getSources(), $m->getUsage(), $m->getProviderPayload(), $m->getProvider(), $m->getModel(), $m->getFrame(),
                ]), $this->records($conversation)),
                'events' => array_map(static fn (array $e): array => [$e['event'], self::withoutIds($e['data'])], $events),
                'requests' => array_map(static fn (ToolChoiceRequestRecord $r): array => [$r->systemPrompt?->getContent(), \count($r->messages), self::toolNames($r)], $requests),
            ];
        }

        self::assertSame([StreamEvent::SOURCES, StreamEvent::TOKEN, StreamEvent::DONE], array_values(array_unique(array_column($runs['legacy']['events'], 0))));
        self::assertSame([MessageKind::TEXT, MessageKind::TEXT, MessageKind::TEXT, MessageKind::TEXT], array_column($runs['legacy']['records'], 1));
        self::assertSame([null, null, null, null], array_column($runs['legacy']['records'], 11), 'no frame without a frame template');
        self::assertCount(2, $runs['legacy']['requests']);
        self::assertSame($runs['empty'], $runs['legacy']);
    }

    /**
     * The sources of a hybrid version carry RRF scores on all three paths.
     * One collection, two chunks, query "Spindel?": the keyword list holds
     * the Spindel chunk only (rank 1, weight 0.25), the vector list both
     * chunks (ranks 1 and 2 in fake-cosine order, weight 0.75). Whatever the
     * vector order, the Spindel chunk ranks first and the two scores sum to
     * 0.25/61 + 0.75/61 + 0.75/62. The same collection on a vector-only
     * version yields cosine scores, which do not add up to that sum.
     */
    public function testHybridVersionSearchesHybridOnSyncStreamAndAgentChat(): void
    {
        $this->usesStore = true;
        $collection = AgentFixtures::collection('agent-turn-hybrid');
        $this->addChunks($collection, ['Die Spindel muss alle 500 Stunden gewartet werden.', 'Der Hauptschalter sitzt links.']);
        $collectionId = $collection->getId()->toRfc4122();
        $rrfSum = 0.25 / 61 + 0.75 / 61 + 0.75 / 62;

        $runs = [];
        foreach (['hybrid' => true, 'vector' => false] as $name => $hybrid) {
            $version = $this->publishedVersion('hybrid-'.$name, ['collections' => [$collectionId], 'retrievalDepth' => 2, 'hybrid' => $hybrid, 'hybridWeight' => 0.25]);
            $conversation = $this->agentConversation($version);

            $this->factory()->queueAnswer('Alle 500 Stunden.');
            $sync = $this->turn($conversation, ['content' => 'Spindel?']);
            self::assertSame(201, $sync->getStatusCode(), $sync->getContent(false));

            // A conversation of its own: as a second turn the search text
            // would also hold the first question (turn.retrieval_history_messages).
            $this->factory()->queueAnswer('Alle 500 Stunden, nochmal.');
            $events = $this->events($this->stream($this->agentConversation($version), ['content' => 'Spindel?']));
            self::assertSame(StreamEvent::SOURCES, $events[0]['event']);

            $this->factory()->queueAnswer('Alle 500 Stunden.');
            $chat = AgentFixtures::send($this->client, 'POST', AgentFixtures::AGENTS.'/'.$version->getAgent()->getId().'/chat', AgentFixtures::USER, [
                'messages' => [['role' => 'user', 'content' => 'Spindel?']],
            ]);
            self::assertSame(200, $chat->getStatusCode(), $chat->getContent(false));

            // Content and score per source; the three paths serialise the
            // same ContextChunk::toArray().
            $project = static fn (array $sources): array => array_map(static fn (array $source): array => [$source['content'], $source['score']], $sources);
            $runs[$name] = [
                'sync' => $project($sync->toArray()['sources']),
                'stream' => $project($events[0]['data']['sources']),
                'agent_chat' => $project($chat->toArray()['sources']),
            ];
        }

        foreach ($runs['hybrid'] as $path => $sources) {
            self::assertCount(2, $sources, $path);
            self::assertStringContainsString('Spindel', $sources[0][0], $path);
            self::assertEqualsWithDelta($rrfSum, $sources[0][1] + $sources[1][1], 1e-9, $path);
            self::assertLessThan(1 / 30, $sources[0][1], $path.': an RRF score, at most 1/61 per list');
        }
        self::assertSame($runs['hybrid']['sync'], $runs['hybrid']['stream']);
        self::assertSame($runs['hybrid']['sync'], $runs['hybrid']['agent_chat']);

        foreach ($runs['vector'] as $path => $sources) {
            self::assertCount(2, $sources, $path);
            self::assertNotEqualsWithDelta($rrfSum, $sources[0][1] + $sources[1][1], 1e-9, $path.': cosine scores');
        }
        self::assertSame($runs['vector']['sync'], $runs['vector']['agent_chat']);
    }

    public function testConversationWithoutAgentIsUnchanged(): void
    {
        $docs = $this->docs(['search', 'submit_answer']);
        $conversation = $this->conversation(mcpConnections: [(string) $docs->getId()]);

        // Tools from the column, terminalTools [] and a responseSchema accepted.
        $this->factory()->queueAnswer('{"color": "blue"}');
        [$before, $providers] = $this->marks();
        $data = $this->turn($conversation, [
            'content' => 'Farbe?',
            'responseSchema' => ['type' => 'object', 'properties' => ['color' => ['type' => 'string']]],
            'terminalTools' => [],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame(MessageKind::SCHEMA, $data['kind']);
        $request = $this->onlyRequest($before);
        self::assertSame(['search', 'submit_answer'], self::toolNames($request));
        self::assertNull($request->toolChoice);
        self::assertSame(ConversationConnections::requested('anthropic'), $this->requestedProviders($providers)[0]);

        // Streamed with a terminal tool of the request.
        $this->mcpFake()->queueToolResult('Alle 500 Stunden.', url: self::DOCS_URL);
        $this->factory()->queueToolCall('submit_answer', ['text' => 'alle 500 Stunden'], 'call-plain');
        $events = $this->events($this->stream($conversation, ['content' => 'Spindel?', 'terminalTools' => ['submit_answer']]));
        self::assertSame(TerminalClose::REASON, $events[array_key_last($events)]['data']['closeReason']);
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * $value without the random parts of two otherwise identical runs: the
     * Neuron message ids (`__id`) and the record ids (`messageId`).
     */
    private static function withoutIds(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        unset($value['__id'], $value['messageId']);

        return array_map(self::withoutIds(...), $value);
    }

    /**
     * An agent (enabled) with version 1 = candidate defaults plus $overrides as
     * its current version, written through the entity manager.
     *
     * @param array<string, mixed> $overrides
     */
    private function publishedVersion(string $slug, array $overrides = [], ?string $name = null): AgentVersion
    {
        $agent = new Agent()->setName($name ?? 'Agent '.$slug)->setSlug(AgentFixtures::PREFIX.'turn-'.$slug)->setEnabled(true);
        $this->entityManager()->persist($agent);
        $this->entityManager()->flush();
        $version = $this->version($agent, 1, $overrides);
        $agent->setCurrentVersion($version);
        $this->entityManager()->flush();

        return $version;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function version(Agent $agent, int $number, array $overrides): AgentVersion
    {
        $version = new AgentVersion($agent, $number, $overrides + ['model' => ConversationConnections::modelId()] + Agent::candidateDefaults(), 'test-admin');
        $this->entityManager()->persist($version);
        $this->entityManager()->flush();

        return $version;
    }

    /**
     * A conversation of `test-admin` pinned to $version as the creation
     * stores it: language, variables (`{}` when none) and `maxUserTurns`.
     *
     * $version may be detached: records() clears the entity manager and a
     * request through the client may reset it, so the version is looked up
     * again in the current entity manager before it is pinned; a detached
     * instance would count as a new entity on flush.
     *
     * @param array<string, scalar> $variables
     */
    private function agentConversation(AgentVersion $version, string $language = 'de', array $variables = [], ?int $maxUserTurns = null): Conversation
    {
        $managed = $this->entityManager()->find(AgentVersion::class, $version->getId());
        self::assertInstanceOf(AgentVersion::class, $managed);

        $conversation = $this->conversation()
            ->setAgentVersion($managed)
            ->setLanguage($language)
            ->setVariables($variables)
            ->setMaxUserTurns($maxUserTurns);
        $this->entityManager()->flush();

        return $conversation;
    }

    /**
     * Chunks of $collection in the store (model `nomic-embed-text`).
     *
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
                    'source_url' => 'https://example.org/agent-turn/'.$number,
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

    /**
     * An MCP connection served by the fake transport at {@see DOCS_URL}
     * with the catalogue rows $tools.
     *
     * @param non-empty-list<string> $tools
     */
    private function docs(array $tools): ApiConfiguration
    {
        $connection = $this->mcpConnection('agent-turn-docs', $tools[0], self::DOCS_URL);
        foreach (\array_slice($tools, 1) as $tool) {
            AgentFixtures::tool($connection, $tool);
        }

        return $connection;
    }

    /**
     * $count parallel `search` calls in one response, then the answer; the
     * fake MCP tool answers each.
     */
    private function queueCalls(int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $this->mcpFake()->queueToolResult('Treffer '.$i, url: self::DOCS_URL);
            $this->factory()->queueToolCall('search', ['query' => 'Spindel '.$i]);
        }
        $this->factory()->queueAnswer('Alle 500 Stunden.');
    }

    /**
     * The user record carries the version's schema, the final record is of
     * kind `schema` with it.
     *
     * @param array<string, mixed> $schema
     *
     * @return list<Message>
     */
    private function assertSchemaRecords(Conversation $conversation, array $schema): array
    {
        $records = $this->records($conversation);
        self::assertGreaterThanOrEqual(2, \count($records));
        self::assertSame(Message::SPEAKER_USER, $records[0]->getRole());
        self::assertSame($schema, $records[0]->getResponseSchema());
        $final = $records[array_key_last($records)];
        self::assertSame(MessageKind::SCHEMA, $final->getKind());
        self::assertSame(MessageStatus::COMPLETE, $final->getStatus());
        self::assertSame($schema, $final->getResponseSchema());

        return $records;
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

    /**
     * Current counts of recorded provider requests and requested providers.
     *
     * @return array{int, int}
     */
    private function marks(): array
    {
        return [\count($this->factory()->getChatProvider()->getRecorded()), \count($this->factory()->getRequestedProviders())];
    }

    /**
     * The first provider request recorded after the first $skip (the
     * answers in these tests take one inference unless a tool runs).
     */
    private function onlyRequest(int $skip): ToolChoiceRequestRecord
    {
        $records = \array_slice(array_values($this->factory()->getChatProvider()->getRecorded()), $skip);
        self::assertNotEmpty($records);
        self::assertInstanceOf(ToolChoiceRequestRecord::class, $records[0]);

        return $records[0];
    }

    /**
     * @return list<array{provider: string, connection?: string, model: ?string, temperature: ?float, maxTokens: ?int}>
     */
    private function requestedProviders(int $skip): array
    {
        $requested = \array_slice($this->factory()->getRequestedProviders(), $skip);
        self::assertNotEmpty($requested);

        return $requested;
    }

    /**
     * @return list<string>
     */
    private static function toolNames(ToolChoiceRequestRecord $record): array
    {
        return array_values(array_map(
            static fn (ToolInterface|ProviderToolInterface $tool): string => $tool->getName(),
            $record->tools,
        ));
    }

    private function providerCalls(): int
    {
        return $this->factory()->getChatProvider()->getCallCount();
    }
}
