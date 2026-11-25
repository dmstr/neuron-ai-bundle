<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:34:44 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Evaluation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Conversation\ConversationStatus;
use Dmstr\NeuronAiBundle\Conversation\Gate\InjectionGuard;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Entity\EvalResult;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultSource;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvaluationStatus;
use Dmstr\NeuronAiBundle\Evaluation\SuggestionType;
use Dmstr\NeuronAiBundle\Evaluation\Verdict;
use Dmstr\NeuronAiBundle\JobProcessor\EvalResultEvaluateJobProcessor;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\FixtureSet;
use Dmstr\NeuronAiBundle\Tests\Integration\Conversation\InjectionGuardTurnTest;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryEvaluationSettings;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\Job\JobProcessorRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The AI judge end to end: `POST /api/eval_results/{id}/evaluate`
 * stores a pending evaluation as the result's latest `evaluation` and
 * queues a job of type `eval_result_evaluate`; running that job (through
 * {@see JobProcessorRegistry}, as the worker would) calls the judge agent
 * of the setting `evaluation.judge_agent` and stores the verdict.
 *
 * Covered: all four verdicts with their `data` and `prompt` suggestions,
 * judge version, model and duration (the fake provider reports no usage,
 * so the token columns stay NULL; the token mapping is covered by
 * Unit/JudgeVerdictTest); a malformed answer (verdict-schema violation,
 * no JSON) and a judge version whose own `responseSchema` differs from the
 * verdict schema fail the evaluation without suggestions and leave the
 * result intact; an empty, unknown, disabled or unpublished judge and a
 * result that is not completed answer 422 without evaluation or job; a
 * judge disabled between action and job fails at runtime; a redelivered
 * job adds nothing; `ROLE_USER` and `ROLE_EVAL_REVIEWER` get 403.
 *
 * The judge answers come from `Fixtures/evaluation/judge.json`, keyed by a
 * marker (`[judge:<case>]`) in the question's expected answer, which only
 * the judge's message contains. Every request through createClient()
 * boots a new kernel and with it a fake provider without fixtures, so the
 * fixtures are armed on the current container right before the job runs.
 * Results, questions and conversations are written through the entity
 * manager (key and owner prefix {@see self::PREFIX}), agents with the
 * prefix of {@see AgentFixtures}. Needs the migrated test database
 * (CI only).
 */
#[Group('database')]
final class EvalResultEvaluateTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const string PREFIX = 'test-evaluate-';
    private const string AGENT_SLUG = AgentFixtures::PREFIX.'judge-';
    private const string FIXTURES = __DIR__.'/../../Fixtures/evaluation/judge.json';

    private const string ROLES_ADMIN = 'ROLE_USER,ROLE_EVAL_ADMIN';
    private const string ROLES_REVIEWER = 'ROLE_USER,ROLE_EVAL_REVIEWER';
    private const string ROLES_USER = 'ROLE_USER';

    /** Columns of neuron_eval_result the judge must not change. */
    private const array RESULT_COLUMNS = ['eval_run_id', 'eval_question_id', 'conversation_id', 'source', 'status', 'error_message', 'duration_ms', 'tokens_input', 'tokens_output', 'interactions', 'human_rating', 'human_rated_by', 'human_rated_at', 'recall_hit', 'first_relevant_rank', 'created_at'];

    private int $fixtureHitMark = 0;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        self::cleanUp();
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function verdicts(): iterable
    {
        yield Verdict::PASS => [Verdict::PASS, 2];
        yield Verdict::MOSTLY_PASS => [Verdict::MOSTLY_PASS, 2];
        yield Verdict::MOSTLY_FAIL => [Verdict::MOSTLY_FAIL, 3];
        yield Verdict::FAIL => [Verdict::FAIL, 2];
    }

    #[DataProvider('verdicts')]
    public function testVerdictIsStoredWithSuggestionsAndMeasurements(string $verdict, int $suggestions): void
    {
        $judge = $this->judgeAgent('main');
        $this->configureJudge(self::AGENT_SLUG.'main');
        $result = $this->evalResult($verdict);
        $before = $this->resultColumns($result);

        $response = $this->evaluate($result);
        self::assertSame(202, $response->getStatusCode(), $response->getContent(false));
        $body = $response->toArray();
        self::assertSame(EvaluationStatus::PENDING, $body['status']);
        self::assertSame('/api/eval_results/'.$result, $body['evalResult']);
        self::assertNotEmpty($body['jobId']);
        self::assertNull($body['verdict'] ?? null);
        self::assertSame((string) $body['id'], (string) $this->resultRow($result)['evaluation_id']);

        $job = $this->runJob($body['jobId']);
        self::assertSame(['[judge:'.$verdict.']'], $this->newFixtureHits());
        $resultData = (array) $job->getResultData();
        self::assertSame(EvaluationStatus::COMPLETED, $resultData['status']);
        self::assertSame($verdict, $resultData['verdict']);
        self::assertSame($suggestions, $resultData['suggestions']);

        $row = $this->evaluationRow((string) $body['id']);
        self::assertSame(EvaluationStatus::COMPLETED, $row['status']);
        self::assertSame($verdict, $row['verdict']);
        self::assertNotEmpty($row['reasoning']);
        self::assertSame((string) $judge->getId(), (string) $row['judge_agent_version_id']);
        self::assertSame(FakeProviderFactory::ANTHROPIC_MODEL, $row['model']);
        self::assertSame(ConversationConnections::modelId(), (string) $row['provider_model_id'], 'the catalogue entry of the judge version, next to the name');
        self::assertNotNull($row['duration_ms']);
        self::assertGreaterThanOrEqual(0, (int) $row['duration_ms']);
        self::assertNull($row['tokens_input']);
        self::assertNull($row['tokens_output']);
        self::assertNull($row['error_message']);
        self::assertNotNull($row['finished_at']);
        self::assertSame($body['jobId'], $row['job_id']);

        $stored = $this->suggestionRows((string) $body['id']);
        self::assertCount($suggestions, $stored);
        $types = array_column($stored, 'type');
        self::assertContains(SuggestionType::DATA, $types);
        self::assertContains(SuggestionType::PROMPT, $types);
        foreach ($stored as $suggestion) {
            self::assertSame('open', $suggestion['status']);
            self::assertNull($suggestion['assignee']);
            self::assertNotSame('', $suggestion['category']);
            self::assertNotSame('', $suggestion['description']);
            self::assertNotSame('', $suggestion['action']);
        }

        // The result keeps its values; only its evaluation link changed.
        self::assertSame($before, $this->resultColumns($result));
        self::assertSame((string) $body['id'], (string) $this->resultRow($result)['evaluation_id']);

        // Readable through the API.
        $read = $this->get('/api/evaluations/'.$body['id'], self::ROLES_USER);
        self::assertSame(200, $read->getStatusCode(), $read->getContent(false));
        self::assertSame($verdict, $read->toArray()['verdict']);
        self::assertSame('/api/agent_versions/'.$judge->getId(), $read->toArray()['judgeAgentVersion']);
        self::assertSame(ConversationConnections::modelIri(), $read->toArray()['model']);
        self::assertSame(FakeProviderFactory::ANTHROPIC_MODEL, $read->toArray()['modelName']);
        self::assertCount($suggestions, $read->toArray()['suggestions']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function malformedAnswers(): iterable
    {
        yield 'wrong enum and missing suggestions' => ['invalid', 'violates the verdict schema'];
        yield 'not JSON' => ['not-json', 'invalid_json'];
    }

    #[DataProvider('malformedAnswers')]
    public function testMalformedAnswerFailsTheEvaluationAndLeavesTheResultIntact(string $case, string $message): void
    {
        $this->judgeAgent('malformed');
        $this->configureJudge(self::AGENT_SLUG.'malformed');
        $result = $this->evalResult($case);
        $before = $this->resultColumns($result);

        $evaluation = $this->evaluate($result)->toArray();
        $job = $this->runJob($evaluation['jobId']);

        self::assertSame(EvaluationStatus::FAILED, ((array) $job->getResultData())['status']);
        $this->assertFailed((string) $evaluation['id'], $message);
        self::assertSame($before, $this->resultColumns($result));
        self::assertSame((string) $evaluation['id'], (string) $this->resultRow($result)['evaluation_id']);
    }

    public function testJudgeVersionWithItsOwnResponseSchemaStillNeedsTheVerdictSchema(): void
    {
        $this->judgeAgent('scored', [
            'type' => 'object',
            'required' => ['score'],
            'properties' => ['score' => ['type' => 'integer']],
            'additionalProperties' => false,
        ]);
        $this->configureJudge(self::AGENT_SLUG.'scored');

        // Valid against the version's schema, not against the verdict schema.
        $scored = $this->evalResult('score');
        $before = $this->resultColumns($scored);
        $evaluation = $this->evaluate($scored)->toArray();
        $this->runJob($evaluation['jobId']);
        $this->assertFailed((string) $evaluation['id'], 'violates the verdict schema');
        self::assertSame($before, $this->resultColumns($scored));

        // A valid verdict that the version's own schema rejects.
        $passed = $this->evalResult(Verdict::PASS);
        $before = $this->resultColumns($passed);
        $evaluation = $this->evaluate($passed)->toArray();
        $this->runJob($evaluation['jobId']);
        $this->assertFailed((string) $evaluation['id'], 'does not match the response schema');
        self::assertSame($before, $this->resultColumns($passed));
    }

    public function testJudgeVersionWithTheInjectionGuardOnRunsWithoutAGateCall(): void
    {
        $this->judgeAgent('guarded', null, InjectionGuardTurnTest::guardConfiguration());
        $this->configureJudge(self::AGENT_SLUG.'guarded');
        $result = $this->evalResult(Verdict::PASS);

        $response = $this->evaluate($result);
        self::assertSame(202, $response->getStatusCode(), $response->getContent(false));
        $recorded = \count($this->factory()->getChatProvider()->getRecorded());
        $job = $this->runJob($response->toArray()['jobId']);

        self::assertSame(EvaluationStatus::COMPLETED, ((array) $job->getResultData())['status'] ?? null);
        // One judge call: a guard call would have matched the judge fixture
        // in the transcript as well (a second hit).
        self::assertSame(['[judge:'.Verdict::PASS.']'], $this->newFixtureHits());
        $requests = \array_slice(array_values($this->factory()->getChatProvider()->getRecorded()), $recorded);
        self::assertCount(1, $requests);
        self::assertNotSame(InjectionGuard::SYSTEM_PROMPT, $requests[0]->systemPrompt?->getContent());
    }

    public function testUnrunnableJudgeOrResultAnswers422WithoutEvaluationOrJob(): void
    {
        $result = $this->evalResult(Verdict::PASS);
        $this->storedAgent('disabled', false, true);
        $this->storedAgent('unpublished', true, false);

        foreach ([
            'no setting' => [null, 'No judge agent is configured (setting "evaluation.judge_agent")'],
            'unknown agent' => [self::AGENT_SLUG.'unknown', 'Unknown agent'],
            'disabled agent' => [self::AGENT_SLUG.'disabled', 'is disabled'],
            'unpublished agent' => [self::AGENT_SLUG.'unpublished', 'has no published version'],
        ] as $case => [$reference, $message]) {
            if (null !== $reference) {
                $this->configureJudge($reference);
            }
            $response = $this->evaluate($result);
            self::assertSame(422, $response->getStatusCode(), $case.': '.$response->getContent(false));
            $detail = (string) ($response->toArray(false)['detail'] ?? '');
            self::assertStringContainsString($message, $detail, $case);
            self::assertStringContainsString('evaluation.judge_agent', $detail, $case);
            $this->assertNothingWritten($result, $case);
        }

        // A runnable judge, but a failed result.
        $this->judgeAgent('ready');
        $this->configureJudge(self::AGENT_SLUG.'ready');
        $failed = $this->evalResult(Verdict::FAIL, EvalResultStatus::FAILED);
        $response = $this->evaluate($failed);
        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertStringContainsString('Only completed evaluation results', (string) ($response->toArray(false)['detail'] ?? ''));
        $this->assertNothingWritten($failed, 'failed result');

        // Unknown result.
        $unknown = $this->evaluate('00000000-0000-4000-8000-000000000000');
        self::assertSame(404, $unknown->getStatusCode(), $unknown->getContent(false));
    }

    public function testJudgeDisabledBetweenActionAndJobFailsAtRuntime(): void
    {
        $judge = $this->judgeAgent('flaky');
        $this->configureJudge(self::AGENT_SLUG.'flaky');
        $result = $this->evalResult(Verdict::PASS);
        $before = $this->resultColumns($result);

        $evaluation = $this->evaluate($result)->toArray();
        self::assertSame(EvaluationStatus::PENDING, $evaluation['status']);

        $agent = $this->entityManager()->find(Agent::class, $judge->getAgent()->getId());
        self::assertInstanceOf(Agent::class, $agent);
        $agent->setEnabled(false);
        $this->entityManager()->flush();

        $this->runJob($evaluation['jobId']);

        $this->assertFailed((string) $evaluation['id'], 'is disabled');
        self::assertNull($this->evaluationRow((string) $evaluation['id'])['judge_agent_version_id']);
        self::assertSame([], $this->newFixtureHits());
        self::assertSame($before, $this->resultColumns($result));
    }

    public function testRedeliveredJobAddsNothing(): void
    {
        $this->judgeAgent('redelivery');
        $this->configureJudge(self::AGENT_SLUG.'redelivery');
        $result = $this->evalResult(Verdict::MOSTLY_PASS);

        $evaluation = $this->evaluate($result)->toArray();
        $this->runJob($evaluation['jobId']);
        $first = $this->evaluationRow((string) $evaluation['id']);
        self::assertCount(2, $this->suggestionRows((string) $evaluation['id']));

        $again = $this->runJob($evaluation['jobId']);

        self::assertArrayHasKey('skipped', (array) $again->getResultData());
        self::assertSame([], $this->newFixtureHits());
        self::assertCount(2, $this->suggestionRows((string) $evaluation['id']));
        self::assertSame($first, $this->evaluationRow((string) $evaluation['id']));
    }

    public function testEvaluatingAgainKeepsEarlierEvaluations(): void
    {
        $this->judgeAgent('twice');
        $this->configureJudge(self::AGENT_SLUG.'twice');
        $result = $this->evalResult(Verdict::FAIL);

        $first = $this->evaluate($result)->toArray();
        $this->runJob($first['jobId']);
        $second = $this->evaluate($result)->toArray();
        $this->runJob($second['jobId']);

        self::assertNotSame($first['id'], $second['id']);
        self::assertSame((string) $second['id'], (string) $this->resultRow($result)['evaluation_id']);
        self::assertSame(2, $this->evaluationCount($result));
        self::assertCount(2, $this->suggestionRows((string) $first['id']));
        self::assertCount(2, $this->suggestionRows((string) $second['id']));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function rolesWithoutEvaluate(): iterable
    {
        yield 'user' => [self::ROLES_USER];
        yield 'reviewer' => [self::ROLES_REVIEWER];
    }

    #[DataProvider('rolesWithoutEvaluate')]
    public function testWithoutEvalAdminEvaluateIsForbidden(string $roles): void
    {
        $this->judgeAgent('forbidden');
        $this->configureJudge(self::AGENT_SLUG.'forbidden');
        $result = $this->evalResult(Verdict::PASS);

        $response = $this->evaluate($result, $roles);

        self::assertSame(403, $response->getStatusCode(), $response->getContent(false));
        $this->assertNothingWritten($result, $roles);
    }

    // --- assertions -----------------------------------------------------

    private function assertFailed(string $evaluationId, string $message): void
    {
        $row = $this->evaluationRow($evaluationId);
        self::assertSame(EvaluationStatus::FAILED, $row['status']);
        self::assertStringContainsString($message, (string) $row['error_message']);
        self::assertNull($row['verdict']);
        self::assertNull($row['reasoning']);
        self::assertNotNull($row['finished_at']);
        self::assertSame([], $this->suggestionRows($evaluationId));
    }

    private function assertNothingWritten(string $resultId, string $case): void
    {
        self::assertSame(0, $this->evaluationCount($resultId), $case);
        self::assertNull($this->resultRow($resultId)['evaluation_id'], $case);
        self::assertSame(0, (int) $this->dbal()->fetchOne('SELECT COUNT(*) FROM dmstr_job WHERE type = :type', ['type' => EvalResultEvaluateJobProcessor::JOB_TYPE]), $case);
    }

    // --- fixtures -------------------------------------------------------

    /**
     * A judge agent with one published version; its configuration is the
     * candidate default with $overrides plus an optional response schema.
     *
     * @param array<string, mixed>|null $responseSchema
     * @param array<string, mixed>      $overrides
     */
    private function judgeAgent(string $slug, ?array $responseSchema = null, array $overrides = []): AgentVersion
    {
        $agent = new Agent()->setName('Judge '.$slug)->setSlug(self::AGENT_SLUG.$slug)->setEnabled(true);
        $this->entityManager()->persist($agent);
        $this->entityManager()->flush();

        $configuration = $overrides + Agent::candidateDefaults();
        if (null !== $responseSchema) {
            $configuration['responseSchema'] = $responseSchema;
        }
        $version = new AgentVersion($agent, 1, ConversationConnections::withConnection($configuration), 'test-admin');
        $this->entityManager()->persist($version);
        $agent->setCurrentVersion($version);
        $this->entityManager()->flush();

        return $version;
    }

    private function storedAgent(string $slug, bool $enabled, bool $published): void
    {
        $agent = new Agent()->setName('Judge '.$slug)->setSlug(self::AGENT_SLUG.$slug)->setEnabled($enabled);
        $this->entityManager()->persist($agent);
        $this->entityManager()->flush();
        if ($published) {
            $version = new AgentVersion($agent, 1, ConversationConnections::withConnection(Agent::candidateDefaults()), 'test-admin');
            $this->entityManager()->persist($version);
            $agent->setCurrentVersion($version);
            $this->entityManager()->flush();
        }
    }

    /**
     * Sets `evaluation.judge_agent` as the default test identity
     * (`test-admin`, ROLE_ADMIN).
     */
    private function configureJudge(string $reference): void
    {
        InMemoryEvaluationSettings::configure(['judge_agent' => $reference]);
    }

    /**
     * A single-execution result of a new question whose expected answer
     * carries the fixture marker of $case, with an evaluation conversation
     * of one question and one answer. Returns the result id.
     */
    private function evalResult(string $case, string $status = EvalResultStatus::COMPLETED): string
    {
        $entityManager = $this->entityManager();
        $key = self::PREFIX.$case.'-'.bin2hex(random_bytes(4));

        $question = new EvalQuestion()
            ->setKey($key)
            ->setQuestion('What is the maintenance interval of the hydraulic pump?')
            ->setExpectedAnswer('Every 500 operating hours, at least once a year. [judge:'.$case.']');
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
            ->setEvalQuestion($question)
            ->setConversation($conversation)
            ->setSource(EvalResultSource::SINGLE)
            ->setStatus($status)
            ->setErrorMessage(EvalResultStatus::FAILED === $status ? 'Upstream provider overloaded (529).' : null)
            ->setDurationMs(1234)
            ->setTokensInput(100)
            ->setTokensOutput(20)
            ->setInteractions(0);
        $entityManager->persist($result);
        $entityManager->flush();
        $entityManager->clear();

        return (string) $result->getId();
    }

    // --- requests -------------------------------------------------------

    private function evaluate(string $resultId, string $roles = self::ROLES_ADMIN): ResponseInterface
    {
        return static::createClient()->request('POST', '/api/eval_results/'.$resultId.'/evaluate', ['headers' => self::headers($roles)]);
    }

    private function get(string $uri, string $roles): ResponseInterface
    {
        return static::createClient()->request('GET', $uri, ['headers' => self::headers($roles)]);
    }

    /**
     * @return array<string, string>
     */
    private static function headers(string $roles): array
    {
        return ['Accept' => 'application/ld+json', TestHeaderAuthenticator::HEADER_ROLES => $roles];
    }

    /**
     * Runs the job in the test process, as the messenger worker would, with
     * the judge fixtures armed on the current container.
     */
    private function runJob(string $jobId): Job
    {
        $this->factory()->useFixtures(FixtureSet::load(self::FIXTURES));
        $this->fixtureHitMark = \count($this->factory()->getChatProvider()->getFixtureHits());

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
     * Fixture keys that answered since the last runJob() started.
     *
     * @return list<string>
     */
    private function newFixtureHits(): array
    {
        return \array_slice($this->factory()->getChatProvider()->getFixtureHits(), $this->fixtureHitMark);
    }

    private function factory(): FakeProviderFactory
    {
        $factory = static::getContainer()->get(ProviderFactory::class);
        self::assertInstanceOf(FakeProviderFactory::class, $factory);

        return $factory;
    }

    // --- database -------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function resultRow(string $id): array
    {
        $row = $this->dbal()->fetchAssociative('SELECT * FROM neuron_eval_result WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row, 'Result '.$id.' is missing.');

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function resultColumns(string $id): array
    {
        return array_intersect_key($this->resultRow($id), array_flip(self::RESULT_COLUMNS));
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluationRow(string $id): array
    {
        $row = $this->dbal()->fetchAssociative('SELECT * FROM neuron_evaluation WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row, 'Evaluation '.$id.' is missing.');

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function suggestionRows(string $evaluationId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->dbal()->fetchAllAssociative('SELECT * FROM neuron_suggestion WHERE evaluation_id = :id ORDER BY created_at, category', ['id' => $evaluationId]);

        return $rows;
    }

    private function evaluationCount(string $resultId): int
    {
        return (int) $this->dbal()->fetchOne('SELECT COUNT(*) FROM neuron_evaluation WHERE eval_result_id = :id', ['id' => $resultId]);
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
     * Results of the test questions (evaluations and suggestions cascade),
     * the questions, the test conversations (messages cascade) with their
     * audit entries, the judge jobs, the `evaluation` settings row with the
     * settings audit entries, then the agents
     * ({@see AgentFixtures::cleanUp()}).
     */
    private static function cleanUp(): void
    {
        static::bootKernel();
        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        $questions = ['prefix' => self::PREFIX.'%'];

        $connection->executeStatement('DELETE FROM neuron_eval_result WHERE eval_question_id IN (SELECT id FROM neuron_eval_question WHERE key LIKE :prefix)', $questions);
        $connection->executeStatement('DELETE FROM neuron_eval_question WHERE key LIKE :prefix', $questions);
        foreach ($connection->fetchFirstColumn('SELECT id FROM neuron_conversation WHERE owner_subject LIKE :prefix', $questions) as $id) {
            $connection->executeStatement('DELETE FROM dmstr_log_entries WHERE object_class = :class AND object_id = :id', ['class' => Conversation::class, 'id' => (string) $id]);
            $connection->executeStatement('DELETE FROM neuron_conversation WHERE id = :id', ['id' => (string) $id]);
        }
        $connection->executeStatement('DELETE FROM dmstr_job WHERE type = :type', ['type' => EvalResultEvaluateJobProcessor::JOB_TYPE]);
        static::ensureKernelShutdown();

        AgentFixtures::cleanUp();
    }
}
