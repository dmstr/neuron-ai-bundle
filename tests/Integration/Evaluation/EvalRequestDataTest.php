<?php

// file generated with AI assistance: Claude Code - 2026-10-04 17:23:44 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Evaluation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvalRunStatus;
use Dmstr\NeuronAiBundle\Evaluation\RetrievalStatus;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\EvalRunTestTrait;
use NeuronAI\Testing\RequestRecord;
use PHPUnit\Framework\Attributes\Group;

/**
 * `EvalQuestion.requestData` as the variables of the evaluation
 * conversation in a batch run: the variables are
 * checked against the pinned version's `startSchema` exactly as for
 * `POST /conversations` with `agent` and `variables`, and every refusal
 * fails only its question while the run completes.
 *
 * Covered: valid `requestData` reaches the instructions of the provider
 * request and the conversation's `variables`; an undeclared variable, a
 * type mismatch, the system value name `date`, a variable missing from
 * the start schema's `required` (no `requestData` at all) and a
 * placeholder of the instructions without value each fail their question
 * with the 422 detail as `errorMessage` and without a conversation; a
 * version without `startSchema` runs questions without or with empty
 * `requestData` as before and fails one with variables or with a JSON
 * list; a retrieval-only run neither uses nor checks `requestData`, while
 * a normal run on the same version fails that question and still measures
 * its retrieval.
 *
 * The single execution (`POST /eval_questions/{id}/execute`) is covered
 * in {@see EvalQuestionExecuteTest}.
 *
 * Fixtures and requests come from {@see EvalRunTestTrait}: the starter is
 * `test-user` with `ROLE_USER,ROLE_EVAL_ADMIN`, answers come from the
 * fake provider's fixture sets, armed on the current container right
 * before each job (every request boots a new kernel). Needs the migrated
 * test database (CI only).
 */
#[Group('database')]
final class EvalRequestDataTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    use EvalRunTestTrait;

    private const string PREFIX = 'test-evalreq-';
    private const string AGENT_SLUG = AgentFixtures::PREFIX.'evalreq-';
    private const string COLLECTION_NAME = 'evalreq-';

    private const string TORQUE_QUESTION = 'Which torque applies to the wheel nuts?';
    private const string TORQUE_ANSWER = 'Die Radmuttern werden mit 450 Nm angezogen und nach 50 km nachgezogen.';

    /** Start schema of the variables version: `vehicle` required, `axles` optional, nothing else. */
    private const array START_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'vehicle' => ['type' => 'string'],
            'axles' => ['type' => 'integer'],
        ],
        'required' => ['vehicle'],
        'additionalProperties' => false,
    ];

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

    public function testValidRequestDataReachesTheInstructionsAndEveryRefusalFailsOnlyItsQuestion(): void
    {
        $version = $this->publishedVersion('schema', [
            'instructions' => ['de' => 'Wartungsassistent für {{vehicle}} mit {{axles}} Achsen.'],
            'startSchema' => self::START_SCHEMA,
        ]);
        $this->persistQuestions([
            $this->torque('a-valid', ['vehicle' => 'Typ 4', 'axles' => 2]),
            $this->torque('b-undeclared', ['vehicle' => 'Typ 4', 'axles' => 2, 'trailer' => 'ja']),
            $this->torque('c-type', ['vehicle' => 'Typ 4', 'axles' => 'zwei']),
            $this->torque('d-system', ['vehicle' => 'Typ 4', 'axles' => 2, 'date' => '2020-01-01']),
            $this->torque('e-required', null),
            $this->torque('f-unfilled', ['vehicle' => 'Typ 4']),
        ]);

        $run = $this->start((string) $version->getId())->toArray();
        self::assertSame(6, $run['questionsTotal']);
        $recorded = \count($this->factory()->getChatProvider()->getRecorded());

        $job = $this->runJob($run['jobId']);

        // Provider requests, read before the next request boots a new kernel.
        $requests = \array_slice(array_values($this->factory()->getChatProvider()->getRecorded()), $recorded);
        self::assertContainsOnlyInstancesOf(RequestRecord::class, $requests);
        $prompts = array_map(static fn (RequestRecord $r): string => (string) $r->systemPrompt?->getContent(), $requests);
        self::assertNotEmpty($prompts, 'The valid question reached the provider.');
        foreach ($prompts as $prompt) {
            self::assertStringContainsString('Wartungsassistent für Typ 4 mit 2 Achsen.', $prompt);
            self::assertStringNotContainsString('{{', $prompt);
        }

        self::assertSame(100, $job->getProgress());
        $resultData = (array) $job->getResultData();
        self::assertSame([1, 5, 0], [$resultData['completed'], $resultData['failed'], $resultData['skipped']]);
        self::assertSame(EvalRunStatus::COMPLETED, $this->runRow($run['id'])['status']);

        $results = $this->keyed($this->results($run['id']));
        self::assertCount(6, $results);

        $valid = $results['a-valid'];
        self::assertSame(EvalResultStatus::COMPLETED, $valid['status'], (string) $valid['error_message']);
        self::assertNull($valid['error_message']);
        self::assertNotNull($valid['conversation_id']);
        $conversation = $this->dbal()->fetchAssociative('SELECT variables FROM neuron_conversation WHERE id = :id', ['id' => $valid['conversation_id']]);
        self::assertIsArray($conversation);
        // assertEquals: the key order of a JSON column is the database's.
        self::assertEquals(['vehicle' => 'Typ 4', 'axles' => 2], json_decode((string) $conversation['variables'], true));
        self::assertContains(self::TORQUE_ANSWER, $this->dbal()->fetchFirstColumn(
            "SELECT content FROM neuron_message WHERE conversation_id = :id AND role = 'assistant'",
            ['id' => $valid['conversation_id']],
        ));

        foreach ([
            'b-undeclared' => ['variables/trailer: not declared by the agent version.'],
            'c-type' => ['variables/axles'],
            'd-system' => ['variables/date: "date" is a system value and cannot be supplied.'],
            'e-required' => ['variables', 'vehicle'],
            'f-unfilled' => ['variables/axles: required by the instructions.'],
        ] as $key => $fragments) {
            $result = $results[$key];
            self::assertSame(EvalResultStatus::FAILED, $result['status'], $key);
            self::assertNull($result['conversation_id'], $key.': no conversation is stored for a refused pinning');
            self::assertNotNull($result['duration_ms'], $key);
            foreach ($fragments as $fragment) {
                self::assertStringContainsString($fragment, (string) $result['error_message'], $key);
            }
        }
        self::assertSame(
            array_map(static fn (string $key): string => self::PREFIX.$key, ['b-undeclared', 'c-type', 'd-system', 'e-required', 'f-unfilled']),
            array_column($resultData['failures'], 'question'),
        );

        self::assertSame(1, $this->evalConversationCount());
    }

    public function testVersionWithoutStartSchemaFailsOnlyQuestionsWithVariables(): void
    {
        $version = $this->publishedVersion('no-schema', ['instructions' => ['de' => 'Wartungsassistent.']]);
        $this->persistQuestions([
            $this->torque('a-null', null),
            $this->torque('b-empty', []),
            $this->torque('c-variables', ['vehicle' => 'Typ 4']),
            $this->torque('d-list', ['Typ 4']),
        ]);

        $run = $this->start((string) $version->getId())->toArray();
        $job = $this->runJob($run['jobId']);

        $resultData = (array) $job->getResultData();
        self::assertSame([2, 2], [$resultData['completed'], $resultData['failed']]);
        self::assertSame(EvalRunStatus::COMPLETED, $this->runRow($run['id'])['status']);

        $results = $this->keyed($this->results($run['id']));
        foreach (['a-null', 'b-empty'] as $key) {
            self::assertSame(EvalResultStatus::COMPLETED, $results[$key]['status'], $key.': '.$results[$key]['error_message']);
            self::assertNotNull($results[$key]['conversation_id'], $key);
        }
        self::assertSame(EvalResultStatus::FAILED, $results['c-variables']['status']);
        self::assertSame('variables/vehicle: not declared by the agent version.', $results['c-variables']['error_message']);
        self::assertSame(EvalResultStatus::FAILED, $results['d-list']['status']);
        self::assertStringContainsString('requestData: must be a JSON object', (string) $results['d-list']['error_message']);
        self::assertNull($results['c-variables']['conversation_id']);
        self::assertNull($results['d-list']['conversation_id']);
        self::assertSame(2, $this->evalConversationCount());
    }

    public function testRetrievalOnlyRunIgnoresRequestDataWhileANormalRunFailsTheQuestion(): void
    {
        // The retrieval version has no startSchema: the variables of the
        // first question are undeclared.
        [$version] = $this->retrievalVersion('retrieval');
        $entries = self::retrievalFixture();
        $entries[0]['requestData'] = ['vehicle' => 'Typ 4'];
        $this->persistQuestions($entries);
        $firstKey = $entries[0]['key'];

        // Retrieval-only: no conversation, no instructions, requestData is
        // not checked; the measurement is the same as without it.
        $only = $this->startRetrievalOnly($version);
        $job = $this->runJob($only['jobId']);
        self::assertSame(['completed' => 6, 'failed' => 0, 'skipped' => 0, 'failures' => []], (array) $job->getResultData());
        $results = $this->results($only['id']);
        self::assertSame(self::RETRIEVAL_EXPECTED, $this->retrievalOf($results));
        foreach ($results as $result) {
            self::assertSame(EvalResultStatus::COMPLETED, $result['status'], (string) $result['key']);
        }

        // Normal run on the same version: the question fails before its
        // turn (one query embedding, the measurement's), and its retrieval
        // is still measured.
        $normal = $this->start((string) $version->getId())->toArray();
        self::assertFalse($normal['retrievalOnly']);
        $this->runJob($normal['jobId']);
        self::assertSame(1, $this->queryEmbeddings($entries[0]['question']));

        $results = $this->keyed($this->results($normal['id']));
        $first = $results[$firstKey];
        self::assertSame(EvalResultStatus::FAILED, $first['status']);
        self::assertSame('variables/vehicle: not declared by the agent version.', $first['error_message']);
        self::assertNull($first['conversation_id']);
        self::assertSame(RetrievalStatus::MEASURED, $first['retrieval_status']);
        self::assertSame(self::RETRIEVAL_EXPECTED, $this->retrievalOf(array_values($results)));
        self::assertSame(EvalRunStatus::COMPLETED, $this->runRow($normal['id'])['status']);
    }

    // --- fixtures -------------------------------------------------------

    /**
     * A question with the torque fixture answer and $requestData.
     *
     * @param array<array-key, mixed>|null $requestData
     *
     * @return array{key: string, question: string, expectedAnswer: string, requestData: array<array-key, mixed>|null}
     */
    private function torque(string $key, ?array $requestData): array
    {
        return [
            'key' => $key,
            'question' => self::TORQUE_QUESTION,
            'expectedAnswer' => self::TORQUE_ANSWER,
            'requestData' => $requestData,
        ];
    }

    // --- database -------------------------------------------------------

    /**
     * Rows of {@see results()} keyed by question key without the prefix.
     *
     * @param list<array<string, mixed>> $results
     *
     * @return array<string, array<string, mixed>>
     */
    private function keyed(array $results): array
    {
        $keyed = [];
        foreach ($results as $result) {
            $keyed[substr((string) $result['key'], \strlen(self::PREFIX))] = $result;
        }

        return $keyed;
    }

    /**
     * Evaluation conversations on the versions of the test agents.
     */
    private function evalConversationCount(): int
    {
        return (int) $this->dbal()->fetchOne(
            "SELECT COUNT(*) FROM neuron_conversation c JOIN neuron_agent_version v ON v.id = c.agent_version JOIN neuron_agent a ON a.id = v.agent_id WHERE c.origin = 'eval' AND a.slug LIKE :agents",
            ['agents' => self::AGENT_SLUG.'%'],
        );
    }
}
