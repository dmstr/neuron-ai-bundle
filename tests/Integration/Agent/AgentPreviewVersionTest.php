<?php

// file generated with AI assistance: Claude Code - 2026-10-13 23:10:57 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Agent;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Agent\AgentPreviewFactory;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Preview versions against the database: a preview is created through
 * {@see AgentPreviewFactory} (the REST paths that create previews come
 * later), is hidden from `GET /agent_versions` unless `?kind=preview` is
 * given, stays readable as an item with `kind: preview`, does not use a
 * version number (a later publish is version 1), cannot be rolled back to
 * (422 at `version`), and is reused for an unchanged candidate while a
 * changed candidate gets a new preview.
 *
 * Uses the fixtures of {@see AgentFixtures}; its cleanUp() removes the
 * previews with the other versions of the test agents (the test creates no
 * conversations or evaluation runs on them). Needs the migrated test
 * database; runs in CI.
 */
#[Group('database')]
final class AgentPreviewVersionTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        AgentFixtures::cleanUp();
    }

    protected function tearDown(): void
    {
        AgentFixtures::cleanUp();
        parent::tearDown();
    }

    public function testPreviewIsListedOnlyWithTheKindFilter(): void
    {
        $id = AgentFixtures::createAgent('preview-list');
        $published = AgentFixtures::publish($id);
        $preview = $this->preview($id);

        $filter = AgentFixtures::VERSIONS.'?agent='.urlencode(AgentFixtures::AGENTS.'/'.$id);

        $list = AgentFixtures::request('GET', $filter, AgentFixtures::USER)->toArray();
        self::assertSame([$published['id']], array_column($list['hydra:member'], 'id'));
        self::assertSame(['published'], array_column($list['hydra:member'], 'kind'));

        $previews = AgentFixtures::request('GET', $filter.'&kind=preview', AgentFixtures::USER)->toArray();
        self::assertSame([$preview], array_column($previews['hydra:member'], 'id'));

        $publishedOnly = AgentFixtures::request('GET', $filter.'&kind=published', AgentFixtures::USER)->toArray();
        self::assertSame([$published['id']], array_column($publishedOnly['hydra:member'], 'id'));
    }

    public function testPreviewIsReadableAsAnItem(): void
    {
        $id = AgentFixtures::createAgent('preview-item');
        $preview = $this->preview($id);

        $item = AgentFixtures::request('GET', AgentFixtures::VERSIONS.'/'.$preview, AgentFixtures::USER)->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame('preview', $item['kind']);
        self::assertSame(0, $item['versionNumber']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $item['candidateHash']);
        self::assertSame(AgentFixtures::AGENTS.'/'.$id, $item['agent']);
        self::assertSame(TestHeaderAuthenticator::ADMIN_IDENTIFIER, $item['publishedBy']);
        self::assertSame(AgentFixtures::model()->getId()->toRfc4122(), $item['configuration']['model']);

        // A preview never becomes current.
        self::assertNull(AgentFixtures::agent($id)['currentVersion'] ?? null);
    }

    public function testPublishAfterAPreviewIsVersionOne(): void
    {
        $id = AgentFixtures::createAgent('preview-publish');
        $preview = $this->preview($id);

        $first = AgentFixtures::publish($id);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $first['versionNumber']);
        self::assertSame('published', $first['kind']);
        self::assertNull($first['candidateHash'] ?? null);
        self::assertSame($first['@id'], AgentFixtures::agent($id)['currentVersion']);

        // The unchanged candidate froze the same configuration as its preview.
        self::assertSame(
            AgentFixtures::versionRow($preview)['configuration'],
            AgentFixtures::versionRow((string) $first['id'])['configuration'],
        );
    }

    public function testRollbackToAPreviewIsRejectedAtVersion(): void
    {
        $id = AgentFixtures::createAgent('preview-rollback');
        $own = AgentFixtures::publish($id);
        $preview = $this->preview($id);

        $response = AgentFixtures::rollback($id, ['version' => AgentFixtures::VERSIONS.'/'.$preview]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['version'], AgentFixtures::violationPaths($response));
        self::assertSame($own['@id'], AgentFixtures::agent($id)['currentVersion']);
        self::assertSame(1, AgentFixtures::agent($id)['currentVersionNumber']);
    }

    public function testUnchangedCandidateReusesThePreviewAndAChangedOneGetsANewOne(): void
    {
        $id = AgentFixtures::createAgent('preview-reuse');

        $first = $this->preview($id);
        self::assertSame($first, $this->preview($id));

        AgentFixtures::request('PATCH', AgentFixtures::AGENTS.'/'.$id, AgentFixtures::ADMIN, ['candidate' => AgentFixtures::validCandidate(['maxToolCalls' => 2])]);
        self::assertResponseStatusCodeSame(200);

        $second = $this->preview($id);
        self::assertNotSame($first, $second);
        self::assertSame($second, $this->preview($id));
        self::assertNotSame(AgentFixtures::versionRow($first)['candidate_hash'], AgentFixtures::versionRow($second)['candidate_hash']);
        self::assertSame(2, AgentFixtures::versionCount());
    }

    /**
     * Creates or reuses the preview of the agent's candidate through the
     * factory, as the default admin would, on a freshly loaded agent (each
     * request reboots the kernel, so earlier entities are detached).
     *
     * @return string the preview's id
     */
    private function preview(string $agentId): string
    {
        $agent = AgentFixtures::entityManager()->find(Agent::class, Uuid::fromString($agentId));
        self::assertInstanceOf(Agent::class, $agent);

        /** @var AgentPreviewFactory $factory */
        $factory = static::getContainer()->get('test.neuron_ai.agent_preview_factory');

        return $factory->previewFor($agent, TestHeaderAuthenticator::ADMIN_IDENTIFIER)->getId()->toRfc4122();
    }
}
