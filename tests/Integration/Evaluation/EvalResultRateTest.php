<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:46:34 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Evaluation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Entity\EvalResult;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultSource;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultStatus;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryEvaluationSettings;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The human rating: `POST /api/eval_results/{id}/rate` with the
 * rating document as body, validated against the setting
 * `evaluation.human_rating_schema`.
 *
 * Covered: a valid rating is stored with author and time and replaces an
 * earlier one (200); an extra property under `additionalProperties: false`
 * and a wrong type answer 422 with the JSON pointer as `propertyPath`; an
 * unset and an invalid schema answer 422 naming the setting; malformed
 * JSON and a body that is no object answer 400; nothing is written on any
 * refusal; `ROLE_USER` and `ROLE_EVAL_ADMIN` alone get 403, a reviewer and
 * `ROLE_ADMIN` (listed in the permission map) may rate; an unknown result
 * answers 404.
 *
 * Results and questions are written through the entity manager (key prefix
 * {@see self::PREFIX}); the settings row of the section `evaluation` is
 * removed before and after every test, so the schema starts unset. Needs
 * the migrated test database (CI only).
 */
#[Group('database')]
final class EvalResultRateTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const string PREFIX = 'test-rate-';
    private const string SETTING = 'evaluation.human_rating_schema';

    private const string ROLES_REVIEWER = 'ROLE_USER,ROLE_EVAL_REVIEWER';
    private const string ROLES_EVAL_ADMIN = 'ROLE_USER,ROLE_EVAL_ADMIN';
    private const string ROLES_USER = 'ROLE_USER';
    private const string REVIEWER = self::PREFIX.'reviewer';

    /** The operator's schema of these tests. */
    private const array SCHEMA = [
        'type' => 'object',
        'required' => ['score'],
        'additionalProperties' => false,
        'properties' => [
            'score' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
            'correct' => ['type' => 'boolean'],
            'comment' => ['type' => 'string', 'maxLength' => 2000],
        ],
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

    public function testValidRatingIsStoredWithAuthorAndTime(): void
    {
        $this->configureSchema(self::SCHEMA);
        $result = $this->evalResult();
        $before = new \DateTimeImmutable('-1 second');

        $response = $this->rate($result, ['score' => 4, 'correct' => true, 'comment' => 'Interval right, source missing.']);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        $body = $response->toArray();
        self::assertSame('/api/eval_results/'.$result, $body['@id']);
        self::assertSame(['score' => 4, 'correct' => true, 'comment' => 'Interval right, source missing.'], $body['humanRating']);
        self::assertSame(self::REVIEWER, $body['humanRatedBy']);
        self::assertNotEmpty($body['humanRatedAt']);

        $row = $this->resultRow($result);
        self::assertEquals(['score' => 4, 'correct' => true, 'comment' => 'Interval right, source missing.'], json_decode((string) $row['human_rating'], true));
        self::assertSame(self::REVIEWER, $row['human_rated_by']);
        self::assertGreaterThanOrEqual($before, new \DateTimeImmutable((string) $row['human_rated_at']));

        // A new rating replaces the previous one, with its own author.
        $again = $this->rate($result, ['score' => 2], self::ROLES_REVIEWER, self::PREFIX.'second');
        self::assertSame(200, $again->getStatusCode(), $again->getContent(false));
        $row = $this->resultRow($result);
        self::assertEquals(['score' => 2], json_decode((string) $row['human_rating'], true));
        self::assertSame(self::PREFIX.'second', $row['human_rated_by']);
    }

    public function testAdminRatesThroughTheRoleHierarchy(): void
    {
        $this->configureSchema(self::SCHEMA);
        $result = $this->evalResult();

        // No role header: `test-admin` with ROLE_ADMIN.
        $response = static::createClient()->request('POST', '/api/eval_results/'.$result.'/rate', [
            'headers' => ['Accept' => 'application/ld+json', 'Content-Type' => 'application/json'],
            'body' => '{"score": 5}',
        ]);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertSame(TestHeaderAuthenticator::ADMIN_IDENTIFIER, $this->resultRow($result)['human_rated_by']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function nonMatchingRatings(): iterable
    {
        yield 'extra property' => [['score' => 3, 'extra' => 'not in the schema'], '/extra'];
        yield 'wrong type' => [['score' => 'three'], '/score'];
        yield 'out of range' => [['score' => 9], '/score'];
        yield 'missing required' => [['comment' => 'no score'], '/'];
    }

    /**
     * @param array<string, mixed> $rating
     */
    #[DataProvider('nonMatchingRatings')]
    public function testNonMatchingRatingAnswers422WithPointer(array $rating, string $pointer): void
    {
        $this->configureSchema(self::SCHEMA);
        $result = $this->evalResult();

        $response = $this->rate($result, $rating);

        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        $violations = $response->toArray(false)['violations'] ?? [];
        self::assertContains($pointer, array_column($violations, 'propertyPath'), $response->getContent(false));
        foreach ($violations as $violation) {
            self::assertNotSame('', (string) $violation['message']);
        }
        $this->assertNotRated($result);
    }

    public function testUnsetSchemaAnswers422NamingTheSetting(): void
    {
        $result = $this->evalResult();

        $response = $this->rate($result, ['score' => 4]);

        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertStringContainsString(self::SETTING, (string) ($response->toArray(false)['detail'] ?? ''));
        $this->assertNotRated($result);
    }

    public function testInvalidSchemaAnswers422NamingTheSetting(): void
    {
        $this->configureSchema(['type' => 'object', 'properties' => ['score' => ['type' => 'no-such-type']]]);
        $result = $this->evalResult();

        $response = $this->rate($result, ['score' => 4]);

        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        $detail = (string) ($response->toArray(false)['detail'] ?? '');
        self::assertStringContainsString(self::SETTING, $detail);
        self::assertStringContainsString('not a valid JSON Schema', $detail);
        $this->assertNotRated($result);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function badBodies(): iterable
    {
        yield 'malformed JSON' => ['{"score": 4'];
        yield 'list' => ['[4]'];
        yield 'scalar' => ['4'];
    }

    #[DataProvider('badBodies')]
    public function testBodyThatIsNoJsonObjectAnswers400(string $body): void
    {
        $this->configureSchema(self::SCHEMA);
        $result = $this->evalResult();

        $response = $this->rate($result, $body);

        self::assertSame(400, $response->getStatusCode(), $response->getContent(false));
        $this->assertNotRated($result);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function rolesWithoutRate(): iterable
    {
        yield 'user' => [self::ROLES_USER];
        yield 'eval admin' => [self::ROLES_EVAL_ADMIN];
    }

    #[DataProvider('rolesWithoutRate')]
    public function testWithoutReviewerRoleRateIsForbidden(string $roles): void
    {
        $this->configureSchema(self::SCHEMA);
        $result = $this->evalResult();

        $response = $this->rate($result, ['score' => 4], $roles);

        self::assertSame(403, $response->getStatusCode(), $response->getContent(false));
        $this->assertNotRated($result);
    }

    public function testUnknownResultAnswers404(): void
    {
        $this->configureSchema(self::SCHEMA);

        $response = $this->rate('00000000-0000-4000-8000-000000000000', ['score' => 4]);

        self::assertSame(404, $response->getStatusCode(), $response->getContent(false));
    }

    // --- assertions -----------------------------------------------------

    private function assertNotRated(string $resultId): void
    {
        $row = $this->resultRow($resultId);
        self::assertNull($row['human_rating']);
        self::assertNull($row['human_rated_by']);
        self::assertNull($row['human_rated_at']);
    }

    // --- fixtures -------------------------------------------------------

    /**
     * Sets `evaluation.human_rating_schema` as the default test identity
     * (`test-admin`, ROLE_ADMIN). The settings row is removed by cleanUp(),
     * so the merge patch starts from the default (null).
     *
     * @param array<string, mixed> $schema
     */
    private function configureSchema(array $schema): void
    {
        InMemoryEvaluationSettings::configure(['human_rating_schema' => $schema]);
    }

    /**
     * A completed single-execution result of a new question; returns its id.
     */
    private function evalResult(): string
    {
        $entityManager = $this->entityManager();
        $question = new EvalQuestion()
            ->setKey(self::PREFIX.bin2hex(random_bytes(4)))
            ->setQuestion('What is the maintenance interval of the hydraulic pump?')
            ->setExpectedAnswer('Every 500 operating hours, at least once a year.');
        $entityManager->persist($question);

        $result = new EvalResult()
            ->setEvalQuestion($question)
            ->setSource(EvalResultSource::SINGLE)
            ->setStatus(EvalResultStatus::COMPLETED)
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

    /**
     * @param array<string, mixed>|string $rating a document, or the raw body
     */
    private function rate(string $resultId, array|string $rating, string $roles = self::ROLES_REVIEWER, string $user = self::REVIEWER): ResponseInterface
    {
        return static::createClient()->request('POST', '/api/eval_results/'.$resultId.'/rate', [
            'headers' => [
                'Accept' => 'application/ld+json',
                'Content-Type' => 'application/json',
                TestHeaderAuthenticator::HEADER_ROLES => $roles,
                TestHeaderAuthenticator::HEADER_USER => $user,
            ],
            'body' => \is_string($rating) ? $rating : json_encode($rating, \JSON_THROW_ON_ERROR),
        ]);
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
     * Results of the test questions, the questions, the `evaluation`
     * settings row with the settings audit entries.
     */
    private static function cleanUp(): void
    {
        static::bootKernel();
        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        $questions = ['prefix' => self::PREFIX.'%'];

        $connection->executeStatement('DELETE FROM neuron_eval_result WHERE eval_question_id IN (SELECT id FROM neuron_eval_question WHERE key LIKE :prefix)', $questions);
        $connection->executeStatement('DELETE FROM neuron_eval_question WHERE key LIKE :prefix', $questions);
        static::ensureKernelShutdown();
    }
}
