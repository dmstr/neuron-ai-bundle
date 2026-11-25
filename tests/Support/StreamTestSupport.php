<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:21:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Anonymous\CapabilityToken;
use Dmstr\NeuronAiBundle\Conversation\ConversationStatus;
use Dmstr\NeuronAiBundle\Conversation\TurnLock;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Entity\Refs\McpTool;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\Stream\StreamEventStore;
use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Testing\RequestRecord;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Shared fixtures and helpers of the database-bound streaming tests
 * (StreamingTurnTest, CancelTest, ResumeTest):
 *
 * - requests: the streamed turn (`POST .../stream`), the resume
 *   (`GET .../stream` with `Last-Event-ID`), the cancel and the
 *   non-streaming turn (`POST .../messages`);
 * - an SSE parser for the captured response body (the kernel browser
 *   buffers the output of a `StreamedResponse`, so the body is the whole
 *   stream): blocks `id:`/`event:`/`data:`, `: ping` comments ignored;
 * - conversations of the default test identity `test-admin` written
 *   through the entity manager on the `anthropic` fixture connection
 *   ({@see ConversationConnections}), MCP connections with the name prefix
 *   `test-stream-`, records and stored events read fresh from the
 *   database;
 * - a second database session that holds the conversation's advisory turn
 *   lock (as in ConcurrencyTest), so a turn counts as running;
 * - provider marks ({@see providerMarks()}): what ran since a mark (no
 *   provider built, no request, nothing embedded) and the one request
 *   recorded since, with its message contents;
 * - anonymous agent conversations created through the public endpoint
 *   from an agent published through the API, and their turns;
 * - agent versions written through the entity manager (configuration
 *   stored as is, with a collection at retrieval depth 3), conversations
 *   pinned to them, scripted answers with usage and the message data of a
 *   decoded JSON-LD response.
 *
 * The using class extends ApiTestCase with `$alwaysBootKernel = true`;
 * setUp() and tearDown() come from here. Everything is removed after each
 * test (messages and stream events cascade with the conversation).
 */
trait StreamTestSupport
{
    private Client $client;

    /** @var list<string> */
    private array $conversationIds = [];

    private ?Connection $second = null;

    private bool $usesStore = false;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        TestDatabase::migrate(static::$kernel);
    }

    protected function tearDown(): void
    {
        $this->second?->close();
        $this->second = null;
        foreach ($this->conversationIds as $id) {
            $this->dbal()->executeStatement('DELETE FROM neuron_conversation WHERE id = :id', ['id' => $id]);
        }
        $this->dbal()->executeStatement('DELETE FROM dmstr_api_configuration WHERE name LIKE :prefix', ['prefix' => 'test-stream-%']);
        if ($this->usesStore) {
            $this->dbal()->executeStatement('TRUNCATE embedding, neuron_collection CASCADE');
        }
        parent::tearDown();
    }

    /**
     * `POST /api/conversations/{id}/stream`; a string body is sent raw.
     *
     * @param array<string, mixed>|string $body
     * @param array<string, string>       $headers
     */
    private function stream(Conversation|string $conversation, array|string $body, array $headers = []): ResponseInterface
    {
        $options = ['headers' => ['Content-Type' => 'application/json', 'Accept' => 'text/event-stream'] + $headers];
        if (\is_string($body)) {
            $options['body'] = $body;
        } else {
            $options['json'] = $body;
        }

        return $this->client->request('POST', '/api/conversations/'.self::idOf($conversation).'/stream', $options);
    }

    /**
     * `GET /api/conversations/{id}/stream`, with `Last-Event-ID` unless null.
     *
     * @param array<string, string> $headers
     */
    private function resume(Conversation|string $conversation, ?string $lastEventId = null, array $headers = []): ResponseInterface
    {
        $headers = ['Accept' => 'text/event-stream'] + $headers;
        if (null !== $lastEventId) {
            $headers['Last-Event-ID'] = $lastEventId;
        }

        return $this->client->request('GET', '/api/conversations/'.self::idOf($conversation).'/stream', ['headers' => $headers]);
    }

    /**
     * `POST /api/conversations/{id}/cancel` without a body.
     *
     * @param array<string, string> $headers
     */
    private function cancel(Conversation $conversation, array $headers = []): ResponseInterface
    {
        return $this->client->request('POST', '/api/conversations/'.$conversation->getId().'/cancel', [
            'headers' => ['Accept' => 'application/ld+json'] + $headers,
        ]);
    }

    /**
     * The non-streaming turn (`POST .../messages`).
     *
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
     * The events of a captured SSE body, asserting a 200 `text/event-stream`
     * response first.
     *
     * @return list<array{id: ?int, event: string, data: array<string, mixed>}>
     */
    private function events(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertStringStartsWith('text/event-stream', $response->getHeaders()['content-type'][0] ?? '');

        return self::parseSse($response->getContent());
    }

    /**
     * Parses SSE blocks (`id: <int>`, `event: <type>`, `data: <json>`,
     * separated by a blank line); comment lines (`: ping`) are skipped. A
     * block without an `id:` line has id null.
     *
     * @return list<array{id: ?int, event: string, data: array<string, mixed>}>
     */
    private static function parseSse(string $body): array
    {
        $events = [];
        foreach (preg_split('/\R\R/', $body) ?: [] as $block) {
            $id = null;
            $event = null;
            $data = [];
            foreach (preg_split('/\R/', $block) ?: [] as $line) {
                if ('' === $line || str_starts_with($line, ':')) {
                    continue;
                }
                [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
                $value = ltrim($value, ' ');
                match ($field) {
                    'id' => $id = (int) $value,
                    'event' => $event = $value,
                    'data' => $data[] = $value,
                    default => self::fail(\sprintf('Unexpected SSE line "%s".', $line)),
                };
            }
            if (null === $event) {
                self::assertNull($id, 'An SSE block with an id has no event type.');
                self::assertSame([], $data, 'An SSE block with data has no event type.');
                continue;
            }
            $events[] = ['id' => $id, 'event' => $event, 'data' => json_decode(implode("\n", $data), true, 512, \JSON_THROW_ON_ERROR)];
        }

        return $events;
    }

    /**
     * @param list<array{id: ?int, event: string, data: array<string, mixed>}> $events
     *
     * @return list<string>
     */
    private static function typesOf(array $events): array
    {
        return array_column($events, 'event');
    }

    /**
     * The joined `text` of all events of $type (`token` or `reasoning`).
     *
     * @param list<array{id: ?int, event: string, data: array<string, mixed>}> $events
     */
    private static function textOf(array $events, string $type): string
    {
        $text = '';
        foreach ($events as $event) {
            if ($type === $event['event']) {
                $text .= (string) ($event['data']['text'] ?? '');
            }
        }

        return $text;
    }

    /**
     * Asserts that every event has an id and the ids strictly increase.
     *
     * @param list<array{id: ?int, event: string, data: array<string, mixed>}> $events
     *
     * @return list<int>
     */
    private static function assertIncreasingIds(array $events): array
    {
        $ids = array_column($events, 'id');
        self::assertNotContains(null, $ids, 'A stored event was sent without an id.');
        for ($i = 1, $n = \count($ids); $i < $n; ++$i) {
            self::assertGreaterThan($ids[$i - 1], $ids[$i]);
        }

        return $ids;
    }

    /**
     * @param list<string> $collections
     * @param list<string> $mcpConnections
     */
    private function conversation(array $collections = [], array $mcpConnections = [], string $status = ConversationStatus::ACTIVE): Conversation
    {
        $conversation = new Conversation()
            ->setOwnerSubject('test-admin')
            ->setProviderModel(ConversationConnections::model())
            ->setRetrievalDepth(4)
            ->setCollections($collections)
            ->setMcpConnections($mcpConnections)
            ->setStatus($status);
        $this->entityManager()->persist($conversation);
        $this->entityManager()->flush();
        $this->conversationIds[] = (string) $conversation->getId();

        return $conversation;
    }

    /**
     * An active MCP connection served by the fake transport, with one
     * synced tool.
     */
    private function mcpConnection(string $name, string $tool, string $url): ApiConfiguration
    {
        $connection = (new ApiConfiguration())
            ->setName('test-stream-'.$name)
            ->setConfigJson(['mcp' => true, 'type' => 'mcp', 'endpoint_type' => 'rest', 'url' => $url, 'transport' => 'streamable-http', 'auth_type' => 'none'])
            ->setActive(true);
        $this->entityManager()->persist($connection);
        $this->entityManager()->persist(new McpTool($connection, $tool));
        $this->entityManager()->flush();

        return $connection;
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

    /**
     * `[sequence, kind, step, status]` per record: the layout the streamed
     * and the non-streaming turn must share.
     *
     * @param list<Message> $records
     *
     * @return list<array{int, string, int, string}>
     */
    private static function layout(array $records): array
    {
        return array_map(static fn (Message $m): array => [$m->getSequence(), $m->getKind(), $m->getStep(), $m->getStatus()], $records);
    }

    private function reload(Conversation $conversation): Conversation
    {
        $this->entityManager()->clear();

        $reloaded = $this->entityManager()->find(Conversation::class, $conversation->getId());
        self::assertInstanceOf(Conversation::class, $reloaded);

        return $reloaded;
    }

    /**
     * The stored stream events of the conversation as `[id, type]`.
     *
     * @return list<array{int, string}>
     */
    private function storedEvents(Conversation $conversation): array
    {
        return array_map(
            static fn (array $row): array => [(int) $row['id'], (string) $row['type']],
            $this->dbal()->fetchAllAssociative('SELECT id, type FROM neuron_stream_event WHERE conversation_id = :id ORDER BY id', ['id' => (string) $conversation->getId()]),
        );
    }

    private function cancelRequestedAt(Conversation $conversation): ?string
    {
        $value = $this->dbal()->fetchOne('SELECT cancel_requested_at FROM neuron_conversation WHERE id = :id', ['id' => (string) $conversation->getId()]);

        return false === $value ? null : $value;
    }

    /**
     * Sets the cancel flag the way the cancel operation does; for chunk
     * hooks that cancel mid-stream.
     */
    private function requestCancel(Conversation $conversation): void
    {
        $this->dbal()->executeStatement('UPDATE neuron_conversation SET cancel_requested_at = now() WHERE id = :id', ['id' => (string) $conversation->getId()]);
    }

    /**
     * Takes the conversation's turn lock in a second database session, so
     * a turn counts as running.
     */
    private function holdLock(Conversation $conversation): void
    {
        $this->second ??= DriverManager::getConnection($this->dbal()->getParams());
        self::assertTrue((bool) $this->second->fetchOne('SELECT pg_try_advisory_lock(CAST(:key AS BIGINT))', ['key' => TurnLock::keyFor($conversation->getId())]));
    }

    private function releaseLock(Conversation $conversation): void
    {
        self::assertNotNull($this->second);
        self::assertTrue((bool) $this->second->fetchOne('SELECT pg_advisory_unlock(CAST(:key AS BIGINT))', ['key' => TurnLock::keyFor($conversation->getId())]));
    }

    private function eventStore(): StreamEventStore
    {
        return new StreamEventStore($this->dbal());
    }

    private function factory(): FakeProviderFactory
    {
        $factory = static::getContainer()->get(ProviderFactory::class);
        self::assertInstanceOf(FakeProviderFactory::class, $factory);

        return $factory;
    }

    private function mcpFake(): FakeMcpTransportFactory
    {
        $fake = static::getContainer()->get(FakeMcpTransportFactory::class);
        self::assertInstanceOf(FakeMcpTransportFactory::class, $fake);

        return $fake;
    }

    private function dbal(): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');

        return $connection;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    /**
     * Counts of requested providers and recorded provider requests; resets
     * the embeddings recorder.
     *
     * @return array{int, int}
     */
    private function providerMarks(): array
    {
        $this->factory()->getEmbeddingsRecorder()->reset();

        return [\count($this->factory()->getRequestedProviders()), \count($this->factory()->getChatProvider()->getRecorded())];
    }

    /**
     * No provider built, no provider request, nothing embedded since $marks.
     *
     * @param array{int, int} $marks of {@see providerMarks()}
     */
    private function assertNothingRanSince(array $marks, string $message): void
    {
        self::assertSame($marks[0], \count($this->factory()->getRequestedProviders()), $message.': no provider built');
        self::assertSame($marks[1], \count($this->factory()->getChatProvider()->getRecorded()), $message.': no provider request');
        $this->factory()->getEmbeddingsRecorder()->assertNothingEmbedded();
    }

    /**
     * The provider requests recorded since $marks.
     *
     * @param array{int, int} $marks of {@see providerMarks()}
     *
     * @return list<RequestRecord>
     */
    private function requestsSince(array $marks): array
    {
        $records = \array_slice(array_values($this->factory()->getChatProvider()->getRecorded()), $marks[1]);
        self::assertContainsOnlyInstancesOf(RequestRecord::class, $records);

        return $records;
    }

    /**
     * The one provider request recorded since $marks.
     *
     * @param array{int, int} $marks of {@see providerMarks()}
     */
    private function onlyRequestSince(array $marks): RequestRecord
    {
        $records = $this->requestsSince($marks);
        self::assertCount(1, $records);

        return $records[0];
    }

    /**
     * @return list<string> the text contents of the request's messages
     */
    private static function messageContents(RequestRecord $record): array
    {
        return array_values(array_map(static fn (NeuronMessage $m): string => (string) $m->getContent(), $record->messages));
    }

    /**
     * An anonymous conversation created through the public endpoint from
     * a release with an agent named $name, whose candidate is $configuration
     * (the `anthropic` fixture connection) and a new collection at retrieval depth 3,
     * published through the API on the shared client. The caller cleans up
     * with {@see AnonymousFixtures::cleanUp()}.
     *
     * @param string               $slug          without the prefix of {@see AgentFixtures}
     * @param array<string, mixed> $configuration candidate keys over {@see AgentFixtures::validCandidate()}
     *
     * @return array{string, string} conversation id and token
     */
    private function anonymousAgentConversation(string $slug, string $name, array $configuration): array
    {
        // The candidate names the fixture connection; the publish below resolves it.
        ConversationConnections::anthropic();
        $collection = AgentFixtures::collection($slug);
        $created = AgentFixtures::send($this->client, 'POST', AgentFixtures::AGENTS, AgentFixtures::ADMIN, [
            'name' => $name,
            'slug' => AgentFixtures::PREFIX.$slug,
            'candidate' => AgentFixtures::validCandidate($configuration + ['collections' => [$collection->getName()], 'retrievalDepth' => 3]),
        ]);
        self::assertSame(201, $created->getStatusCode(), $created->getContent(false));
        $published = AgentFixtures::send($this->client, 'POST', AgentFixtures::AGENTS.'/'.$created->toArray()['id'].'/publish', AgentFixtures::ADMIN);
        self::assertSame(201, $published->getStatusCode(), $published->getContent(false));
        AnonymousFixtures::configure(['agent' => AgentFixtures::PREFIX.$slug]);

        $response = AnonymousFixtures::create($this->client, AnonymousFixtures::randomAddress());
        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        $data = $response->toArray();
        self::assertSame($published->toArray()['@id'], $data['conversation']['agentVersion']);

        return [(string) $data['conversation']['id'], (string) $data['token']];
    }

    /**
     * `POST .../messages` of an anonymous conversation with its token.
     *
     * @param array<string, mixed> $body
     */
    private function anonymousTurn(string $token, array $body): ResponseInterface
    {
        return $this->client->request('POST', '/api/conversations/'.self::anonymousConversationId($token).'/messages', [
            'headers' => AnonymousFixtures::tokenHeaders($token),
            'json' => $body,
        ]);
    }

    /**
     * `POST .../stream` of an anonymous conversation with its token.
     *
     * @param array<string, mixed> $body
     */
    private function anonymousStream(string $token, array $body): ResponseInterface
    {
        return $this->client->request('POST', '/api/conversations/'.self::anonymousConversationId($token).'/stream', [
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'text/event-stream'] + AnonymousFixtures::tokenHeaders($token),
            'json' => $body,
        ]);
    }

    private static function anonymousConversationId(string $token): string
    {
        return (string) AnonymousFixtures::connection()->fetchOne('SELECT id FROM neuron_conversation WHERE token_hash = :hash', ['hash' => CapabilityToken::hash($token)]);
    }

    /**
     * The configuration of a fixture version: the candidate defaults with the
     * `anthropic` fixture connection (full id) and its model, a new
     * collection named $collection (after the prefix of
     * {@see AgentFixtures}) at retrieval depth 3, plus $keys.
     *
     * @param array<string, mixed> $keys
     *
     * @return array<string, mixed>
     */
    private function agentVersionConfiguration(string $collection, array $keys): array
    {
        $created = AgentFixtures::collection($collection);

        return $keys + ['model' => ConversationConnections::modelId(), 'collections' => [$created->getId()->toRfc4122()], 'retrievalDepth' => 3] + Agent::candidateDefaults();
    }

    /**
     * An enabled agent $name (slug: $slug after the prefix of
     * {@see AgentFixtures}, removed by its cleanUp()) with
     * $configuration stored as is as its current version 1, written
     * through the entity manager.
     *
     * @param array<string, mixed> $configuration
     */
    private function storeAgentVersion(string $slug, array $configuration, string $name = 'Wächter'): AgentVersion
    {
        $agent = new Agent()->setName($name)->setSlug(AgentFixtures::PREFIX.$slug)->setEnabled(true);
        $this->entityManager()->persist($agent);
        $this->entityManager()->flush();
        $version = new AgentVersion($agent, 1, ConversationConnections::withConnection($configuration), 'test-admin');
        $this->entityManager()->persist($version);
        $agent->setCurrentVersion($version);
        $this->entityManager()->flush();

        return $version;
    }

    /**
     * A conversation of `test-admin` pinned to $version (looked up again,
     * it may be detached), with the version's collections.
     */
    private function pinnedConversation(AgentVersion $version, string $language = 'de', ?int $maxUserTurns = null): Conversation
    {
        $managed = $this->entityManager()->find(AgentVersion::class, $version->getId());
        self::assertInstanceOf(AgentVersion::class, $managed);

        $conversation = $this->conversation(collections: $managed->getConfiguration()['collections'] ?? [])
            ->setAgentVersion($managed)
            ->setLanguage($language)
            ->setVariables([])
            ->setMaxUserTurns($maxUserTurns);
        $this->entityManager()->flush();

        return $conversation;
    }

    /**
     * $text with $input input and $output output tokens as the next
     * scripted answer of the fake provider.
     */
    private function queueAnswerWithUsage(string $text, int $input, int $output): void
    {
        $this->factory()->getChatProvider()->addResponses(new AssistantMessage($text)->setUsage(new Usage($input, $output)));
    }

    /**
     * The messages of a decoded JSON-LD response ($data): the members of a
     * collection, else the item itself; without the hydra keys (operations
     * and their documentation), which are not message data.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private static function messageData(array $data): array
    {
        $messages = $data['hydra:member'] ?? $data['member'] ?? [$data];

        return array_values(array_map(
            static fn (array $message): array => array_filter($message, static fn (string|int $key): bool => !str_starts_with((string) $key, 'hydra:'), \ARRAY_FILTER_USE_KEY),
            $messages,
        ));
    }

    private static function idOf(Conversation|string $conversation): string
    {
        return $conversation instanceof Conversation ? (string) $conversation->getId() : $conversation;
    }
}
