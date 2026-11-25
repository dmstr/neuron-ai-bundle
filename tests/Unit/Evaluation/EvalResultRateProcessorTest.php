<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:46:34 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Evaluation;

use ApiPlatform\Metadata\Post;
use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Entity\EvalResult;
use Dmstr\NeuronAiBundle\Evaluation\EvaluationSettingsInterface;
use Dmstr\NeuronAiBundle\State\EvalResultRateProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Kernel-free checks of `POST /eval_results/{id}/rate`
 * ({@see EvalResultRateProcessor}): body decoding (400 for malformed JSON
 * and non-objects), the 422 refusals naming the setting (unset, invalid
 * schema at the top level and nested), the validation errors with their
 * JSON pointers (an extra property under `additionalProperties: false` at
 * its own pointer, also nested), and on success the stored rating, author
 * and time with one flush, a new rating replacing the old one. Entity
 * manager and settings are test doubles; the HTTP path, security and
 * persistence run in EvalResultRateTest (CI).
 */
final class EvalResultRateProcessorTest extends TestCase
{
    private const string NOW = '2026-10-03 12:00:00';

    /** @var array<string, mixed> */
    private const array SCHEMA = [
        'type' => 'object',
        'required' => ['score'],
        'additionalProperties' => false,
        'properties' => [
            'score' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
            'details' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => ['sources' => ['type' => 'boolean']],
            ],
            'comment' => ['type' => 'string'],
        ],
    ];

    /** @var array<string, mixed>|null */
    private ?array $schema = self::SCHEMA;

    private int $flushes = 0;

    public function testValidRatingIsStoredWithAuthorAndTime(): void
    {
        $result = self::evalResult();

        $returned = $this->process($result, '{"score": 4, "details": {"sources": false}, "comment": "ok"}');

        self::assertSame($result, $returned);
        self::assertSame(['score' => 4, 'details' => ['sources' => false], 'comment' => 'ok'], $result->getHumanRating());
        self::assertSame('reviewer-a', $result->getHumanRatedBy());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $result->getHumanRatedAt());
        self::assertSame(1, $this->flushes);

        $this->process($result, '{"score": 1}', 'reviewer-b');
        self::assertSame(['score' => 1], $result->getHumanRating());
        self::assertSame('reviewer-b', $result->getHumanRatedBy());
        self::assertSame(2, $this->flushes);
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function nonMatching(): iterable
    {
        yield 'extra property' => ['{"score": 3, "extra": 1}', ['/extra']];
        yield 'two extra properties' => ['{"score": 3, "a": 1, "b": 2}', ['/a', '/b']];
        yield 'nested extra property' => ['{"score": 3, "details": {"sources": true, "x": 1}}', ['/details/x']];
        yield 'wrong type' => ['{"score": "3"}', ['/score']];
        yield 'missing required' => ['{"comment": "x"}', ['/']];
    }

    /**
     * @param list<string> $pointers
     */
    #[DataProvider('nonMatching')]
    public function testNonMatchingRatingIsRejectedWithPointers(string $body, array $pointers): void
    {
        $result = self::evalResult();

        try {
            $this->process($result, $body);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
            $paths = [];
            foreach ($e->getConstraintViolationList() as $violation) {
                $paths[] = $violation->getPropertyPath();
                self::assertNotSame('', (string) $violation->getMessage());
            }
            self::assertSame($pointers, $paths);
        }

        self::assertNull($result->getHumanRating());
        self::assertNull($result->getHumanRatedBy());
        self::assertSame(0, $this->flushes);
    }

    public function testExtraPropertyMessageNamesTheProperty(): void
    {
        $errors = EvalResultRateProcessor::errors(EvalResultRateProcessor::decode('{"score": 3, "extra": 1}'), EvalResultRateProcessor::schemaObject(self::SCHEMA));

        self::assertSame([['/extra', 'The property "extra" is not allowed.']], $errors);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>|null, 1: string}>
     */
    public static function unusableSchemas(): iterable
    {
        yield 'unset' => [null, 'No human rating schema is configured'];
        yield 'empty' => [[], 'No human rating schema is configured'];
        yield 'invalid type' => [['type' => 'no-such-type'], 'not a valid JSON Schema'];
        yield 'invalid nested keyword' => [['type' => 'object', 'properties' => ['score' => ['minimum' => 'one']]], 'not a valid JSON Schema'];
    }

    /**
     * @param array<string, mixed>|null $schema
     */
    #[DataProvider('unusableSchemas')]
    public function testUnusableSchemaAnswers422NamingTheSetting(?array $schema, string $message): void
    {
        $this->schema = $schema;
        $result = self::evalResult();

        try {
            $this->process($result, '{"score": 4}');
            self::fail('Expected an UnprocessableEntityHttpException.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertStringContainsString($message, $e->getMessage());
            self::assertStringContainsString('evaluation.human_rating_schema', $e->getMessage());
        }

        self::assertNull($result->getHumanRating());
        self::assertSame(0, $this->flushes);
    }

    public function testWithoutSettingsServiceAnswers422(): void
    {
        $processor = new EvalResultRateProcessor($this->entityManager(), $this->tokenStorage('reviewer-a'), new MockClock(self::NOW));

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('evaluation.human_rating_schema');
        $processor->process(self::evalResult(), new Post(), [], ['request' => self::request('{"score": 4}')]);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function badBodies(): iterable
    {
        yield 'malformed' => ['{"score": 4'];
        yield 'empty' => [''];
        yield 'list' => ['[4]'];
        yield 'string' => ['"4"'];
        yield 'null' => ['null'];
    }

    #[DataProvider('badBodies')]
    public function testBodyThatIsNoJsonObjectAnswers400(string $body): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->process(self::evalResult(), $body);
    }

    public function testMissingResultIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->processor('reviewer-a')->process(null, new Post(), [], ['request' => self::request('{"score": 4}')]);
    }

    public function testEmptyObjectStaysAnObjectForValidation(): void
    {
        $this->schema = ['type' => 'object'];
        $result = self::evalResult();

        $this->process($result, '{}');

        self::assertSame([], $result->getHumanRating());
    }

    // --- helpers --------------------------------------------------------

    private function process(EvalResult $result, string $body, string $user = 'reviewer-a'): EvalResult
    {
        return $this->processor($user)->process($result, new Post(), [], ['request' => self::request($body)]);
    }

    private function processor(string $user): EvalResultRateProcessor
    {
        $schema = $this->schema;
        $settings = new class($schema) implements EvaluationSettingsInterface {
            /**
             * @param array<string, mixed>|null $schema
             */
            public function __construct(private readonly ?array $schema)
            {
            }

            public function judgeAgent(): ?string
            {
                return null;
            }

            public function humanRatingSchema(): ?array
            {
                return $this->schema;
            }

            public function feedbackPerSubjectAndHour(): int
            {
                return 30;
            }

            public function humanRatingComparison(): ?array
            {
                return null;
            }
        };

        return new EvalResultRateProcessor($this->entityManager(), $this->tokenStorage($user), new MockClock(self::NOW), $settings);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        return $entityManager;
    }

    private function tokenStorage(string $user): TokenStorage
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser($user, null, ['ROLE_EVAL_REVIEWER']), 'api', ['ROLE_EVAL_REVIEWER']));

        return $tokenStorage;
    }

    private static function request(string $body): Request
    {
        return Request::create('/api/eval_results/x/rate', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    }

    private static function evalResult(): EvalResult
    {
        return new EvalResult()->setEvalQuestion(new EvalQuestion()->setKey('q')->setQuestion('Q?')->setExpectedAnswer('A.'));
    }
}
