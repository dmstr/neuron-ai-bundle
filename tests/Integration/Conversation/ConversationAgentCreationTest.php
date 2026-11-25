<?php

// file generated with AI assistance: Claude Code - 2026-10-03 06:58:57 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Conversation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\State\ConversationCreateProcessor;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryAgentLimitDefaults;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Creating a conversation with an agent: the write-only `agent` (slug,
 * full UUID or IRI; never a partial UUID) pins the agent's current
 * published version as `agentVersion` and fills `maxUserTurns` from the
 * version, then the host default ({@see InMemoryAgentLimitDefaults}); a
 * request value may only lower it. Without an agent the request value
 * applies as given and the host default does not. Later publishes and
 * rollbacks leave the pinned version and limit alone; unknown, disabled
 * and unpublished agents, `maxUserTurns` below 1 and partial UUIDs answer
 * 422.
 *
 * The version values (provider, model, collections, the connection UUIDs
 * of its MCP tools, retrieval depth) are copied into the columns; every
 * body key the version fixes answers 422 naming it; the language is
 * validated against the version (absent: its default language is
 * stored); `variables` are validated against the version's `startSchema`
 * and its placeholders, stored, shown on the item GET (owner and
 * `neuronai:conversation:read_any`) but not in the creation response, the
 * list or the audit log; `variables` without agent and a stale collection
 * of the version answer 422 as well.
 *
 * Uses the shared helpers of {@see AccessTest} (conversations as
 * `test-conv-user-a`) and {@see AgentFixtures} (agents with the prefix
 * `test-agent-`, created and published as the default admin). Cleanup
 * removes the conversations first, because they reference agent versions,
 * then the agents. Group `database`.
 */
#[Group('database')]
final class ConversationAgentCreationTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const string CONVERSATIONS = '/api/conversations';

    /** A type-correct value for every key the agent version fixes. */
    private const array VERSION_FIXED_VALUES = [
        'instructions' => 'Du bist ein anderer Assistent.',
        'model' => '/api/refs/provider_models/0199a1b2-0000-4000-8000-000000000000',
        'collections' => [],
        'mcpConnections' => [],
        'retrievalDepth' => 3,
        'responseSchema' => ['type' => 'object'],
        'terminalTools' => [],
        'rules' => [],
        'templates' => ['refusal' => ['de' => 'Dazu gebe ich keine Auskunft.']],
        'hybrid' => true,
        'hybridWeight' => 0.7,
        'contextMaxChunks' => 5,
        'contextMaxChars' => 4000,
        'contextExpansionMaxChars' => 0,
        'contextInstructions' => ['de' => 'Nur aus dem Kontext.'],
        'injectionGuard' => true,
        'answerabilityCheck' => true,
        'answerabilityVotes' => 3,
        'followUps' => true,
        'artifactTools' => true,
    ];

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        self::cleanUp();
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        parent::tearDown();
    }

    public function testAgentIsResolvedBySlugFullUuidAndIri(): void
    {
        [$id, $version] = self::publishedAgent('resolve', 3);

        foreach ([AgentFixtures::PREFIX.'resolve', $id, AgentFixtures::AGENTS.'/'.$id] as $reference) {
            $response = self::create(['agent' => $reference]);

            self::assertSame(201, $response->getStatusCode(), $reference.': '.$response->getContent(false));
            $data = $response->toArray();
            self::assertSame($version['@id'], $data['agentVersion'], $reference);
            self::assertSame(3, $data['maxUserTurns'], $reference);
            self::assertArrayNotHasKey('agent', $data, $reference);
        }

        self::assertSame(3, AccessTest::conversationCount());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function requestValues(): iterable
    {
        yield 'above the version: clamped' => [5, 3];
        yield 'equal to the version' => [3, 3];
        yield 'below the version' => [2, 2];
    }

    #[DataProvider('requestValues')]
    public function testRequestValueOnlyLowersTheVersionLimit(int $request, int $expected): void
    {
        self::publishedAgent('clamp', 3);

        $response = self::create(['agent' => AgentFixtures::PREFIX.'clamp', 'maxUserTurns' => $request]);

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        self::assertSame($expected, $response->toArray()['maxUserTurns']);
    }

    public function testSettingsDefaultAppliesToAVersionWithoutLimit(): void
    {
        $slug = AgentFixtures::PREFIX.'settings';
        self::publishedAgent('settings', null);
        self::setSettingsMaxUserTurns(4);

        self::assertSame(4, self::createdMaxUserTurns(['agent' => $slug]));
        self::assertSame(2, self::createdMaxUserTurns(['agent' => $slug, 'maxUserTurns' => 2]));
        self::assertSame(4, self::createdMaxUserTurns(['agent' => $slug, 'maxUserTurns' => 6]));

        self::setSettingsMaxUserTurns(null);

        self::assertSame(2, self::createdMaxUserTurns(['agent' => $slug, 'maxUserTurns' => 2]));
        self::assertNull(self::createdMaxUserTurns(['agent' => $slug]));
    }

    public function testVersionLimitWinsOverTheSettingsDefault(): void
    {
        self::publishedAgent('version-wins', 3);
        self::setSettingsMaxUserTurns(5);

        self::assertSame(3, self::createdMaxUserTurns(['agent' => AgentFixtures::PREFIX.'version-wins']));
    }

    public function testConversationWithoutAgentIgnoresTheSettingsDefault(): void
    {
        self::setSettingsMaxUserTurns(4);

        $response = self::create(AccessTest::createBody(['title' => 'Ohne Agent']));
        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        self::assertNull($response->toArray()['maxUserTurns'] ?? null);
        self::assertNull($response->toArray()['agentVersion'] ?? null);

        self::assertSame(7, self::createdMaxUserTurns(AccessTest::createBody(['maxUserTurns' => 7])));
    }

    public function testPinnedVersionSurvivesPublishAndRollback(): void
    {
        [$id, $first] = self::publishedAgent('pinned', 3);
        $early = self::create(['agent' => $id])->toArray();
        self::assertSame($first['@id'], $early['agentVersion']);

        self::request('PATCH', AgentFixtures::AGENTS.'/'.$id, ['candidate' => AgentFixtures::validCandidate(['maxUserTurns' => 5])]);
        self::assertResponseStatusCodeSame(200);
        $second = AgentFixtures::publish($id);
        self::assertSame(2, $second['versionNumber'] ?? null);

        self::assertPinned($early['@id'], $first['@id'], 3);

        $late = self::create(['agent' => $id])->toArray();
        self::assertSame($second['@id'], $late['agentVersion']);
        self::assertSame(5, $late['maxUserTurns']);

        $rollback = AgentFixtures::rollback($id, ['version' => $first['@id']]);
        self::assertSame(200, $rollback->getStatusCode(), $rollback->getContent(false));

        self::assertPinned($early['@id'], $first['@id'], 3);
        self::assertPinned($late['@id'], $second['@id'], 5);
    }

    public function testUnknownAgentIsRejected(): void
    {
        self::assertRejected(AgentFixtures::PREFIX.'nobody', 'Unknown agent "test-agent-nobody".');
        self::assertRejected('', 'Unknown agent "".');
    }

    public function testDisabledAgentIsRejected(): void
    {
        [$id] = self::publishedAgent('off', 3);
        self::request('PATCH', AgentFixtures::AGENTS.'/'.$id, ['enabled' => false]);
        self::assertResponseStatusCodeSame(200);

        self::assertRejected(AgentFixtures::PREFIX.'off', 'Agent "test-agent-off" is disabled.');
    }

    public function testAgentWithoutPublishedVersionIsRejected(): void
    {
        $id = AgentFixtures::createAgent('unpublished');

        self::assertRejected($id, \sprintf('Agent "%s" has no published version.', $id));
    }

    public function testPartialUuidIsNotResolved(): void
    {
        [$id] = self::publishedAgent('partial', 3);
        $partial = substr($id, 0, 8);

        self::assertRejected($partial, \sprintf('Unknown agent "%s".', $partial));
        self::assertRejected(AgentFixtures::AGENTS.'/'.$partial, \sprintf('Unknown agent "%s".', AgentFixtures::AGENTS.'/'.$partial));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidMaxUserTurns(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('invalidMaxUserTurns')]
    public function testMaxUserTurnsMustBePositive(int $value): void
    {
        self::publishedAgent('positive', 3);

        foreach ([['maxUserTurns' => $value], ['agent' => AgentFixtures::PREFIX.'positive', 'maxUserTurns' => $value]] as $body) {
            $response = self::create($body);

            self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
            self::assertContains('maxUserTurns', AgentFixtures::violationPaths($response));
        }

        self::assertSame(0, AccessTest::conversationCount());
    }

    public function testRepresentationShowsTheLimitAndTheCounterButNotTheAgent(): void
    {
        [, $version] = self::publishedAgent('representation', 2);
        $created = self::create(['agent' => AgentFixtures::PREFIX.'representation'])->toArray();

        $item = AccessTest::request('GET', (string) $created['@id'], AccessTest::USER_A);

        self::assertSame(200, $item->getStatusCode(), $item->getContent(false));
        $data = $item->toArray();
        self::assertSame(2, $data['maxUserTurns']);
        self::assertSame(0, $data['turnCount']);
        self::assertSame($version['@id'], $data['agentVersion']);
        self::assertArrayNotHasKey('agent', $data);

        $list = AccessTest::request('GET', self::CONVERSATIONS, AccessTest::USER_A)->toArray();
        $member = $list['hydra:member'][0] ?? $list['member'][0] ?? [];
        self::assertSame(2, $member['maxUserTurns'] ?? null);
        self::assertArrayNotHasKey('agent', $member);
    }

    public function testVersionValuesAreCopiedIntoTheColumns(): void
    {
        $collection = AgentFixtures::collection('copy');
        $docs = AgentFixtures::connection('docs');
        $crm = AgentFixtures::connection('crm');
        foreach (['search', 'fetch'] as $name) {
            AgentFixtures::tool($docs, $name);
        }
        AgentFixtures::tool($crm, 'lookup');
        $collectionId = $collection->getId()->toRfc4122();
        $docsId = $docs->getId()->toRfc4122();
        $crmId = $crm->getId()->toRfc4122();

        [, $version] = self::publishedAgentWith('copy', [
            'collections' => [$collection->getName()],
            'retrievalDepth' => 6,
            'mcpTools' => [
                ['connection' => $docs->getName(), 'name' => 'search'],
                ['connection' => $crm->getName(), 'name' => 'lookup'],
                ['connection' => $docs->getName(), 'name' => 'fetch'],
            ],
            'maxUserTurns' => 3,
        ]);

        $response = self::create(['agent' => AgentFixtures::PREFIX.'copy', 'title' => 'Mit Agent']);

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        $data = $response->toArray();
        self::assertSame($version['@id'], $data['agentVersion']);
        self::assertSame('Mit Agent', $data['title']);
        self::assertSame('anthropic', $data['provider']);
        self::assertSame(AgentFixtures::ANTHROPIC_CONNECTION, $data['connection']);
        self::assertSame(ConversationConnections::modelIri(), $data['model'], 'the IRI of the version\'s catalogue entry');
        self::assertSame(FakeProviderFactory::ANTHROPIC_MODEL, $data['modelName']);
        self::assertSame([$collectionId], $data['collections']);
        self::assertSame([$docsId, $crmId], $data['mcpConnections']);
        self::assertSame(6, $data['retrievalDepth']);
        self::assertSame('de', $data['language']);
        self::assertSame(3, $data['maxUserTurns']);
        // Item group only: not in the creation response.
        self::assertArrayNotHasKey('variables', $data);

        $item = AccessTest::request('GET', (string) $data['@id'], AccessTest::USER_A)->toArray();
        self::assertSame([], $item['variables']);
    }

    /**
     * Type-correct values, so the serializer accepts the conversation's own
     * properties and every key reaches the raw-body check of the processor;
     * `instructions`, `responseSchema` and `terminalTools` are no property of
     * the conversation and are ignored by the serializer (extra attributes
     * are allowed); the same holds for `rules` and `templates`
     * and for `hybrid`, `hybridWeight` and the context keys.
     *
     * @return iterable<string, array{string, mixed}>
     */
    public static function versionFixedKeys(): iterable
    {
        foreach (self::VERSION_FIXED_VALUES as $key => $value) {
            yield $key => [$key, $value];
        }
    }

    #[DataProvider('versionFixedKeys')]
    public function testVersionFixedKeyIsRejected(string $key, mixed $value): void
    {
        self::assertSame(ConversationCreateProcessor::VERSION_FIXED_KEYS, array_keys(self::VERSION_FIXED_VALUES));
        self::publishedAgent('fixed', 3);

        $response = self::create(['agent' => AgentFixtures::PREFIX.'fixed', $key => $value]);

        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertSame(\sprintf('"%s" is fixed by the agent version and cannot be set when creating a conversation with an agent.', $key), $response->toArray(false)['detail'] ?? null);
        self::assertSame(0, AccessTest::conversationCount());

        // Without an agent the same key is no error; `model` then has to
        // name a real catalogue entry, the placeholder above is unknown.
        $plain = self::create(AccessTest::createBody('model' === $key ? [] : [$key => $value]));
        self::assertSame(201, $plain->getStatusCode(), $key.': '.$plain->getContent(false));
    }

    public function testLanguageIsValidatedAgainstTheVersion(): void
    {
        $slug = AgentFixtures::PREFIX.'lang';
        self::publishedAgentWith('lang', [
            'instructions' => ['de' => 'Du bist ein Assistent.', 'en' => 'You are an assistant.'],
            'languages' => ['de', 'en'],
            'defaultLanguage' => 'de',
        ]);

        $default = self::create(['agent' => $slug]);
        self::assertSame(201, $default->getStatusCode(), $default->getContent(false));
        self::assertSame('de', $default->toArray()['language']);

        $english = self::create(['agent' => $slug, 'language' => 'en']);
        self::assertSame(201, $english->getStatusCode(), $english->getContent(false));
        self::assertSame('en', $english->toArray()['language']);

        $french = self::create(['agent' => $slug, 'language' => 'fr']);
        self::assertSame(422, $french->getStatusCode(), $french->getContent(false));
        self::assertSame(\sprintf('language: "fr" is not supported by agent "%s" (supported: de, en).', $slug), $french->toArray(false)['detail'] ?? null);
        self::assertSame(2, AccessTest::conversationCount());
    }

    public function testVariablesAreStoredAndShownOnTheItemOnly(): void
    {
        $slug = AgentFixtures::PREFIX.'variables';
        self::publishedAgentWith('variables', self::customerCandidate());

        $response = self::create(['agent' => $slug, 'variables' => ['customer' => 'ACME GmbH']]);

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        $created = $response->toArray();
        self::assertArrayNotHasKey('variables', $created);

        $item = AccessTest::request('GET', (string) $created['@id'], AccessTest::USER_A);
        self::assertSame(200, $item->getStatusCode(), $item->getContent(false));
        self::assertSame(['customer' => 'ACME GmbH'], $item->toArray()['variables']);

        // neuronai:conversation:read_any sees them like message content.
        $master = AccessTest::request('GET', (string) $created['@id'], AccessTest::ADMIN);
        self::assertSame(200, $master->getStatusCode(), $master->getContent(false));
        self::assertSame(['customer' => 'ACME GmbH'], $master->toArray()['variables']);

        $list = AccessTest::request('GET', self::CONVERSATIONS, AccessTest::USER_A)->toArray();
        $member = $list['hydra:member'][0] ?? $list['member'][0] ?? [];
        self::assertSame($created['@id'], $member['@id'] ?? null);
        self::assertArrayNotHasKey('variables', $member);

        // Never audited: the create entry carries neither the key nor a value.
        $entries = AgentFixtures::dbal()->fetchAllAssociative(
            'SELECT action, data FROM dmstr_log_entries WHERE object_class = :class AND object_id = :id ORDER BY version ASC',
            ['class' => Conversation::class, 'id' => $created['id']],
        );
        self::assertNotEmpty($entries);
        self::assertSame('create', $entries[0]['action']);
        foreach ($entries as $entry) {
            self::assertStringNotContainsString('variables', (string) $entry['data']);
            self::assertStringNotContainsString('ACME', (string) $entry['data']);
        }
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidVariables(): iterable
    {
        yield 'undeclared' => [['customer' => 'ACME', 'other' => 'x'], 'variables/other: not declared by the agent version.'];
        yield 'missing placeholder value' => [new \stdClass(), 'variables/customer: required by the instructions.'];
        yield 'non-scalar value' => [['customer' => ['name' => 'ACME']], 'variables/customer: must be a string, number or boolean.'];
        yield 'reserved name' => [['customer' => 'ACME', 'date' => '2020-01-01'], 'variables/date: "date" is a system value and cannot be supplied.'];
        yield 'schema violation' => [['customer' => 42], 'variables/customer: '];
        yield 'list' => [['ACME'], '"variables" must be a JSON object.'];
    }

    #[DataProvider('invalidVariables')]
    public function testInvalidVariablesAreRejected(mixed $variables, string $detail): void
    {
        self::publishedAgentWith('invalid', self::customerCandidate());

        $response = self::create(['agent' => AgentFixtures::PREFIX.'invalid', 'variables' => $variables]);

        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertStringContainsString($detail, (string) ($response->toArray(false)['detail'] ?? ''));
        self::assertSame(0, AccessTest::conversationCount());
    }

    /**
     * A scalar `variables` does not match the property type (`array`); the
     * serializer answers 400 before the processor runs.
     */
    public function testScalarVariablesAreRejectedByTheSerializer(): void
    {
        self::publishedAgentWith('scalar', self::customerCandidate());

        $response = self::create(['agent' => AgentFixtures::PREFIX.'scalar', 'variables' => 'ACME']);

        self::assertSame(400, $response->getStatusCode(), $response->getContent(false));
        self::assertSame(0, AccessTest::conversationCount());
    }

    public function testVariablesRequireAnAgent(): void
    {
        foreach ([['customer' => 'ACME'], new \stdClass()] as $variables) {
            $response = self::create(['title' => 'Ohne Agent', 'variables' => $variables]);

            self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
            self::assertSame('"variables" require an agent.', $response->toArray(false)['detail'] ?? null);
        }

        self::assertSame(0, AccessTest::conversationCount());
    }

    public function testStaleCollectionOfTheVersionIsRejectedWithItsPath(): void
    {
        $collection = AgentFixtures::collection('stale');
        $collectionId = $collection->getId();
        self::publishedAgentWith('stale', ['collections' => [$collection->getName()]]);

        $entityManager = AgentFixtures::entityManager();
        $stale = $entityManager->find(Collection::class, $collectionId);
        self::assertInstanceOf(Collection::class, $stale);
        $entityManager->remove($stale);
        $entityManager->flush();

        $response = self::create(['agent' => AgentFixtures::PREFIX.'stale']);

        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertStringContainsString('configuration/collections/0: Collection "'.$collectionId->toRfc4122().'" does not exist (any more).', (string) ($response->toArray(false)['detail'] ?? ''));
        self::assertSame(0, AccessTest::conversationCount());
    }

    /**
     * A version with a `startMessage` starts the first turn with the
     * creation: the rendered start message is the first user record, the
     * answer follows, and the turn's events are stored for the stream. The
     * test client terminates the kernel after the response, so the turn has
     * run when create() returns.
     */
    public function testStartMessageStartsTheFirstTurn(): void
    {
        self::publishedAgentWith('start', self::customerCandidate() + ['startMessage' => ['de' => 'Hallo, ich komme von {{ customer }}.']]);

        $client = static::createClient();
        $client->disableReboot();
        $factory = static::getContainer()->get(ProviderFactory::class);
        self::assertInstanceOf(FakeProviderFactory::class, $factory);
        $factory->queueAnswer('Willkommen, ACME.');
        $response = AccessTest::send($client, 'POST', self::CONVERSATIONS, AccessTest::USER_A, ['agent' => AgentFixtures::PREFIX.'start', 'variables' => ['customer' => 'ACME']]);

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        $records = AccessTest::request('GET', $response->toArray()['@id'].'/messages', AccessTest::USER_A)->toArray();
        $records = $records['member'] ?? $records['hydra:member'] ?? [];
        self::assertGreaterThanOrEqual(2, \count($records), json_encode($records, \JSON_THROW_ON_ERROR));
        self::assertSame('user', $records[0]['role']);
        self::assertSame('Hallo, ich komme von ACME.', $records[0]['content']);
        self::assertSame('assistant', $records[\count($records) - 1]['role']);
        self::assertSame('complete', $records[\count($records) - 1]['status'], json_encode($records[\count($records) - 1], \JSON_THROW_ON_ERROR));
        self::assertSame('Willkommen, ACME.', $records[\count($records) - 1]['content']);

        $events = (int) AgentFixtures::dbal()->fetchOne('SELECT COUNT(*) FROM neuron_stream_event WHERE conversation_id = :id', ['id' => $response->toArray()['id']]);
        self::assertGreaterThan(0, $events, 'the start turn is a streamed turn, its events are stored');
    }

    public function testWithoutStartMessageNoTurnRuns(): void
    {
        self::publishedAgentWith('nostart', self::customerCandidate());

        $response = self::create(['agent' => AgentFixtures::PREFIX.'nostart', 'variables' => ['customer' => 'ACME']]);

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        $records = AccessTest::request('GET', $response->toArray()['@id'].'/messages', AccessTest::USER_A)->toArray();
        self::assertSame([], $records['member'] ?? $records['hydra:member'] ?? null);
    }

    public function testPublishRefusesAStartMessageWithoutDefaultLanguageOrWithAnUnknownPlaceholder(): void
    {
        $id = AgentFixtures::createAgent('badstart', AgentFixtures::validCandidate(self::customerCandidate() + ['startMessage' => ['en' => 'Hi {{ unknown }}.']]));

        $result = AgentFixtures::publish($id);

        self::assertArrayNotHasKey('versionNumber', $result, json_encode($result, \JSON_THROW_ON_ERROR));
        $messages = implode("\n", array_column($result['violations'] ?? [], 'message'));
        self::assertStringContainsString('The start message has no text for the default language "de".', $messages);
        self::assertStringContainsString('The placeholder "{{unknown}}" is not available in the start message', $messages);
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * Creates and publishes an agent whose candidate sets `maxUserTurns`.
     *
     * @param string $slug without the prefix
     *
     * @return array{0: string, 1: array<string, mixed>} the agent id and the published version
     */
    private static function publishedAgent(string $slug, ?int $maxUserTurns): array
    {
        $id = AgentFixtures::createAgent($slug, AgentFixtures::validCandidate(['maxUserTurns' => $maxUserTurns]));
        $version = AgentFixtures::publish($id);
        self::assertArrayHasKey('@id', $version, json_encode($version, \JSON_THROW_ON_ERROR));
        self::assertSame($maxUserTurns, $version['configuration']['maxUserTurns'] ?? null);

        return [$id, $version];
    }

    /**
     * Creates and publishes an agent with a valid candidate plus $overrides.
     *
     * @param string               $slug      without the prefix
     * @param array<string, mixed> $overrides
     *
     * @return array{0: string, 1: array<string, mixed>} the agent id and the published version
     */
    private static function publishedAgentWith(string $slug, array $overrides): array
    {
        $id = AgentFixtures::createAgent($slug, AgentFixtures::validCandidate($overrides));
        $version = AgentFixtures::publish($id);
        self::assertArrayHasKey('@id', $version, json_encode($version, \JSON_THROW_ON_ERROR));

        return [$id, $version];
    }

    /**
     * Candidate overrides of an agent whose instructions need the start
     * variable `customer`.
     *
     * @return array<string, mixed>
     */
    private static function customerCandidate(): array
    {
        return [
            'instructions' => ['de' => 'Du bist der Assistent von {{ customer }}.'],
            'startSchema' => ['type' => 'object', 'properties' => ['customer' => ['type' => 'string']], 'additionalProperties' => false],
        ];
    }

    /**
     * `POST /conversations` as `test-conv-user-a`.
     *
     * @param array<string, mixed> $body objects in it must be arrays with string keys or \stdClass (`{}`)
     */
    private static function create(array $body): ResponseInterface
    {
        $response = AccessTest::request('POST', self::CONVERSATIONS, AccessTest::USER_A, $body);
        $response->getStatusCode();

        return $response;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function createdMaxUserTurns(array $body): ?int
    {
        $response = self::create($body);
        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));

        return $response->toArray()['maxUserTurns'] ?? null;
    }

    /**
     * A request as the default admin of {@see AgentFixtures}.
     *
     * @param array<string, mixed> $json
     */
    private static function request(string $method, string $uri, array $json): ResponseInterface
    {
        $response = AgentFixtures::request($method, $uri, AgentFixtures::ADMIN, $json);
        $response->getStatusCode();

        return $response;
    }

    private static function setSettingsMaxUserTurns(?int $value): void
    {
        InMemoryAgentLimitDefaults::set(null, $value);
    }

    private static function assertRejected(string $reference, string $detail): void
    {
        $response = self::create(['agent' => $reference]);

        self::assertSame(422, $response->getStatusCode(), $reference.': '.$response->getContent(false));
        self::assertSame($detail, $response->toArray(false)['detail'] ?? null, $reference);
        self::assertSame(0, AccessTest::conversationCount(), $reference);
    }

    private static function assertPinned(string $conversation, string $version, int $maxUserTurns): void
    {
        $data = AccessTest::request('GET', $conversation, AccessTest::USER_A)->toArray();

        self::assertSame($version, $data['agentVersion'], $conversation);
        self::assertSame($maxUserTurns, $data['maxUserTurns'], $conversation);
    }

    /**
     * Conversations first (they reference agent versions), then the
     * agents.
     */
    private static function cleanUp(): void
    {
        AccessTest::cleanUp();

        AgentFixtures::cleanUp();
    }
}
