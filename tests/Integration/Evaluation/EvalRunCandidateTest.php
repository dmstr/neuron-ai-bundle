<?php

// file generated with AI assistance: Claude Code - 2026-10-13 23:25:16 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Evaluation;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use Dmstr\NeuronAiBundle\Tests\Support\EvalRunTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * `POST /api/eval_runs/start` on the candidate of an agent: `agent` (slug,
 * IRI or full UUID) with `candidate: true` freezes the candidate as a
 * preview (created by the starter) and starts the run on it; the run is
 * listed under `GET /api/eval_runs`, the preview is not listed under
 * `GET /api/agent_versions?agent=` and the agent's current version is not
 * touched. A second start on the unchanged candidate reuses the preview,
 * and a preview id is accepted directly as `agentVersion`, also for an
 * agent that was never published. Both ways in one body, an unknown or
 * disabled agent and an invalid candidate (at `candidate/<field>`) answer
 * 422 without a run (and, for the agent cases, without a preview).
 *
 * The batch itself is not run: what it does with the version is covered
 * by {@see EvalRunTest}, it does not distinguish previews. Agents are
 * created through the API with the candidate of
 * {@see AgentFixtures::validCandidate()} and the slug prefix
 * {@see self::AGENT_SLUG}, so the cleanUp() of {@see EvalRunTestTrait}
 * removes their runs before {@see AgentFixtures::cleanUp()} removes
 * the versions (previews included) and agents. Needs the migrated test
 * database (CI only).
 */
#[Group('database')]
final class EvalRunCandidateTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    use EvalRunTestTrait;

    private const string PREFIX = 'test-evalcand-';
    private const string AGENT_SLUG = AgentFixtures::PREFIX.'evalcand-';
    private const string COLLECTION_NAME = 'evalcand-';

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

    public function testStartOnTheCandidateRunsOnAReusedPreview(): void
    {
        $id = AgentFixtures::createAgent('evalcand-main');
        $published = AgentFixtures::publish($id);
        $this->questions();

        $response = $this->post(self::RUNS.'/start', self::ROLES_ADMIN, ['agent' => self::AGENT_SLUG.'main', 'candidate' => true]);
        self::assertSame(202, $response->getStatusCode(), $response->getContent(false));
        $run = $response->toArray();
        self::assertNotSame($published['@id'], $run['agentVersion']);
        self::assertSame(TestHeaderAuthenticator::USER_IDENTIFIER, $run['ownerSubject']);
        self::assertSame('agent', $run['mode']);
        self::assertSame(1, $run['questionsTotal']);

        $preview = $this->get($run['agentVersion'], self::ROLES_USER)->toArray();
        self::assertSame('preview', $preview['kind']);
        self::assertSame(0, $preview['versionNumber']);
        self::assertSame(AgentFixtures::AGENTS.'/'.$id, $preview['agent']);
        self::assertSame(TestHeaderAuthenticator::USER_IDENTIFIER, $preview['publishedBy']);

        // The run is listed; the preview is not a published version.
        $runs = $this->get(self::RUNS.'?agentVersion='.urlencode($run['agentVersion']), self::ROLES_USER)->toArray();
        self::assertSame([$run['id']], array_column($runs['hydra:member'] ?? $runs['member'] ?? [], 'id'));
        $versions = $this->get(AgentFixtures::VERSIONS.'?agent='.urlencode(AgentFixtures::AGENTS.'/'.$id), self::ROLES_USER)->toArray();
        self::assertSame([$published['@id']], array_column($versions['hydra:member'] ?? $versions['member'] ?? [], '@id'));
        self::assertSame($published['@id'], AgentFixtures::agent($id)['currentVersion']);

        // The unchanged candidate reuses the preview, the agent given by IRI.
        $again = $this->post(self::RUNS.'/start', self::ROLES_ADMIN, ['agent' => AgentFixtures::AGENTS.'/'.$id, 'candidate' => true, 'mode' => 'agent']);
        self::assertSame(202, $again->getStatusCode(), $again->getContent(false));
        self::assertSame($run['agentVersion'], $again->toArray()['agentVersion']);
        self::assertSame(2, $this->versionCountOf($id));
    }

    public function testPreviewIdIsAcceptedAsAgentVersion(): void
    {
        $id = AgentFixtures::createAgent('evalcand-direct');
        $this->questions();

        $first = $this->post(self::RUNS.'/start', self::ROLES_ADMIN, ['agent' => $id, 'candidate' => true]);
        self::assertSame(202, $first->getStatusCode(), $first->getContent(false));
        $preview = (string) $first->toArray()['agentVersion'];
        $previewId = substr($preview, \strlen(AgentFixtures::VERSIONS.'/'));

        // The agent was never published; the preview is startable by its id.
        $direct = $this->post(self::RUNS.'/start', self::ROLES_ADMIN, ['agentVersion' => $previewId]);
        self::assertSame(202, $direct->getStatusCode(), $direct->getContent(false));
        self::assertSame($preview, $direct->toArray()['agentVersion']);
        self::assertNull(AgentFixtures::agent($id)['currentVersion'] ?? null);
    }

    public function testRefusalsAnswer422WithoutARun(): void
    {
        $id = AgentFixtures::createAgent('evalcand-refusals');
        $published = AgentFixtures::publish($id);
        $disabled = AgentFixtures::createAgent('evalcand-disabled');
        self::assertSame(200, AgentFixtures::request('PATCH', AgentFixtures::AGENTS.'/'.$disabled, AgentFixtures::ADMIN, ['enabled' => false])->getStatusCode());
        $invalid = AgentFixtures::createAgent('evalcand-invalid', AgentFixtures::validCandidate(['collections' => [AgentFixtures::PREFIX.'missing']]));
        $this->questions();
        $versions = AgentFixtures::versionCount();

        foreach ([
            'agentVersion and agent' => [['agentVersion' => $published['@id'], 'agent' => $id, 'candidate' => true], '"agentVersion" cannot be combined with "agent" or "candidate"'],
            'agent without candidate' => [['agent' => $id], 'candidate'],
            'candidate false' => [['agent' => $id, 'candidate' => false], 'candidate'],
            'unknown agent' => [['agent' => self::AGENT_SLUG.'nobody', 'candidate' => true], 'Unknown agent "'.self::AGENT_SLUG.'nobody".'],
            'disabled agent' => [['agent' => $disabled, 'candidate' => true], 'Agent "'.self::AGENT_SLUG.'disabled" is disabled.'],
        ] as $case => [$body, $message]) {
            $response = $this->post(self::RUNS.'/start', self::ROLES_ADMIN, $body);
            self::assertSame(422, $response->getStatusCode(), $case.': '.$response->getContent(false));
            self::assertStringContainsString($message, (string) ($response->toArray(false)['detail'] ?? ''), $case);
        }

        $response = $this->post(self::RUNS.'/start', self::ROLES_ADMIN, ['agent' => $invalid, 'candidate' => true]);
        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertSame(['candidate/collections/0'], AgentFixtures::violationPaths($response));

        self::assertSame(0, $this->runCountOfTestAgents());
        self::assertSame($versions, AgentFixtures::versionCount());
    }

    /**
     * One active question, so a start is not refused for lack of questions.
     */
    private function questions(): void
    {
        $this->persistQuestions([
            ['key' => 'a-maintenance', 'question' => 'What is the maintenance interval?', 'expectedAnswer' => 'Every 500 hours.'],
        ]);
    }

    /**
     * Versions of the agent, previews and published alike.
     */
    private function versionCountOf(string $agentId): int
    {
        return (int) $this->dbal()->fetchOne('SELECT COUNT(*) FROM neuron_agent_version WHERE agent_id = :agent', ['agent' => $agentId]);
    }

    private function runCountOfTestAgents(): int
    {
        return (int) $this->dbal()->fetchOne(
            'SELECT COUNT(*) FROM neuron_eval_run r JOIN neuron_agent_version v ON v.id = r.agent_version_id JOIN neuron_agent a ON a.id = v.agent_id WHERE a.slug LIKE :agents',
            ['agents' => self::AGENT_SLUG.'%'],
        );
    }
}
