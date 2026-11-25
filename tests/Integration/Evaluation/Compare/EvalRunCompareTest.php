<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:11:43 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Evaluation\Compare;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Anonymous\CapabilityToken;
use Dmstr\NeuronAiBundle\Conversation\ConversationStatus;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Entity\EvalResult;
use Dmstr\NeuronAiBundle\Entity\EvalRun;
use Dmstr\NeuronAiBundle\Entity\Evaluation;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\Change;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\Presence;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\Reason;
use Dmstr\NeuronAiBundle\Evaluation\EvalQuestionStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultSource;
use Dmstr\NeuronAiBundle\Evaluation\EvalResultStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvalRunStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvaluationStatus;
use Dmstr\NeuronAiBundle\Evaluation\Verdict;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\ConversationConnections;
use Dmstr\NeuronAiBundle\Tests\Support\Port\InMemoryEvaluationSettings;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The run comparison: `GET /api/eval_runs/{id}/compare/{baselineId}`
 * compares the candidate run `{id}` with the baseline run `{baselineId}`.
 *
 * Covered, on fixtures written through the entity manager (no provider
 * call): the classification matrix (worse, equal, better; latest
 * evaluation pending, failed or missing is unrated; newly failed; only in
 * the baseline and only in the candidate; a changed question by
 * fingerprint and, for results without one, by the first user message,
 * including a deleted result conversation), the run references with agent
 * versions and `startedAt`, and the summary; a re-evaluation moves the
 * verdict; the human rating without the setting, with a number field and
 * with an ordered list, and the merge-patch behaviour of the setting
 * `evaluation.human_rating_comparison` (an object merged key by key, its
 * `values` list replaced whole, null removing a key, an incomplete entry
 * refused); 422 for a run that is not completed and the same run twice,
 * 404 for an unknown run; access: anonymous 401, `ROLE_USER` and a
 * capability-token holder 403 (while `ROLE_USER` reads the run itself),
 * both evaluation roles and `ROLE_ADMIN` 200.
 *
 * Questions and conversations carry the prefix {@see self::PREFIX} (the
 * questions are archived, so no batch picks them up), agents the prefix of
 * {@see AgentFixtures}; the `evaluation` settings row is removed before
 * and after every test. Needs the migrated test database (CI only),
 * except {@see self::testComparePathIsRoutedOnTheApiFirewall()}.
 */
#[Group('database')]
final class EvalRunCompareTest extends ApiTestCase
{
    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
    }

    protected static ?bool $alwaysBootKernel = true;

    private const string PREFIX = 'test-compare-';
    private const string AGENT_SLUG = AgentFixtures::PREFIX.'compare-';
    private const string TOKEN = 'anon_Y29tcGFyZS1hY2Nlc3MtdG9rZW4tdGVzdA';

    private const string ROLES_REVIEWER = 'ROLE_USER,ROLE_EVAL_REVIEWER';
    private const string ROLES_EVAL_ADMIN = 'ROLE_USER,ROLE_EVAL_ADMIN';
    private const string ROLES_USER = 'ROLE_USER';

    private const string QUESTION_TEXT = 'What is the maintenance interval of the hydraulic pump?';

    /** @var array<string, EvalQuestion> by key suffix */
    private array $questions = [];

    private bool $usesDatabase = false;

    protected function tearDown(): void
    {
        if ($this->usesDatabase) {
            self::cleanUp();
        }
        $this->questions = [];
        parent::tearDown();
    }

    /**
     * Kernel only: the path is routed to the operation and lies on the
     * `api` firewall, so the test kernel's security configuration needed no change.
     */
    public function testComparePathIsRoutedOnTheApiFirewall(): void
    {
        $path = '/api/eval_runs/0199aa00-0000-7000-8000-000000000001/compare/0199aa00-0000-7000-8000-000000000002';

        $map = static::getContainer()->get('security.firewall.map');
        self::assertInstanceOf(FirewallMap::class, $map);
        self::assertSame('api', $map->getFirewallConfig(Request::create($path))?->getName());

        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $router->getContext()->setMethod('GET');
        self::assertSame(EvalRun::OPERATION_COMPARE, $router->match($path)['_route']);
    }

    public function testFixtureMatrix(): void
    {
        $this->useDatabase();
        [$v1, $v2] = $this->versions();
        $baseline = $this->evalRun($v1, startedAt: '2026-10-01 08:00:00');
        $candidate = $this->evalRun($v2, startedAt: '2026-10-02 09:30:00');

        $this->pair('m01-worse', $baseline, $candidate, [[EvaluationStatus::COMPLETED, Verdict::PASS]], [[EvaluationStatus::COMPLETED, Verdict::MOSTLY_FAIL]]);
        $this->pair('m02-equal', $baseline, $candidate, [[EvaluationStatus::COMPLETED, Verdict::PASS]], [[EvaluationStatus::COMPLETED, Verdict::PASS]]);
        $this->pair('m03-better', $baseline, $candidate, [[EvaluationStatus::COMPLETED, Verdict::FAIL]], [[EvaluationStatus::COMPLETED, Verdict::MOSTLY_PASS]]);
        // An earlier completed verdict does not count once a newer evaluation is pending.
        $this->pair('m04-pending', $baseline, $candidate, [[EvaluationStatus::COMPLETED, Verdict::PASS]], [[EvaluationStatus::COMPLETED, Verdict::PASS], [EvaluationStatus::PENDING, null]]);
        $this->pair('m05-evaluation-failed', $baseline, $candidate, [[EvaluationStatus::COMPLETED, Verdict::PASS]], [[EvaluationStatus::FAILED, null]]);
        $this->pair('m06-evaluation-missing', $baseline, $candidate, [[EvaluationStatus::COMPLETED, Verdict::PASS]], []);
        $this->evalResult($baseline, 'm07-newly-failed', [[EvaluationStatus::COMPLETED, Verdict::PASS]]);
        $this->evalResult($candidate, 'm07-newly-failed', [], status: EvalResultStatus::FAILED);
        $this->evalResult($baseline, 'm08-only-baseline', [[EvaluationStatus::COMPLETED, Verdict::PASS]]);
        $this->evalResult($candidate, 'm09-only-candidate', [[EvaluationStatus::COMPLETED, Verdict::FAIL]]);
        // The baseline ran with an older text and expected answer.
        $this->evalResult($baseline, 'm10-changed', [], fingerprint: hash('sha256', "Old question?\0Old answer."));
        $this->evalResult($candidate, 'm10-changed', []);
        $this->pair('m11-same', $baseline, $candidate, [], []);
        // Results recorded before the fingerprint existed.
        $this->evalResult($baseline, 'm12-legacy-changed', [], fingerprint: null, firstMessage: 'What was the old maintenance interval?');
        $this->evalResult($candidate, 'm12-legacy-changed', [], fingerprint: null);
        $this->evalResult($baseline, 'm13-legacy-same', [], fingerprint: null);
        $this->evalResult($candidate, 'm13-legacy-same', [], fingerprint: null);
        $deleted = $this->evalResult($baseline, 'm14-legacy-deleted-conversation', [], fingerprint: null, firstMessage: 'Another text?');
        $this->evalResult($candidate, 'm14-legacy-deleted-conversation', [], fingerprint: null);
        $this->deleteConversationOf($deleted);

        $comparison = $this->compare($candidate, $baseline);
        $questions = array_column($comparison['questions'], null, 'key');

        self::assertSame(array_map(fn (string $key): string => self::PREFIX.$key, [
            'm01-worse', 'm02-equal', 'm03-better', 'm04-pending', 'm05-evaluation-failed', 'm06-evaluation-missing', 'm07-newly-failed',
            'm08-only-baseline', 'm09-only-candidate', 'm10-changed', 'm11-same', 'm12-legacy-changed', 'm13-legacy-same', 'm14-legacy-deleted-conversation',
        ]), array_column($comparison['questions'], 'key'));

        $worse = $questions[self::PREFIX.'m01-worse'];
        self::assertSame(Presence::BOTH, $worse['presence']);
        self::assertSame(Change::WORSE, $worse['verdictChange']);
        self::assertTrue($worse['regression']);
        self::assertSame([Reason::VERDICT_WORSE], $worse['regressionReasons']);
        self::assertSame(Verdict::PASS, $worse['baseline']['verdict']);
        self::assertSame(Verdict::MOSTLY_FAIL, $worse['candidate']['verdict']);
        self::assertSame('/api/eval_questions/'.$this->questions['m01-worse']->getId(), $worse['question']);
        self::assertStringStartsWith('/api/eval_results/', $worse['candidate']['result']);

        self::assertSame(Change::EQUAL, $questions[self::PREFIX.'m02-equal']['verdictChange']);
        self::assertFalse($questions[self::PREFIX.'m02-equal']['regression']);

        $better = $questions[self::PREFIX.'m03-better'];
        self::assertSame(Change::BETTER, $better['verdictChange']);
        self::assertFalse($better['regression']);
        self::assertSame([Reason::VERDICT_BETTER], $better['improvementReasons']);

        foreach (['m04-pending' => EvaluationStatus::PENDING, 'm05-evaluation-failed' => EvaluationStatus::FAILED, 'm06-evaluation-missing' => null] as $key => $evaluationStatus) {
            $unrated = $questions[self::PREFIX.$key];
            self::assertNull($unrated['verdictChange'], $key);
            self::assertTrue($unrated['newlyUnrated'], $key);
            self::assertFalse($unrated['regression'], $key);
            self::assertFalse($unrated['candidate']['rated'], $key);
            self::assertNull($unrated['candidate']['verdict'], $key);
            self::assertSame($evaluationStatus, $unrated['candidate']['evaluationStatus'], $key);
        }

        $failed = $questions[self::PREFIX.'m07-newly-failed'];
        self::assertTrue($failed['newlyFailed']);
        self::assertTrue($failed['regression']);
        self::assertSame([Reason::NEWLY_FAILED], $failed['regressionReasons']);
        self::assertSame(EvalResultStatus::FAILED, $failed['candidate']['status']);

        $onlyBaseline = $questions[self::PREFIX.'m08-only-baseline'];
        self::assertSame(Presence::ONLY_IN_BASELINE, $onlyBaseline['presence']);
        self::assertNull($onlyBaseline['candidate']);
        self::assertTrue($onlyBaseline['regression']);
        self::assertSame([Reason::MISSING_IN_CANDIDATE], $onlyBaseline['regressionReasons']);

        $onlyCandidate = $questions[self::PREFIX.'m09-only-candidate'];
        self::assertSame(Presence::ONLY_IN_CANDIDATE, $onlyCandidate['presence']);
        self::assertNull($onlyCandidate['baseline']);
        self::assertFalse($onlyCandidate['regression']);

        self::assertTrue($questions[self::PREFIX.'m10-changed']['questionChanged']);
        self::assertFalse($questions[self::PREFIX.'m10-changed']['regression']);
        self::assertFalse($questions[self::PREFIX.'m11-same']['questionChanged']);
        self::assertTrue($questions[self::PREFIX.'m12-legacy-changed']['questionChanged']);
        self::assertNull($questions[self::PREFIX.'m13-legacy-same']['questionChanged']);
        self::assertNull($questions[self::PREFIX.'m14-legacy-deleted-conversation']['questionChanged']);

        // Without the setting the human rating is only compared for equality.
        self::assertNull($comparison['humanRatingComparison']);
        self::assertSame(Change::UNCHANGED, $worse['humanRating']['change']);

        self::assertSame('/api/eval_runs/'.$baseline->getId(), $comparison['baseline']['run']);
        self::assertSame('/api/eval_runs/'.$candidate->getId(), $comparison['candidate']['run']);
        self::assertSame('/api/agent_versions/'.$v1->getId(), $comparison['baseline']['agentVersion']);
        self::assertSame('/api/agent_versions/'.$v2->getId(), $comparison['candidate']['agentVersion']);
        self::assertSame(1, $comparison['baseline']['versionNumber']);
        self::assertSame(2, $comparison['candidate']['versionNumber']);
        self::assertSame(self::AGENT_SLUG.'subject', $comparison['candidate']['agentSlug']);
        self::assertSame(EvalRunStatus::COMPLETED, $comparison['candidate']['status']);
        self::assertSame(new \DateTimeImmutable('2026-10-01 08:00:00 UTC')->getTimestamp(), new \DateTimeImmutable($comparison['baseline']['startedAt'])->getTimestamp());
        self::assertSame(new \DateTimeImmutable('2026-10-02 09:30:00 UTC')->getTimestamp(), new \DateTimeImmutable($comparison['candidate']['startedAt'])->getTimestamp());

        $summary = $comparison['summary'];
        self::assertSame(14, $summary['questions']);
        self::assertSame(12, $summary['inBoth']);
        self::assertSame(1, $summary['onlyInBaseline']);
        self::assertSame(1, $summary['onlyInCandidate']);
        self::assertSame(3, $summary['regressions']);
        self::assertSame(1, $summary['improvements']);
        self::assertSame(1, $summary['verdictWorse']);
        self::assertSame(1, $summary['verdictEqual']);
        self::assertSame(1, $summary['verdictBetter']);
        self::assertSame(1, $summary['newlyFailed']);
        self::assertSame(4, $summary['newlyUnrated']);
        self::assertSame(2, $summary['questionChanged']);
        self::assertSame(2, $summary['questionChangeUnknown']);
    }

    public function testReEvaluationMovesTheVerdict(): void
    {
        $this->useDatabase();
        [$v1, $v2] = $this->versions();
        $baseline = $this->evalRun($v1);
        $candidate = $this->evalRun($v2);
        $this->evalResult($baseline, 'reevaluated', [[EvaluationStatus::COMPLETED, Verdict::PASS]]);
        $result = $this->evalResult($candidate, 'reevaluated', [[EvaluationStatus::COMPLETED, Verdict::PASS]]);

        self::assertSame(Change::EQUAL, $this->question($candidate, $baseline, 'reevaluated')['verdictChange']);

        $this->evaluate($result, EvaluationStatus::COMPLETED, Verdict::FAIL);
        $question = $this->question($candidate, $baseline, 'reevaluated');
        self::assertSame(Change::WORSE, $question['verdictChange']);
        self::assertTrue($question['regression']);

        $this->evaluate($result, EvaluationStatus::PENDING, null);
        $question = $this->question($candidate, $baseline, 'reevaluated');
        self::assertNull($question['verdictChange']);
        self::assertTrue($question['newlyUnrated']);
        self::assertFalse($question['regression']);
    }

    public function testHumanRatingWithoutSettingReportsChangedWithBothRatings(): void
    {
        $this->useDatabase();
        [$v1, $v2] = $this->versions();
        $baseline = $this->evalRun($v1);
        $candidate = $this->evalRun($v2);
        $this->evalResult($baseline, 'rating-changed', [], humanRating: ['score' => 4, 'comment' => 'Good']);
        $this->evalResult($candidate, 'rating-changed', [], humanRating: ['score' => 2]);
        $this->evalResult($baseline, 'rating-same', [], humanRating: ['score' => 4, 'comment' => 'Good']);
        $this->evalResult($candidate, 'rating-same', [], humanRating: ['comment' => 'Good', 'score' => 4]);

        $comparison = $this->compare($candidate, $baseline);
        $questions = array_column($comparison['questions'], null, 'key');

        self::assertNull($comparison['humanRatingComparison']);
        $changed = $questions[self::PREFIX.'rating-changed']['humanRating'];
        self::assertNull($changed['field']);
        self::assertSame(['score' => 4, 'comment' => 'Good'], $changed['baseline']);
        self::assertSame(['score' => 2], $changed['candidate']);
        self::assertTrue($changed['changed']);
        self::assertSame(Change::CHANGED, $changed['change']);
        self::assertFalse($questions[self::PREFIX.'rating-changed']['regression']);
        self::assertSame(Change::UNCHANGED, $questions[self::PREFIX.'rating-same']['humanRating']['change']);
        self::assertSame(1, $comparison['summary']['humanRatingChanged']);
    }

    public function testHumanRatingNumberField(): void
    {
        $this->useDatabase();
        $this->patchComparison(['field' => 'score', 'order' => 'higher_is_better']);
        [$v1, $v2] = $this->versions();
        $baseline = $this->evalRun($v1);
        $candidate = $this->evalRun($v2);
        $this->evalResult($baseline, 'score-worse', [], humanRating: ['score' => 4]);
        $this->evalResult($candidate, 'score-worse', [], humanRating: ['score' => 3]);
        $this->evalResult($baseline, 'score-better', [], humanRating: ['score' => 2]);
        $this->evalResult($candidate, 'score-better', [], humanRating: ['score' => 5]);
        $this->evalResult($baseline, 'score-missing', [], humanRating: ['score' => 5]);
        $this->evalResult($candidate, 'score-missing', [], humanRating: ['comment' => 'Not scored']);
        $this->evalResult($baseline, 'score-unrated', [], humanRating: ['score' => 5]);
        $this->evalResult($candidate, 'score-unrated', []);

        $comparison = $this->compare($candidate, $baseline);
        $questions = array_column($comparison['questions'], null, 'key');

        self::assertSame(['field' => 'score', 'order' => 'higher_is_better', 'values' => null], $comparison['humanRatingComparison']);
        $worse = $questions[self::PREFIX.'score-worse'];
        self::assertSame(['field' => 'score', 'order' => 'higher_is_better', 'baseline' => 4, 'candidate' => 3, 'changed' => true, 'change' => Change::WORSE], array_intersect_key($worse['humanRating'], array_flip(['field', 'order', 'baseline', 'candidate', 'changed', 'change'])));
        self::assertTrue($worse['regression']);
        self::assertSame([Reason::HUMAN_RATING_WORSE], $worse['regressionReasons']);
        self::assertSame(Change::BETTER, $questions[self::PREFIX.'score-better']['humanRating']['change']);
        self::assertSame([Reason::HUMAN_RATING_BETTER], $questions[self::PREFIX.'score-better']['improvementReasons']);
        foreach (['score-missing', 'score-unrated'] as $key) {
            self::assertSame(Change::UNRATED, $questions[self::PREFIX.$key]['humanRating']['change'], $key);
            self::assertNull($questions[self::PREFIX.$key]['humanRating']['candidate'], $key);
            self::assertFalse($questions[self::PREFIX.$key]['regression'], $key);
        }
        self::assertSame(1, $comparison['summary']['humanRatingWorse']);
        self::assertSame(1, $comparison['summary']['humanRatingBetter']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function unfinishedRuns(): iterable
    {
        yield 'candidate running' => [EvalRunStatus::RUNNING, EvalRunStatus::COMPLETED];
        yield 'candidate pending' => [EvalRunStatus::PENDING, EvalRunStatus::COMPLETED];
        yield 'baseline cancelled' => [EvalRunStatus::COMPLETED, EvalRunStatus::CANCELLED];
        yield 'baseline failed' => [EvalRunStatus::COMPLETED, EvalRunStatus::FAILED];
    }

    #[DataProvider('unfinishedRuns')]
    public function testRunThatIsNotCompletedAnswers422(string $candidateStatus, string $baselineStatus): void
    {
        $this->useDatabase();
        [$v1, $v2] = $this->versions();
        $baseline = $this->evalRun($v1, $baselineStatus);
        $candidate = $this->evalRun($v2, $candidateStatus);

        $response = $this->request($candidate->getId().'/compare/'.$baseline->getId(), self::ROLES_REVIEWER);

        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertStringContainsString('not completed', $response->toArray(false)['detail'] ?? '');
    }

    public function testSameRunTwiceAnswers422(): void
    {
        $this->useDatabase();
        [$v1] = $this->versions();
        $run = $this->evalRun($v1);

        $response = $this->request($run->getId().'/compare/'.$run->getId(), self::ROLES_REVIEWER);

        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
    }

    public function testUnknownRunAnswers404(): void
    {
        $this->useDatabase();
        [$v1] = $this->versions();
        $run = $this->evalRun($v1);
        $unknown = '0199aa00-0000-7000-8000-00000000dead';

        self::assertSame(404, $this->request($unknown.'/compare/'.$run->getId(), self::ROLES_REVIEWER)->getStatusCode());
        self::assertSame(404, $this->request($run->getId().'/compare/'.$unknown, self::ROLES_REVIEWER)->getStatusCode());
    }

    /**
     * @return iterable<string, array{0: string|null, 1: int}>
     */
    public static function identities(): iterable
    {
        yield 'ROLE_USER' => [self::ROLES_USER, 403];
        yield 'ROLE_USER with ROLE_AGENT_ADMIN' => ['ROLE_USER,ROLE_AGENT_ADMIN', 403];
        yield 'ROLE_EVAL_REVIEWER' => [self::ROLES_REVIEWER, 200];
        yield 'ROLE_EVAL_ADMIN' => [self::ROLES_EVAL_ADMIN, 200];
        yield 'ROLE_ADMIN through the map' => [null, 200];
    }

    #[DataProvider('identities')]
    public function testAccess(?string $roles, int $status): void
    {
        $this->useDatabase();
        [$v1, $v2] = $this->versions();
        $baseline = $this->evalRun($v1);
        $candidate = $this->evalRun($v2);

        $response = $this->request($candidate->getId().'/compare/'.$baseline->getId(), $roles);
        self::assertSame($status, $response->getStatusCode(), $response->getContent(false));

        // The asymmetry: every identity here reads the run itself.
        self::assertSame(200, $this->request((string) $candidate->getId(), $roles)->getStatusCode());
    }

    /**
     * Access is decided before the runs are read, so an unknown run does
     * not reveal itself to a plain user.
     */
    public function testUserIsForbiddenEvenForUnknownRuns(): void
    {
        $this->useDatabase();
        $unknown = '0199aa00-0000-7000-8000-00000000dead';

        self::assertSame(403, $this->request($unknown.'/compare/'.$unknown, self::ROLES_USER)->getStatusCode());
    }

    public function testAnonymousAndCapabilityTokenHolder(): void
    {
        $this->useDatabase();
        [$v1, $v2] = $this->versions();
        $path = '/api/eval_runs/'.$this->evalRun($v2)->getId().'/compare/'.$this->evalRun($v1)->getId();
        $this->anonymousConversation();

        static::createClient()->request('GET', $path, ['headers' => [
            'Accept' => 'application/ld+json',
            TestHeaderAuthenticator::HEADER_AUTH => 'anonymous',
        ]]);
        self::assertResponseStatusCodeSame(401);

        static::createClient()->request('GET', $path, ['headers' => [
            'Accept' => 'application/ld+json',
            TestHeaderAuthenticator::HEADER_AUTH => 'anonymous',
            'Authorization' => 'Bearer '.self::TOKEN,
        ]]);
        self::assertResponseStatusCodeSame(403);
    }

    // --- requests -------------------------------------------------------

    /**
     * The comparison as a reviewer; asserts 200.
     *
     * @return array<string, mixed>
     */
    private function compare(EvalRun $candidate, EvalRun $baseline): array
    {
        $response = $this->request($candidate->getId().'/compare/'.$baseline->getId(), self::ROLES_REVIEWER);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));

        return $response->toArray();
    }

    /**
     * One compared question by key suffix.
     *
     * @return array<string, mixed>
     */
    private function question(EvalRun $candidate, EvalRun $baseline, string $suffix): array
    {
        $questions = array_column($this->compare($candidate, $baseline)['questions'], null, 'key');
        self::assertArrayHasKey(self::PREFIX.$suffix, $questions);

        return $questions[self::PREFIX.$suffix];
    }

    /**
     * GET /api/eval_runs/$path with $roles (null: the default identity
     * `test-admin` with ROLE_ADMIN).
     */
    private function request(string $path, ?string $roles): ResponseInterface
    {
        $headers = ['Accept' => 'application/ld+json'];
        if (null !== $roles) {
            $headers[TestHeaderAuthenticator::HEADER_ROLES] = $roles;
        }

        return static::createClient()->request('GET', '/api/eval_runs/'.$path, ['headers' => $headers]);
    }

    /**
     * Merge-patches `evaluation.human_rating_comparison` as `test-admin`;
     * asserts 200.
     *
     * @param array<string, mixed> $comparison
     */
    private function patchComparison(array $comparison): void
    {
        InMemoryEvaluationSettings::configure(['human_rating_comparison' => $comparison]);
    }

    // --- fixtures -------------------------------------------------------

    private function useDatabase(): void
    {
        $this->usesDatabase = true;
        self::cleanUp();
        self::bootKernel();
    }

    /**
     * Versions 1 and 2 of the agent under test.
     *
     * @return array{AgentVersion, AgentVersion}
     */
    private function versions(): array
    {
        $entityManager = $this->entityManager();
        $agent = new Agent()->setName('Compare subject')->setSlug(self::AGENT_SLUG.'subject')->setEnabled(true);
        $entityManager->persist($agent);
        $entityManager->flush();

        $v1 = new AgentVersion($agent, 1, ConversationConnections::withConnection(Agent::candidateDefaults()), 'test-admin');
        $v2 = new AgentVersion($agent, 2, ConversationConnections::withConnection(Agent::candidateDefaults()), 'test-admin');
        $entityManager->persist($v1);
        $entityManager->persist($v2);
        $agent->setCurrentVersion($v2);
        $entityManager->flush();

        return [$v1, $v2];
    }

    private function evalRun(AgentVersion $version, string $status = EvalRunStatus::COMPLETED, string $startedAt = '2026-10-01 08:00:00'): EvalRun
    {
        $run = new EvalRun()
            ->setAgentVersion($version)
            ->setOwnerSubject(self::PREFIX.'owner')
            ->setStartedAt(new \DateTimeImmutable($startedAt.' UTC'))
            ->setStatus($status);
        if (EvalRunStatus::PENDING !== $status && EvalRunStatus::RUNNING !== $status) {
            $run->finish($status, new \DateTimeImmutable($startedAt.' UTC')->modify('+5 minutes'));
        }
        $this->entityManager()->persist($run);
        $this->entityManager()->flush();

        return $run;
    }

    /**
     * Results of the same question in both runs.
     *
     * @param list<array{0: string, 1: string|null}> $baselineEvaluations
     * @param list<array{0: string, 1: string|null}> $candidateEvaluations
     */
    private function pair(string $suffix, EvalRun $baseline, EvalRun $candidate, array $baselineEvaluations, array $candidateEvaluations): void
    {
        $this->evalResult($baseline, $suffix, $baselineEvaluations);
        $this->evalResult($candidate, $suffix, $candidateEvaluations);
    }

    /**
     * A result of $run for the question $suffix (created once per test),
     * with an evaluation conversation whose first user message is
     * $firstMessage (default: the question text) and the given evaluations
     * in order (the last one is the latest). $fingerprint 'current' stores
     * the fingerprint of the question as it is, null none (a result
     * recorded before fingerprints existed).
     *
     * @param list<array{0: string, 1: string|null}> $evaluations
     * @param array<string, mixed>|null              $humanRating
     */
    private function evalResult(
        EvalRun $run,
        string $suffix,
        array $evaluations,
        string $status = EvalResultStatus::COMPLETED,
        ?array $humanRating = null,
        ?string $fingerprint = 'current',
        ?string $firstMessage = null,
    ): EvalResult {
        $entityManager = $this->entityManager();
        $question = $this->questions[$suffix] ??= $this->newQuestion($suffix);

        $conversation = new Conversation()
            ->setOwnerSubject(self::PREFIX.'owner')
            ->setOrigin(Conversation::ORIGIN_EVAL)
            ->setStatus(ConversationStatus::ACTIVE)
            ->setProviderModel(ConversationConnections::model())
            ->setRetrievalDepth(4)
            ->setNextSequence(3);
        $entityManager->persist($conversation);
        $entityManager->flush();
        foreach ([[1, Message::SPEAKER_USER, $firstMessage ?? $question->getQuestion()], [2, Message::SPEAKER_ASSISTANT, 'Every 500 operating hours.']] as [$sequence, $role, $content]) {
            $entityManager->persist(new Message()
                ->setConversation($conversation)
                ->setSequence($sequence)
                ->setRole($role)
                ->setKind(MessageKind::TEXT)
                ->setStatus(MessageStatus::COMPLETE)
                ->setContent($content));
        }

        $result = new EvalResult()
            ->setEvalRun($run)
            ->setEvalQuestion($question)
            ->setConversation($conversation)
            ->setSource(EvalResultSource::BATCH)
            ->setStatus($status)
            ->setErrorMessage(EvalResultStatus::FAILED === $status ? 'Upstream provider overloaded (529).' : null)
            ->setHumanRating($humanRating)
            ->setQuestionFingerprint('current' === $fingerprint ? EvalResult::fingerprintOf($question) : $fingerprint);
        $entityManager->persist($result);
        $entityManager->flush();

        foreach ($evaluations as [$evaluationStatus, $verdict]) {
            $this->evaluate($result, $evaluationStatus, $verdict);
        }

        return $result;
    }

    /**
     * A new evaluation of $result that becomes its latest one.
     */
    private function evaluate(EvalResult $result, string $status, ?string $verdict): void
    {
        $entityManager = $this->entityManager();
        $result = $entityManager->find(EvalResult::class, $result->getId());
        self::assertInstanceOf(EvalResult::class, $result);

        $evaluation = new Evaluation()
            ->setEvalResult($result)
            ->setStatus($status)
            ->setVerdict($verdict)
            ->setReasoning(null === $verdict ? null : 'Fixture verdict.')
            ->setErrorMessage(EvaluationStatus::FAILED === $status ? 'Judge answer did not match the verdict schema.' : null);
        $entityManager->persist($evaluation);
        $result->setEvaluation($evaluation);
        $entityManager->flush();
    }

    private function newQuestion(string $suffix): EvalQuestion
    {
        $question = new EvalQuestion()
            ->setKey(self::PREFIX.$suffix)
            ->setQuestion(self::QUESTION_TEXT)
            ->setExpectedAnswer('Every 500 operating hours, at least once a year.')
            ->setStatus(EvalQuestionStatus::ARCHIVED);
        $this->entityManager()->persist($question);
        $this->entityManager()->flush();

        return $question;
    }

    /**
     * Deletes the result's conversation in the database; the foreign key
     * sets the result's `conversation` to null.
     */
    private function deleteConversationOf(EvalResult $result): void
    {
        $id = (string) $result->getConversation()?->getId();
        $this->dbal()->executeStatement('DELETE FROM neuron_conversation WHERE id = :id', ['id' => $id]);
        $this->dbal()->executeStatement('DELETE FROM dmstr_log_entries WHERE object_class = :class AND object_id = :id', ['class' => Conversation::class, 'id' => $id]);
        $this->entityManager()->clear();
        self::assertNull($this->dbal()->fetchOne('SELECT conversation_id FROM neuron_eval_result WHERE id = :id', ['id' => (string) $result->getId()]));
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
            ->setAddressHash(hash('sha256', self::PREFIX.'access'))
            ->setProviderModel(ConversationConnections::model())
            ->setRetrievalDepth(4);
        $this->entityManager()->persist($conversation);
        $this->entityManager()->flush();
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
     * Runs of the test agent (results and evaluations cascade), the test
     * questions, the test conversations and the token holder's conversation
     * (messages cascade) with their audit entries, the `evaluation`
     * settings row with the settings audit entries, then the agents
     * ({@see AgentFixtures::cleanUp()}).
     */
    private static function cleanUp(): void
    {
        static::bootKernel();
        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        $agents = ['agents' => self::AGENT_SLUG.'%'];
        $prefix = ['prefix' => self::PREFIX.'%'];

        $connection->executeStatement('DELETE FROM neuron_eval_run WHERE agent_version_id IN (SELECT v.id FROM neuron_agent_version v JOIN neuron_agent a ON a.id = v.agent_id WHERE a.slug LIKE :agents)', $agents);
        $connection->executeStatement('DELETE FROM neuron_eval_result WHERE eval_question_id IN (SELECT id FROM neuron_eval_question WHERE key LIKE :prefix)', $prefix);
        $connection->executeStatement('DELETE FROM neuron_eval_question WHERE key LIKE :prefix', $prefix);
        $conversations = array_merge(
            $connection->fetchFirstColumn('SELECT id FROM neuron_conversation WHERE owner_subject LIKE :prefix', $prefix),
            $connection->fetchFirstColumn('SELECT id FROM neuron_conversation WHERE token_hash = :hash', ['hash' => CapabilityToken::hash(self::TOKEN)]),
        );
        foreach ($conversations as $id) {
            $connection->executeStatement('DELETE FROM dmstr_log_entries WHERE object_class = :class AND object_id = :id', ['class' => Conversation::class, 'id' => (string) $id]);
            $connection->executeStatement('DELETE FROM neuron_conversation WHERE id = :id', ['id' => (string) $id]);
        }
        static::ensureKernelShutdown();

        AgentFixtures::cleanUp();
    }
}
