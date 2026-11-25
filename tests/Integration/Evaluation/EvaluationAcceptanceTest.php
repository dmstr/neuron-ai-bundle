<?php

// file generated with AI assistance: Claude Code - 2026-10-03 21:25:21 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Evaluation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\EvalRun;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultSource;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvalRunStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvaluationStatus;
use Dmstr\NeuronAiBundle\Evaluation\SuggestionType;
use Dmstr\NeuronAiBundle\Evaluation\Verdict;
use Dmstr\NeuronAiBundle\JobProcessor\EvalResultEvaluateJobProcessor;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\FixtureSet;
use Dmstr\NeuronAiBundle\Tests\Integration\Conversation\AccessTest;
use Dmstr\NeuronAiBundle\Tests\Integration\Feedback\FeedbackSubmitTest;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryEvaluationSettings;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\Job\JobProcessorRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Acceptance test of the evaluation feature: one walk through
 * the whole path with fixtures only, no live model.
 *
 *  1. Import the test set `Fixtures/evaluation/acceptance-questions.json`
 *     with `neuron:eval:questions:import` (three active questions, one
 *     archived); a second import creates nothing.
 *  2. Publish an agent version and start a run as `ROLE_EVAL_ADMIN`; the
 *     `eval_run_start` job (run through {@see JobProcessorRegistry}, as the
 *     worker would) writes one result per active question, each with an
 *     evaluation conversation pinned to the version; the warranty question
 *     fails at the provider (fixture failure) and the run still completes.
 *  3. Configure `evaluation.judge_agent` and
 *     `evaluation.human_rating_schema`, evaluate the maintenance result and
 *     run the `eval_result_evaluate` job: verdict `pass` with its
 *     suggestions, the run's `verdictCounts` follow.
 *  4. A reviewer rates the result (valid: stored; an extra property under
 *     `additionalProperties: false`: 422).
 *  5. Feedback on the evaluation conversation: the reviewer (`reviewer`),
 *     the starter (`enduser`), a foreign `ROLE_USER` (403), an unknown
 *     conversation IRI (400 from API Platform's denormalization).
 *  6. Visibility: the starter's ordinary conversation list omits the
 *     evaluation conversations, `?origin=eval` lists them, the results'
 *     `conversation` IRIs resolve for the starter and a reviewer, and a
 *     reviewer lists both feedback rows of the conversation.
 *
 * The question answers come from `answers.json` (keyed by question text),
 * the judge's from `judge.json` (keyed by the marker `[judge:pass]` in the
 * expected answer). The two sets are armed separately, each right before
 * its job: the judge's message also contains the question text, which
 * would otherwise win as the longer key. Every request through
 * createClient() boots a new kernel with a fake provider without
 * fixtures, so arming happens on the current container.
 *
 * Identities: fresh identifiers from {@see FeedbackSubmitTest::identity()}
 * for everybody who submits feedback, because the feedback counters live
 * in the cache pool and survive between tests. Questions carry the key
 * prefix {@see self::PREFIX}, agents the prefix of
 * {@see AgentFixtures} (removed by its cleanUp()). The batch runs
 * every active question in the database, so the test stops as incomplete
 * when another test left active questions behind. Needs the migrated
 * test database (CI only).
 */
#[Group('database')]
final class EvaluationAcceptanceTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const string PREFIX = 'test-acceptance.';
    private const string AGENT_SLUG = AgentFixtures::PREFIX.'acceptance-';
    private const string QUESTIONS = __DIR__.'/../../Fixtures/evaluation/acceptance-questions.json';
    private const string ANSWERS = __DIR__.'/../../Fixtures/evaluation/answers.json';
    private const string JUDGE = __DIR__.'/../../Fixtures/evaluation/judge.json';

    private const string ROLES_ADMIN = 'ROLE_USER,ROLE_EVAL_ADMIN';
    private const string ROLES_REVIEWER = 'ROLE_USER,ROLE_EVAL_REVIEWER';
    private const string ROLES_USER = 'ROLE_USER';

    private const string MAINTENANCE_ANSWER = 'Die Hydraulikpumpe wird alle 500 Betriebsstunden gewartet, spätestens jedoch einmal jährlich.';
    private const string TORQUE_ANSWER = 'Die Radmuttern werden mit 450 Nm angezogen und nach 50 km nachgezogen.';

    /** The operator's rating schema of this test. */
    private const array RATING_SCHEMA = [
        'type' => 'object',
        'required' => ['score'],
        'additionalProperties' => false,
        'properties' => [
            'score' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
            'comment' => ['type' => 'string', 'maxLength' => 2000],
        ],
    ];

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

    public function testImportRunJudgeRateFeedbackAndVisibility(): void
    {
        $starter = FeedbackSubmitTest::identity('acceptance-starter');
        $reviewer = FeedbackSubmitTest::identity('acceptance-reviewer');

        // 1. Import, twice.
        $this->importQuestions('created: 4, updated: 0, unchanged: 0');
        $this->importQuestions('created: 0, updated: 0, unchanged: 4');
        if (0 !== (int) $this->dbal()->fetchOne("SELECT COUNT(*) FROM neuron_eval_question WHERE status = 'active' AND key NOT LIKE :prefix", ['prefix' => self::PREFIX.'%'])) {
            self::markTestIncomplete('Active questions of another test are left in the database.');
        }

        // 2. Run on the published version.
        $version = $this->publishedVersion('main');
        $start = $this->post('/api/eval_runs/start', self::ROLES_ADMIN, $starter, ['agentVersion' => (string) $version->getId()]);
        self::assertSame(202, $start->getStatusCode(), $start->getContent(false));
        $run = $start->toArray();
        self::assertSame(EvalRunStatus::PENDING, $run['status']);
        self::assertSame($starter, $run['ownerSubject']);
        self::assertSame(3, $run['questionsTotal']);

        $job = $this->runJob($run['jobId'], self::ANSWERS);
        $report = (array) $job->getResultData();
        self::assertSame(2, $report['completed']);
        self::assertSame(1, $report['failed']);

        $runRow = $this->runRow($run['id']);
        self::assertSame(EvalRunStatus::COMPLETED, $runRow['status']);
        self::assertNotNull($runRow['finished_at']);
        self::assertEquals(self::counts(), $this->verdictCounts($run['id']));

        $results = $this->results($run['id']);
        self::assertSame([self::PREFIX.'a-maintenance', self::PREFIX.'b-torque', self::PREFIX.'c-warranty'], array_column($results, 'key'));
        $expected = [self::MAINTENANCE_ANSWER, self::TORQUE_ANSWER, null];
        foreach ($results as $i => $result) {
            self::assertSame(EvalResultSource::BATCH, $result['source']);
            self::assertNotNull($result['conversation_id'], (string) $result['key']);
            $conversation = $this->conversationRow((string) $result['conversation_id']);
            self::assertSame(Conversation::ORIGIN_EVAL, $conversation['origin']);
            self::assertSame((string) $version->getId(), (string) $conversation['agent_version']);
            self::assertSame($starter, $conversation['owner_subject']);

            if (null !== $expected[$i]) {
                self::assertSame(EvalResultStatus::COMPLETED, $result['status'], (string) $result['key']);
                self::assertContains($expected[$i], $this->assistantContents((string) $result['conversation_id']));
            } else {
                self::assertSame(EvalResultStatus::FAILED, $result['status']);
                self::assertStringContainsString('529', (string) $result['error_message']);
            }
        }
        $maintenance = $results[0];
        $resultId = (string) $maintenance['id'];
        $conversationIri = '/api/conversations/'.$maintenance['conversation_id'];
        $conversationIds = array_map('strval', array_column($results, 'conversation_id'));

        // 3. Judge.
        $judge = $this->publishedVersion('judge');
        InMemoryEvaluationSettings::configure([
            'judge_agent' => self::AGENT_SLUG.'judge',
            'human_rating_schema' => self::RATING_SCHEMA,
        ]);

        $evaluate = $this->post('/api/eval_results/'.$resultId.'/evaluate', self::ROLES_ADMIN, $starter);
        self::assertSame(202, $evaluate->getStatusCode(), $evaluate->getContent(false));
        $evaluation = $evaluate->toArray();
        self::assertSame(EvaluationStatus::PENDING, $evaluation['status']);
        self::assertNull($evaluation['verdict'] ?? null);

        $this->runJob($evaluation['jobId'], self::JUDGE);
        self::assertContains('[judge:pass]', $this->factory()->getChatProvider()->getFixtureHits());

        $evaluationRow = $this->dbal()->fetchAssociative('SELECT * FROM neuron_evaluation WHERE id = :id', ['id' => $evaluation['id']]);
        self::assertIsArray($evaluationRow);
        self::assertSame(EvaluationStatus::COMPLETED, $evaluationRow['status']);
        self::assertSame(Verdict::PASS, $evaluationRow['verdict']);
        self::assertNotEmpty($evaluationRow['reasoning']);
        self::assertSame((string) $judge->getId(), (string) $evaluationRow['judge_agent_version_id']);
        self::assertSame((string) $evaluation['id'], (string) $this->dbal()->fetchOne('SELECT evaluation_id FROM neuron_eval_result WHERE id = :id', ['id' => $resultId]));

        /** @var list<string> $types */
        $types = $this->dbal()->fetchFirstColumn('SELECT type FROM neuron_suggestion WHERE evaluation_id = :id ORDER BY type', ['id' => $evaluation['id']]);
        self::assertSame([SuggestionType::DATA, SuggestionType::PROMPT], $types);

        self::assertEquals(self::counts(pass: 1), $this->verdictCounts($run['id']));
        $runRead = $this->get('/api/eval_runs/'.$run['id'], self::ROLES_USER, $starter);
        self::assertSame(200, $runRead->getStatusCode(), $runRead->getContent(false));
        self::assertEquals(self::counts(pass: 1), $runRead->toArray()['verdictCounts']);

        // 4. Human rating.
        $rated = $this->rate($resultId, ['score' => 4, 'comment' => 'Interval right, source missing.'], $reviewer);
        self::assertSame(200, $rated->getStatusCode(), $rated->getContent(false));
        self::assertSame(['score' => 4, 'comment' => 'Interval right, source missing.'], $rated->toArray()['humanRating']);
        self::assertSame($reviewer, $rated->toArray()['humanRatedBy']);

        $refused = $this->rate($resultId, ['score' => 3, 'extra' => 'not in the schema'], $reviewer);
        self::assertSame(422, $refused->getStatusCode(), $refused->getContent(false));
        self::assertContains('/extra', array_column($refused->toArray(false)['violations'] ?? [], 'propertyPath'));
        $ratingRow = $this->dbal()->fetchAssociative('SELECT human_rating, human_rated_by FROM neuron_eval_result WHERE id = :id', ['id' => $resultId]);
        self::assertIsArray($ratingRow);
        self::assertEquals(['score' => 4, 'comment' => 'Interval right, source missing.'], json_decode((string) $ratingRow['human_rating'], true));
        self::assertSame($reviewer, $ratingRow['human_rated_by']);

        // 5. Feedback on the evaluation conversation.
        $byReviewer = FeedbackSubmitTest::submit(
            ['conversation' => $conversationIri, 'rating' => 'down', 'reason' => 'missing source'],
            FeedbackSubmitTest::asUser(self::ROLES_REVIEWER, $reviewer),
        );
        self::assertSame(201, $byReviewer->getStatusCode(), $byReviewer->getContent(false));
        self::assertSame('reviewer', $byReviewer->toArray()['source']);
        self::assertSame($reviewer, $byReviewer->toArray()['subject']);

        $byStarter = FeedbackSubmitTest::submit(
            ['conversation' => $conversationIri, 'rating' => 'up', 'comment' => 'Matches the manual.'],
            FeedbackSubmitTest::asUser(self::ROLES_USER, $starter),
        );
        self::assertSame(201, $byStarter->getStatusCode(), $byStarter->getContent(false));
        self::assertSame('enduser', $byStarter->toArray()['source']);
        self::assertSame($starter, $byStarter->toArray()['subject']);

        $foreign = FeedbackSubmitTest::submit(
            ['conversation' => $conversationIri, 'rating' => 'up'],
            FeedbackSubmitTest::asUser(self::ROLES_USER, FeedbackSubmitTest::identity('acceptance-foreign')),
        );
        self::assertSame(403, $foreign->getStatusCode(), $foreign->getContent(false));

        $unknown = FeedbackSubmitTest::submit(
            ['conversation' => '/api/conversations/00000000-0000-4000-8000-000000000000', 'rating' => 'up'],
            FeedbackSubmitTest::asUser(self::ROLES_USER, FeedbackSubmitTest::identity('acceptance-unknown')),
        );
        self::assertSame(400, $unknown->getStatusCode(), $unknown->getContent(false));

        self::assertSame(2, (int) $this->dbal()->fetchOne('SELECT COUNT(*) FROM neuron_feedback WHERE conversation_id = :id', ['id' => $maintenance['conversation_id']]));

        // 6. Visibility.
        $ordinary = AccessTest::memberIds($this->get('/api/conversations', self::ROLES_USER, $starter));
        self::assertSame([], array_values(array_intersect($conversationIds, $ordinary)));

        $evalList = AccessTest::memberIds($this->get('/api/conversations?origin=eval', self::ROLES_USER, $starter));
        self::assertEqualsCanonicalizing($conversationIds, $evalList);

        $listed = $this->get('/api/eval_results?evalRun='.urlencode('/api/eval_runs/'.$run['id']), self::ROLES_USER, $starter);
        self::assertSame(200, $listed->getStatusCode(), $listed->getContent(false));
        $data = $listed->toArray();
        $members = array_values($data['hydra:member'] ?? $data['member'] ?? []);
        self::assertCount(3, $members);
        foreach ($members as $member) {
            // Collection responses embed to-one relations with their label (api-platform-utils-bundle `relation_labels`).
            $iri = $member['conversation']['@id'] ?? null;
            self::assertIsString($iri);
            $asStarter = $this->get($iri, self::ROLES_USER, $starter);
            self::assertSame(200, $asStarter->getStatusCode(), $asStarter->getContent(false));
            self::assertSame(Conversation::ORIGIN_EVAL, $asStarter->toArray()['origin'] ?? null);
            self::assertSame(200, $this->get($iri, self::ROLES_REVIEWER, $reviewer)->getStatusCode());
        }

        $feedback = $this->get('/api/feedback?conversation='.urlencode($conversationIri), self::ROLES_REVIEWER, $reviewer);
        self::assertSame(200, $feedback->getStatusCode(), $feedback->getContent(false));
        $data = $feedback->toArray();
        $rows = array_values($data['hydra:member'] ?? $data['member'] ?? []);
        self::assertCount(2, $rows);
        $sources = array_column($rows, 'source');
        sort($sources);
        self::assertSame(['enduser', 'reviewer'], $sources);
        foreach ($rows as $row) {
            // Collection responses embed to-one relations with their label (api-platform-utils-bundle `relation_labels`).
            self::assertSame($conversationIri, $row['conversation']['@id'] ?? null);
        }
    }

    // --- steps ------------------------------------------------------------

    private function importQuestions(string $summary): void
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester(new Application($kernel)->find('neuron:eval:questions:import'));
        $tester->execute(['file' => self::QUESTIONS]);

        // SymfonyStyle wraps at the terminal width; compare on collapsed whitespace.
        $display = trim((string) preg_replace('/\s+/u', ' ', $tester->getDisplay()));
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $display);
        self::assertStringContainsString($summary, $display);
    }

    /**
     * An enabled agent with one published version (candidate defaults).
     */
    private function publishedVersion(string $slug): AgentVersion
    {
        $entityManager = $this->entityManager();
        $agent = new Agent()->setName('Acceptance '.$slug)->setSlug(self::AGENT_SLUG.$slug)->setEnabled(true);
        $entityManager->persist($agent);
        $entityManager->flush();
        $version = new AgentVersion($agent, 1, ConversationConnections::withConnection(Agent::candidateDefaults()), 'test-admin');
        $entityManager->persist($version);
        $agent->setCurrentVersion($version);
        $entityManager->flush();

        return $version;
    }

    /**
     * Runs the job in the test process, as the messenger worker would, with
     * the fixture set $fixtures armed on the current container.
     */
    private function runJob(string $jobId, string $fixtures): Job
    {
        $this->factory()->useFixtures(FixtureSet::load($fixtures));
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

    // --- requests ---------------------------------------------------------

    /**
     * @param array<string, mixed>|null $json
     */
    private function post(string $uri, string $roles, string $user, ?array $json = null): ResponseInterface
    {
        $options = ['headers' => self::headers($roles, $user)];
        if (null !== $json) {
            $options['headers']['Content-Type'] = 'application/ld+json';
            $options['body'] = json_encode($json, \JSON_THROW_ON_ERROR);
        }

        return static::createClient()->request('POST', $uri, $options);
    }

    private function get(string $uri, string $roles, string $user): ResponseInterface
    {
        return static::createClient()->request('GET', $uri, ['headers' => self::headers($roles, $user)]);
    }

    /**
     * @param array<string, mixed> $rating
     */
    private function rate(string $resultId, array $rating, string $reviewer): ResponseInterface
    {
        return static::createClient()->request('POST', '/api/eval_results/'.$resultId.'/rate', [
            'headers' => ['Content-Type' => 'application/json'] + self::headers(self::ROLES_REVIEWER, $reviewer),
            'body' => json_encode($rating, \JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function headers(string $roles, string $user): array
    {
        return [
            'Accept' => 'application/ld+json',
            TestHeaderAuthenticator::HEADER_ROLES => $roles,
            TestHeaderAuthenticator::HEADER_USER => $user,
        ];
    }

    // --- database ---------------------------------------------------------

    /**
     * @return array<string, int>
     */
    private static function counts(int $pass = 0, int $mostlyPass = 0, int $mostlyFail = 0, int $fail = 0): array
    {
        return [Verdict::PASS => $pass, Verdict::MOSTLY_PASS => $mostlyPass, Verdict::MOSTLY_FAIL => $mostlyFail, Verdict::FAIL => $fail];
    }

    /**
     * @return array<string, int>|null
     */
    private function verdictCounts(string $runId): ?array
    {
        $json = $this->runRow($runId)['verdict_counts'];

        /** @var array<string, int>|null $counts */
        $counts = null === $json ? null : json_decode((string) $json, true, 512, \JSON_THROW_ON_ERROR);

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function runRow(string $id): array
    {
        $row = $this->dbal()->fetchAssociative('SELECT * FROM neuron_eval_run WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row, 'Run '.$id.' is missing.');

        return $row;
    }

    /**
     * Results of the run with their question key, in question order.
     *
     * @return list<array<string, mixed>>
     */
    private function results(string $runId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->dbal()->fetchAllAssociative(
            'SELECT r.*, q.key FROM neuron_eval_result r JOIN neuron_eval_question q ON q.id = r.eval_question_id WHERE r.eval_run_id = :run ORDER BY q.key',
            ['run' => $runId],
        );

        return $rows;
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
     * @return list<string|null>
     */
    private function assistantContents(string $conversationId): array
    {
        /** @var list<string|null> $contents */
        $contents = $this->dbal()->fetchFirstColumn("SELECT content FROM neuron_message WHERE conversation_id = :id AND role = 'assistant' ORDER BY sequence", ['id' => $conversationId]);

        return $contents;
    }

    private function factory(): FakeProviderFactory
    {
        $factory = static::getContainer()->get(ProviderFactory::class);
        self::assertInstanceOf(FakeProviderFactory::class, $factory);

        return $factory;
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
     * Runs of the test agents (results, evaluations and suggestions
     * cascade), their evaluation conversations (messages and feedback
     * cascade) with audit entries, the test questions, the batch and judge
     * jobs, the `evaluation` settings row with the settings audit entries,
     * then the agents ({@see AgentFixtures::cleanUp()}).
     */
    private static function cleanUp(): void
    {
        static::bootKernel();
        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        $agents = ['agents' => self::AGENT_SLUG.'%'];
        $runs = 'SELECT r.id FROM neuron_eval_run r JOIN neuron_agent_version v ON v.id = r.agent_version_id JOIN neuron_agent a ON a.id = v.agent_id WHERE a.slug LIKE :agents';

        $conversations = $connection->fetchFirstColumn(
            'SELECT conversation_id FROM neuron_eval_result WHERE conversation_id IS NOT NULL AND eval_run_id IN ('.$runs.')',
            $agents,
        );
        $conversations = array_merge($conversations, $connection->fetchFirstColumn(
            "SELECT c.id FROM neuron_conversation c JOIN neuron_agent_version v ON v.id = c.agent_version JOIN neuron_agent a ON a.id = v.agent_id WHERE c.origin = 'eval' AND a.slug LIKE :agents",
            $agents,
        ));

        $connection->executeStatement('DELETE FROM neuron_eval_run WHERE id IN ('.$runs.')', $agents);
        foreach (array_unique(array_map('strval', $conversations)) as $id) {
            $connection->executeStatement('DELETE FROM dmstr_log_entries WHERE object_class = :class AND object_id = :id', ['class' => Conversation::class, 'id' => $id]);
            $connection->executeStatement('DELETE FROM neuron_conversation WHERE id = :id', ['id' => $id]);
        }
        $connection->executeStatement('DELETE FROM neuron_eval_result WHERE eval_question_id IN (SELECT id FROM neuron_eval_question WHERE key LIKE :prefix)', ['prefix' => self::PREFIX.'%']);
        $connection->executeStatement('DELETE FROM neuron_eval_question WHERE key LIKE :prefix', ['prefix' => self::PREFIX.'%']);
        foreach ([EvalRun::JOB_TYPE_START, EvalResultEvaluateJobProcessor::JOB_TYPE] as $type) {
            $connection->executeStatement('DELETE FROM dmstr_job WHERE type = :type', ['type' => $type]);
        }
        static::ensureKernelShutdown();

        AgentFixtures::cleanUp();
    }
}
