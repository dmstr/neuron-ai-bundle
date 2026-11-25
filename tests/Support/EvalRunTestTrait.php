<?php

// file generated with AI assistance: Claude Code - 2026-10-03 23:34:39 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Conversation\ContextChunk;
use Dmstr\NeuronAiBundle\Conversation\ContextRetriever;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingsProviderLocator;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Entity\EvalRun;
use Dmstr\NeuronAiBundle\Evaluation\EvalQuestionStatus;
use Dmstr\NeuronAiBundle\Evaluation\RetrievalStatus;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\Testing\FakeEmbeddingsProvider;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\FixtureSet;
use Dmstr\NeuronPgvectorStore\PgVectorStore;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\Job\JobProcessorRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\RAG\Document;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Fixtures, requests and database reads of the evaluation run tests,
 * shared by EvalRunTest, RetrievalOnlyRunTest, HybridRetrievalProofTest
 * and ContextExpansionPoolTest, for an
 * {@see \ApiPlatform\Symfony\Bundle\Test\ApiTestCase}.
 *
 * The retrieval fixture: a version with one collection of four chunks
 * (`tests/Fixtures/evaluation/retrieval-metrics-chunks.json`, every chunk
 * contains `Hydraulik`) at retrieval depth {@see self::RETRIEVAL_DEPTH},
 * so the pool of every question is the whole collection in fake-vector
 * order, a pre-answer rule that refuses `passwort`, and the six questions
 * of `retrieval-metrics-questions.json` with their expected measurement
 * {@see self::RETRIEVAL_EXPECTED}.
 *
 * The using class defines the constants that keep its rows apart:
 *
 * - `PREFIX`: key prefix of its questions;
 * - `AGENT_SLUG`: slug prefix of its agents (starting with
 *   {@see AgentFixtures::PREFIX}, removed by its cleanUp());
 * - `COLLECTION_NAME`: name prefix of its collections (after
 *   {@see AgentFixtures::PREFIX}).
 *
 * Every request through createClient() boots a new kernel, and with it a
 * new container, a new fake provider without fixtures and a new, empty
 * embedding recorder: fixtures are set on the current container right
 * before the batch runs ({@see self::armFixtures()}), and embeddings are
 * counted before the next request ({@see self::queryEmbeddings()}).
 */
trait EvalRunTestTrait
{
    private const string RUNS = '/api/eval_runs';
    private const string FIXTURES = __DIR__.'/../Fixtures/evaluation/answers.json';

    private const string ROLES_ADMIN = 'ROLE_USER,ROLE_EVAL_ADMIN';
    private const string ROLES_USER = 'ROLE_USER';

    private const string RETRIEVAL_ANSWERS = __DIR__.'/../Fixtures/evaluation/retrieval-metrics-answers.json';
    private const string RETRIEVAL_QUESTIONS = __DIR__.'/../Fixtures/evaluation/retrieval-metrics-questions.json';
    private const string RETRIEVAL_CHUNKS = __DIR__.'/../Fixtures/evaluation/retrieval-metrics-chunks.json';
    private const string EMBEDDING_MODEL = 'nomic-embed-text';
    private const int RETRIEVAL_DEPTH = 4;
    private const string FAIL_TRIGGER = 'test_evalrun_refuse_completion';

    /**
     * Pool order of question `c-rank-two` as indexes into the chunk
     * fixture, computed from the fake vectors (cosine of
     * `<query prefix><question>` against `<document prefix><chunk>`);
     * the marker of the question is in chunk 2, hence rank 2.
     */
    private const array RANK_TWO_POOL = [1, 2, 3, 0];

    /** Per question key (without prefix): retrieval status, recall hit, first relevant rank. */
    private const array RETRIEVAL_EXPECTED = [
        'a-rank-one' => [RetrievalStatus::MEASURED, true, 1],
        'b-miss' => [RetrievalStatus::MEASURED, false, null],
        'c-rank-two' => [RetrievalStatus::MEASURED, true, 2],
        'd-negative' => [RetrievalStatus::NEGATIVE, null, null],
        'e-unmeasured' => [RetrievalStatus::UNMEASURED, null, null],
        'f-ruled' => [RetrievalStatus::MEASURED, true, 1],
    ];

    /** The question that a pre-answer rule of the retrieval version refuses. */
    private const string RULED = 'f-ruled';

    /** Fixture set the next job or batch runs with. */
    private string $answers = self::FIXTURES;

    /**
     * Fixtures added to {@see $answers} for the next job or batch; they win
     * over a key of the set (e.g. the verdict of an answer gate, keyed
     * `<gate marker> <question>`).
     *
     * @var array<string, string|array<string, mixed>>
     */
    private array $extraFixtures = [];

    /**
     * @param array<string, mixed> $configuration overrides of the candidate defaults
     */
    private function publishedVersion(string $slug, array $configuration = []): AgentVersion
    {
        $agent = new Agent()->setName('Eval agent '.$slug)->setSlug(self::AGENT_SLUG.$slug)->setEnabled(true);
        $this->entityManager()->persist($agent);
        $this->entityManager()->flush();
        $version = $this->version($agent, 1, $configuration);
        $agent->setCurrentVersion($version);
        $this->entityManager()->flush();

        return $version;
    }

    /**
     * @param array<string, mixed> $configuration overrides of the candidate defaults
     */
    private function version(Agent $agent, int $number, array $configuration = []): AgentVersion
    {
        $version = new AgentVersion($agent, $number, ConversationConnections::withConnection($configuration + Agent::candidateDefaults()), 'test-admin');
        $this->entityManager()->persist($version);
        $this->entityManager()->flush();

        return $version;
    }

    /**
     * An enabled agent whose current version searches one new collection
     * (the chunk fixture, model {@see self::EMBEDDING_MODEL}) at depth
     * {@see self::RETRIEVAL_DEPTH} and refuses questions with `passwort`
     * through a pre-answer rule; answers come from
     * {@see self::RETRIEVAL_ANSWERS}.
     *
     * @return array{AgentVersion, string} the version and the collection id
     */
    private function retrievalVersion(string $slug): array
    {
        $collection = AgentFixtures::collection(self::COLLECTION_NAME.$slug);
        $collectionId = $collection->getId()->toRfc4122();
        $this->addChunks($collectionId, self::chunkTexts());

        $version = $this->publishedVersion($slug, [
            'collections' => [$collectionId],
            'retrievalDepth' => self::RETRIEVAL_DEPTH,
            'rules' => [
                ['name' => 'secrets', 'match' => ['type' => 'keywords', 'keywords' => ['passwort'], 'wholeWord' => true], 'outcome' => 'refuse', 'template' => 'refusal'],
            ],
            'templates' => ['refusal' => ['de' => 'Dazu gibt es keine Auskunft.']],
        ]);
        $this->answers = self::RETRIEVAL_ANSWERS;

        return [$version, $collectionId];
    }

    /**
     * The questions of the retrieval fixture, keyed with {@see self::PREFIX},
     * written in file order (the batch order is `createdAt`, then `key`).
     */
    private function retrievalQuestions(): void
    {
        $this->persistQuestions(self::retrievalFixture());
    }

    /**
     * Active questions keyed with {@see self::PREFIX}, written in the given
     * order (the batch order is `createdAt`, then `key`); marks the test
     * incomplete when active questions of another test are left.
     *
     * @param list<array{key: string, question: string, expectedAnswer: string, markers?: list<string>, negative?: bool, requestData?: array<array-key, mixed>|null}> $entries keys without the prefix
     */
    private function persistQuestions(array $entries): void
    {
        foreach ($entries as $entry) {
            $question = new EvalQuestion()
                ->setKey(self::PREFIX.$entry['key'])
                ->setQuestion($entry['question'])
                ->setExpectedAnswer($entry['expectedAnswer'])
                ->setMarkers($entry['markers'] ?? null)
                ->setNegative($entry['negative'] ?? false)
                ->setRequestData($entry['requestData'] ?? null)
                ->setStatus(EvalQuestionStatus::ACTIVE);
            $this->entityManager()->persist($question);
            $this->entityManager()->flush();
        }

        $this->assertNoForeignActiveQuestions();
    }

    /**
     * The batch runs every active question in the database: marks the test
     * incomplete when active questions without {@see self::PREFIX} are left.
     */
    private function assertNoForeignActiveQuestions(): void
    {
        if (0 !== (int) $this->dbal()->fetchOne("SELECT COUNT(*) FROM neuron_eval_question WHERE status = 'active' AND key NOT LIKE :prefix", ['prefix' => self::PREFIX.'%'])) {
            self::markTestIncomplete('Active questions of another test are left in the database.');
        }
    }

    /**
     * @return list<array{key: string, question: string, expectedAnswer: string, markers?: list<string>, negative?: bool}>
     */
    private static function retrievalFixture(): array
    {
        /** @var list<array{key: string, question: string, expectedAnswer: string, markers?: list<string>, negative?: bool}> $entries */
        $entries = json_decode((string) file_get_contents(self::RETRIEVAL_QUESTIONS), true, 512, \JSON_THROW_ON_ERROR);

        return $entries;
    }

    /**
     * @return array<string, string> question text per key (without prefix)
     */
    private static function retrievalQuestionTexts(): array
    {
        return array_column(self::retrievalFixture(), 'question', 'key');
    }

    /**
     * @return list<string>
     */
    private static function chunkTexts(): array
    {
        /** @var list<string> $chunks */
        $chunks = json_decode((string) file_get_contents(self::RETRIEVAL_CHUNKS), true, 512, \JSON_THROW_ON_ERROR);

        return $chunks;
    }

    /**
     * Embeds and stores chunks of one collection with its model in the
     * store table.
     *
     * @param list<string> $contents
     */
    private function addChunks(string $collectionId, array $contents): void
    {
        $chunkId = (string) Uuid::v4();
        $documents = [];
        foreach ($contents as $number => $content) {
            $document = (new Document($content))
                ->setSourceType('chunk')
                ->setSourceName($chunkId)
                ->setMetadata([
                    'collection_id' => $collectionId,
                    'topic' => 'hydraulik',
                    'source_url' => 'https://example.org/eval/'.$number,
                    'chunk_id' => $chunkId,
                    'chunk_number' => $number,
                ]);
            $documents[] = $document;
        }

        /** @var EmbeddingsProviderLocator $locator */
        $locator = static::getContainer()->get(EmbeddingsProviderLocator::class);
        /** @var PgVectorStore $store */
        $store = static::getContainer()->get(PgVectorStore::class);
        $store->forModel(self::EMBEDDING_MODEL, $this->registry()->get(self::EMBEDDING_MODEL)->dimension)
            ->addDocuments($locator->for(ConversationConnections::ollama(), self::EMBEDDING_MODEL)->embedDocuments($documents));
    }

    /**
     * The pinned pool order of `c-rank-two` equals the order of the fake
     * vectors and the pool the store returns now.
     */
    private function assertRankTwoPool(string $collectionId): void
    {
        $question = self::retrievalQuestionTexts()['c-rank-two'];
        $chunks = self::chunkTexts();

        self::assertSame(self::RANK_TWO_POOL, array_keys($this->fakeVectorScores($question, $chunks)), 'Pinned order differs from the fake vectors.');

        /** @var ContextRetriever $retriever */
        $retriever = static::getContainer()->get(ContextRetriever::class);
        $pool = $retriever->pool($question, $retriever->loadCollections([$collectionId]), self::RETRIEVAL_DEPTH);
        self::assertSame(
            array_map(static fn (int $index): string => $chunks[$index], self::RANK_TWO_POOL),
            array_map(static fn (ContextChunk $chunk): string => $chunk->content, $pool),
            'The store returns another pool order.',
        );
    }

    /**
     * The cosine similarities the store computes for $question against
     * $chunks with the fake vectors of {@see self::EMBEDDING_MODEL}
     * (`<query prefix><question>` against `<document prefix><chunk>`),
     * best first: the vector-only order of chunks of that model. Embeds
     * outside the provider factory, so the recorder of the kernel stays
     * untouched.
     *
     * @param list<string> $chunks
     *
     * @return array<int, float> cosine per chunk index, descending
     */
    private function fakeVectorScores(string $question, array $chunks): array
    {
        $model = $this->registry()->get(self::EMBEDDING_MODEL);
        $fake = new FakeEmbeddingsProvider(self::EMBEDDING_MODEL, $model->dimension);
        $query = $fake->embedText($model->queryPrefix.$question);
        $scores = [];
        foreach ($chunks as $index => $chunk) {
            $scores[$index] = self::cosine($query, $fake->embedText($model->documentPrefix.$chunk));
        }
        arsort($scores);

        return $scores;
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private static function cosine(array $a, array $b): float
    {
        $dot = $normA = $normB = 0.0;
        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $normA += $value * $value;
            $normB += $b[$i] * $b[$i];
        }

        return $dot / sqrt($normA * $normB);
    }

    private static function dropFailTrigger(Connection $connection): void
    {
        $connection->executeStatement('DROP TRIGGER IF EXISTS '.self::FAIL_TRIGGER.' ON neuron_eval_run');
        $connection->executeStatement('DROP FUNCTION IF EXISTS '.self::FAIL_TRIGGER.'()');
    }

    private function start(string $version): ResponseInterface
    {
        $response = $this->post(self::RUNS.'/start', self::ROLES_ADMIN, ['agentVersion' => $version]);
        self::assertSame(202, $response->getStatusCode(), $response->getContent(false));

        return $response;
    }

    /**
     * Starts a retrieval-only run on $version and asserts that the started
     * run is one (`retrievalOnly` true, mode `retrieval`).
     *
     * @return array<string, mixed> the started run as returned
     */
    private function startRetrievalOnly(AgentVersion $version): array
    {
        $response = $this->post(self::RUNS.'/start', self::ROLES_ADMIN, ['agentVersion' => (string) $version->getId(), 'retrievalOnly' => true]);
        self::assertSame(202, $response->getStatusCode(), $response->getContent(false));
        $run = $response->toArray();
        self::assertTrue($run['retrievalOnly']);
        self::assertSame('retrieval', $run['mode']);

        return $run;
    }

    /**
     * @param array<string, mixed>|null $json
     */
    private function post(string $uri, string $roles, ?array $json = null): ResponseInterface
    {
        $options = ['headers' => self::headers($roles)];
        if (null !== $json) {
            $options['headers']['Content-Type'] = 'application/ld+json';
            $options['body'] = json_encode([] === $json ? new \stdClass() : $json, \JSON_THROW_ON_ERROR);
        }

        return static::createClient()->request('POST', $uri, $options);
    }

    private function get(string $uri, string $roles, ?string $user = null): ResponseInterface
    {
        return static::createClient()->request('GET', $uri, ['headers' => self::headers($roles, $user)]);
    }

    /**
     * @return array<string, string>
     */
    private static function headers(string $roles, ?string $user = null): array
    {
        $headers = ['Accept' => 'application/ld+json', TestHeaderAuthenticator::HEADER_ROLES => $roles];
        if (null !== $user) {
            $headers[TestHeaderAuthenticator::HEADER_USER] = $user;
        }

        return $headers;
    }

    /**
     * Runs the job in the test process, as the messenger worker would; the
     * embedding recorder starts empty for it.
     */
    private function runJob(string $jobId): Job
    {
        $this->armFixtures();
        $this->factory()->getEmbeddingsRecorder()->reset();
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
     * Retrieval status, recall hit and first relevant rank per result,
     * keyed by question key without the prefix.
     *
     * @param list<array<string, mixed>> $results rows of {@see results()}
     *
     * @return array<string, array{string|null, bool|null, int|null}>
     */
    private function retrievalOf(array $results): array
    {
        $measured = [];
        foreach ($results as $result) {
            $measured[substr((string) $result['key'], \strlen(self::PREFIX))] = [
                null === $result['retrieval_status'] ? null : (string) $result['retrieval_status'],
                null === $result['recall_hit'] ? null : (bool) $result['recall_hit'],
                null === $result['first_relevant_rank'] ? null : (int) $result['first_relevant_rank'],
            ];
        }

        return $measured;
    }

    /**
     * recall, mrr, questions measured, negative, unmeasured, retrieval
     * failed of a run, as stored.
     *
     * @return array{float|null, float|null, int|null, int|null, int|null, int|null}
     */
    private function aggregates(string $runId): array
    {
        $row = $this->runRow($runId);
        $int = static fn (mixed $value): ?int => null === $value ? null : (int) $value;

        return [
            null === $row['recall'] ? null : (float) $row['recall'],
            null === $row['mrr'] ? null : (float) $row['mrr'],
            $int($row['questions_measured']),
            $int($row['questions_negative']),
            $int($row['questions_unmeasured']),
            $int($row['questions_retrieval_failed']),
        ];
    }

    /**
     * Query embeddings of $question with {@see self::EMBEDDING_MODEL} since
     * the last job started. The recorder belongs to the current kernel:
     * call this before the next request (createClient() boots a new one).
     */
    private function queryEmbeddings(string $question): int
    {
        $text = $this->registry()->get(self::EMBEDDING_MODEL)->queryPrefix.$question;

        return \count(array_keys($this->factory()->getEmbeddingsRecorder()->getRecordedTexts(self::EMBEDDING_MODEL), $text, true));
    }

    /**
     * Sets the answer fixtures and {@see $extraFixtures} on the fake
     * provider of the current container (the one the job processor and the
     * batch runner use).
     */
    private function armFixtures(): void
    {
        $this->factory()->useFixtures($this->extraFixtures + FixtureSet::load($this->answers));
    }

    private function registry(): EmbeddingModelRegistry
    {
        /** @var EmbeddingModelRegistry $registry */
        $registry = static::getContainer()->get(EmbeddingModelRegistry::class);

        return $registry;
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
     * The failure trigger, runs of the test agents (results cascade), their
     * evaluation conversations with audit entries, the test questions, the
     * batch jobs, the chunks of the test collections, then the agents and
     * collections ({@see AgentFixtures::cleanUp()}).
     */
    private static function cleanUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        self::dropFailTrigger($connection);
        $agents = ['agents' => self::AGENT_SLUG.'%'];
        $runs = 'SELECT r.id FROM neuron_eval_run r JOIN neuron_agent_version v ON v.id = r.agent_version_id JOIN neuron_agent a ON a.id = v.agent_id WHERE a.slug LIKE :agents';

        $conversations = $connection->fetchFirstColumn(
            'SELECT conversation_id FROM neuron_eval_result WHERE conversation_id IS NOT NULL AND eval_run_id IN ('.$runs.')',
            $agents,
        );
        // Evaluation conversations whose result was never written.
        $conversations = array_merge($conversations, $connection->fetchFirstColumn(
            "SELECT c.id FROM neuron_conversation c JOIN neuron_agent_version v ON v.id = c.agent_version JOIN neuron_agent a ON a.id = v.agent_id WHERE c.origin = 'eval' AND a.slug LIKE :agents",
            $agents,
        ));

        $connection->executeStatement('DELETE FROM neuron_eval_run WHERE id IN ('.$runs.')', $agents);
        foreach (array_unique(array_map('strval', $conversations)) as $id) {
            $connection->executeStatement('DELETE FROM dmstr_log_entries WHERE object_class = :class AND object_id = :id', ['class' => Conversation::class, 'id' => $id]);
            $connection->executeStatement('DELETE FROM neuron_conversation WHERE id = :id', ['id' => $id]);
        }
        $connection->executeStatement('DELETE FROM neuron_eval_question WHERE key LIKE :prefix', ['prefix' => self::PREFIX.'%']);
        $connection->executeStatement('DELETE FROM dmstr_job WHERE type = :type', ['type' => EvalRun::JOB_TYPE_START]);
        $connection->executeStatement(
            "DELETE FROM embedding WHERE metadata->>'collection_id' IN (SELECT id::text FROM neuron_collection WHERE name LIKE :collections)",
            ['collections' => AgentFixtures::PREFIX.self::COLLECTION_NAME.'%'],
        );
        static::ensureKernelShutdown();

        AgentFixtures::cleanUp();
    }
}

