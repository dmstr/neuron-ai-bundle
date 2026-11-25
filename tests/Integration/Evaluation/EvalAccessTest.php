<?php

// file generated with AI assistance: Claude Code - 2026-10-03 19:07:47 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Evaluation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Anonymous\CapabilityToken;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Access matrix of the evaluation resources on the api
 * firewall: anonymous requests 401, `ROLE_USER` reads questions, runs and
 * results but may not write questions (403), `ROLE_EVAL_ADMIN` (directly or
 * through `ROLE_ADMIN`) creates, edits and deletes them,
 * `ROLE_EVAL_REVIEWER` alone grants no writes, a duplicate key answers 422
 * and a capability-token holder gets 403.
 *
 * Test identities as documented in the test kernel's security configuration (`X-Test-Roles` replaces
 * the roles of the default identity `test-admin` with `ROLE_ADMIN`,
 * `X-Test-Auth: anonymous` drops it). Needs the migrated test database;
 * runs in CI, except {@see testEvaluationPathsAreOnTheApiFirewall()}, which
 * only needs the kernel.
 */
#[Group('database')]
final class EvalAccessTest extends ApiTestCase
{
    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
    }

    protected static ?bool $alwaysBootKernel = true;

    private const string PREFIX = 'test-eval-access.';
    private const string TOKEN = 'anon_RXZhbC1hY2Nlc3MtbWF0cml4LXRva2Vu';

    private bool $usesDatabase = false;

    private ?Conversation $conversation = null;

    protected function tearDown(): void
    {
        if ($this->usesDatabase) {
            $this->cleanUp();
        }
        parent::tearDown();
    }

    /**
     * /api/evaluations and /api/suggestions and /api/feedback
     * are routed now; the check stays on the firewall map, not
     * over HTTP, because it needs no database, and because the router
     * listener runs before the firewall, so a path without a route would
     * answer 404 on any firewall.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function firewallPaths(): iterable
    {
        foreach (['/api/eval_questions', '/api/eval_questions/x/execute', '/api/eval_runs', '/api/eval_runs/start', '/api/eval_results', '/api/evaluations', '/api/suggestions', '/api/feedback'] as $path) {
            yield $path => [$path, 'api'];
        }
        yield '/api/docs is on the api firewall' => ['/api/docs', 'api'];
        yield '/api/contexts is on the api firewall' => ['/api/contexts/EvalQuestion', 'api'];
    }

    #[DataProvider('firewallPaths')]
    public function testEvaluationPathsAreOnTheApiFirewall(string $path, string $firewall): void
    {
        $map = static::getContainer()->get('security.firewall.map');
        self::assertInstanceOf(FirewallMap::class, $map);

        self::assertSame($firewall, $map->getFirewallConfig(Request::create($path))?->getName());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function lists(): iterable
    {
        yield 'questions' => ['/api/eval_questions'];
        yield 'runs' => ['/api/eval_runs'];
        yield 'results' => ['/api/eval_results'];
    }

    #[DataProvider('lists')]
    public function testAnonymousRequestsAreUnauthorized(string $uri): void
    {
        $this->useDatabase();
        static::createClient()->request('GET', $uri, ['headers' => [
            'Accept' => 'application/ld+json',
            TestHeaderAuthenticator::HEADER_AUTH => 'anonymous',
        ]]);

        self::assertResponseStatusCodeSame(401);
    }

    #[DataProvider('lists')]
    public function testUserReadsTheLists(string $uri): void
    {
        $this->useDatabase();
        self::send('GET', $uri, 'ROLE_USER');

        self::assertResponseStatusCodeSame(200);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function rolesWithoutWrites(): iterable
    {
        yield 'user' => ['ROLE_USER'];
        yield 'reviewer' => ['ROLE_USER,ROLE_EVAL_REVIEWER'];
        yield 'agent admin' => ['ROLE_USER,ROLE_AGENT_ADMIN'];
    }

    #[DataProvider('rolesWithoutWrites')]
    public function testWritesWithoutEvalAdminAreForbidden(string $roles): void
    {
        $this->useDatabase();
        $question = $this->question('forbidden');

        self::send('GET', self::iri($question), $roles);
        self::assertResponseStatusCodeSame(200);

        self::send('POST', '/api/eval_questions', $roles, self::payload('created'));
        self::assertResponseStatusCodeSame(403);

        self::send('PATCH', self::iri($question), $roles, ['question' => 'Changed']);
        self::assertResponseStatusCodeSame(403);

        self::send('DELETE', self::iri($question), $roles);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(1, self::rows());
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
    public function testEvalAdminCreatesEditsAndDeletes(?string $roles): void
    {
        $this->useDatabase();

        $created = self::send('POST', '/api/eval_questions', $roles, self::payload('created') + ['requestData' => ['vehicle' => 'Typ 4']]);
        self::assertResponseStatusCodeSame(201);
        $data = $created->toArray();
        self::assertSame(self::PREFIX.'created', $data['key']);
        self::assertSame('active', $data['status']);
        $iri = (string) $data['@id'];

        $patched = self::send('PATCH', $iri, $roles, ['status' => 'archived', 'expectedAnswer' => 'Changed answer']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('archived', $patched->toArray()['status']);
        self::assertSame('Changed answer', $patched->toArray()['expectedAnswer']);

        self::send('DELETE', $iri, $roles);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, self::rows());
    }

    public function testDuplicateKeyIsUnprocessable(): void
    {
        $this->useDatabase();
        $this->question('taken');

        $response = self::send('POST', '/api/eval_questions', 'ROLE_USER,ROLE_EVAL_ADMIN', self::payload('taken'));

        self::assertResponseStatusCodeSame(422);
        self::assertContains('key', array_column($response->toArray(false)['violations'] ?? [], 'propertyPath'));
        self::assertSame(1, self::rows());
    }

    #[DataProvider('lists')]
    public function testCapabilityTokenHolderIsForbidden(string $uri): void
    {
        $this->useDatabase();
        $this->anonymousConversation();

        static::createClient()->request('GET', $uri, ['headers' => [
            'Accept' => 'application/ld+json',
            TestHeaderAuthenticator::HEADER_AUTH => 'anonymous',
            'Authorization' => 'Bearer '.self::TOKEN,
        ]]);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Marks the test as using the database (cleaned up in tearDown) and
     * removes test questions a previous, aborted run may have left.
     */
    private function useDatabase(): void
    {
        $this->usesDatabase = true;
        self::connection()->executeStatement('DELETE FROM neuron_eval_question WHERE key LIKE :prefix', ['prefix' => self::PREFIX.'%']);
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

    /**
     * @return array<string, string>
     */
    private static function payload(string $suffix): array
    {
        return [
            'key' => self::PREFIX.$suffix,
            'question' => 'What is the maintenance interval',
            'expectedAnswer' => 'Every 500 operating hours.',
        ];
    }

    private function question(string $suffix): EvalQuestion
    {
        $question = (new EvalQuestion())
            ->setKey(self::PREFIX.$suffix)
            ->setQuestion('Question '.$suffix)
            ->setExpectedAnswer('Answer '.$suffix);
        self::entityManager()->persist($question);
        self::entityManager()->flush();

        return $question;
    }

    private static function iri(EvalQuestion $question): string
    {
        return '/api/eval_questions/'.$question->getId();
    }

    /**
     * An anonymous conversation whose capability token is self::TOKEN.
     */
    private function anonymousConversation(): void
    {
        $conversation = new Conversation();
        $conversation
            ->setOwnerSubject(Conversation::ANONYMOUS_OWNER_PREFIX.$conversation->getId()->toRfc4122())
            ->setAnonymous(true)
            ->setTokenHash(CapabilityToken::hash(self::TOKEN))
            ->setAddressHash(hash('sha256', 'eval-access'))
            ->setProviderModel(ConversationConnections::model())
            ->setRetrievalDepth(4);
        self::entityManager()->persist($conversation);
        self::entityManager()->flush();
        $this->conversation = $conversation;
    }

    private static function rows(): int
    {
        return (int) self::connection()->fetchOne('SELECT COUNT(*) FROM neuron_eval_question WHERE key LIKE :prefix', ['prefix' => self::PREFIX.'%']);
    }

    /**
     * Removes the test questions and the conversation of the token holder
     * with its audit entries.
     */
    private function cleanUp(): void
    {
        static::bootKernel();
        $connection = self::connection();
        $connection->executeStatement('DELETE FROM neuron_eval_question WHERE key LIKE :prefix', ['prefix' => self::PREFIX.'%']);
        if (null !== $this->conversation) {
            $id = (string) $this->conversation->getId();
            $connection->executeStatement('DELETE FROM neuron_conversation WHERE id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM dmstr_log_entries WHERE object_class = :class AND object_id = :id', ['class' => Conversation::class, 'id' => $id]);
            $this->conversation = null;
        }
        static::ensureKernelShutdown();
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
