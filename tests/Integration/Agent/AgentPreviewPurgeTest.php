<?php

// file generated with AI assistance: Claude Code - 2026-10-13 23:25:27 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Agent;

use Dmstr\NeuronAiBundle\Agent\AgentVersionKind;
use Dmstr\NeuronAiBundle\Agent\Preview\AgentPreviewPurger;
use Dmstr\NeuronAiBundle\Agent\Preview\AgentPreviewPurgeReport;
use Dmstr\NeuronAiBundle\Agent\Preview\AgentPreviewRetentionInterface;
use Dmstr\NeuronAiBundle\Agent\Preview\PurgeAgentPreviewsHandler;
use Dmstr\NeuronAiBundle\Agent\Preview\PurgeAgentPreviewsMessage;
use Dmstr\NeuronAiBundle\Anonymous\SystemActor;
use Dmstr\NeuronAiBundle\Conversation\ConversationStatus;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Conversation\TurnLock;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\ConversationReplay;
use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Entity\EvalResult;
use Dmstr\NeuronAiBundle\Entity\EvalRun;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Evaluation\EvalQuestionStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultSource;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvalRunStatus;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryAgentPreviewRetention;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Group;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The purge of agent preview versions ({@see AgentPreviewPurger}) against
 * the database: a due preview goes with its completed evaluation run (its
 * results and human rating by cascade) and its conversations (their
 * messages by cascade); a preview with a running evaluation run, a replay
 * target and a preview whose conversation has a running turn are kept and
 * counted per reason; a fresh preview is kept; a retention of 0 and a dry
 * run delete nothing; the scheduled handler uses the host's default of 7
 * days.
 *
 * Previews are written directly as entities with a creation time in the
 * past; the purger runs with a fixed retention stub of 7 days unless the
 * test checks the container service. Report counts are global, so they are
 * asserted as lower bounds; the rows of the fixtures are asserted exactly.
 *
 * Needs the migrated test database (CI only). Fixtures use the agent slug
 * prefix {@see SLUG} and the question key prefix {@see QUESTION_PREFIX};
 * cleanUp() removes evaluation runs and questions first, then the
 * conversations' audit entries, then the agents with
 * {@see AgentFixtures::cleanUp()} (conversations on the fixture
 * connections, versions, agents).
 */
#[Group('database')]
final class AgentPreviewPurgeTest extends KernelTestCase
{
    private const string SLUG = 'preview-purge-';
    private const string QUESTION_PREFIX = 'test-preview-purge-';
    private const string OWNER = 'test-preview-purge-owner';

    /**
     * Ids of the conversations the fixtures created, for the cleanup of
     * their audit entries (the purge deletes the rows, not the entries).
     *
     * @var list<string>
     */
    private static array $conversationIds = [];

    private ?Connection $second = null;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        self::cleanUp();
        static::bootKernel();
    }

    protected function tearDown(): void
    {
        $this->second?->close();
        $this->second = null;
        self::cleanUp();
        parent::tearDown();
    }

    public function testDuePreviewIsDeletedWithItsCompletedRunAndConversations(): void
    {
        $preview = $this->preview('due', 10);
        $run = $this->evalRun($preview, EvalRunStatus::COMPLETED);
        $conversation = $this->conversation($preview, Conversation::ORIGIN_EVAL);
        $result = $this->evalResult($run, $conversation, ['score' => 4]);
        $chat = $this->conversation($preview);
        AgentFixtures::entityManager()->clear();

        $report = $this->purger(7)->run();

        self::assertTrue($report->ran);
        self::assertFalse($report->dryRun);
        self::assertSame(7, $report->retentionDays);
        self::assertGreaterThanOrEqual(1, $report->deleted);
        self::assertGreaterThanOrEqual(1, $report->evalRuns);
        self::assertGreaterThanOrEqual(2, $report->conversations);

        self::assertFalse($this->exists('neuron_agent_version', $preview));
        self::assertFalse($this->exists('neuron_eval_run', $run));
        self::assertFalse($this->exists('neuron_eval_result', $result));
        self::assertFalse($this->exists('neuron_conversation', $conversation));
        self::assertFalse($this->exists('neuron_conversation', $chat));
        self::assertSame(0, (int) $this->dbal()->fetchOne('SELECT COUNT(*) FROM neuron_message WHERE conversation_id IN (:a, :b)', ['a' => $conversation, 'b' => $chat]));

        // The conversations were removed through the entity manager, audited
        // under the system actor.
        $actors = $this->dbal()->fetchFirstColumn(
            "SELECT username FROM dmstr_log_entries WHERE object_class = :class AND action = 'remove' AND object_id IN (:a, :b)",
            ['class' => Conversation::class, 'a' => $conversation, 'b' => $chat],
        );
        self::assertSame([AgentPreviewPurger::ACTOR, AgentPreviewPurger::ACTOR], $actors);

        // The agent itself stays.
        self::assertTrue($this->exists('neuron_agent', $this->agentOf('due')));
    }

    public function testPreviewWithARunningEvaluationRunIsSkipped(): void
    {
        $preview = $this->preview('running-run', 10);
        $run = $this->evalRun($preview, EvalRunStatus::RUNNING);
        $pending = $this->preview('pending-run', 10);
        $this->evalRun($pending, EvalRunStatus::PENDING);
        AgentFixtures::entityManager()->clear();

        $report = $this->purger(7)->run();

        self::assertGreaterThanOrEqual(2, $report->skipped[AgentPreviewPurgeReport::SKIP_ACTIVE_EVAL_RUN] ?? 0);
        self::assertTrue($this->exists('neuron_agent_version', $preview));
        self::assertTrue($this->exists('neuron_eval_run', $run));
        self::assertTrue($this->exists('neuron_agent_version', $pending));
    }

    public function testReplayTargetIsSkipped(): void
    {
        $preview = $this->preview('replay-target', 10);
        $replayConversation = $this->conversation($preview, Conversation::ORIGIN_REPLAY);
        $entityManager = AgentFixtures::entityManager();
        $entityManager->persist(new ConversationReplay(
            $entityManager->getReference(Conversation::class, Uuid::fromString($replayConversation)),
            $entityManager->getReference(AgentVersion::class, Uuid::fromString($preview)),
            self::OWNER,
        ));
        $entityManager->flush();
        $entityManager->clear();

        $report = $this->purger(7)->run();

        self::assertGreaterThanOrEqual(1, $report->skipped[AgentPreviewPurgeReport::SKIP_REPLAY_TARGET] ?? 0);
        self::assertTrue($this->exists('neuron_agent_version', $preview));
        self::assertTrue($this->exists('neuron_conversation', $replayConversation));
        self::assertTrue($this->exists('neuron_conversation_replay', $replayConversation));
    }

    public function testPreviewWithARunningTurnIsSkippedUntilTheTurnEnds(): void
    {
        $preview = $this->preview('running-turn', 10);
        $conversation = $this->conversation($preview);
        AgentFixtures::entityManager()->clear();

        $key = TurnLock::keyFor(Uuid::fromString($conversation));
        $this->second = DriverManager::getConnection($this->dbal()->getParams());
        self::assertTrue((bool) $this->second->fetchOne('SELECT pg_try_advisory_lock(CAST(:key AS BIGINT))', ['key' => $key]));

        $report = $this->purger(7)->run();

        self::assertGreaterThanOrEqual(1, $report->skipped[AgentPreviewPurgeReport::SKIP_RUNNING_TURN] ?? 0);
        self::assertTrue($this->exists('neuron_agent_version', $preview));
        self::assertTrue($this->exists('neuron_conversation', $conversation));

        $this->second->fetchOne('SELECT pg_advisory_unlock(CAST(:key AS BIGINT))', ['key' => $key]);
        $this->second->close();
        $this->second = null;

        $this->purger(7)->run();

        self::assertFalse($this->exists('neuron_agent_version', $preview));
        self::assertFalse($this->exists('neuron_conversation', $conversation));
    }

    public function testFreshPreviewIsKept(): void
    {
        $fresh = $this->preview('fresh', 6);
        $run = $this->evalRun($fresh, EvalRunStatus::COMPLETED);
        AgentFixtures::entityManager()->clear();

        $this->purger(7)->run();

        self::assertTrue($this->exists('neuron_agent_version', $fresh));
        self::assertTrue($this->exists('neuron_eval_run', $run));
    }

    public function testPublishedVersionsAreNeverPurged(): void
    {
        $agent = $this->agent('published');
        $version = new AgentVersion($agent, 1, ConversationConnections::withConnection(Agent::candidateDefaults()), self::OWNER, new \DateTimeImmutable('-400 days'));
        AgentFixtures::entityManager()->persist($version);
        AgentFixtures::entityManager()->flush();
        $id = $version->getId()->toRfc4122();
        AgentFixtures::entityManager()->clear();

        $this->purger(7)->run();

        self::assertTrue($this->exists('neuron_agent_version', $id));
    }

    public function testRetentionZeroPurgesNothing(): void
    {
        $preview = $this->preview('retention-zero', 400);
        AgentFixtures::entityManager()->clear();

        $report = $this->purger(0)->run();

        self::assertTrue($report->ran);
        self::assertTrue($report->isEmpty());
        self::assertTrue($this->exists('neuron_agent_version', $preview));
    }

    public function testDryRunDeletesNothing(): void
    {
        $preview = $this->preview('dry-run', 10);
        $run = $this->evalRun($preview, EvalRunStatus::COMPLETED);
        $conversation = $this->conversation($preview);
        $skipped = $this->preview('dry-run-skipped', 10);
        $this->evalRun($skipped, EvalRunStatus::RUNNING);
        AgentFixtures::entityManager()->clear();

        $report = $this->purger(7)->run(true);

        self::assertTrue($report->dryRun);
        self::assertGreaterThanOrEqual(1, $report->deleted);
        self::assertGreaterThanOrEqual(1, $report->evalRuns);
        self::assertGreaterThanOrEqual(1, $report->conversations);
        self::assertGreaterThanOrEqual(1, $report->skipped[AgentPreviewPurgeReport::SKIP_ACTIVE_EVAL_RUN] ?? 0);
        self::assertTrue($this->exists('neuron_agent_version', $preview));
        self::assertTrue($this->exists('neuron_eval_run', $run));
        self::assertTrue($this->exists('neuron_conversation', $conversation));
        self::assertTrue($this->exists('neuron_agent_version', $skipped));
    }

    /**
     * The container wiring: the handler runs the purger with the settings
     * retention of the host port (the default of 7 days).
     */
    public function testHandlerPurgesWithTheSettingsDefault(): void
    {
        $due = $this->preview('handler-due', 8);
        $fresh = $this->preview('handler-fresh', 6);
        AgentFixtures::entityManager()->clear();

        $retention = static::getContainer()->get(AgentPreviewRetentionInterface::class);
        self::assertInstanceOf(AgentPreviewRetentionInterface::class, $retention);
        self::assertSame(InMemoryAgentPreviewRetention::DEFAULT_RETENTION_DAYS, $retention->retentionDays());

        $handler = static::getContainer()->get(PurgeAgentPreviewsHandler::class);
        self::assertInstanceOf(PurgeAgentPreviewsHandler::class, $handler);
        $handler(new PurgeAgentPreviewsMessage());

        self::assertFalse($this->exists('neuron_agent_version', $due));
        self::assertTrue($this->exists('neuron_agent_version', $fresh));
    }

    /**
     * A purger on the container's services with a fixed retention.
     */
    private function purger(int $retentionDays): AgentPreviewPurger
    {
        $container = static::getContainer();
        $turnLock = $container->get(TurnLock::class);
        $systemActor = $container->get(SystemActor::class);
        $clock = $container->get('clock');
        self::assertInstanceOf(TurnLock::class, $turnLock);
        self::assertInstanceOf(SystemActor::class, $systemActor);
        self::assertInstanceOf(ClockInterface::class, $clock);

        $retention = new class($retentionDays) implements AgentPreviewRetentionInterface {
            public function __construct(private readonly int $days)
            {
            }

            public function retentionDays(): int
            {
                return $this->days;
            }
        };

        return new AgentPreviewPurger(AgentFixtures::entityManager(), $turnLock, $systemActor, $retention, $clock);
    }

    private function agent(string $suffix): Agent
    {
        $agent = AgentFixtures::entityManager()->find(Agent::class, Uuid::fromString(
            AgentFixtures::storeAgent(self::SLUG.$suffix, AgentFixtures::validCandidate()),
        ));
        self::assertInstanceOf(Agent::class, $agent);

        return $agent;
    }

    private function agentOf(string $suffix): string
    {
        return (string) $this->dbal()->fetchOne('SELECT id FROM neuron_agent WHERE slug = :slug', ['slug' => AgentFixtures::PREFIX.self::SLUG.$suffix]);
    }

    /**
     * A preview of a new agent, created $daysAgo days ago.
     *
     * @return string the preview id
     */
    private function preview(string $suffix, int $daysAgo): string
    {
        $preview = new AgentVersion(
            $this->agent($suffix),
            0,
            ConversationConnections::withConnection(Agent::candidateDefaults()),
            self::OWNER,
            new \DateTimeImmutable(\sprintf('-%d days', $daysAgo)),
            AgentVersionKind::PREVIEW,
            hash('sha256', self::SLUG.$suffix),
        );
        AgentFixtures::entityManager()->persist($preview);
        AgentFixtures::entityManager()->flush();

        return $preview->getId()->toRfc4122();
    }

    private function evalRun(string $versionId, string $status): string
    {
        $entityManager = AgentFixtures::entityManager();
        $version = $entityManager->find(AgentVersion::class, Uuid::fromString($versionId));
        self::assertInstanceOf(AgentVersion::class, $version);
        $run = new EvalRun()
            ->setAgentVersion($version)
            ->setOwnerSubject(self::OWNER)
            ->setStatus($status);
        $entityManager->persist($run);
        $entityManager->flush();

        return $run->getId()->toRfc4122();
    }

    /**
     * @param array<string, mixed> $humanRating
     */
    private function evalResult(string $runId, string $conversationId, array $humanRating): string
    {
        $entityManager = AgentFixtures::entityManager();
        $question = new EvalQuestion()
            ->setKey(self::QUESTION_PREFIX.Uuid::v4()->toRfc4122())
            ->setQuestion('How often is the filter changed?')
            ->setExpectedAnswer('Every 500 operating hours.')
            ->setStatus(EvalQuestionStatus::ARCHIVED);
        $entityManager->persist($question);
        $run = $entityManager->find(EvalRun::class, Uuid::fromString($runId));
        $conversation = $entityManager->find(Conversation::class, Uuid::fromString($conversationId));
        self::assertInstanceOf(EvalRun::class, $run);
        self::assertInstanceOf(Conversation::class, $conversation);
        $result = new EvalResult()
            ->setEvalRun($run)
            ->setEvalQuestion($question)
            ->setConversation($conversation)
            ->setSource(EvalResultSource::BATCH)
            ->setStatus(EvalResultStatus::COMPLETED)
            ->setHumanRating($humanRating);
        $entityManager->persist($result);
        $entityManager->flush();

        return $result->getId()->toRfc4122();
    }

    /**
     * A conversation pinned to the version, with one user message.
     */
    private function conversation(string $versionId, ?string $origin = null): string
    {
        $entityManager = AgentFixtures::entityManager();
        $version = $entityManager->find(AgentVersion::class, Uuid::fromString($versionId));
        self::assertInstanceOf(AgentVersion::class, $version);
        $conversation = new Conversation()
            ->setOwnerSubject(self::OWNER)
            ->setStatus(ConversationStatus::ACTIVE)
            ->setProviderModel(ConversationConnections::model())
            ->setAgentVersion($version)
            ->setRetrievalDepth(4)
            ->setNextSequence(2);
        if (null !== $origin) {
            $conversation->setOrigin($origin);
        }
        $entityManager->persist($conversation);
        $entityManager->persist(new Message()
            ->setConversation($conversation)
            ->setSequence(1)
            ->setRole(Message::SPEAKER_USER)
            ->setKind(MessageKind::TEXT)
            ->setStatus(MessageStatus::COMPLETE)
            ->setContent('How often is the filter changed?'));
        $entityManager->flush();
        self::$conversationIds[] = $conversation->getId()->toRfc4122();

        return $conversation->getId()->toRfc4122();
    }

    private function exists(string $table, string $id): bool
    {
        return false !== $this->dbal()->fetchOne('SELECT 1 FROM '.$table.' WHERE id = :id', ['id' => $id]);
    }

    private function dbal(): Connection
    {
        return AgentFixtures::dbal();
    }

    /**
     * Evaluation runs on versions of the test agents (results, evaluations
     * and suggestions cascade), the test questions, the audit entries of
     * the fixture conversations, then {@see AgentFixtures::cleanUp()} (conversations on
     * the fixture connections with their replay records, versions, agents).
     */
    private static function cleanUp(): void
    {
        static::bootKernel();
        $connection = AgentFixtures::dbal();
        $agents = ['agents' => AgentFixtures::PREFIX.self::SLUG.'%'];
        $versions = 'SELECT v.id FROM neuron_agent_version v JOIN neuron_agent a ON a.id = v.agent_id WHERE a.slug LIKE :agents';

        $connection->executeStatement('DELETE FROM neuron_eval_run WHERE agent_version_id IN ('.$versions.')', $agents);
        $connection->executeStatement('DELETE FROM neuron_eval_question WHERE key LIKE :prefix', ['prefix' => self::QUESTION_PREFIX.'%']);
        foreach (self::$conversationIds as $id) {
            $connection->executeStatement('DELETE FROM dmstr_log_entries WHERE object_class = :class AND object_id = :id', ['class' => Conversation::class, 'id' => $id]);
        }
        self::$conversationIds = [];
        static::ensureKernelShutdown();

        AgentFixtures::cleanUp();
    }
}
