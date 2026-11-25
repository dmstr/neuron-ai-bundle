<?php

// file generated with AI assistance: Claude Code - 2026-10-13 23:25:16 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Agent;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\AgentChatTestTrait;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use PHPUnit\Framework\Attributes\Group;

/**
 * `POST /api/agents/{id}/chat` with `target: candidate`: the turn runs on a
 * preview of the agent's current candidate. On a candidate identical to the
 * published configuration it answers like the published version (same
 * system prompt, provider and model), but `agentVersion` is the preview
 * (`kind` preview, number 0, created by the calling user) and
 * `agentVersionKind` is `preview`; a second call reuses the preview, a
 * changed candidate gets a new one. `target: candidate` needs
 * `ROLE_AGENT_ADMIN`: `ROLE_USER` alone gets 403 naming the permission, before
 * any preview or provider call, while the published target stays open to
 * `ROLE_USER`. The agent's current version is not
 * touched and the published version list does not show the preview. An
 * agent that was never published can be chatted with on its candidate. A
 * disabled agent and an invalid candidate (at `candidate/<field>`) answer
 * 422 without provider call and without a preview; `target` outside the
 * enum answers 422.
 *
 * Runs on one client without kernel reboot through
 * {@see AgentChatTestTrait}, so the fake provider keeps its queue and
 * recordings; fixtures use the
 * static helpers and the prefix of {@see AgentFixtures}, whose
 * cleanUp() removes the previews with the other versions (no conversation
 * or evaluation run is created). Needs the migrated test database (CI
 * only).
 */
#[Group('database')]
final class AgentChatCandidateTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    use AgentChatTestTrait;

    /** Chat role plus the role `target: candidate` needs. */
    private const string AGENT_ADMIN = 'ROLE_USER,ROLE_AGENT_ADMIN';

    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        AgentFixtures::cleanUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        AgentFixtures::cleanUp();
        parent::tearDown();
    }

    public function testCandidateAnswersLikeTheSamePublishedConfigurationOnAPreview(): void
    {
        $id = $this->createAgent('same');
        $published = $this->publish($id);

        $this->factory()->queueAnswer('Alle 500 Stunden.');
        $response = $this->chat($id, ['messages' => self::ask()]);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        $onPublished = $response->toArray();
        $publishedPrompt = (string) $this->lastRequest()->systemPrompt?->getContent();
        self::assertSame($published['@id'], $onPublished['agentVersion']);
        self::assertSame('published', $onPublished['agentVersionKind']);

        $this->factory()->queueAnswer('Alle 500 Stunden.');
        $response = $this->chat($id, ['messages' => self::ask(), 'target' => 'candidate'], self::AGENT_ADMIN);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        $onCandidate = $response->toArray();

        self::assertSame($onPublished['answer'], $onCandidate['answer']);
        self::assertSame($onPublished['provider'], $onCandidate['provider']);
        self::assertSame($onPublished['model'], $onCandidate['model']);
        self::assertSame($onPublished['connection'], $onCandidate['connection']);
        self::assertSame($onPublished['language'], $onCandidate['language']);
        self::assertSame($publishedPrompt, (string) $this->lastRequest()->systemPrompt?->getContent());
        self::assertStringStartsWith(AgentFixtures::VERSIONS.'/', $onCandidate['agentVersion']);
        self::assertNotSame($published['@id'], $onCandidate['agentVersion']);
        self::assertSame('preview', $onCandidate['agentVersionKind']);

        $preview = $this->get($onCandidate['agentVersion']);
        self::assertSame('preview', $preview['kind']);
        self::assertSame(0, $preview['versionNumber']);
        self::assertSame(AgentFixtures::AGENTS.'/'.$id, $preview['agent']);
        // Created by the caller; X-Test-Roles keeps the default test user.
        self::assertSame(TestHeaderAuthenticator::USER_IDENTIFIER, $preview['publishedBy']);
        // The unchanged candidate froze the published configuration.
        self::assertSame($published['configuration'], $preview['configuration']);

        // Reused while the candidate is unchanged.
        $this->factory()->queueAnswer('Weiterhin alle 500 Stunden.');
        $again = $this->chat($id, ['messages' => self::ask(), 'target' => 'candidate'], self::AGENT_ADMIN);
        self::assertSame(200, $again->getStatusCode(), $again->getContent(false));
        self::assertSame($onCandidate['agentVersion'], $again->toArray()['agentVersion']);

        // The current version and the published list are untouched.
        $agent = $this->get(AgentFixtures::AGENTS.'/'.$id);
        self::assertSame($published['@id'], $agent['currentVersion']);
        self::assertSame(1, $agent['currentVersionNumber']);
        $list = $this->get(AgentFixtures::VERSIONS.'?agent='.urlencode(AgentFixtures::AGENTS.'/'.$id));
        self::assertSame([$published['@id']], array_column($list['hydra:member'], '@id'));
        self::assertSame(2, AgentFixtures::versionCount());

        // `target: published` explicitly is today's behaviour.
        $this->factory()->queueAnswer('Alle 500 Stunden.');
        $explicit = $this->chat($id, ['messages' => self::ask(), 'target' => 'published']);
        self::assertSame(200, $explicit->getStatusCode(), $explicit->getContent(false));
        self::assertSame($published['@id'], $explicit->toArray()['agentVersion']);
    }

    public function testChangedCandidateGetsANewPreview(): void
    {
        $id = $this->createAgent('changed');

        $this->factory()->queueAnswer('Eins');
        $first = $this->chat($id, ['messages' => self::ask(), 'target' => 'candidate'], self::AGENT_ADMIN);
        self::assertSame(200, $first->getStatusCode(), $first->getContent(false));

        $patch = AgentFixtures::send($this->client, 'PATCH', AgentFixtures::AGENTS.'/'.$id, AgentFixtures::ADMIN, ['candidate' => AgentFixtures::validCandidate(['instructions' => ['de' => 'Du bist ein knapper Assistent.']])]);
        self::assertSame(200, $patch->getStatusCode(), $patch->getContent(false));

        $this->factory()->queueAnswer('Zwei');
        $second = $this->chat($id, ['messages' => self::ask(), 'target' => 'candidate'], self::AGENT_ADMIN);
        self::assertSame(200, $second->getStatusCode(), $second->getContent(false));

        self::assertNotSame($first->toArray()['agentVersion'], $second->toArray()['agentVersion']);
        self::assertStringContainsString('Du bist ein knapper Assistent.', (string) $this->lastRequest()->systemPrompt?->getContent());
        self::assertSame(2, AgentFixtures::versionCount());
    }

    public function testUnpublishedAgentAnswersOnItsCandidate(): void
    {
        $id = $this->createAgent('unpublished');

        $response = $this->chat($id, ['messages' => self::ask()]);
        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertSame('Agent "'.AgentFixtures::PREFIX.'unpublished" has no published version.', self::detail($response));

        $this->factory()->queueAnswer('Alle 500 Stunden.');
        $response = $this->chat($id, ['messages' => self::ask(), 'target' => 'candidate'], self::AGENT_ADMIN);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertSame('Alle 500 Stunden.', $response->toArray()['answer']);
        self::assertSame('preview', $response->toArray()['agentVersionKind']);
        self::assertNull($this->get(AgentFixtures::AGENTS.'/'.$id)['currentVersion'] ?? null);
    }

    public function testDisabledAgentIsRejected(): void
    {
        $id = $this->createAgent('disabled');
        $patch = AgentFixtures::send($this->client, 'PATCH', AgentFixtures::AGENTS.'/'.$id, AgentFixtures::ADMIN, ['enabled' => false]);
        self::assertSame(200, $patch->getStatusCode(), $patch->getContent(false));
        $calls = $this->providerCalls();

        $response = $this->chat($id, ['messages' => self::ask(), 'target' => 'candidate'], self::AGENT_ADMIN);

        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertSame('Agent "'.AgentFixtures::PREFIX.'disabled" is disabled.', self::detail($response));
        self::assertSame($calls, $this->providerCalls());
        self::assertSame(0, AgentFixtures::versionCount());
    }

    public function testInvalidCandidateIsRejectedAtTheCandidatePath(): void
    {
        $id = $this->createAgent('invalid', AgentFixtures::validCandidate(['collections' => [AgentFixtures::PREFIX.'missing']]));
        $calls = $this->providerCalls();

        $response = $this->chat($id, ['messages' => self::ask(), 'target' => 'candidate'], self::AGENT_ADMIN);

        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertSame(['candidate/collections/0'], AgentFixtures::violationPaths($response));
        self::assertSame($calls, $this->providerCalls());
        self::assertSame(0, AgentFixtures::versionCount());
    }

    public function testCandidateNeedsTheAgentAdminRole(): void
    {
        $id = $this->createAgent('role');
        $published = $this->publish($id);
        $calls = $this->providerCalls();

        $response = $this->chat($id, ['messages' => self::ask(), 'target' => 'candidate']);

        self::assertSame(403, $response->getStatusCode(), $response->getContent(false));
        self::assertSame('Chatting with the candidate of an agent (target "candidate") requires the permission neuronai:agent:chat_candidate.', self::detail($response));
        self::assertSame($calls, $this->providerCalls());
        self::assertSame(1, AgentFixtures::versionCount());

        // The published target keeps the chat's role.
        $this->factory()->queueAnswer('Alle 500 Stunden.');
        $response = $this->chat($id, ['messages' => self::ask(), 'target' => 'published']);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertSame($published['@id'], $response->toArray()['agentVersion']);
    }

    public function testTargetOutsideTheEnumIsRejected(): void
    {
        $id = $this->createAgent('target');
        $this->publish($id);

        $response = $this->chat($id, ['messages' => self::ask(), 'target' => 'draft'], self::AGENT_ADMIN);

        self::assertSame(422, $response->getStatusCode(), $response->getContent(false));
        self::assertStringContainsString('target', self::detail($response));
        self::assertSame(1, AgentFixtures::versionCount());
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * `GET` on the shared client as `ROLE_USER`, decoded.
     *
     * @return array<string, mixed>
     */
    private function get(string $uri): array
    {
        $response = AgentFixtures::send($this->client, 'GET', $uri, AgentFixtures::USER);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));

        return $response->toArray();
    }
}
