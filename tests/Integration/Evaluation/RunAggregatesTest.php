<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:46:34 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Evaluation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Conversation\ConversationStatus;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Entity\EvalResult;
use Dmstr\NeuronAiBundle\Entity\EvalRun;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Evaluation\EvalQuestionStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultSource;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvalRunStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvaluationStatus;
use Dmstr\NeuronAiBundle\Evaluation\Verdict;
use Dmstr\NeuronAiBundle\JobProcessor\EvalResultEvaluateJobProcessor;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\FixtureSet;
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

/**
 * The verdict counts of an evaluation run: `verdictCounts`
 * counts the run's results by the verdict of their LATEST evaluation when
 * that evaluation is `completed`; the evaluate job refreshes them after
 * each outcome, and the batch job sets them (all four keys) when it
 * completes a run.
 *
 * Covered: counts after evaluations through the job (judge fixtures
 * `Fixtures/evaluation/judge.json`, keyed by the marker `[judge:<case>]`
 * in the question's expected answer, as in EvalResultEvaluateTest); a
 * re-evaluation with another verdict moves the result instead of adding
 * one, and a failed re-evaluation removes it; a single execution (no run)
 * leaves the run alone; a run completed by the batch job without
 * evaluations has all four keys at 0. `tokensTotal` and
 * `interactionsTotal` stay untouched by the judge.
 *
 * Runs, results, questions and conversations are written through the
 * entity manager (key and owner prefix {@see self::PREFIX}; questions are
 * archived, so no batch picks them up), agents with the prefix of
 * {@see AgentFixtures}. Needs the migrated test database (CI only).
 */
#[Group('database')]
final class RunAggregatesTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const string PREFIX = 'test-aggregates-';
    private const string AGENT_SLUG = AgentFixtures::PREFIX.'aggregates-';
    private const string FIXTURES = __DIR__.'/../../Fixtures/evaluation/judge.json';
    private const string ROLES_ADMIN = 'ROLE_USER,ROLE_EVAL_ADMIN';

    private const int TOKENS_TOTAL = 360;
    private const int INTERACTIONS_TOTAL = 2;

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

    public function testVerdictCountsFollowTheLatestEvaluationOfEachResult(): void
    {
        $this->judgeAgent();
        $run = $this->completedRun();
        $pass = $this->evalResult($run, Verdict::PASS);
        $mostlyPass = $this->evalResult($run, Verdict::MOSTLY_PASS);
        $fail = $this->evalResult($run, Verdict::FAIL);
        $this->evalResult($run, Verdict::MOSTLY_FAIL); // never evaluated

        self::assertNull($this->runRow($run)['verdict_counts']);

        $this->evaluateAndRun($pass);
        self::assertEquals(self::counts(pass: 1), $this->verdictCounts($run));

        $this->evaluateAndRun($mostlyPass);
        $this->evaluateAndRun($fail);
        self::assertEquals(self::counts(pass: 1, mostlyPass: 1, fail: 1), $this->verdictCounts($run));

        // Re-evaluation with another verdict moves the result.
        $this->setMarker($pass, Verdict::MOSTLY_FAIL);
        $this->evaluateAndRun($pass);
        self::assertEquals(self::counts(mostlyPass: 1, mostlyFail: 1, fail: 1), $this->verdictCounts($run));
        self::assertSame(2, (int) $this->dbal()->fetchOne('SELECT COUNT(*) FROM neuron_evaluation WHERE eval_result_id = :id', ['id' => $pass]));

        // A failed re-evaluation is the latest one now: the result drops out.
        $this->setMarker($fail, 'invalid');
        $failed = $this->evaluateAndRun($fail);
        self::assertSame(EvaluationStatus::FAILED, $this->dbal()->fetchOne('SELECT status FROM neuron_evaluation WHERE id = :id', ['id' => $failed]));
        self::assertEquals(self::counts(mostlyPass: 1, mostlyFail: 1), $this->verdictCounts($run));

        // The judge's tokens are not added to the run.
        $row = $this->runRow($run);
        self::assertSame(self::TOKENS_TOTAL, (int) $row['tokens_total']);
        self::assertSame(self::INTERACTIONS_TOTAL, (int) $row['interactions_total']);

        // Readable through the API.
        $read = static::createClient()->request('GET', '/api/eval_runs/'.$run, ['headers' => ['Accept' => 'application/ld+json', TestHeaderAuthenticator::HEADER_ROLES => 'ROLE_USER']]);
        self::assertSame(200, $read->getStatusCode(), $read->getContent(false));
        self::assertEquals(self::counts(mostlyPass: 1, mostlyFail: 1), $read->toArray()['verdictCounts']);
    }

    public function testSingleExecutionLeavesRunsAlone(): void
    {
        $this->judgeAgent();
        $run = $this->completedRun();
        $single = $this->evalResult(null, Verdict::PASS);

        $this->evaluateAndRun($single);

        self::assertNull($this->runRow($run)['verdict_counts']);
    }

    public function testCompletedRunWithoutEvaluationsHasEveryVerdictAtZero(): void
    {
        // The batch runs every active question in the database; with none
        // the run completes right away.
        if (0 !== (int) $this->dbal()->fetchOne("SELECT COUNT(*) FROM neuron_eval_question WHERE status = 'active'")) {
            self::markTestIncomplete('Active questions of another test are left in the database.');
        }

        $run = new EvalRun()
            ->setAgentVersion($this->subjectVersion())
            ->setOwnerSubject(self::PREFIX.'owner')
            ->setStatus(EvalRunStatus::PENDING);
        $this->entityManager()->persist($run);
        $this->entityManager()->flush();

        $job = new Job();
        $job->setType(EvalRun::JOB_TYPE_START);
        $job->setInputData(['run' => (string) $run->getId(), 'ownerSubject' => self::PREFIX.'owner']);
        $job->setStatus('pending');
        $this->entityManager()->persist($job);
        $run->setJobId($job->getId());
        $this->entityManager()->flush();
        $runId = (string) $run->getId();

        $this->runJob($job->getId());

        $row = $this->runRow($runId);
        self::assertSame(EvalRunStatus::COMPLETED, $row['status']);
        self::assertEquals(self::counts(), json_decode((string) $row['verdict_counts'], true));
        self::assertSame(Verdict::ALL, array_keys(self::counts()));
    }

    // --- helpers --------------------------------------------------------

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
     * Starts an evaluation of the result through the API and runs its job;
     * returns the evaluation id.
     */
    private function evaluateAndRun(string $resultId): string
    {
        $response = static::createClient()->request('POST', '/api/eval_results/'.$resultId.'/evaluate', [
            'headers' => ['Accept' => 'application/ld+json', TestHeaderAuthenticator::HEADER_ROLES => self::ROLES_ADMIN],
        ]);
        self::assertSame(202, $response->getStatusCode(), $response->getContent(false));
        $evaluation = $response->toArray();

        $this->runJob((string) $evaluation['jobId'], true);

        return (string) $evaluation['id'];
    }

    /**
     * Runs the job in the test process, as the messenger worker would, with
     * the judge fixtures armed on the current container.
     */
    private function runJob(string $jobId, bool $judge = false): void
    {
        if ($judge) {
            $factory = static::getContainer()->get(ProviderFactory::class);
            self::assertInstanceOf(FakeProviderFactory::class, $factory);
            $factory->useFixtures(FixtureSet::load(self::FIXTURES));
        }

        $entityManager = $this->entityManager();
        $entityManager->clear();
        $job = $entityManager->find(Job::class, $jobId);
        self::assertInstanceOf(Job::class, $job);

        /** @var JobProcessorRegistry $registry */
        $registry = static::getContainer()->get(JobProcessorRegistry::class);
        $registry->getProcessor($job->getType())->process($job);
        $job->setStatus('completed');
        $entityManager->flush();
    }

    /**
     * The judge agent with one published version, configured as
     * `evaluation.judge_agent` (as `test-admin`, ROLE_ADMIN).
     */
    private function judgeAgent(): void
    {
        $this->publishedVersion('judge');

        InMemoryEvaluationSettings::configure(['judge_agent' => self::AGENT_SLUG.'judge']);
    }

    /**
     * The evaluated agent's version, created once per test.
     */
    private function subjectVersion(): AgentVersion
    {
        $id = $this->dbal()->fetchOne('SELECT v.id FROM neuron_agent_version v JOIN neuron_agent a ON a.id = v.agent_id WHERE a.slug = :slug', ['slug' => self::AGENT_SLUG.'subject']);
        if (false !== $id) {
            $version = $this->entityManager()->find(AgentVersion::class, $id);
            self::assertInstanceOf(AgentVersion::class, $version);

            return $version;
        }

        return $this->publishedVersion('subject');
    }

    private function publishedVersion(string $slug): AgentVersion
    {
        $entityManager = $this->entityManager();
        $agent = new Agent()->setName('Aggregates '.$slug)->setSlug(self::AGENT_SLUG.$slug)->setEnabled(true);
        $entityManager->persist($agent);
        $entityManager->flush();

        $version = new AgentVersion($agent, 1, ConversationConnections::withConnection(Agent::candidateDefaults()), 'test-admin');
        $entityManager->persist($version);
        $agent->setCurrentVersion($version);
        $entityManager->flush();

        return $version;
    }

    /**
     * A completed run with the token and interaction totals of its question
     * turns; returns the run id.
     */
    private function completedRun(): string
    {
        $run = new EvalRun()
            ->setAgentVersion($this->subjectVersion())
            ->setOwnerSubject(self::PREFIX.'owner')
            ->setQuestionsTotal(4)
            ->setTokensTotal(self::TOKENS_TOTAL)
            ->setInteractionsTotal(self::INTERACTIONS_TOTAL)
            ->finish(EvalRunStatus::COMPLETED, new \DateTimeImmutable());
        $this->entityManager()->persist($run);
        $this->entityManager()->flush();

        return (string) $run->getId();
    }

    /**
     * A completed result of $runId (a single execution when null) for a new
     * archived question whose expected answer carries the fixture marker of
     * $case, with an evaluation conversation of one question and one
     * answer. Returns the result id.
     */
    private function evalResult(?string $runId, string $case): string
    {
        $entityManager = $this->entityManager();
        $run = null === $runId ? null : $entityManager->find(EvalRun::class, $runId);

        $question = new EvalQuestion()
            ->setKey(self::PREFIX.$case.'-'.bin2hex(random_bytes(4)))
            ->setQuestion('What is the maintenance interval of the hydraulic pump?')
            ->setExpectedAnswer(self::expectedAnswer($case))
            ->setStatus(EvalQuestionStatus::ARCHIVED);
        $conversation = new Conversation()
            ->setOwnerSubject(self::PREFIX.'owner')
            ->setOrigin(Conversation::ORIGIN_EVAL)
            ->setStatus(ConversationStatus::ACTIVE)
            ->setProviderModel(ConversationConnections::model())
            ->setRetrievalDepth(4)
            ->setNextSequence(3);
        $entityManager->persist($question);
        $entityManager->persist($conversation);
        $entityManager->flush();

        foreach ([[1, Message::SPEAKER_USER, 'What is the maintenance interval of the hydraulic pump?'], [2, Message::SPEAKER_ASSISTANT, 'Every 500 operating hours.']] as [$sequence, $role, $content]) {
            $entityManager->persist(new Message()
                ->setConversation($conversation)
                ->setSequence($sequence)
                ->setRole($role)
                ->setKind(MessageKind::TEXT)
                ->setStatus(MessageStatus::COMPLETE)
                ->setContent($content));
        }

        $result = new EvalResult()
            ->setEvalRun($run)
            ->setEvalQuestion($question)
            ->setConversation($conversation)
            ->setSource(null === $run ? EvalResultSource::SINGLE : EvalResultSource::BATCH)
            ->setStatus(EvalResultStatus::COMPLETED)
            ->setDurationMs(1234)
            ->setTokensInput(70)
            ->setTokensOutput(20)
            ->setInteractions(0);
        $entityManager->persist($result);
        $entityManager->flush();
        $entityManager->clear();

        return (string) $result->getId();
    }

    /**
     * Points the result's question at another judge fixture, so the next
     * evaluation answers differently.
     */
    private function setMarker(string $resultId, string $case): void
    {
        $this->dbal()->executeStatement(
            'UPDATE neuron_eval_question SET expected_answer = :answer WHERE id = (SELECT eval_question_id FROM neuron_eval_result WHERE id = :id)',
            ['answer' => self::expectedAnswer($case), 'id' => $resultId],
        );
    }

    private static function expectedAnswer(string $case): string
    {
        return 'Every 500 operating hours, at least once a year. [judge:'.$case.']';
    }

    // --- database -------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function runRow(string $id): array
    {
        $row = $this->dbal()->fetchAssociative('SELECT * FROM neuron_eval_run WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row, 'Run '.$id.' is missing.');

        return $row;
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
     * cascade), single results of the test questions, the questions, the
     * test conversations (messages cascade) with their audit entries, the
     * judge and batch jobs, the `evaluation` settings row with the settings
     * audit entries, then the agents ({@see AgentFixtures::cleanUp()}).
     */
    private static function cleanUp(): void
    {
        static::bootKernel();
        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        $agents = ['agents' => self::AGENT_SLUG.'%'];
        $questions = ['prefix' => self::PREFIX.'%'];

        $connection->executeStatement('DELETE FROM neuron_eval_run WHERE agent_version_id IN (SELECT v.id FROM neuron_agent_version v JOIN neuron_agent a ON a.id = v.agent_id WHERE a.slug LIKE :agents)', $agents);
        $connection->executeStatement('DELETE FROM neuron_eval_result WHERE eval_question_id IN (SELECT id FROM neuron_eval_question WHERE key LIKE :prefix)', $questions);
        $connection->executeStatement('DELETE FROM neuron_eval_question WHERE key LIKE :prefix', $questions);
        foreach ($connection->fetchFirstColumn('SELECT id FROM neuron_conversation WHERE owner_subject LIKE :prefix', $questions) as $id) {
            $connection->executeStatement('DELETE FROM dmstr_log_entries WHERE object_class = :class AND object_id = :id', ['class' => Conversation::class, 'id' => (string) $id]);
            $connection->executeStatement('DELETE FROM neuron_conversation WHERE id = :id', ['id' => (string) $id]);
        }
        $connection->executeStatement('DELETE FROM dmstr_job WHERE type IN (:judge, :batch)', ['judge' => EvalResultEvaluateJobProcessor::JOB_TYPE, 'batch' => EvalRun::JOB_TYPE_START]);
        static::ensureKernelShutdown();

        AgentFixtures::cleanUp();
    }
}
