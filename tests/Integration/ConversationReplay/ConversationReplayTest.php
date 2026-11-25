<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\ConversationReplay;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Anonymous\CapabilityToken;
use Dmstr\NeuronAiBundle\Conversation\Gate\InjectionGuard;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\ConversationReplay;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Evaluation\BatchRunner;
use Dmstr\NeuronAiBundle\JobProcessor\ConversationReplayJobProcessor;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\Replay\ConversationReplayRunner;
use Dmstr\NeuronAiBundle\Replay\ConversationReplayStatus;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\FixtureSet;
use Dmstr\NeuronAiBundle\Tests\Integration\Conversation\InjectionGuardTurnTest;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\Job\JobProcessorRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Conversation replay end to end: `POST
 * /api/conversations/{id}/replay` creates exactly one hidden replay
 * conversation and queues a job of type `conversation_replay`; running
 * that job (through {@see JobProcessorRegistry}, as the worker would)
 * replays the source's user messages in order on the target version.
 * Covered: the source stays unchanged; redelivery after the end and in
 * the middle (same conversation, no duplicate user records, gap-free
 * sequence); an older target version; variable override and source
 * variables the target does not declare; the 422 refusals without a
 * stored replay (invalid variables, unknown version, disabled agent, stale
 * reference with its path, unsupported language, no user message); a
 * target with a lower user-turn limit (`partial`, `closeReason`
 * `user_turn_limit`); a rule-refused source turn; a source without an
 * agent and an anonymous source; cancel before and during the replay and
 * after its end (422); a source deleted during and after the replay;
 * manual turns refused while the replay runs (both entry
 * points) and accepted afterwards; the audit actor of the replay
 * conversation is the triggering user, never `system:neuron:eval`.
 *
 * Identities: the trigger sends `X-Test-Roles: ROLE_USER,ROLE_EVAL_ADMIN`
 * and is `test-user`. Agents and versions are written through the entity
 * manager with the prefix of {@see AgentFixtures} (removed by its
 * cleanUp()), sources carry the title prefix {@see self::PREFIX}; answers
 * come from the fixture set `answers.json` next to this test. Every request
 * boots a new kernel, so the fixtures are set on the current container
 * right before a job runs ({@see self::armFixtures()}). Relies on the `api`
 * firewall rows for `/api/conversations` (the test kernel's security configuration, unchanged). Needs
 * the migrated test database (CI only).
 */
#[Group('database')]
final class ConversationReplayTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    public const string PREFIX = 'test-replay-';
    public const string AGENT_SLUG = AgentFixtures::PREFIX.'replay-';
    private const string FIXTURES = __DIR__.'/answers.json';

    private const string TRIGGER = TestHeaderAuthenticator::USER_IDENTIFIER;
    private const string ROLES_ADMIN = 'ROLE_USER,ROLE_EVAL_ADMIN';
    private const string SOURCE_OWNER = self::PREFIX.'source-owner';

    private const array QUESTIONS = ['Replay question one', 'Replay question two', 'Replay question three'];

    /**
     * Fixtures added to `answers.json` for the next job (they win over its
     * keys), e.g. answer gate verdicts keyed `<gate marker> <question>`.
     *
     * @var array<string, string|array<string, mixed>>
     */
    private array $extraFixtures = [];

    protected function setUp(): void
    {
        // Counts or scans all rows: start from an empty database.
        TestDatabase::migrate(static::bootKernel());
        TestDatabase::purge(TestDatabase::connection(static::$kernel));
        self::cleanUp();
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        parent::tearDown();
    }

    public function testReplayCreatesOneHiddenConversationWithTheSourceMessagesInOrder(): void
    {
        $version = $this->publishedVersion('main');
        $source = $this->source(self::QUESTIONS);
        $sourceBefore = $this->messageRows((string) $source->getId());
        $conversationsBefore = $this->replayConversationCount((string) $version->getId());

        $response = $this->trigger((string) $source->getId(), ['targetVersion' => '/api/agent_versions/'.$version->getId()]);
        self::assertSame(202, $response->getStatusCode(), $response->getContent(false));
        $replay = $response->toArray();
        self::assertSame(ConversationReplayStatus::PENDING, $replay['status']);
        self::assertSame(self::TRIGGER, $replay['ownerSubject']);
        self::assertSame('/api/conversations/'.$source->getId(), $replay['sourceConversation']);
        self::assertSame('/api/conversations/'.$replay['id'], $replay['conversation']);
        self::assertSame('/api/agent_versions/'.$version->getId(), $replay['targetVersion']);
        self::assertSame('de', $replay['language']);
        self::assertSame(3, $replay['turnsTotal']);
        self::assertSame(0, $replay['turnsReplayed']);
        self::assertNotEmpty($replay['jobId']);
        self::assertArrayNotHasKey('sourceMessageIds', $replay);
        self::assertSame($conversationsBefore + 1, $this->replayConversationCount((string) $version->getId()));

        $conversation = $this->conversationRow($replay['id']);
        self::assertSame(Conversation::ORIGIN_REPLAY, $conversation['origin']);
        self::assertSame(self::TRIGGER, $conversation['owner_subject']);
        self::assertSame((string) $version->getId(), $conversation['agent_version']);
        self::assertFalse((bool) $conversation['anonymous']);

        $job = $this->runJob($replay['jobId']);
        self::assertSame(100, $job->getProgress());
        self::assertSame(['status' => ConversationReplayStatus::COMPLETED, 'turnsReplayed' => 3, 'turnsTotal' => 3], $job->getResultData());

        $row = $this->replayRow($replay['id']);
        self::assertSame(ConversationReplayStatus::COMPLETED, $row['status']);
        self::assertSame(3, (int) $row['turns_replayed']);
        self::assertNotNull($row['started_at']);
        self::assertNotNull($row['finished_at']);
        self::assertSame(self::QUESTIONS, $this->userContents($replay['id']));
        self::assertContains('Antwort drei aus der Wiederholung.', $this->assistantContents($replay['id']));
        $this->assertGapFree($replay['id']);
        self::assertSame(3, (int) $this->conversationRow($replay['id'])['turn_count']);

        // Exactly one replay conversation, the source unchanged.
        self::assertSame($conversationsBefore + 1, $this->replayConversationCount((string) $version->getId()));
        self::assertSame($sourceBefore, $this->messageRows((string) $source->getId()));

        // Redelivery after the end: a no-op.
        $again = $this->runJob($replay['jobId']);
        self::assertArrayHasKey('skipped', (array) $again->getResultData());
        self::assertSame(self::QUESTIONS, $this->userContents($replay['id']));

        // The record as the replay conversation's readers see it.
        $read = $this->get('/api/conversations/'.$replay['id'].'/replay_run');
        self::assertSame(200, $read->getStatusCode(), $read->getContent(false));
        self::assertSame(ConversationReplayStatus::COMPLETED, $read->toArray()['status']);

        // Audit: creation and turn changes name the triggering user.
        $entries = $this->auditEntries($replay['id']);
        self::assertNotEmpty($entries);
        self::assertSame('create', $entries[0]['action']);
        foreach ($entries as $entry) {
            self::assertSame(self::TRIGGER, $entry['username']);
            self::assertNotSame(BatchRunner::ACTOR, $entry['username']);
        }

        // An ended replay cannot be cancelled.
        $cancel = $this->post('/api/conversations/'.$replay['id'].'/replay_run/cancel');
        self::assertSame(422, $cancel->getStatusCode(), $cancel->getContent(false));
    }

    public function testRedeliveryInTheMiddleResumesWithoutDuplicates(): void
    {
        $version = $this->publishedVersion('resume');
        $source = $this->source(self::QUESTIONS);
        $replay = $this->triggered((string) $source->getId(), ['targetVersion' => (string) $version->getId()]);
        $this->runJob($replay['jobId']);

        // As if the worker died after the first turn: the later records are
        // gone, the replay is still running.
        $id = $replay['id'];
        $second = (int) $this->dbal()->fetchOne("SELECT MIN(sequence) FROM neuron_message WHERE conversation_id = :id AND role = 'user' AND sequence > 1", ['id' => $id]);
        $this->dbal()->executeStatement('DELETE FROM neuron_message WHERE conversation_id = :id AND sequence >= :sequence', ['id' => $id, 'sequence' => $second]);
        $this->dbal()->executeStatement("UPDATE neuron_conversation SET next_sequence = :sequence, turn_count = 1, status = 'active', closed_at = NULL, close_reason = NULL WHERE id = :id", ['id' => $id, 'sequence' => $second]);
        $this->dbal()->executeStatement("UPDATE neuron_conversation_replay SET status = 'running', finished_at = NULL, turns_replayed = 1 WHERE id = :id", ['id' => $id]);

        $job = $this->runJob($replay['jobId']);

        self::assertSame(ConversationReplayStatus::COMPLETED, $job->getResultData()['status'] ?? null);
        self::assertSame(self::QUESTIONS, $this->userContents($id));
        $this->assertGapFree($id);
        self::assertSame(1, $this->replayConversationCount((string) $version->getId()));
    }

    public function testAnOlderVersionAndAVariableOverride(): void
    {
        $agent = $this->agent('older');
        $configuration = [
            'instructions' => ['de' => 'Kunde: {{customer}}.'],
            'startSchema' => ['type' => 'object', 'properties' => ['customer' => ['type' => 'string']], 'required' => ['customer'], 'additionalProperties' => false],
        ] + Agent::candidateDefaults();
        $older = $this->version($agent, 1, $configuration);
        $agent->setCurrentVersion($this->version($agent, 2, $configuration));
        $this->entityManager()->flush();

        $source = $this->source(['Replay question one'], ['customer' => 'ACME']);

        $replay = $this->triggered((string) $source->getId(), ['targetVersion' => (string) $older->getId(), 'variables' => ['customer' => 'Globex']]);
        self::assertSame('/api/agent_versions/'.$older->getId(), $replay['targetVersion']);
        self::assertSame(['variables' => ['customer' => 'Globex']], $replay['overrides']);
        self::assertSame(['customer' => 'Globex'], json_decode((string) $this->conversationRow($replay['id'])['variables'], true));

        // Omitted: the source variables apply.
        $copied = $this->triggered((string) $source->getId(), ['targetVersion' => (string) $older->getId()]);
        self::assertNull($copied['overrides'] ?? null);
        self::assertSame(['customer' => 'ACME'], json_decode((string) $this->conversationRow($copied['id'])['variables'], true));

        $this->runJob($replay['jobId']);
        self::assertSame(ConversationReplayStatus::COMPLETED, $this->replayRow($replay['id'])['status']);
        self::assertSame(['customer' => 'ACME'], json_decode((string) $this->conversationRow((string) $source->getId())['variables'], true));
    }

    public function testRefusalsAnswer422WithoutAStoredReplay(): void
    {
        $plain = $this->publishedVersion('plain');
        $declaring = $this->publishedVersion('declaring', [
            'instructions' => ['de' => 'Kunde: {{customer}}.'],
            'startSchema' => ['type' => 'object', 'properties' => ['customer' => ['type' => 'string']], 'required' => ['customer'], 'additionalProperties' => false],
        ]);
        $stale = $this->publishedVersion('stale', ['collections' => [Uuid::v4()->toRfc4122()]]);
        $disabled = $this->publishedVersion('disabled', [], false);
        $source = $this->source(['Replay question one'], ['customer' => 'ACME']);
        $empty = $this->source([]);

        $replays = $this->replayCount();
        foreach ([
            'source variables the target does not declare' => [$source, ['targetVersion' => (string) $plain->getId()], 'variables/customer'],
            'invalid variables' => [$source, ['targetVersion' => (string) $declaring->getId(), 'variables' => ['customer' => 42]], 'variables'],
            'unknown version' => [$source, ['targetVersion' => '/api/agent_versions/00000000-0000-4000-8000-000000000000'], 'unknown agent version'],
            'disabled agent' => [$source, ['targetVersion' => (string) $disabled->getId()], 'is disabled'],
            'stale reference' => [$source, ['targetVersion' => (string) $stale->getId(), 'variables' => new \stdClass()], 'configuration/collections/0'],
            'unsupported language' => [$source, ['targetVersion' => (string) $plain->getId(), 'variables' => new \stdClass(), 'language' => 'fr'], 'language'],
            'no user message' => [$empty, ['targetVersion' => (string) $plain->getId()], 'no user message'],
            'unknown property' => [$source, ['targetVersion' => (string) $plain->getId(), 'maxUserTurns' => 1], 'maxUserTurns'],
        ] as $case => [$from, $body, $message]) {
            $response = $this->trigger((string) $from->getId(), $body);
            self::assertSame(422, $response->getStatusCode(), $case.': '.$response->getContent(false));
            self::assertStringContainsString($message, (string) ($response->toArray(false)['detail'] ?? ''), $case);
            self::assertSame($replays, $this->replayCount(), $case);
        }

        // Replacing the undeclared source variables is accepted.
        self::assertSame(ConversationReplayStatus::PENDING, $this->triggered((string) $source->getId(), ['targetVersion' => (string) $plain->getId(), 'variables' => new \stdClass()])['status']);

        $unknown = $this->trigger('00000000-0000-4000-8000-000000000000', ['targetVersion' => (string) $plain->getId()]);
        self::assertSame(404, $unknown->getStatusCode(), $unknown->getContent(false));
    }

    public function testALowerUserTurnLimitGivesAPartialReplay(): void
    {
        $version = $this->publishedVersion('limit', ['maxUserTurns' => 2]);
        $source = $this->source(self::QUESTIONS);

        $replay = $this->triggered((string) $source->getId(), ['targetVersion' => (string) $version->getId()]);
        $job = $this->runJob($replay['jobId']);

        self::assertSame(['status' => ConversationReplayStatus::PARTIAL, 'turnsReplayed' => 2, 'turnsTotal' => 3], $job->getResultData());
        self::assertSame(['Replay question one', 'Replay question two'], $this->userContents($replay['id']));
        $conversation = $this->conversationRow($replay['id']);
        self::assertSame('closed', $conversation['status']);
        self::assertSame(Conversation::CLOSE_REASON_USER_TURN_LIMIT, $conversation['close_reason']);
        self::assertSame(ConversationReplayStatus::PARTIAL, $this->replayRow($replay['id'])['status']);
        foreach ($this->auditEntries($replay['id']) as $entry) {
            self::assertSame(self::TRIGGER, $entry['username']);
        }
    }

    public function testReplayRunsOnTheConnectionOfTheTargetVersion(): void
    {
        // Source on the anthropic connection, target version on ollama.
        $ollama = ConversationConnections::ollama();
        $version = $this->publishedVersion('target-connection', ['model' => ConversationConnections::modelId('ollama')]);
        $source = $this->source(['Replay question one']);
        self::assertSame(ConversationConnections::id(), (string) $this->conversationRow((string) $source->getId())['provider_connection_id']);

        $replay = $this->triggered((string) $source->getId(), ['targetVersion' => (string) $version->getId()]);
        $row = $this->conversationRow($replay['id']);
        self::assertSame($ollama->getId()->toRfc4122(), (string) $row['provider_connection_id']);
        self::assertSame('ollama', $row['provider']);

        $this->runJob($replay['jobId']);

        self::assertSame(ConversationReplayStatus::COMPLETED, $this->replayRow($replay['id'])['status']);
        $assistant = $this->dbal()->fetchAllAssociative("SELECT provider, model, connection_id FROM neuron_message WHERE conversation_id = :id AND role = 'assistant'", ['id' => $replay['id']]);
        self::assertNotEmpty($assistant);
        foreach ($assistant as $message) {
            self::assertSame(['ollama', FakeProviderFactory::OLLAMA_MODEL, $ollama->getId()->toRfc4122()], [$message['provider'], $message['model'], (string) $message['connection_id']]);
        }
    }

    public function testDeactivatedConnectionOfTheTargetVersionFailsTheReplay(): void
    {
        $remote = AgentFixtures::providerConnection('replay-remote', 'ollama');
        $version = $this->publishedVersion('stale-connection', ['model' => AgentFixtures::catalogueModel(AgentFixtures::entityManager(), $remote, FakeProviderFactory::OLLAMA_MODEL)->getId()->toRfc4122()]);
        $source = $this->source(['Replay question one']);
        $replay = $this->triggered((string) $source->getId(), ['targetVersion' => (string) $version->getId()]);

        $this->dbal()->executeStatement('UPDATE dmstr_api_configuration SET active = false WHERE id = :id', ['id' => $remote->getId()->toRfc4122()]);
        $this->entityManager()->clear();
        $this->runJob($replay['jobId']);

        $row = $this->replayRow($replay['id']);
        self::assertSame(ConversationReplayStatus::FAILED, $row['status']);
        self::assertStringContainsString('configuration/model: ', (string) $row['error_message']);
        self::assertSame([], $this->assistantContents($replay['id']));

        // Deactivated before the trigger: refused with 422, nothing stored.
        $replays = $this->replayCount();
        $refused = $this->trigger((string) $source->getId(), ['targetVersion' => (string) $version->getId()]);
        self::assertSame(422, $refused->getStatusCode(), $refused->getContent(false));
        self::assertStringContainsString('configuration/model: ', (string) ($refused->toArray(false)['detail'] ?? ''));
        self::assertSame($replays, $this->replayCount());
    }

    public function testRuleRefusedTurnsOfTheSourceAreReplayed(): void
    {
        $version = $this->publishedVersion('ruled');
        $source = $this->conversation(self::SOURCE_OWNER);
        $this->record($source, 1, Message::SPEAKER_USER, 'Replay question one');
        $this->record($source, 2, Message::SPEAKER_ASSISTANT, 'Dazu darf ich nichts sagen.', MessageKind::RULE_REFUSAL, MessageKind::RULE_REFUSAL);
        $this->record($source, 3, Message::SPEAKER_USER, 'Replay question two');
        $this->record($source, 4, Message::SPEAKER_ASSISTANT, 'Abgebrochen', MessageKind::TEXT, null, MessageStatus::ABORTED);

        $replay = $this->triggered((string) $source->getId(), ['targetVersion' => (string) $version->getId()]);
        $this->runJob($replay['jobId']);

        self::assertSame(['Replay question one', 'Replay question two'], $this->userContents($replay['id']));
        self::assertContains('Antwort eins aus der Wiederholung.', $this->assistantContents($replay['id']));
        self::assertSame(ConversationReplayStatus::COMPLETED, $this->replayRow($replay['id'])['status']);
    }

    public function testTheInjectionGuardOfTheTargetVersionRefusesAReplayedTurn(): void
    {
        $version = $this->publishedVersion('guard', InjectionGuardTurnTest::guardConfiguration());
        $source = $this->source(self::QUESTIONS);
        // Without the marker fixtures the question fixtures would answer
        // the guard calls too (an unparsable verdict, failing open).
        $this->extraFixtures = [
            InjectionGuard::MARKER.' '.self::QUESTIONS[0] => '{"injection": false, "reason": "A question."}',
            InjectionGuard::MARKER.' '.self::QUESTIONS[1] => '{"injection": true, "reason": "'.InjectionGuardTurnTest::REASON.'"}',
            InjectionGuard::MARKER.' '.self::QUESTIONS[2] => '{"injection": false, "reason": "A question."}',
        ];

        $replay = $this->triggered((string) $source->getId(), ['targetVersion' => (string) $version->getId()]);
        $this->runJob($replay['jobId']);

        self::assertSame(ConversationReplayStatus::COMPLETED, $this->replayRow($replay['id'])['status']);
        self::assertSame(self::QUESTIONS, $this->userContents($replay['id']));
        self::assertSame(
            ['Antwort eins aus der Wiederholung.', InjectionGuardTurnTest::REFUSAL_DE, 'Antwort drei aus der Wiederholung.'],
            $this->assistantContents($replay['id']),
        );
        $kinds = $this->dbal()->fetchFirstColumn("SELECT kind FROM neuron_message WHERE conversation_id = :id AND role = 'assistant' ORDER BY sequence", ['id' => $replay['id']]);
        self::assertSame([MessageKind::TEXT, MessageKind::INJECTION_REFUSAL, MessageKind::TEXT], $kinds);
        self::assertSame(3, (int) $this->conversationRow($replay['id'])['turn_count']);
        $this->assertGapFree($replay['id']);
    }

    public function testAnAnonymousSourceIsReplayedIntoAConversationOfTheTrigger(): void
    {
        $version = $this->publishedVersion('anonymous');
        $source = new Conversation();
        $source
            ->setOwnerSubject($source->anonymousOwnerSubject())
            ->setAnonymous(true)
            ->setTokenHash(CapabilityToken::hash('anon_UmVwbGF5LWFub255bW91cy1zb3VyY2U'))
            ->setTitle(self::PREFIX.'anonymous')
            ->setProviderModel(ConversationConnections::model())
            ->setRetrievalDepth(4);
        $this->entityManager()->persist($source);
        $this->entityManager()->flush();
        $this->record($source, 1, Message::SPEAKER_USER, 'Replay question one');

        $replay = $this->triggered((string) $source->getId(), ['targetVersion' => (string) $version->getId()]);
        $this->runJob($replay['jobId']);

        $conversation = $this->conversationRow($replay['id']);
        self::assertFalse((bool) $conversation['anonymous']);
        self::assertNull($conversation['token_hash']);
        self::assertSame(self::TRIGGER, $conversation['owner_subject']);
        self::assertSame(['Replay question one'], $this->userContents($replay['id']));
    }

    public function testCancelBeforeTheJobRunsReplaysNothing(): void
    {
        $version = $this->publishedVersion('cancel-early');
        $replay = $this->triggered((string) $this->source(self::QUESTIONS)->getId(), ['targetVersion' => (string) $version->getId()]);

        $cancel = $this->post('/api/conversations/'.$replay['id'].'/replay_run/cancel');
        self::assertSame(200, $cancel->getStatusCode(), $cancel->getContent(false));
        self::assertNotNull($cancel->toArray()['cancelRequestedAt']);
        self::assertSame(ConversationReplayStatus::PENDING, $cancel->toArray()['status']);

        $this->runJob($replay['jobId']);

        self::assertSame(ConversationReplayStatus::CANCELLED, $this->replayRow($replay['id'])['status']);
        self::assertSame([], $this->userContents($replay['id']));
    }

    public function testCancelDuringTheReplayStopsAfterTheCurrentTurn(): void
    {
        $version = $this->publishedVersion('cancel-during');
        $replay = $this->triggered((string) $this->source(self::QUESTIONS)->getId(), ['targetVersion' => (string) $version->getId()]);

        $this->runReplay($replay['id'], function (ConversationReplay $replay): void {
            if (1 === $replay->getTurnsReplayed()) {
                $this->dbal()->executeStatement('UPDATE neuron_conversation_replay SET cancel_requested_at = NOW() WHERE id = :id', ['id' => (string) $replay->getId()]);
            }
        });

        $row = $this->replayRow($replay['id']);
        self::assertSame(ConversationReplayStatus::CANCELLED, $row['status']);
        self::assertSame(1, (int) $row['turns_replayed']);
        self::assertSame(['Replay question one'], $this->userContents($replay['id']));
    }

    public function testDeletingTheSourceDuringTheReplayEndsItFailedAndKeepsIt(): void
    {
        $version = $this->publishedVersion('delete-during');
        $source = $this->source(self::QUESTIONS);
        $replay = $this->triggered((string) $source->getId(), ['targetVersion' => (string) $version->getId()]);

        $this->runReplay($replay['id'], function (ConversationReplay $replay) use ($source): void {
            if (1 === $replay->getTurnsReplayed()) {
                $this->dbal()->executeStatement('DELETE FROM neuron_conversation WHERE id = :id', ['id' => (string) $source->getId()]);
            }
        });

        $row = $this->replayRow($replay['id']);
        self::assertSame(ConversationReplayStatus::FAILED, $row['status']);
        self::assertNull($row['source_conversation_id']);
        self::assertStringContainsString('source conversation was deleted', (string) $row['error_message']);
        self::assertSame(['Replay question one'], $this->userContents($replay['id']));
    }

    public function testDeletingTheSourceAfterTheReplayKeepsTheReplay(): void
    {
        $version = $this->publishedVersion('delete-after');
        $source = $this->source(self::QUESTIONS);
        $replay = $this->triggered((string) $source->getId(), ['targetVersion' => (string) $version->getId()]);
        $this->runJob($replay['jobId']);

        // DBAL, like the anonymous purge's DQL bulk delete: no ORM cascade.
        $this->dbal()->executeStatement('DELETE FROM neuron_conversation WHERE id = :id', ['id' => (string) $source->getId()]);

        $row = $this->replayRow($replay['id']);
        self::assertNull($row['source_conversation_id']);
        self::assertSame(ConversationReplayStatus::COMPLETED, $row['status']);
        self::assertSame(self::QUESTIONS, $this->userContents($replay['id']));

        $read = $this->get('/api/conversations/'.$replay['id'].'/replay_run');
        self::assertSame(200, $read->getStatusCode(), $read->getContent(false));
        self::assertNull($read->toArray()['sourceConversation'] ?? null);
    }

    public function testManualTurnsAreRefusedWhileTheReplayRunsAndAcceptedAfterwards(): void
    {
        $version = $this->publishedVersion('manual');
        $replay = $this->triggered((string) $this->source(['Replay question one'])->getId(), ['targetVersion' => (string) $version->getId()]);
        $id = $replay['id'];

        $turn = $this->post('/api/conversations/'.$id.'/messages', ['content' => 'Manual follow-up']);
        self::assertSame(422, $turn->getStatusCode(), $turn->getContent(false));
        self::assertSame('conversation_closed', $turn->toArray(false)['code'] ?? null);

        $stream = $this->post('/api/conversations/'.$id.'/stream', ['content' => 'Manual follow-up']);
        self::assertSame(422, $stream->getStatusCode(), $stream->getContent(false));
        self::assertSame('conversation_closed', json_decode($stream->getContent(false), true)['code'] ?? null);
        self::assertSame([], $this->userContents($id));

        $this->runJob($replay['jobId']);
        self::assertSame(ConversationReplayStatus::COMPLETED, $this->replayRow($id)['status']);

        $this->armFixtures();
        $after = $this->post('/api/conversations/'.$id.'/messages', ['content' => 'Manual follow-up']);
        self::assertSame(201, $after->getStatusCode(), $after->getContent(false));
        self::assertSame(['Replay question one', 'Manual follow-up'], $this->userContents($id));
    }

    // --- fixtures -------------------------------------------------------

    private function agent(string $slug, bool $enabled = true): Agent
    {
        $agent = new Agent()->setName('Replay agent '.$slug)->setSlug(self::AGENT_SLUG.$slug)->setEnabled($enabled);
        $this->entityManager()->persist($agent);
        $this->entityManager()->flush();

        return $agent;
    }

    /**
     * @param array<string, mixed> $configuration overrides of the candidate defaults
     */
    private function publishedVersion(string $slug, array $configuration = [], bool $enabled = true): AgentVersion
    {
        $agent = $this->agent($slug, $enabled);
        $version = $this->version($agent, 1, $configuration + Agent::candidateDefaults());
        $agent->setCurrentVersion($version);
        $this->entityManager()->flush();

        return $version;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function version(Agent $agent, int $number, array $configuration): AgentVersion
    {
        $version = new AgentVersion($agent, $number, ConversationConnections::withConnection($configuration), 'test-admin');
        $this->entityManager()->persist($version);
        $this->entityManager()->flush();

        return $version;
    }

    private function conversation(string $owner): Conversation
    {
        $conversation = new Conversation()
            ->setOwnerSubject($owner)
            ->setTitle(self::PREFIX.'source')
            ->setProviderModel(ConversationConnections::model())
            ->setRetrievalDepth(4);
        $this->entityManager()->persist($conversation);
        $this->entityManager()->flush();

        return $conversation;
    }

    /**
     * A source conversation without an agent: one complete user/assistant
     * pair per question.
     *
     * @param list<string>              $questions
     * @param array<string, mixed>|null $variables
     */
    private function source(array $questions, ?array $variables = null): Conversation
    {
        $conversation = $this->conversation(self::SOURCE_OWNER)->setVariables($variables);
        $this->entityManager()->flush();
        foreach ($questions as $i => $question) {
            $this->record($conversation, 2 * $i + 1, Message::SPEAKER_USER, $question);
            $this->record($conversation, 2 * $i + 2, Message::SPEAKER_ASSISTANT, 'Original answer '.($i + 1));
        }

        return $conversation;
    }

    private function record(Conversation $conversation, int $sequence, string $role, string $content, string $kind = MessageKind::TEXT, ?string $closeReason = null, string $status = MessageStatus::COMPLETE): void
    {
        $managed = $this->entityManager()->find(Conversation::class, $conversation->getId());
        self::assertInstanceOf(Conversation::class, $managed);
        $message = new Message()
            ->setConversation($managed)
            ->setSequence($sequence)
            ->setRole($role)
            ->setKind($kind)
            ->setStatus($status)
            ->setContent($content)
            ->setCloseReason($closeReason);
        $managed->setNextSequence($sequence + 1);
        $this->entityManager()->persist($message);
        $this->entityManager()->flush();
    }

    // --- requests -------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     */
    private function trigger(string $sourceId, array $body): ResponseInterface
    {
        return $this->post('/api/conversations/'.$sourceId.'/replay', $body);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function triggered(string $sourceId, array $body): array
    {
        $response = $this->trigger($sourceId, $body);
        self::assertSame(202, $response->getStatusCode(), $response->getContent(false));

        return $response->toArray();
    }

    /**
     * @param array<string, mixed>|null $json
     */
    private function post(string $uri, ?array $json = null): ResponseInterface
    {
        $options = ['headers' => self::headers()];
        if (null !== $json) {
            $options['headers']['Content-Type'] = 'application/ld+json';
            $options['body'] = json_encode([] === $json ? new \stdClass() : $json, \JSON_THROW_ON_ERROR);
        }

        return static::createClient()->request('POST', $uri, $options);
    }

    private function get(string $uri): ResponseInterface
    {
        return static::createClient()->request('GET', $uri, ['headers' => self::headers()]);
    }

    /**
     * @return array<string, string>
     */
    private static function headers(): array
    {
        return ['Accept' => 'application/ld+json', TestHeaderAuthenticator::HEADER_ROLES => self::ROLES_ADMIN];
    }

    /**
     * Runs the job in the test process, as the messenger worker would.
     */
    private function runJob(string $jobId): Job
    {
        $this->armFixtures();
        $entityManager = $this->entityManager();
        $entityManager->clear();
        $job = $entityManager->find(Job::class, $jobId);
        self::assertInstanceOf(Job::class, $job);

        /** @var JobProcessorRegistry $registry */
        $registry = static::getContainer()->get(JobProcessorRegistry::class);
        $registry->getProcessor($job->getType())->process($job);
        $job->setStatus('completed');
        $entityManager->flush();

        return $job;
    }

    /**
     * Runs the replay through the runner of the registered job processor
     * with $checkpoint (a private service the container may inline, so it
     * is read from the processor).
     *
     * @param callable(ConversationReplay): void $checkpoint
     */
    private function runReplay(string $id, callable $checkpoint): void
    {
        $this->armFixtures();
        /** @var JobProcessorRegistry $registry */
        $registry = static::getContainer()->get(JobProcessorRegistry::class);
        $processor = $registry->getProcessor(ConversationReplay::JOB_TYPE);
        self::assertInstanceOf(ConversationReplayJobProcessor::class, $processor);
        $runner = new \ReflectionProperty(ConversationReplayJobProcessor::class, 'runner')->getValue($processor);
        self::assertInstanceOf(ConversationReplayRunner::class, $runner);

        $entityManager = $this->entityManager();
        $entityManager->clear();
        $replay = $entityManager->find(ConversationReplay::class, Uuid::fromString($id));
        self::assertInstanceOf(ConversationReplay::class, $replay);
        $runner->run($replay, $checkpoint);
    }

    // --- database -------------------------------------------------------

    private function assertGapFree(string $conversationId): void
    {
        /** @var list<int|string> $sequences */
        $sequences = $this->dbal()->fetchFirstColumn('SELECT sequence FROM neuron_message WHERE conversation_id = :id ORDER BY sequence', ['id' => $conversationId]);
        self::assertSame(range(1, \count($sequences)), array_map('intval', $sequences));
    }

    /**
     * @return array<string, mixed>
     */
    private function replayRow(string $id): array
    {
        $row = $this->dbal()->fetchAssociative('SELECT * FROM neuron_conversation_replay WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row, 'Replay '.$id.' is missing.');

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationRow(string $id): array
    {
        $row = $this->dbal()->fetchAssociative('SELECT * FROM neuron_conversation WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row, 'Conversation '.$id.' is missing.');

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messageRows(string $conversationId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->dbal()->fetchAllAssociative('SELECT id, sequence, role, kind, status, content FROM neuron_message WHERE conversation_id = :id ORDER BY sequence', ['id' => $conversationId]);

        return $rows;
    }

    /**
     * @return list<string|null>
     */
    private function userContents(string $conversationId): array
    {
        /** @var list<string|null> $contents */
        $contents = $this->dbal()->fetchFirstColumn("SELECT content FROM neuron_message WHERE conversation_id = :id AND role = 'user' ORDER BY sequence", ['id' => $conversationId]);

        return $contents;
    }

    /**
     * @return list<string|null>
     */
    private function assistantContents(string $conversationId): array
    {
        /** @var list<string|null> $contents */
        $contents = $this->dbal()->fetchFirstColumn("SELECT content FROM neuron_message WHERE conversation_id = :id AND role = 'assistant' ORDER BY sequence", ['id' => $conversationId]);

        return $contents;
    }

    /**
     * @return list<array{action: string, username: string|null}>
     */
    private function auditEntries(string $conversationId): array
    {
        /** @var list<array{action: string, username: string|null}> $entries */
        $entries = $this->dbal()->fetchAllAssociative(
            'SELECT action, username FROM dmstr_log_entries WHERE object_class = :class AND object_id = :id ORDER BY version ASC',
            ['class' => Conversation::class, 'id' => $conversationId],
        );

        return $entries;
    }

    private function replayConversationCount(string $versionId): int
    {
        return (int) $this->dbal()->fetchOne("SELECT COUNT(*) FROM neuron_conversation WHERE origin = 'replay' AND agent_version = :version", ['version' => $versionId]);
    }

    private function replayCount(): int
    {
        return (int) $this->dbal()->fetchOne('SELECT COUNT(*) FROM neuron_conversation_replay');
    }

    /**
     * Sets the answer fixtures on the fake provider of the current
     * container (the one the job processor and the runner use).
     */
    private function armFixtures(): void
    {
        $factory = static::getContainer()->get(ProviderFactory::class);
        self::assertInstanceOf(FakeProviderFactory::class, $factory);
        $factory->useFixtures($this->extraFixtures + FixtureSet::load(self::FIXTURES));
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    private function dbal(): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');

        return $connection;
    }

    /**
     * Replay conversations of the test agents (their records cascade) with
     * their audit entries, the sources, the replay jobs, then the agents
     * ({@see AgentFixtures::cleanUp()}).
     */
    public static function cleanUp(): void
    {
        static::bootKernel();
        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');

        $conversations = $connection->fetchFirstColumn(
            'SELECT c.id FROM neuron_conversation c JOIN neuron_agent_version v ON v.id = c.agent_version JOIN neuron_agent a ON a.id = v.agent_id WHERE a.slug LIKE :agents',
            ['agents' => self::AGENT_SLUG.'%'],
        );
        $conversations = array_merge($conversations, $connection->fetchFirstColumn(
            'SELECT id FROM neuron_conversation WHERE title LIKE :prefix OR owner_subject LIKE :prefix',
            ['prefix' => self::PREFIX.'%'],
        ));
        foreach (array_unique(array_map('strval', $conversations)) as $id) {
            $connection->executeStatement('DELETE FROM dmstr_log_entries WHERE object_class = :class AND object_id = :id', ['class' => Conversation::class, 'id' => $id]);
            $connection->executeStatement('DELETE FROM neuron_conversation WHERE id = :id', ['id' => $id]);
        }
        $connection->executeStatement('DELETE FROM dmstr_job WHERE type = :type', ['type' => ConversationReplay::JOB_TYPE]);
        static::ensureKernelShutdown();

        AgentFixtures::cleanUp();
    }
}
