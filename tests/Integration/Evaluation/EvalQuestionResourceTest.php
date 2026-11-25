<?php

// file generated with AI assistance: Claude Code - 2026-10-04 17:23:51 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Evaluation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * `markers` and `negative` through `POST` and `PATCH /api/eval_questions`
 *: the rules of the import
 * ({@see \Dmstr\NeuronAiBundle\Validator\EvalQuestionRetrievalRules}) answer
 * 422 with the import's message on the property path, `negative` must be
 * a boolean (a type error answers 422 as well), `negative: true` with
 * markers is refused on POST and against the stored values on PATCH,
 * `markers: null` clears, a PATCH without the keys keeps them, the
 * trimmed markers are stored, and `ROLE_USER` stays forbidden.
 *
 * Test identities as documented in the test kernel's security configuration (the default identity
 * `test-admin` has ROLE_ADMIN, which includes ROLE_EVAL_ADMIN). Needs the
 * migrated test database; runs in CI.
 */
#[Group('database')]
final class EvalQuestionResourceTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const string PREFIX = 'test-eval-question-resource.';
    private const string ADMIN = 'ROLE_USER,ROLE_EVAL_ADMIN';
    private const string CONFLICT = '"negative": true cannot be combined with "markers" (a negative question expects no hits).';

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        parent::setUp();
        self::connection()->executeStatement('DELETE FROM neuron_eval_question WHERE key LIKE :prefix', ['prefix' => self::PREFIX.'%']);
    }

    protected function tearDown(): void
    {
        static::bootKernel();
        self::connection()->executeStatement('DELETE FROM neuron_eval_question WHERE key LIKE :prefix', ['prefix' => self::PREFIX.'%']);
        static::ensureKernelShutdown();
        parent::tearDown();
    }

    /**
     * Invalid marker lists with the expected message (the import's,
     * without `item N: `).
     *
     * @return iterable<string, array{0: list<mixed>, 1: string}>
     */
    public static function invalidMarkers(): iterable
    {
        yield 'empty list' => [[], '"markers" must not be an empty list (omit it or use null for no markers).'];
        yield '51 entries' => [array_map(static fn (int $i): string => 'marker '.$i, range(1, 51)), '"markers" must not have more than 50 entries (51 given).'];
        yield '201 characters' => [['ok', str_repeat('x', 201)], '"markers" entry 1 must not be longer than 200 characters.'];
        yield 'whitespace only' => [['ok', '   '], '"markers" entry 1 must be a non-empty string.'];
        yield 'duplicate after trimming' => [['Hydraulik', ' Hydraulik '], '"markers" entry 1 duplicates entry 0 ("Hydraulik").'];
        yield 'number entry' => [['ok', 5], '"markers" entry 1 must be a non-empty string.'];
        yield 'null entry' => [[null], '"markers" entry 0 must be a non-empty string.'];
        yield 'list entry' => [[['nested']], '"markers" entry 0 must be a non-empty string.'];
    }

    /**
     * @param list<mixed> $markers
     */
    #[DataProvider('invalidMarkers')]
    public function testPostRefusesInvalidMarkers(array $markers, string $message): void
    {
        $response = self::send('POST', '/api/eval_questions', self::payload('post') + ['markers' => $markers]);

        self::assertViolation($response, 'markers', $message);
        self::assertSame(0, self::rows());
    }

    /**
     * @param list<mixed> $markers
     */
    #[DataProvider('invalidMarkers')]
    public function testPatchRefusesInvalidMarkers(array $markers, string $message): void
    {
        $question = $this->question('patch', ['stored'], false);

        $response = self::send('PATCH', self::iri($question), ['markers' => $markers]);

        self::assertViolation($response, 'markers', $message);
        self::assertSame(['markers' => ['stored'], 'negative' => false], self::stored('patch'));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function nonBooleans(): iterable
    {
        yield 'string' => ['yes'];
        yield 'string true' => ['true'];
        yield 'number' => [1];
        yield 'zero' => [0];
    }

    #[DataProvider('nonBooleans')]
    public function testPostRefusesANegativeThatIsNoBoolean(mixed $negative): void
    {
        $response = self::send('POST', '/api/eval_questions', self::payload('post') + ['negative' => $negative]);

        self::assertViolation($response, 'negative');
        self::assertSame(0, self::rows());
    }

    #[DataProvider('nonBooleans')]
    public function testPatchRefusesANegativeThatIsNoBoolean(mixed $negative): void
    {
        $question = $this->question('patch', null, false);

        $response = self::send('PATCH', self::iri($question), ['negative' => $negative]);

        self::assertViolation($response, 'negative');
        self::assertSame(['markers' => null, 'negative' => false], self::stored('patch'));
    }

    public function testPostRefusesNegativeWithMarkers(): void
    {
        $response = self::send('POST', '/api/eval_questions', self::payload('post') + ['negative' => true, 'markers' => ['Hydraulikpumpe']]);

        self::assertViolation($response, 'negative', self::CONFLICT);
        self::assertSame(0, self::rows());
    }

    public function testPatchRefusesNegativeOnAQuestionWithStoredMarkers(): void
    {
        $question = $this->question('patch', ['Hydraulikpumpe'], false);

        $response = self::send('PATCH', self::iri($question), ['negative' => true]);

        self::assertViolation($response, 'negative', self::CONFLICT);
        self::assertSame(['markers' => ['Hydraulikpumpe'], 'negative' => false], self::stored('patch'));
    }

    public function testPatchRefusesMarkersOnAStoredNegativeQuestion(): void
    {
        $question = $this->question('patch', null, true);

        $response = self::send('PATCH', self::iri($question), ['markers' => ['Hydraulikpumpe']]);

        self::assertViolation($response, 'markers', self::CONFLICT);
        self::assertSame(['markers' => null, 'negative' => true], self::stored('patch'));
    }

    public function testPatchSwitchesANegativeQuestionToMarkersInOneRequest(): void
    {
        $question = $this->question('patch', null, true);

        $response = self::send('PATCH', self::iri($question), ['negative' => false, 'markers' => ['Hydraulikpumpe']]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Hydraulikpumpe'], $response->toArray()['markers']);
        self::assertFalse($response->toArray()['negative']);
        self::assertSame(['markers' => ['Hydraulikpumpe'], 'negative' => false], self::stored('patch'));
    }

    public function testPostStoresTrimmedMarkers(): void
    {
        $response = self::send('POST', '/api/eval_questions', self::payload('post') + ['markers' => [' Hydraulikpumpe ', "\tÖlwechsel\n"], 'negative' => false]);

        self::assertResponseStatusCodeSame(201);
        $data = $response->toArray();
        self::assertSame(['Hydraulikpumpe', 'Ölwechsel'], $data['markers']);
        self::assertFalse($data['negative']);
        self::assertSame(['markers' => ['Hydraulikpumpe', 'Ölwechsel'], 'negative' => false], self::stored('post'));
    }

    public function testPostCreatesANegativeQuestion(): void
    {
        $response = self::send('POST', '/api/eval_questions', self::payload('post') + ['negative' => true, 'markers' => null]);

        self::assertResponseStatusCodeSame(201);
        self::assertTrue($response->toArray()['negative']);
        self::assertSame(['markers' => null, 'negative' => true], self::stored('post'));
    }

    public function testPatchStoresTrimmedMarkers(): void
    {
        $question = $this->question('patch', ['old'], false);

        $response = self::send('PATCH', self::iri($question), ['markers' => ['  new  ', 'other ']]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['new', 'other'], $response->toArray()['markers']);
        self::assertSame(['markers' => ['new', 'other'], 'negative' => false], self::stored('patch'));
    }

    public function testPatchWithNullClearsTheMarkers(): void
    {
        $question = $this->question('patch', ['Hydraulikpumpe'], false);

        $response = self::send('PATCH', self::iri($question), ['markers' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertNull($response->toArray()['markers']);
        self::assertSame(['markers' => null, 'negative' => false], self::stored('patch'));

        // Without markers the question may become negative.
        self::send('PATCH', self::iri($question), ['negative' => true]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['markers' => null, 'negative' => true], self::stored('patch'));
    }

    public function testPatchClearsMarkersAndSetsNegativeInOneRequest(): void
    {
        $question = $this->question('patch', ['Hydraulikpumpe'], false);

        self::send('PATCH', self::iri($question), ['markers' => null, 'negative' => true]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['markers' => null, 'negative' => true], self::stored('patch'));
    }

    public function testPatchWithoutTheKeysKeepsTheStoredValues(): void
    {
        $withMarkers = $this->question('markers', ['Hydraulikpumpe'], false);
        $negative = $this->question('negative', null, true);

        $response = self::send('PATCH', self::iri($withMarkers), ['expectedAnswer' => 'Changed answer']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Hydraulikpumpe'], $response->toArray()['markers']);
        self::assertSame(['markers' => ['Hydraulikpumpe'], 'negative' => false], self::stored('markers'));

        $response = self::send('PATCH', self::iri($negative), ['status' => 'archived']);
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($response->toArray()['negative']);
        self::assertSame(['markers' => null, 'negative' => true], self::stored('negative'));
    }

    public function testUserMayNotWriteTheRetrievalFields(): void
    {
        $question = $this->question('forbidden', ['Hydraulikpumpe'], false);

        self::send('POST', '/api/eval_questions', self::payload('created') + ['markers' => ['x']], 'ROLE_USER');
        self::assertResponseStatusCodeSame(403);

        self::send('PATCH', self::iri($question), ['markers' => null, 'negative' => true], 'ROLE_USER');
        self::assertResponseStatusCodeSame(403);

        self::assertSame(1, self::rows());
        self::assertSame(['markers' => ['Hydraulikpumpe'], 'negative' => false], self::stored('forbidden'));
    }

    /**
     * A 422 with a violation on the path, with the message when given.
     */
    private static function assertViolation(ResponseInterface $response, string $path, ?string $message = null): void
    {
        self::assertResponseStatusCodeSame(422);
        $violations = $response->toArray(false)['violations'] ?? [];
        $onPath = array_values(array_filter($violations, static fn (array $violation): bool => $path === ($violation['propertyPath'] ?? null)));
        self::assertNotSame([], $onPath, (string) $response->getContent(false));
        if (null !== $message) {
            self::assertContains($message, array_column($onPath, 'message'), (string) $response->getContent(false));
        }
    }

    /**
     * One request with a fresh client as an evaluation admin unless roles
     * are given.
     *
     * @param array<string, mixed> $json
     */
    private static function send(string $method, string $uri, array $json, string $roles = self::ADMIN): ResponseInterface
    {
        return static::createClient()->request($method, $uri, [
            'headers' => [
                'Accept' => 'application/ld+json',
                'Content-Type' => 'PATCH' === $method ? 'application/merge-patch+json' : 'application/ld+json',
                TestHeaderAuthenticator::HEADER_ROLES => $roles,
            ],
            'json' => $json,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function payload(string $suffix): array
    {
        return [
            'key' => self::PREFIX.$suffix,
            'question' => 'What is the maintenance interval?',
            'expectedAnswer' => 'Every 500 operating hours.',
        ];
    }

    /**
     * @param list<string>|null $markers
     */
    private function question(string $suffix, ?array $markers, bool $negative): EvalQuestion
    {
        $question = (new EvalQuestion())
            ->setKey(self::PREFIX.$suffix)
            ->setQuestion('Question '.$suffix)
            ->setExpectedAnswer('Answer '.$suffix)
            ->setMarkers($markers)
            ->setNegative($negative);
        self::entityManager()->persist($question);
        self::entityManager()->flush();

        return $question;
    }

    private static function iri(EvalQuestion $question): string
    {
        return '/api/eval_questions/'.$question->getId();
    }

    /**
     * The stored retrieval fields of a test question, read past the
     * entity manager.
     *
     * @return array{markers: mixed, negative: bool|null}
     */
    private static function stored(string $suffix): array
    {
        $row = self::connection()->fetchAssociative('SELECT markers, negative FROM neuron_eval_question WHERE key = :key', ['key' => self::PREFIX.$suffix]);
        self::assertIsArray($row, 'question '.$suffix.' not found');

        return [
            'markers' => null === $row['markers'] ? null : json_decode((string) $row['markers'], true, 512, \JSON_THROW_ON_ERROR),
            'negative' => null === $row['negative'] ? null : (bool) $row['negative'],
        ];
    }

    private static function rows(): int
    {
        return (int) self::connection()->fetchOne('SELECT COUNT(*) FROM neuron_eval_question WHERE key LIKE :prefix', ['prefix' => self::PREFIX.'%']);
    }

    private static function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    private static function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');

        return $connection;
    }
}
