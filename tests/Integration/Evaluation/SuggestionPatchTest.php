<?php

// file generated with AI assistance: Claude Code - 2026-10-03 20:23:53 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Evaluation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Entity\EvalResult;
use Dmstr\NeuronAiBundle\Entity\Evaluation;
use Dmstr\NeuronAiBundle\Entity\Suggestion;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultSource;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvaluationStatus;
use Dmstr\NeuronAiBundle\Evaluation\SuggestionPriority;
use Dmstr\NeuronAiBundle\Evaluation\SuggestionStatus;
use Dmstr\NeuronAiBundle\Evaluation\SuggestionType;
use Dmstr\NeuronAiBundle\Evaluation\Verdict;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The judge's evaluations and suggestions over the API:
 * `ROLE_USER` reads both, `ROLE_EVAL_ADMIN` (directly or through
 * `ROLE_ADMIN`) patches the `status` and `assignee` of a suggestion, an
 * unknown status or an overlong assignee answers 422, `ROLE_USER` and
 * `ROLE_EVAL_REVIEWER` get 403, and the judge's fields of a suggestion stay
 * unchanged whatever the PATCH body contains (ignored, not rejected).
 *
 * The question, result, evaluation and suggestions are created directly
 * through the EntityManager. Test identities as documented in
 * the test kernel's security configuration (`X-Test-Roles`). Needs the migrated test database; runs
 * in CI.
 */
#[Group('database')]
final class SuggestionPatchTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const string PREFIX = 'test-suggestion-patch.';

    private Evaluation $evaluation;

    private Suggestion $suggestion;

    private Suggestion $other;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        $this->cleanUp();
        static::bootKernel();
        $this->fixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function evalAdmins(): iterable
    {
        yield 'ROLE_EVAL_ADMIN' => ['ROLE_USER,ROLE_EVAL_ADMIN'];
        yield 'ROLE_ADMIN through the map' => [null];
    }

    #[DataProvider('evalAdmins')]
    public function testEvalAdminPatchesStatusAndAssignee(?string $roles): void
    {
        $response = self::send('PATCH', self::iri($this->suggestion), $roles, ['status' => SuggestionStatus::FIXED, 'assignee' => 'editor@example.org']);

        self::assertResponseStatusCodeSame(200);
        $data = $response->toArray();
        self::assertSame(SuggestionStatus::FIXED, $data['status']);
        self::assertSame('editor@example.org', $data['assignee']);
        self::assertSame(['fixed', 'editor@example.org'], $this->stored($this->suggestion, ['status', 'assignee']));

        self::send('PATCH', self::iri($this->suggestion), $roles, ['assignee' => null]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['fixed', null], $this->stored($this->suggestion, ['status', 'assignee']));

        // The other suggestion of the evaluation is untouched.
        self::assertSame(['open', null], $this->stored($this->other, ['status', 'assignee']));
    }

    public function testReadOnlyFieldsStayUnchanged(): void
    {
        $before = $this->stored($this->suggestion, ['evaluation_id', 'type', 'category', 'description', 'action', 'priority', 'created_at']);

        $response = self::send('PATCH', self::iri($this->suggestion), 'ROLE_USER,ROLE_EVAL_ADMIN', [
            'status' => SuggestionStatus::WONTFIX,
            'evaluation' => '/api/evaluations/'.$this->evaluation->getId(),
            'type' => SuggestionType::PROMPT,
            'category' => 'changed-category',
            'description' => 'Changed description',
            'action' => 'Changed action',
            'priority' => SuggestionPriority::LOW,
            'createdAt' => '2001-01-01T00:00:00+00:00',
        ]);

        self::assertResponseStatusCodeSame(200);
        $data = $response->toArray();
        self::assertSame(SuggestionStatus::WONTFIX, $data['status']);
        self::assertSame(SuggestionType::DATA, $data['type']);
        self::assertSame('missing-document', $data['category']);
        self::assertSame(SuggestionPriority::HIGH, $data['priority']);
        self::assertSame($before, $this->stored($this->suggestion, ['evaluation_id', 'type', 'category', 'description', 'action', 'priority', 'created_at']));
        self::assertSame('wontfix', $this->stored($this->suggestion, ['status'])[0]);
    }

    public function testUnknownStatusIsUnprocessable(): void
    {
        $response = self::send('PATCH', self::iri($this->suggestion), 'ROLE_USER,ROLE_EVAL_ADMIN', ['status' => 'done']);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('status', array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'));
        self::assertSame(['open'], $this->stored($this->suggestion, ['status']));
    }

    public function testOverlongAssigneeIsUnprocessable(): void
    {
        $response = self::send('PATCH', self::iri($this->suggestion), 'ROLE_USER,ROLE_EVAL_ADMIN', ['assignee' => str_repeat('a', Suggestion::MAX_ASSIGNEE_LENGTH + 1)]);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('assignee', array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'));
        self::assertSame([null], $this->stored($this->suggestion, ['assignee']));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function rolesWithoutPatch(): iterable
    {
        yield 'user' => ['ROLE_USER'];
        yield 'reviewer' => ['ROLE_USER,ROLE_EVAL_REVIEWER'];
    }

    #[DataProvider('rolesWithoutPatch')]
    public function testPatchWithoutEvalAdminIsForbidden(string $roles): void
    {
        self::send('PATCH', self::iri($this->suggestion), $roles, ['status' => SuggestionStatus::FIXED, 'assignee' => 'someone']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['open', null], $this->stored($this->suggestion, ['status', 'assignee']));
    }

    public function testUserReadsEvaluationsAndSuggestions(): void
    {
        $evaluationIri = '/api/evaluations/'.$this->evaluation->getId();
        $resultIri = '/api/eval_results/'.$this->evaluation->getEvalResult()->getId();

        $evaluation = self::send('GET', $evaluationIri, 'ROLE_USER')->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame($resultIri, $evaluation['evalResult']);
        self::assertSame(EvaluationStatus::COMPLETED, $evaluation['status']);
        self::assertSame(Verdict::MOSTLY_PASS, $evaluation['verdict']);
        self::assertSame('Mostly right, the interval is missing.', $evaluation['reasoning']);
        self::assertEqualsCanonicalizing([self::iri($this->suggestion), self::iri($this->other)], $evaluation['suggestions']);

        $evaluations = self::send('GET', '/api/evaluations?evalResult='.urlencode($resultIri), 'ROLE_USER')->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$evaluationIri], array_column(self::members($evaluations), '@id'));

        $byVerdict = self::send('GET', '/api/evaluations?verdict='.Verdict::MOSTLY_PASS.'&evalResult='.urlencode($resultIri), 'ROLE_USER')->toArray();
        self::assertCount(1, self::members($byVerdict));

        $suggestion = self::send('GET', self::iri($this->suggestion), 'ROLE_USER')->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame($evaluationIri, $suggestion['evaluation']);
        self::assertSame(SuggestionStatus::OPEN, $suggestion['status']);
        self::assertSame('Add the maintenance manual.', $suggestion['action']);

        $suggestions = self::send('GET', '/api/suggestions?evaluation='.urlencode($evaluationIri), 'ROLE_USER')->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertCount(2, self::members($suggestions));

        $prompts = self::send('GET', '/api/suggestions?evaluation='.urlencode($evaluationIri).'&type='.SuggestionType::PROMPT, 'ROLE_USER')->toArray();
        self::assertSame([self::iri($this->other)], array_column(self::members($prompts), '@id'));

        $result = self::send('GET', $resultIri, 'ROLE_USER')->toArray();
        self::assertResponseStatusCodeSame(200);
        self::assertSame($evaluationIri, $result['evaluation']);
        // Unrated: JSON-LD skips null values, so the rating keys are absent or null.
        self::assertNull($result['humanRating'] ?? null);
        self::assertNull($result['humanRatedBy'] ?? null);
        self::assertNull($result['humanRatedAt'] ?? null);
    }

    /**
     * One question with a single-execution result, its completed evaluation
     * (also the result's latest) and two open suggestions.
     */
    private function fixtures(): void
    {
        $em = self::entityManager();

        $question = (new EvalQuestion())
            ->setKey(self::PREFIX.'question')
            ->setQuestion('What is the maintenance interval?')
            ->setExpectedAnswer('Every 500 operating hours.');
        $result = (new EvalResult())
            ->setEvalQuestion($question)
            ->setSource(EvalResultSource::SINGLE)
            ->setStatus(EvalResultStatus::COMPLETED);
        $em->persist($question);
        $em->persist($result);
        $em->flush();

        $this->evaluation = (new Evaluation())
            ->setEvalResult($result)
            ->setStatus(EvaluationStatus::COMPLETED)
            ->setVerdict(Verdict::MOSTLY_PASS)
            ->setReasoning('Mostly right, the interval is missing.')
            ->setFinishedAt(new \DateTimeImmutable());
        $this->suggestion = (new Suggestion())
            ->setEvaluation($this->evaluation)
            ->setType(SuggestionType::DATA)
            ->setCategory('missing-document')
            ->setDescription('The maintenance manual is not indexed.')
            ->setAction('Add the maintenance manual.')
            ->setPriority(SuggestionPriority::HIGH);
        $this->other = (new Suggestion())
            ->setEvaluation($this->evaluation)
            ->setType(SuggestionType::PROMPT)
            ->setCategory('instructions')
            ->setDescription('The agent does not name the interval.')
            ->setAction('Ask for exact numbers in the instructions.')
            ->setPriority(SuggestionPriority::LOW);
        $em->persist($this->evaluation);
        $em->persist($this->suggestion);
        $em->persist($this->other);
        $em->flush();

        $result->setEvaluation($this->evaluation);
        $em->flush();
        $em->clear();
    }

    /**
     * Removes the test question with its results; evaluations and
     * suggestions go with the results (ON DELETE CASCADE).
     */
    private function cleanUp(): void
    {
        static::bootKernel();
        $connection = self::connection();
        $questions = ['prefix' => self::PREFIX.'%'];
        $connection->executeStatement('DELETE FROM neuron_eval_result WHERE eval_question_id IN (SELECT id FROM neuron_eval_question WHERE key LIKE :prefix)', $questions);
        $connection->executeStatement('DELETE FROM neuron_eval_question WHERE key LIKE :prefix', $questions);
        static::ensureKernelShutdown();
    }

    /**
     * One request with a fresh client; null roles keep the default test
     * identity (`test-admin`, ROLE_ADMIN).
     *
     * @param array<string, mixed>|null $json
     */
    private static function send(string $method, string $uri, ?string $roles, ?array $json = null): ResponseInterface
    {
        $headers = ['Accept' => 'application/ld+json'];
        if (null !== $roles) {
            $headers[TestHeaderAuthenticator::HEADER_ROLES] = $roles;
        }
        $options = ['headers' => $headers];
        if (null !== $json) {
            $options['headers']['Content-Type'] = 'PATCH' === $method ? 'application/merge-patch+json' : 'application/ld+json';
            $options['json'] = $json;
        }

        return static::createClient()->request($method, $uri, $options);
    }

    private static function iri(Suggestion $suggestion): string
    {
        return '/api/suggestions/'.$suggestion->getId();
    }

    /**
     * @param array<string, mixed> $collection
     *
     * @return list<array<string, mixed>>
     */
    private static function members(array $collection): array
    {
        return $collection['member'] ?? $collection['hydra:member'] ?? [];
    }

    /**
     * The stored values of the given columns of the suggestion row.
     *
     * @param list<string> $columns
     *
     * @return list<mixed>
     */
    private function stored(Suggestion $suggestion, array $columns): array
    {
        return array_values(self::connection()->fetchAssociative(
            'SELECT '.implode(', ', $columns).' FROM neuron_suggestion WHERE id = :id',
            ['id' => (string) $suggestion->getId()],
        ) ?: []);
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
