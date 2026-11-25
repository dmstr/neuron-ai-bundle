<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:11:43 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Evaluation\Compare;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Entity\EvalRun;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\Presence;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\Reason;
use Dmstr\NeuronAiBundle\Evaluation\EvalQuestionStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultStatus;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\FixtureSet;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\Job\JobProcessorRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Question fingerprints end to end: the batch job stores the
 * fingerprint of question text and expected answer on every result
 * (`questionFingerprint`, sha256 of question, NUL, expected answer), and
 * the comparison of two batch runs flags a question whose expected answer
 * changed in between, although the question text and therefore the first
 * user message stayed the same. A question archived before the second run
 * is missing in the candidate (a regression), a question added before it
 * is only in the candidate (its turn fails through the fixture, which is
 * no "newly failed" without a baseline result).
 *
 * Answers come from the fixture set `Fixtures/evaluation/answers.json` of
 * the fake provider, armed on the current container before each job (see
 * {@see \Dmstr\NeuronAiBundle\Tests\Integration\Evaluation\EvalRunTest}). Questions carry the key prefix
 * {@see self::PREFIX}, agents the prefix of {@see AgentFixtures}; the
 * batch runs every active question in the database, so the test stops as
 * incomplete when another test left active questions. Needs the migrated
 * test database (CI only).
 */
#[Group('database')]
final class BatchFingerprintCompareTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const string PREFIX = 'test-fingerprint-';
    private const string AGENT_SLUG = AgentFixtures::PREFIX.'fingerprint-';
    private const string FIXTURES = __DIR__.'/../../../Fixtures/evaluation/answers.json';

    private const string ROLES_ADMIN = 'ROLE_USER,ROLE_EVAL_ADMIN';
    private const string ROLES_REVIEWER = 'ROLE_USER,ROLE_EVAL_REVIEWER';

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

    public function testBatchStoresFingerprintsAndTheComparisonFlagsAChangedExpectedAnswer(): void
    {
        $version = (string) $this->publishedVersion()->getId();
        $this->question('a-maintenance', 'What is the maintenance interval?');
        $this->question('b-torque', 'Which torque applies to the wheel nuts?');
        if (0 !== (int) $this->dbal()->fetchOne("SELECT COUNT(*) FROM neuron_eval_question WHERE status = 'active' AND key NOT LIKE :prefix", ['prefix' => self::PREFIX.'%'])) {
            self::markTestIncomplete('Active questions of another test are left in the database.');
        }

        $baseline = $this->startAndRun($version);

        $results = $this->results($baseline);
        self::assertSame([self::PREFIX.'a-maintenance', self::PREFIX.'b-torque'], array_keys($results));
        foreach ($results as $key => $result) {
            self::assertSame(EvalResultStatus::COMPLETED, $result['status'], $key);
            self::assertSame(hash('sha256', $result['question']."\0".$result['expected_answer']), $result['question_fingerprint'], $key);
        }
        $read = static::createClient()->request('GET', '/api/eval_results/'.$results[self::PREFIX.'b-torque']['id'], ['headers' => ['Accept' => 'application/ld+json', TestHeaderAuthenticator::HEADER_ROLES => 'ROLE_USER']]);
        self::assertSame(200, $read->getStatusCode(), $read->getContent(false));
        self::assertSame($results[self::PREFIX.'b-torque']['question_fingerprint'], $read->toArray()['questionFingerprint']);

        // Between the runs: a new expected answer, one question archived, one added.
        $this->dbal()->executeStatement('UPDATE neuron_eval_question SET expected_answer = :answer WHERE key = :key', ['answer' => '450 Nm, retightened after 50 km.', 'key' => self::PREFIX.'b-torque']);
        $this->dbal()->executeStatement('UPDATE neuron_eval_question SET status = :archived WHERE key = :key', ['archived' => EvalQuestionStatus::ARCHIVED, 'key' => self::PREFIX.'a-maintenance']);
        $this->question('c-warranty', 'Summarise the warranty conditions.');

        $candidate = $this->startAndRun($version);
        self::assertNotSame($results[self::PREFIX.'b-torque']['question_fingerprint'], $this->results($candidate)[self::PREFIX.'b-torque']['question_fingerprint']);

        $response = static::createClient()->request('GET', '/api/eval_runs/'.$candidate.'/compare/'.$baseline, ['headers' => ['Accept' => 'application/ld+json', TestHeaderAuthenticator::HEADER_ROLES => self::ROLES_REVIEWER]]);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        $questions = array_column($response->toArray()['questions'], null, 'key');

        $archived = $questions[self::PREFIX.'a-maintenance'];
        self::assertSame(Presence::ONLY_IN_BASELINE, $archived['presence']);
        self::assertSame([Reason::MISSING_IN_CANDIDATE], $archived['regressionReasons']);

        $changed = $questions[self::PREFIX.'b-torque'];
        self::assertSame(Presence::BOTH, $changed['presence']);
        self::assertTrue($changed['questionChanged']);
        self::assertNull($changed['verdictChange'], 'Neither result was evaluated.');
        self::assertFalse($changed['newlyUnrated']);
        self::assertFalse($changed['regression']);

        $added = $questions[self::PREFIX.'c-warranty'];
        self::assertSame(Presence::ONLY_IN_CANDIDATE, $added['presence']);
        self::assertSame(EvalResultStatus::FAILED, $added['candidate']['status']);
        self::assertFalse($added['newlyFailed']);
        self::assertFalse($added['regression']);
    }

    // --- helpers --------------------------------------------------------

    /**
     * Starts a run on $version through the API and runs its job; returns
     * the run id.
     */
    private function startAndRun(string $version): string
    {
        $response = $this->post('/api/eval_runs/start', ['agentVersion' => $version]);
        self::assertSame(202, $response->getStatusCode(), $response->getContent(false));
        $run = $response->toArray();

        $this->factory()->useFixtures(FixtureSet::load(self::FIXTURES));
        $entityManager = $this->entityManager();
        $entityManager->clear();
        $job = $entityManager->find(Job::class, $run['jobId']);
        self::assertInstanceOf(Job::class, $job);
        /** @var JobProcessorRegistry $registry */
        $registry = static::getContainer()->get(JobProcessorRegistry::class);
        $registry->getProcessor($job->getType())->process($job);
        $job->setStatus('completed');
        $entityManager->flush();

        return (string) $run['id'];
    }

    /**
     * @param array<string, mixed> $json
     */
    private function post(string $uri, array $json): ResponseInterface
    {
        return static::createClient()->request('POST', $uri, [
            'headers' => ['Accept' => 'application/ld+json', 'Content-Type' => 'application/ld+json', TestHeaderAuthenticator::HEADER_ROLES => self::ROLES_ADMIN],
            'body' => json_encode($json, \JSON_THROW_ON_ERROR),
        ]);
    }

    private function publishedVersion(): AgentVersion
    {
        $entityManager = $this->entityManager();
        $agent = new Agent()->setName('Compare batch agent')->setSlug(self::AGENT_SLUG.'main')->setEnabled(true);
        $entityManager->persist($agent);
        $entityManager->flush();
        $version = new AgentVersion($agent, 1, ConversationConnections::withConnection(Agent::candidateDefaults()), 'test-admin');
        $entityManager->persist($version);
        $agent->setCurrentVersion($version);
        $entityManager->flush();

        return $version;
    }

    private function question(string $key, string $text): void
    {
        $entityManager = $this->entityManager();
        $entityManager->persist(new EvalQuestion()
            ->setKey(self::PREFIX.$key)
            ->setQuestion($text)
            ->setExpectedAnswer('Expected answer of '.$key));
        $entityManager->flush();
    }

    /**
     * Results of the run with question key, text and expected answer, by
     * key.
     *
     * @return array<string, array<string, mixed>>
     */
    private function results(string $runId): array
    {
        $rows = $this->dbal()->fetchAllAssociative(
            'SELECT r.id, r.status, r.question_fingerprint, q.key, q.question, q.expected_answer FROM neuron_eval_result r JOIN neuron_eval_question q ON q.id = r.eval_question_id WHERE r.eval_run_id = :run ORDER BY q.key',
            ['run' => $runId],
        );

        return array_column($rows, null, 'key');
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
     * Runs of the test agent (results cascade), their evaluation
     * conversations with audit entries, the test questions, the batch jobs,
     * then the agents ({@see AgentFixtures::cleanUp()}).
     */
    private static function cleanUp(): void
    {
        static::bootKernel();
        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        $agents = ['agents' => self::AGENT_SLUG.'%'];

        $conversations = $connection->fetchFirstColumn(
            "SELECT c.id FROM neuron_conversation c JOIN neuron_agent_version v ON v.id = c.agent_version JOIN neuron_agent a ON a.id = v.agent_id WHERE c.origin = 'eval' AND a.slug LIKE :agents",
            $agents,
        );
        $connection->executeStatement('DELETE FROM neuron_eval_run WHERE agent_version_id IN (SELECT v.id FROM neuron_agent_version v JOIN neuron_agent a ON a.id = v.agent_id WHERE a.slug LIKE :agents)', $agents);
        foreach (array_unique(array_map('strval', $conversations)) as $id) {
            $connection->executeStatement('DELETE FROM dmstr_log_entries WHERE object_class = :class AND object_id = :id', ['class' => Conversation::class, 'id' => $id]);
            $connection->executeStatement('DELETE FROM neuron_conversation WHERE id = :id', ['id' => $id]);
        }
        $connection->executeStatement('DELETE FROM neuron_eval_question WHERE key LIKE :prefix', ['prefix' => self::PREFIX.'%']);
        $connection->executeStatement('DELETE FROM dmstr_job WHERE type = :type', ['type' => EvalRun::JOB_TYPE_START]);
        static::ensureKernelShutdown();

        AgentFixtures::cleanUp();
    }
}
