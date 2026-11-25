<?php

// file generated with AI assistance: Claude Code - 2026-10-03 23:34:39 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Evaluation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultSource;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvalRunStatus;
use Dmstr\NeuronAiBundle\Evaluation\RetrievalStatus;
use Dmstr\NeuronAiBundle\JobProcessor\EvalResultEvaluateJobProcessor;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\EvalRunTestTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\Group;

/**
 * Retrieval-only evaluation runs end to end: a run started with
 * `retrievalOnly: true` runs no turn; per question the batch only searches
 * ({@see \Dmstr\NeuronAiBundle\Evaluation\BatchRunner}, the retrieval-only
 * branch) and stores the measurement.
 *
 * Fixtures: the retrieval fixture of {@see EvalRunTestTrait} (one collection
 * of four chunks at depth 4, six questions, expected measurement
 * {@see EvalRunTestTrait::RETRIEVAL_EXPECTED}: recall 3/4 = 0.75, MRR
 * (1 + 0 + 1/2 + 1)/4 = 0.625, 4 measured, 1 negative, 1 unmeasured, 0
 * failed). Covered: the same metrics as a normal retrieval run on the same
 * version; no chat or stream call, no conversation or message, null
 * tokens, interactions and conversation per result, run totals 0; ONE
 * query embedding per question and model (the ruled question included,
 * rules do not run) instead of two; a search that throws (the collection
 * is deleted after the start) makes `failed` results with the message and
 * retrieval status `failed` while the run completes; `evaluate` on such a
 * result answers 422 with the retrieval-only message; cancel between
 * questions, redelivery after partial progress and after the end.
 *
 * Embedding and chat calls are read from the fake provider factory of the
 * current kernel before the next request (every createClient() boots a new
 * kernel with a new factory). Needs the migrated test database (CI only).
 */
#[Group('database')]
final class RetrievalOnlyRunTest extends ApiTestCase
{
    use EvalRunTestTrait;

    protected static ?bool $alwaysBootKernel = true;

    private const string PREFIX = 'test-retrievalonly-';
    private const string AGENT_SLUG = AgentFixtures::PREFIX.'retrievalonly-';
    private const string COLLECTION_NAME = 'retrievalonly-';

    /** Aggregates of the retrieval fixture: recall, mrr, measured, negative, unmeasured, retrieval failed. */
    private const array EXPECTED_AGGREGATES = [0.75, 0.625, 4, 1, 1, 0];

    private const string NOTHING_TO_JUDGE = 'Retrieval-only result, nothing to judge: the run measured retrieval without an answer.';

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

    public function testRetrievalOnlyRunMeasuresWithoutTurnConversationOrChatCall(): void
    {
        [$version] = $this->retrievalVersion('metrics');
        $this->retrievalQuestions();
        $run = $this->startRetrievalOnly($version);
        self::assertSame(6, $run['questionsTotal']);

        $chat = $this->factory()->getChatProvider();
        $before = [
            'conversations' => $this->rowCount('neuron_conversation'),
            'messages' => $this->rowCount('neuron_message'),
            'recorded' => \count($chat->getRecorded()),
            'fixtureHits' => \count($chat->getFixtureHits()),
            'providers' => \count($this->factory()->getRequestedProviders()),
        ];

        $job = $this->runJob($run['jobId']);

        // Counted before the next request (new kernel, new fake factory).
        self::assertSame($before, [
            'conversations' => $this->rowCount('neuron_conversation'),
            'messages' => $this->rowCount('neuron_message'),
            'recorded' => \count($chat->getRecorded()),
            'fixtureHits' => \count($chat->getFixtureHits()),
            'providers' => \count($this->factory()->getRequestedProviders()),
        ], 'No conversation, no message, no chat or stream call.');
        foreach (self::retrievalQuestionTexts() as $key => $text) {
            self::assertSame(1, $this->queryEmbeddings($text), $key);
        }
        self::assertCount(6, $this->factory()->getEmbeddingsRecorder()->getRecorded());
        self::assertSame([], $this->factory()->getEmbeddingsRecorder()->getRecordedTexts('mxbai-embed-large'));

        self::assertSame(100, $job->getProgress());
        self::assertSame(['completed' => 6, 'failed' => 0, 'skipped' => 0, 'failures' => []], (array) $job->getResultData());

        $results = $this->results($run['id']);
        self::assertSame(self::RETRIEVAL_EXPECTED, $this->retrievalOf($results));
        foreach ($results as $result) {
            $key = (string) $result['key'];
            self::assertSame(EvalResultStatus::COMPLETED, $result['status'], $key);
            self::assertNull($result['error_message'], $key);
            self::assertNull($result['conversation_id'], $key);
            self::assertNull($result['tokens_input'], $key);
            self::assertNull($result['tokens_output'], $key);
            self::assertNull($result['interactions'], $key);
            self::assertNotNull($result['duration_ms'], $key);
            self::assertGreaterThanOrEqual(0, (int) $result['duration_ms'], $key);
            self::assertSame(EvalResultSource::BATCH, $result['source'], $key);
        }

        $row = $this->runRow($run['id']);
        self::assertSame(EvalRunStatus::COMPLETED, $row['status']);
        self::assertSame([0, 0], [(int) $row['tokens_total'], (int) $row['interactions_total']]);
        self::assertSame(self::EXPECTED_AGGREGATES, $this->aggregates($run['id']));

        $read = $this->get(self::RUNS.'/'.$run['id'], self::ROLES_USER)->toArray();
        self::assertSame(
            [true, 'retrieval', 0.75, 0.625, 4, 1, 1, 0, 0, 0],
            [$read['retrievalOnly'], $read['mode'], $read['recall'], $read['mrr'], $read['questionsMeasured'], $read['questionsNegative'], $read['questionsUnmeasured'], $read['questionsRetrievalFailed'], $read['tokensTotal'], $read['interactionsTotal']],
        );
    }

    public function testSameMetricsAsANormalRetrievalRunOnTheSameVersion(): void
    {
        [$version] = $this->retrievalVersion('same-metrics');
        $this->retrievalQuestions();

        // The normal run: turn and measurement per question (two query
        // embeddings, one for the ruled question).
        $normal = $this->start((string) $version->getId())->toArray();
        self::assertFalse($normal['retrievalOnly']);
        $this->runJob($normal['jobId']);
        foreach (self::retrievalQuestionTexts() as $key => $text) {
            self::assertSame(self::RULED === $key ? 1 : 2, $this->queryEmbeddings($text), $key);
        }

        // The retrieval-only run: the search alone, one per question.
        $only = $this->startRetrievalOnly($version);
        $this->runJob($only['jobId']);
        foreach (self::retrievalQuestionTexts() as $key => $text) {
            self::assertSame(1, $this->queryEmbeddings($text), $key);
        }

        $normalResults = $this->results($normal['id']);
        $onlyResults = $this->results($only['id']);
        self::assertSame(self::RETRIEVAL_EXPECTED, $this->retrievalOf($normalResults));
        self::assertSame(self::RETRIEVAL_EXPECTED, $this->retrievalOf($onlyResults));
        self::assertSame(self::EXPECTED_AGGREGATES, $this->aggregates($normal['id']));
        self::assertSame(self::EXPECTED_AGGREGATES, $this->aggregates($only['id']));

        // The miss question fails at the provider in the normal run only;
        // the retrieval-only run calls no provider.
        $normalByKey = array_column($normalResults, null, 'key');
        $onlyByKey = array_column($onlyResults, null, 'key');
        self::assertSame(EvalResultStatus::FAILED, $normalByKey[self::PREFIX.'b-miss']['status']);
        self::assertSame(EvalResultStatus::COMPLETED, $onlyByKey[self::PREFIX.'b-miss']['status']);
        self::assertSame(array_column($normalResults, 'question_fingerprint', 'key'), array_column($onlyResults, 'question_fingerprint', 'key'));
    }

    public function testFailingSearchMakesFailedResultsAndTheRunCompletes(): void
    {
        [$version, $collectionId] = $this->retrievalVersion('search-failure');
        $this->retrievalQuestions();
        $run = $this->startRetrievalOnly($version);

        // The collection of the version is deleted after the start, so
        // loading it for the search throws.
        $this->dbal()->executeStatement("DELETE FROM embedding WHERE metadata->>'collection_id' = :id", ['id' => $collectionId]);
        $this->dbal()->executeStatement('DELETE FROM neuron_collection WHERE id = :id', ['id' => $collectionId]);

        $conversations = $this->rowCount('neuron_conversation');
        $job = $this->runJob($run['jobId']);
        $this->factory()->getEmbeddingsRecorder()->assertNothingEmbedded();
        self::assertSame($conversations, $this->rowCount('neuron_conversation'));

        $message = 'Collection "'.$collectionId.'" does not exist (any more).';
        $resultData = (array) $job->getResultData();
        self::assertSame([0, 6, 0], [$resultData['completed'], $resultData['failed'], $resultData['skipped']]);
        self::assertSame(100, $job->getProgress());

        // Every question searches, the negative and the unmeasured one
        // included, so every result failed.
        $results = $this->results($run['id']);
        self::assertSame(array_fill_keys(array_keys(self::RETRIEVAL_EXPECTED), [RetrievalStatus::FAILED, null, null]), $this->retrievalOf($results));
        foreach ($results as $result) {
            $key = (string) $result['key'];
            self::assertSame(EvalResultStatus::FAILED, $result['status'], $key);
            self::assertSame($message, $result['error_message'], $key);
            self::assertNull($result['conversation_id'], $key);
            self::assertNull($result['tokens_input'], $key);
            self::assertNull($result['interactions'], $key);
            self::assertGreaterThanOrEqual(0, (int) $result['duration_ms'], $key);
        }

        $row = $this->runRow($run['id']);
        self::assertSame(EvalRunStatus::COMPLETED, $row['status']);
        self::assertNotNull($row['finished_at']);
        self::assertSame([null, null, 0, 0, 0, 6], $this->aggregates($run['id']));

        // A failed retrieval-only result is not judged either, with the
        // same message.
        $response = $this->post('/api/eval_results/'.$results[0]['id'].'/evaluate', self::ROLES_ADMIN);
        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertSame(self::NOTHING_TO_JUDGE, (string) ($response->toArray(false)['detail'] ?? ''));
    }

    public function testEvaluateOnARetrievalOnlyResultAnswers422(): void
    {
        [$version] = $this->retrievalVersion('evaluate');
        $this->retrievalQuestions();
        $run = $this->startRetrievalOnly($version);
        $this->runJob($run['jobId']);

        $result = array_column($this->results($run['id']), null, 'key')[self::PREFIX.'a-rank-one'];
        self::assertSame(EvalResultStatus::COMPLETED, $result['status']);

        $response = $this->post('/api/eval_results/'.$result['id'].'/evaluate', self::ROLES_ADMIN);
        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        // The decoded detail: the raw JSON body escapes characters of the message.
        self::assertSame(self::NOTHING_TO_JUDGE, (string) ($response->toArray(false)['detail'] ?? ''));

        // Nothing written: no evaluation, no judge job.
        self::assertSame(0, (int) $this->dbal()->fetchOne('SELECT COUNT(*) FROM neuron_evaluation WHERE eval_result_id = :id', ['id' => $result['id']]));
        self::assertNull($this->dbal()->fetchOne('SELECT evaluation_id FROM neuron_eval_result WHERE id = :id', ['id' => $result['id']]));
        self::assertSame(0, (int) $this->dbal()->fetchOne('SELECT COUNT(*) FROM dmstr_job WHERE type = :type', ['type' => EvalResultEvaluateJobProcessor::JOB_TYPE]));
    }

    public function testCancelBetweenQuestionsAggregatesTheStoredResults(): void
    {
        [$version] = $this->retrievalVersion('cancel');
        $this->retrievalQuestions();
        $run = $this->startRetrievalOnly($version);

        // The cancel arrives from another process once two results are
        // stored (observed after every flush of the job).
        $listener = new class($this->dbal(), $run['id']) {
            public function __construct(private readonly Connection $connection, private readonly string $run)
            {
            }

            public function postFlush(): void
            {
                if (2 <= (int) $this->connection->fetchOne('SELECT COUNT(*) FROM neuron_eval_result WHERE eval_run_id = :run', ['run' => $this->run])) {
                    $this->connection->executeStatement('UPDATE neuron_eval_run SET cancel_requested_at = NOW() WHERE id = :run AND cancel_requested_at IS NULL', ['run' => $this->run]);
                }
            }
        };
        $events = $this->entityManager()->getEventManager();
        $events->addEventListener([Events::postFlush], $listener);
        try {
            $job = $this->runJob($run['jobId']);
        } finally {
            $events->removeEventListener([Events::postFlush], $listener);
        }
        // Counted before the next request: one search per answered question.
        $texts = self::retrievalQuestionTexts();
        self::assertSame([1, 1, 0], [$this->queryEmbeddings($texts['a-rank-one']), $this->queryEmbeddings($texts['b-miss']), $this->queryEmbeddings($texts['c-rank-two'])]);
        self::assertCount(2, $this->factory()->getEmbeddingsRecorder()->getRecorded());
        self::assertSame(['completed' => 2, 'failed' => 0, 'skipped' => 0, 'failures' => []], (array) $job->getResultData());

        self::assertSame(EvalRunStatus::CANCELLED, $this->runRow($run['id'])['status']);
        $results = $this->results($run['id']);
        self::assertSame([self::PREFIX.'a-rank-one', self::PREFIX.'b-miss'], array_column($results, 'key'));
        self::assertSame(array_intersect_key(self::RETRIEVAL_EXPECTED, ['a-rank-one' => 0, 'b-miss' => 0]), $this->retrievalOf($results));
        self::assertSame([null, null], array_column($results, 'conversation_id'));
        // Hit at rank 1 and a miss: recall 1/2, MRR (1 + 0)/2.
        self::assertSame([0.5, 0.5, 2, 0, 0, 0], $this->aggregates($run['id']));
    }

    public function testRedeliveryAfterPartialProgressEqualsASingleDelivery(): void
    {
        [$version] = $this->retrievalVersion('redelivery');
        $this->retrievalQuestions();
        $run = $this->startRetrievalOnly($version);
        $this->runJob($run['jobId']);
        $single = [$this->retrievalOf($this->results($run['id'])), $this->aggregates($run['id'])];
        self::assertSame([self::RETRIEVAL_EXPECTED, self::EXPECTED_AGGREGATES], $single);

        // As if the worker died after two questions.
        $keep = \array_slice(array_column($this->results($run['id']), 'id'), 0, 2);
        $this->dbal()->executeStatement('DELETE FROM neuron_eval_result WHERE eval_run_id = :run AND id NOT IN (:a, :b)', ['run' => $run['id'], 'a' => $keep[0], 'b' => $keep[1]]);
        $this->dbal()->executeStatement("UPDATE neuron_eval_run SET status = 'running', finished_at = NULL, recall = NULL, mrr = NULL, questions_measured = NULL, questions_negative = NULL, questions_unmeasured = NULL, questions_retrieval_failed = NULL WHERE id = :run", ['run' => $run['id']]);

        $conversations = $this->rowCount('neuron_conversation');
        $job = $this->runJob($run['jobId']);
        // Only the four outstanding questions searched.
        self::assertCount(4, $this->factory()->getEmbeddingsRecorder()->getRecorded());
        self::assertSame(['completed' => 4, 'failed' => 0, 'skipped' => 2, 'failures' => []], (array) $job->getResultData());
        self::assertSame(100, $job->getProgress());
        self::assertSame($conversations, $this->rowCount('neuron_conversation'));

        $results = $this->results($run['id']);
        self::assertSame($single, [$this->retrievalOf($results), $this->aggregates($run['id'])]);
        self::assertSame(array_fill(0, 6, null), array_column($results, 'conversation_id'));
        self::assertSame(array_fill(0, 6, EvalResultStatus::COMPLETED), array_column($results, 'status'));
        $row = $this->runRow($run['id']);
        self::assertSame([EvalRunStatus::COMPLETED, 6, 0, 0], [$row['status'], (int) $row['questions_total'], (int) $row['tokens_total'], (int) $row['interactions_total']]);

        // Redelivery after the end: a no-op.
        $again = $this->runJob($run['jobId']);
        self::assertArrayHasKey('skipped', (array) $again->getResultData());
        $this->factory()->getEmbeddingsRecorder()->assertNothingEmbedded();
        self::assertSame($single, [$this->retrievalOf($this->results($run['id'])), $this->aggregates($run['id'])]);
        self::assertCount(6, $this->results($run['id']));
    }

    // --- helpers --------------------------------------------------------

    private function rowCount(string $table): int
    {
        return (int) $this->dbal()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }
}
