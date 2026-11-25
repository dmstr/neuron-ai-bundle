<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:35:36 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Conversation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Conversation\ConversationStatus;
use Dmstr\NeuronAiBundle\Conversation\ForcedClose;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Conversation\TerminalClose;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingsProviderLocator;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Entity\Refs\McpTool;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use Dmstr\NeuronPgvectorStore\PgVectorStore;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Functional test of `POST /api/conversations/{id}/messages`: records, sequences, title, replay of the
 * earlier turn, tool calls (also failing ones), reasoning, schema turns,
 * retrieval sources, usage and the 422 cases. A turn with
 * `maxToolCalls` ends with a `limit` record and the forced answer with
 * `closeReason` `forced_bound`; an invalid bound answers 422; an unbounded
 * turn keeps its records without either. A call of a tool named
 * in `terminalTools` ends the turn with its unwrapped result as the answer
 * (`closeReason` `terminal_tool`, the `tool_call` record kept), the next
 * turn replays the call with its result, and an unknown, duplicate or
 * non-string terminal tool name answers 422. E3.1: the turn runs on the
 * conversation's provider connection (provider type, connection name and
 * model in the requested provider, the connection id on the assistant
 * records); a deactivated connection or a conversation stored before
 * provider connections answers 422 before any record or provider call.
 *
 * Needs the migrated test database (CI only). Conversations are created
 * through the entity manager for the default test identity `test-admin`
 * (no X-Test-* headers) on the `anthropic` fixture connection
 * ({@see ConversationConnections}) and removed after each test; MCP and
 * further provider connections carry the name prefix `test-turn-`.
 */
#[Group('database')]
final class TurnTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const string PREFIX = 'test-turn-';
    private const string OWNER = 'test-admin';
    private const string DOCS_URL = 'https://docs.example.org/mcp';

    private Client $client;

    /** @var list<string> */
    private array $conversationIds = [];

    private bool $usesStore = false;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        foreach ($this->conversationIds as $id) {
            $connection->executeStatement('DELETE FROM neuron_conversation WHERE id = :id', ['id' => $id]);
        }
        $connection->executeStatement('DELETE FROM dmstr_api_configuration WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
        if ($this->usesStore) {
            $connection->executeStatement('TRUNCATE embedding, neuron_collection CASCADE');
        }
        parent::tearDown();
    }

    public function testFirstTurnStoresBothRecordsAndSetsTheTitle(): void
    {
        $conversation = $this->conversation();
        $this->factory()->queueAnswer('Alle 500 Stunden.');

        $response = $this->turn($conversation, ['content' => 'Wann muss die Spindel gewartet werden?']);

        self::assertResponseStatusCodeSame(201);
        $data = $response->toArray();
        self::assertSame('Alle 500 Stunden.', $data['content']);
        self::assertSame(MessageKind::TEXT, $data['kind']);
        self::assertSame(MessageStatus::COMPLETE, $data['status']);
        self::assertSame(2, $data['sequence']);
        self::assertArrayNotHasKey('providerPayload', $data);

        $records = $this->records($conversation);
        self::assertSame([[1, Message::SPEAKER_USER, 0], [2, Message::SPEAKER_ASSISTANT, 1]], array_map(static fn (Message $m): array => [$m->getSequence(), $m->getRole(), $m->getStep()], $records));
        self::assertSame('anthropic', $records[1]->getProvider());
        self::assertSame(FakeProviderFactory::ANTHROPIC_MODEL, $records[1]->getModel());
        self::assertNotNull($records[1]->getProviderPayload());

        $reloaded = $this->reload($conversation);
        self::assertSame('Wann muss die Spindel gewartet werden?', $reloaded->getTitle());
        self::assertSame(3, $reloaded->getNextSequence());
        self::assertSame([ConversationConnections::requested('anthropic')], $this->factory()->getRequestedProviders());
        self::assertNull($records[0]->getConnectionId());
        self::assertSame(ConversationConnections::id(), $records[1]->getConnectionId());
    }

    public function testSecondTurnReplaysTheFirst(): void
    {
        $conversation = $this->conversation(title: 'Fester Titel');
        $this->factory()->queueAnswer('Alle 500 Stunden.')->queueAnswer('Alle 1000 Stunden.');

        $this->turn($conversation, ['content' => 'Spindel?']);
        $this->turn($conversation, ['content' => 'Und das Getriebe?']);

        self::assertResponseStatusCodeSame(201);
        $recorded = $this->factory()->getChatProvider()->getRecorded();
        $second = $recorded[1]->messages;
        self::assertCount(3, $second);
        self::assertInstanceOf(UserMessage::class, $second[0]);
        self::assertInstanceOf(AssistantMessage::class, $second[1]);
        self::assertSame('Alle 500 Stunden.', $second[1]->getContent());
        self::assertSame('Und das Getriebe?', $second[2]->getContent());
        self::assertSame([1, 2, 3, 4], array_map(static fn (Message $m): int => $m->getSequence(), $this->records($conversation)));
        self::assertSame('Fester Titel', $this->reload($conversation)->getTitle());
    }

    public function testToolCallsAreRecordedAndAFailingToolDoesNotAbortTheTurn(): void
    {
        $docs = $this->connection('docs');
        $this->tool($docs, 'search');
        $this->tool($docs, 'fetch');
        $conversation = $this->conversation(mcpConnections: [(string) $docs->getId()]);
        $this->fake()->queueToolResult('Spindel: alle 500 Stunden.', url: self::DOCS_URL);
        $this->fake()->queueError('tools/call', -32603, 'Index offline', self::DOCS_URL);
        $this->factory()
            ->queueToolCall('search', ['query' => 'Spindel'])
            ->queueToolCall('fetch', ['id' => 'doc-7'])
            ->queueAnswer('Alle 500 Stunden.');

        $this->turn($conversation, ['content' => 'Spindel?']);

        self::assertResponseStatusCodeSame(201);
        $records = $this->records($conversation);
        self::assertSame(
            [[MessageKind::TEXT, 0], [MessageKind::TOOL_CALL, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::TEXT, 2]],
            array_map(static fn (Message $m): array => [$m->getKind(), $m->getStep()], $records),
        );
        [, $search, $fetch, $answer] = $records;
        self::assertSame('search', $search->getToolName());
        self::assertSame(['query' => 'Spindel'], $search->getToolInput());
        self::assertStringContainsString('alle 500 Stunden', (string) $search->getToolResult());
        self::assertFalse($search->isToolError());
        self::assertGreaterThanOrEqual(0, $search->getDurationMs());
        self::assertNotNull($search->getToolCallId());
        self::assertTrue($fetch->isToolError());
        self::assertStringContainsString('Index offline', (string) $fetch->getToolResult());
        self::assertSame(MessageStatus::COMPLETE, $answer->getStatus());
        self::assertSame('Alle 500 Stunden.', $answer->getContent());

        // Unbounded: no limit record and no close reason anywhere.
        foreach ($records as $record) {
            self::assertNull($record->getCloseReason());
        }
        foreach ($this->listed($conversation) as $listed) {
            self::assertNotSame(MessageKind::LIMIT, $listed['kind']);
            self::assertNull($listed['closeReason'] ?? null);
        }
    }

    public function testBoundedTurnEndsWithALimitRecordAndTheForcedAnswer(): void
    {
        $docs = $this->connection('docs');
        $this->tool($docs, 'search');
        $this->tool($docs, 'fetch');
        $conversation = $this->conversation(mcpConnections: [(string) $docs->getId()]);
        $this->fake()
            ->queueToolResult('Spindel: alle 500 Stunden.', url: self::DOCS_URL)
            ->queueToolResult('Dokument 7: Wartungsplan.', url: self::DOCS_URL);
        $this->factory()
            ->queueToolCall('search', ['query' => 'Spindel'])
            ->queueToolCall('fetch', ['id' => 'doc-7'])
            ->queueAnswer('Alle 500 Stunden.');

        $data = $this->turn($conversation, ['content' => 'Spindel?', 'maxToolCalls' => 1])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame(MessageKind::TEXT, $data['kind']);
        self::assertSame(MessageStatus::COMPLETE, $data['status']);
        self::assertSame('Alle 500 Stunden.', $data['content']);
        self::assertSame(ForcedClose::REASON_BOUND, $data['closeReason']);

        // The batch that crossed the bound ran whole, then the limit record.
        $records = $this->records($conversation);
        self::assertSame(
            [[MessageKind::TEXT, 0], [MessageKind::TOOL_CALL, 1], [MessageKind::TOOL_CALL, 1], [MessageKind::LIMIT, 2], [MessageKind::TEXT, 2]],
            array_map(static fn (Message $m): array => [$m->getKind(), $m->getStep()], $records),
        );
        self::assertSame(ForcedClose::limitReason(2, 1), $records[3]->getContent());
        self::assertNull($records[3]->getCloseReason());
        self::assertSame(ForcedClose::REASON_BOUND, $records[4]->getCloseReason());
        self::assertCount(2, $this->fake()->getRequests(self::DOCS_URL, 'tools/call'));

        // The messages list shows the limit record before the forced answer.
        $listed = $this->listed($conversation);
        self::assertSame(
            [[MessageKind::TEXT, null], [MessageKind::TOOL_CALL, null], [MessageKind::TOOL_CALL, null], [MessageKind::LIMIT, null], [MessageKind::TEXT, ForcedClose::REASON_BOUND]],
            array_map(static fn (array $m): array => [$m['kind'], $m['closeReason'] ?? null], $listed),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBounds')]
    public function testInvalidBoundIsRejectedWithoutNewRecords(mixed $maxToolCalls): void
    {
        $conversation = $this->conversation();

        $this->turn($conversation, ['content' => 'Frage', 'maxToolCalls' => $maxToolCalls]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->records($conversation));
        $this->factory()->getChatProvider()->assertNothingSent();
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidBounds(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'string' => ['3'];
        yield 'fraction' => [2.5];
    }

    public function testTerminalToolCallEndsTheTurnWithItsResult(): void
    {
        $docs = $this->connection('docs');
        $this->tool($docs, 'search');
        $this->tool($docs, 'submit_answer');
        $conversation = $this->conversation(mcpConnections: [(string) $docs->getId()]);
        $this->fake()->queueToolResult('Die Spindel wird alle 500 Stunden gewartet.', url: self::DOCS_URL);
        $this->factory()->queueToolCall('submit_answer', ['text' => 'alle 500 Stunden'], 'call-terminal');

        $data = $this->turn($conversation, ['content' => 'Spindel?', 'terminalTools' => ['submit_answer']])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame(MessageKind::TEXT, $data['kind']);
        self::assertSame(MessageStatus::COMPLETE, $data['status']);
        // The MCP content list is unwrapped to its text.
        self::assertSame('Die Spindel wird alle 500 Stunden gewartet.', $data['content']);
        self::assertSame(TerminalClose::REASON, $data['closeReason']);
        self::assertNull($data['structured'] ?? null);

        // No further model call after the terminal tool ran.
        $this->factory()->getChatProvider()->assertCallCount(1);
        self::assertCount(1, $this->fake()->getRequests(self::DOCS_URL, 'tools/call'));

        // The tool_call record keeps the result as text (neuron-ai 4.x
        // ToolOutput::getText()); the final record shares its step.
        $records = $this->records($conversation);
        self::assertSame(
            [[MessageKind::TEXT, 0, null], [MessageKind::TOOL_CALL, 1, null], [MessageKind::TEXT, 1, TerminalClose::REASON]],
            array_map(static fn (Message $m): array => [$m->getKind(), $m->getStep(), $m->getCloseReason()], $records),
        );
        [, $call, $final] = $records;
        self::assertSame('submit_answer', $call->getToolName());
        self::assertSame('call-terminal', $call->getToolCallId());
        self::assertSame('Die Spindel wird alle 500 Stunden gewartet.', $call->getToolResult());
        self::assertFalse($call->isToolError());
        $payload = $final->getProviderPayload();
        self::assertSame('terminal', $payload['part'] ?? null);
        self::assertSame(['name' => 'submit_answer', 'callId' => 'call-terminal'], $payload['tool'] ?? null);
        self::assertArrayNotHasKey('message', $payload);

        // Not bounded: no limit record.
        foreach ($this->listed($conversation) as $listed) {
            self::assertNotSame(MessageKind::LIMIT, $listed['kind']);
        }
    }

    public function testTerminalJsonResultWithASchemaIsStructured(): void
    {
        $docs = $this->connection('docs');
        $this->tool($docs, 'submit_answer');
        $conversation = $this->conversation(mcpConnections: [(string) $docs->getId()]);
        $this->fake()->queueToolResult('{"hours": 500}', url: self::DOCS_URL);
        $this->factory()->queueToolCall('submit_answer', ['hours' => 500]);
        $schema = ['type' => 'object', 'properties' => ['hours' => ['type' => 'integer']], 'required' => ['hours']];

        $data = $this->turn($conversation, ['content' => 'Spindel?', 'responseSchema' => $schema, 'terminalTools' => ['submit_answer']])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame(MessageKind::SCHEMA, $data['kind']);
        self::assertSame(['hours' => 500], $data['structured']);
        self::assertSame(TerminalClose::REASON, $data['closeReason']);
        $this->factory()->getChatProvider()->assertCallCount(1);
    }

    public function testNextTurnReplaysTheTerminalCallWithItsResult(): void
    {
        $docs = $this->connection('docs');
        $this->tool($docs, 'submit_answer');
        $conversation = $this->conversation(mcpConnections: [(string) $docs->getId()]);
        $this->fake()->queueToolResult('Alle 500 Stunden.', url: self::DOCS_URL);
        $this->factory()
            ->queueToolCall('submit_answer', ['text' => 'alle 500 Stunden'], 'call-terminal')
            ->queueAnswer('Alle 1000 Stunden.');

        $this->turn($conversation, ['content' => 'Spindel?', 'terminalTools' => ['submit_answer']]);
        self::assertResponseStatusCodeSame(201);
        $data = $this->turn($conversation, ['content' => 'Und das Getriebe?'])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Alle 1000 Stunden.', $data['content']);
        self::assertNull($data['closeReason'] ?? null);

        $recorded = $this->factory()->getChatProvider()->getRecorded();
        self::assertCount(2, $recorded);
        $second = array_values($recorded[1]->messages);
        self::assertSame(
            [UserMessage::class, ToolCallMessage::class, ToolResultMessage::class, UserMessage::class],
            array_map(static fn (object $message): string => $message::class, $second),
        );
        self::assertSame('Spindel?', $second[0]->getContent());
        self::assertSame('Und das Getriebe?', $second[3]->getContent());

        /** @var ToolCallMessage $call */
        $call = $second[1];
        /** @var ToolResultMessage $result */
        $result = $second[2];
        self::assertSame(['call-terminal'], array_map(static fn (ToolCall $tool): ?string => $tool->getCallId(), $call->getToolCalls()));
        self::assertSame(['call-terminal'], array_map(static fn (ToolCall $tool): ?string => $tool->getCallId(), $result->getToolCalls()));
        self::assertStringContainsString('Alle 500 Stunden.', (string) $result->getToolCalls()[0]->getResult());
    }

    /**
     * @param list<mixed> $terminalTools
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidTerminalTools')]
    public function testInvalidTerminalToolsAreRejectedWithoutNewRecords(array $terminalTools): void
    {
        $docs = $this->connection('docs');
        $this->tool($docs, 'search');
        $conversation = $this->conversation(mcpConnections: [(string) $docs->getId()]);

        $this->turn($conversation, ['content' => 'Frage', 'terminalTools' => $terminalTools]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->records($conversation));
        $this->factory()->getChatProvider()->assertNothingSent();
        self::assertSame(0, $this->fake()->getRequestCount());
    }

    /**
     * @return iterable<string, array{list<mixed>}>
     */
    public static function invalidTerminalTools(): iterable
    {
        yield 'unknown name' => [['page']];
        yield 'duplicate name' => [['search', 'search']];
        yield 'non-string entry' => [['search', 42]];
        yield 'empty name' => [['']];
    }

    public function testUnknownTerminalToolIsNamedInTheProblem(): void
    {
        $docs = $this->connection('docs');
        $this->tool($docs, 'search');
        $conversation = $this->conversation(mcpConnections: [(string) $docs->getId()]);

        $response = $this->turn($conversation, ['content' => 'Frage', 'terminalTools' => ['search', 'page']]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('"page" is not a tool offered in this turn', $response->toArray(false)['detail']);
    }

    public function testReasoningAndAnswerShareOneStep(): void
    {
        $conversation = $this->conversation();
        $this->factory()->queueReasoning('Kurz nachdenken.', 'Blau.', 'sig-1');

        $this->turn($conversation, ['content' => 'Farbe?']);

        $records = $this->records($conversation);
        self::assertSame([[MessageKind::TEXT, 0], [MessageKind::REASONING, 1], [MessageKind::TEXT, 1]], array_map(static fn (Message $m): array => [$m->getKind(), $m->getStep()], $records));
        self::assertSame('sig-1', $records[1]->getProviderPayload()['signature']);
    }

    public function testUsageIsStoredOnTheAnswer(): void
    {
        $conversation = $this->conversation();
        $this->factory()->getChatProvider()->addResponses(new AssistantMessage('Blau.')->setUsage(new Usage(30, 4, 10, 0)));

        $data = $this->turn($conversation, ['content' => 'Farbe?'])->toArray();

        self::assertSame(['input_tokens' => 30, 'output_tokens' => 4, 'cached_input_tokens' => 10, 'reasoning_tokens' => 0], $data['usage']);
    }

    public function testSchemaTurnAnswersWithAParsedRecord(): void
    {
        $conversation = $this->conversation();
        $this->factory()->queueAnswer('{"color": "blue"}');
        $schema = ['type' => 'object', 'properties' => ['color' => ['type' => 'string']], 'required' => ['color']];

        $data = $this->turn($conversation, ['content' => 'Farbe?', 'responseSchema' => $schema])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame(MessageKind::SCHEMA, $data['kind']);
        self::assertSame(['color' => 'blue'], $data['structured']);
        self::assertStringContainsString('Respond ONLY with a single JSON object', (string) $this->factory()->getChatProvider()->getRecorded()[0]->systemPrompt?->getContent());
    }

    public function testCollectionContextIsSentAndReportedAsSources(): void
    {
        $this->usesStore = true;
        $handbook = $this->collection('handbuch');
        $this->addChunks($handbook, ['Die Spindel muss alle 500 Stunden gewartet werden.', 'Der Hauptschalter sitzt links.']);
        $conversation = $this->conversation(collections: [(string) $handbook->getId()]);
        $this->factory()->queueAnswer('Alle 500 Stunden [1].');

        $data = $this->turn($conversation, ['content' => 'Wann wird die Spindel gewartet?'])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertNotEmpty($data['sources']);
        foreach ($data['sources'] as $source) {
            self::assertSame((string) $handbook->getId(), $source['collectionId']);
            self::assertSame('handbuch', $source['collectionName']);
        }
        $prompt = (string) $this->factory()->getChatProvider()->getRecorded()[0]->systemPrompt?->getContent();
        self::assertStringContainsString('<context>', $prompt);
        self::assertStringContainsString('Spindel muss alle 500 Stunden', $prompt);
        $this->factory()->getEmbeddingsRecorder()->assertEmbeddedText('search_query: Wann wird die Spindel gewartet?', 'nomic-embed-text');
    }

    public function testMissingCollectionIsRejectedWithoutNewRecords(): void
    {
        $conversation = $this->conversation(collections: [(string) Uuid::v4()]);

        $this->turn($conversation, ['content' => 'Frage']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->records($conversation));
        $this->factory()->getChatProvider()->assertNothingSent();
    }

    public function testInactiveConnectionIsRejected(): void
    {
        $docs = $this->connection('docs', active: false);
        $conversation = $this->conversation(mcpConnections: [(string) $docs->getId()]);

        $this->turn($conversation, ['content' => 'Frage']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->records($conversation));
    }

    public function testDeactivatedProviderConnectionIsStale(): void
    {
        $remote = ProviderConnectionFixtures::ollama(self::PREFIX.'remote');
        ProviderConnectionFixtures::persist($this->entityManager(), $remote);
        $conversation = $this->conversation();
        $conversation->setProviderConnection($remote)->setModel(FakeProviderFactory::OLLAMA_MODEL);
        $this->entityManager()->flush();

        $this->factory()->queueAnswer('Blau.');
        $data = $this->turn($conversation, ['content' => 'Farbe?'])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame('ollama', $data['provider']);
        self::assertSame(['provider' => 'ollama', 'connection' => self::PREFIX.'remote', 'model' => FakeProviderFactory::OLLAMA_MODEL, 'temperature' => null, 'maxTokens' => null], $this->factory()->getRequestedProviders()[0]);

        $managed = $this->entityManager()->find(ApiConfiguration::class, $remote->getId());
        self::assertInstanceOf(ApiConfiguration::class, $managed);
        $managed->setActive(false);
        $this->entityManager()->flush();
        $stored = \count($this->records($conversation));
        $calls = $this->factory()->getChatProvider()->getCallCount();

        $response = $this->turn($conversation, ['content' => 'Noch einmal?']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('provider connection "'.self::PREFIX.'remote" of the conversation is inactive', (string) ($response->toArray(false)['detail'] ?? ''));
        self::assertCount($stored, $this->records($conversation));
        self::assertSame($calls, $this->factory()->getChatProvider()->getCallCount());
    }

    public function testConversationWithoutProviderConnectionIsStale(): void
    {
        // A conversation stored before provider connections: provider and
        // model columns, no provider_connection_id.
        $conversation = $this->conversation();
        static::getContainer()->get('doctrine.dbal.default_connection')->executeStatement('UPDATE neuron_conversation SET provider_connection_id = NULL WHERE id = :id', ['id' => (string) $conversation->getId()]);
        $this->entityManager()->clear();

        $response = $this->turn($conversation, ['content' => 'Frage']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('the conversation has no provider connection', (string) ($response->toArray(false)['detail'] ?? ''));
        self::assertSame([], $this->records($conversation));
        $this->factory()->getChatProvider()->assertNothingSent();
    }

    public function testArchivedConversationIsRejected(): void
    {
        $conversation = $this->conversation(status: ConversationStatus::ARCHIVED);

        $this->turn($conversation, ['content' => 'Frage']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->records($conversation));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function turn(Conversation $conversation, array $body): ResponseInterface
    {
        return $this->client->request('POST', '/api/conversations/'.$conversation->getId().'/messages', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => $body,
        ]);
    }

    /**
     * The conversation's messages as `GET /api/conversations/{id}/messages`
     * lists them (by sequence).
     *
     * @return list<array<string, mixed>>
     */
    private function listed(Conversation $conversation): array
    {
        $response = $this->client->request('GET', '/api/conversations/'.$conversation->getId().'/messages', [
            'headers' => ['Accept' => 'application/ld+json'],
        ]);
        self::assertResponseIsSuccessful();

        $data = $response->toArray();

        return $data['hydra:member'] ?? $data['member'] ?? [];
    }

    /**
     * @param list<string> $collections
     * @param list<string> $mcpConnections
     */
    private function conversation(array $collections = [], array $mcpConnections = [], ?string $title = null, string $status = ConversationStatus::ACTIVE): Conversation
    {
        $conversation = new Conversation()
            ->setOwnerSubject(self::OWNER)
            ->setProviderModel(ConversationConnections::model())
            ->setRetrievalDepth(4)
            ->setCollections($collections)
            ->setMcpConnections($mcpConnections)
            ->setTitle($title)
            ->setStatus($status);
        $this->entityManager()->persist($conversation);
        $this->entityManager()->flush();
        $this->conversationIds[] = (string) $conversation->getId();

        return $conversation;
    }

    /**
     * @return list<Message> ordered by sequence, fresh from the database
     */
    private function records(Conversation $conversation): array
    {
        $this->entityManager()->clear();

        /** @var list<Message> $records */
        $records = $this->entityManager()->getRepository(Message::class)->findBy(['conversation' => $conversation->getId()], ['sequence' => 'ASC']);

        return $records;
    }

    private function reload(Conversation $conversation): Conversation
    {
        $this->entityManager()->clear();

        $reloaded = $this->entityManager()->find(Conversation::class, $conversation->getId());
        self::assertInstanceOf(Conversation::class, $reloaded);

        return $reloaded;
    }

    private function connection(string $name, bool $active = true): ApiConfiguration
    {
        $connection = (new ApiConfiguration())
            ->setName(self::PREFIX.$name)
            ->setConfigJson(['mcp' => true, 'type' => 'mcp', 'endpoint_type' => 'rest', 'url' => self::DOCS_URL, 'transport' => 'streamable-http', 'auth_type' => 'none'])
            ->setActive($active);
        $this->entityManager()->persist($connection);
        $this->entityManager()->flush();

        return $connection;
    }

    private function tool(ApiConfiguration $connection, string $name): McpTool
    {
        $tool = new McpTool($connection, $name);
        $this->entityManager()->persist($tool);
        $this->entityManager()->flush();

        return $tool;
    }

    private function collection(string $name): Collection
    {
        $collection = new Collection()->setName($name)->setEmbeddingModel('nomic-embed-text')->setEmbeddingProviderModel(ConversationConnections::model('ollama', 'nomic-embed-text:latest'));
        $this->entityManager()->persist($collection);
        $this->entityManager()->flush();

        return $collection;
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

    private function factory(): FakeProviderFactory
    {
        $factory = static::getContainer()->get(ProviderFactory::class);
        self::assertInstanceOf(FakeProviderFactory::class, $factory);

        return $factory;
    }

    private function fake(): FakeMcpTransportFactory
    {
        $fake = static::getContainer()->get(FakeMcpTransportFactory::class);
        self::assertInstanceOf(FakeMcpTransportFactory::class, $fake);

        return $fake;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }
}
