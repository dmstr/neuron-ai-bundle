<?php

// file generated with AI assistance: Claude Code - 2026-10-02 22:05:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Agent;

use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Lifecycle of an agent through the API: create, PATCH of the candidate
 * (replaced as a whole), two publishes with consecutive version numbers,
 * the frozen model (catalogue entry) and references as full UUIDs,
 * rollback by IRI, full and partial UUID, a foreign version (422 at
 * `version`) and an unknown one (404), `enabled: false` round trip, and the
 * structural 422 cases of POST/PATCH (unknown candidate key, string
 * `maxToolCalls`, duplicate slug).
 *
 * The fixtures, requests and cleanup come from {@see AgentFixtures}
 * (prefix `test-agent-`), shared by the other database-bound agent tests.
 * Requests without identity headers run as `test-admin` with `ROLE_ADMIN`.
 * Group `database`.
 */
#[Group('database')]
final class AgentResourceTest extends AgentFixtures
{
    protected function setUp(): void
    {
        TestDatabase::migrate(static::bootKernel());
        self::cleanUp();
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        parent::tearDown();
    }

    public function testCreateWithoutCandidateGetsTheSchemaDefaults(): void
    {
        $response = self::request('POST', self::AGENTS, self::ADMIN, ['name' => 'Support', 'slug' => self::PREFIX.'support']);

        self::assertResponseStatusCodeSame(201);
        $data = $response->toArray();
        self::assertSame('Support', $data['name']);
        self::assertSame(self::PREFIX.'support', $data['slug']);
        self::assertTrue($data['enabled']);
        self::assertNull($data['currentVersion'] ?? null);
        self::assertNull($data['currentVersionNumber'] ?? null);
        self::assertSame(['de'], $data['candidate']['languages']);
        self::assertSame('de', $data['candidate']['defaultLanguage']);
        self::assertSame([], $data['candidate']['mcpTools']);
        self::assertNull($data['candidate']['maxToolCalls']);
        self::assertCount(\count(Agent::candidateDefaults()), $data['candidate']);
        self::assertTrue($data['candidate']['hybrid']);
        self::assertSame(0.3, $data['candidate']['hybridWeight']);
        self::assertSame(['maxTokens' => 2048], $data['candidate']['modelOptions']);
    }

    public function testCreateWithAPartialCandidateGetsTheNewAgentDefaults(): void
    {
        $response = self::request('POST', self::AGENTS, self::ADMIN, ['name' => 'Partial', 'slug' => self::PREFIX.'partial', 'candidate' => ['retrievalDepth' => 5]]);

        self::assertResponseStatusCodeSame(201);
        $candidate = $response->toArray()['candidate'];
        self::assertSame(5, $candidate['retrievalDepth']);
        self::assertTrue($candidate['hybrid']);
        self::assertSame(0.3, $candidate['hybridWeight']);
        self::assertSame(['maxTokens' => 2048], $candidate['modelOptions']);
    }

    public function testPatchReplacesTheCandidateAsAWhole(): void
    {
        $id = self::createAgent('replace', self::validCandidate(['maxToolCalls' => 4, 'modelOptions' => ['temperature' => 0.5]]));

        $response = self::request('PATCH', self::AGENTS.'/'.$id, self::ADMIN, ['candidate' => [
            'instructions' => ['de' => 'Neu.', 'en' => 'New.'],
            'languages' => ['de', 'en'],
            'defaultLanguage' => 'de',
        ]]);

        self::assertResponseStatusCodeSame(200);
        $candidate = $response->toArray()['candidate'];
        self::assertSame(['de' => 'Neu.', 'en' => 'New.'], $candidate['instructions']);
        self::assertSame(['de', 'en'], $candidate['languages']);
        self::assertNull($candidate['maxToolCalls'] ?? null);
        // PATCH stores the candidate as sent; missing keys take the schema
        // defaults only when the candidate is published or mapped.
        self::assertNull($candidate['modelOptions'] ?? null);
    }

    public function testEnabledFalseIsStoredAndRead(): void
    {
        $id = self::createAgent('disabled');

        self::request('PATCH', self::AGENTS.'/'.$id, self::ADMIN, ['enabled' => false]);
        self::assertResponseStatusCodeSame(200);

        self::assertFalse(self::request('GET', self::AGENTS.'/'.$id, self::USER)->toArray()['enabled']);
        self::assertFalse((bool) self::dbal()->fetchOne('SELECT enabled FROM neuron_agent WHERE id = :id', ['id' => $id]));

        $filtered = self::request('GET', self::AGENTS.'?enabled=false&slug='.self::PREFIX.'disabled', self::USER)->toArray();
        self::assertSame([$id], array_column($filtered['hydra:member'], 'id'));
    }

    public function testTwoPublishesNumberTheVersionsAndFreezeTheReferences(): void
    {
        $docs = self::collection('docs');
        $tools = self::connection('tools');
        self::tool($tools, 'search');
        self::tool($tools, 'finish');

        $id = self::createAgent('lifecycle', self::validCandidate([
            'collections' => [$docs->getName()],
            'mcpTools' => [
                ['connection' => $tools->getName(), 'name' => 'search'],
                ['connection' => substr($tools->getId()->toRfc4122(), 0, 8), 'name' => 'finish', 'terminal' => true],
            ],
        ]));

        $first = self::publish($id);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $first['versionNumber']);
        self::assertSame(self::AGENTS.'/'.$id, $first['agent']);
        self::assertSame(TestHeaderAuthenticator::ADMIN_IDENTIFIER, $first['publishedBy']);
        self::assertSame(self::model()->getId()->toRfc4122(), $first['configuration']['model']);
        self::assertArrayNotHasKey('connection', $first['configuration']);
        self::assertArrayNotHasKey('provider', $first['configuration']);
        self::assertSame([$docs->getId()->toRfc4122()], $first['configuration']['collections']);
        self::assertSame([
            ['connection' => $tools->getId()->toRfc4122(), 'name' => 'search', 'terminal' => false],
            ['connection' => $tools->getId()->toRfc4122(), 'name' => 'finish', 'terminal' => true],
        ], $first['configuration']['mcpTools']);
        self::assertSame($first['@id'], self::agent($id)['currentVersion']);
        self::assertSame(1, self::agent($id)['currentVersionNumber']);
        $firstRow = self::versionRow($first['id']);

        // The candidate changes after the first publish; version 1 does not.
        self::request('PATCH', self::AGENTS.'/'.$id, self::ADMIN, ['candidate' => self::validCandidate(['maxToolCalls' => 2])]);
        self::assertResponseStatusCodeSame(200);

        $second = self::publish($id);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(2, $second['versionNumber']);
        self::assertSame(2, $second['configuration']['maxToolCalls']);
        self::assertSame([], $second['configuration']['collections']);
        self::assertSame($second['@id'], self::agent($id)['currentVersion']);
        self::assertSame(2, self::agent($id)['currentVersionNumber']);
        self::assertSame($firstRow, self::versionRow($first['id']));

        $list = self::request('GET', self::VERSIONS.'?agent='.urlencode(self::AGENTS.'/'.$id), self::USER)->toArray();
        self::assertSame([1, 2], array_column($list['hydra:member'], 'versionNumber'));
    }

    public function testRollbackAcceptsIriFullAndPartialUuid(): void
    {
        $id = self::createAgent('rollback');
        $first = self::publish($id);
        $second = self::publish($id);
        $rows = [self::versionRow($first['id']), self::versionRow($second['id'])];

        foreach ([$first['@id'], $second['id'], substr($first['id'], 0, 8)] as $reference) {
            $expected = $reference === $second['id'] ? $second['@id'] : $first['@id'];

            $response = self::rollback($id, ['version' => $reference]);

            self::assertResponseStatusCodeSame(200);
            self::assertSame(self::AGENTS.'/'.$id, $response->toArray()['@id']);
            self::assertSame($expected, $response->toArray()['currentVersion'], $reference);
            self::assertSame($expected, self::agent($id)['currentVersion'], $reference);
            self::assertSame($expected === $second['@id'] ? 2 : 1, self::agent($id)['currentVersionNumber'], $reference);
        }

        self::assertSame($rows, [self::versionRow($first['id']), self::versionRow($second['id'])]);
        self::assertSame(2, (int) self::dbal()->fetchOne('SELECT COUNT(*) FROM neuron_agent_version WHERE agent_id = :id', ['id' => $id]));
    }

    public function testRollbackToAVersionOfAnotherAgentIsRejected(): void
    {
        $id = self::createAgent('own');
        $own = self::publish($id);
        $foreign = self::publish(self::createAgent('foreign'));

        $response = self::rollback($id, ['version' => $foreign['@id']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['version'], self::violationPaths($response));
        self::assertSame($own['@id'], self::agent($id)['currentVersion']);
    }

    public function testRollbackToAnUnknownVersionIsNotFound(): void
    {
        $id = self::createAgent('unknown');
        $own = self::publish($id);

        foreach ([Uuid::v4()->toRfc4122(), self::VERSIONS.'/'.Uuid::v4()->toRfc4122(), 'not-a-version', '%'] as $reference) {
            self::rollback($id, ['version' => $reference]);
            self::assertResponseStatusCodeSame(404, $reference);
        }

        self::assertSame($own['@id'], self::agent($id)['currentVersion']);
    }

    public function testRollbackBodyIsValidatedAgainstTheInputSchema(): void
    {
        $id = self::createAgent('body');
        self::publish($id);

        self::rollback($id, []);
        self::assertResponseStatusCodeSame(422);

        self::rollback($id, ['version' => self::VERSIONS.'/x', 'extra' => true]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testPublishOfAnUnknownAgentIsNotFound(): void
    {
        self::request('POST', self::AGENTS.'/'.Uuid::v4()->toRfc4122().'/publish', self::ADMIN);

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownCandidateKeyIsRejected(): void
    {
        $id = self::createAgent('unknown-key');

        $response = self::request('PATCH', self::AGENTS.'/'.$id, self::ADMIN, ['candidate' => self::validCandidate(['chatModel' => 'x'])]);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('candidate', self::violationPaths($response));
    }

    /**
     * The key `draft` was renamed to `candidate` without transition: an old
     * client gets 422 instead of a silently ignored configuration.
     */
    public function testRenamedDraftKeyIsRefused(): void
    {
        self::request('POST', self::AGENTS, self::ADMIN, ['name' => 'Old', 'slug' => self::PREFIX.'old-client', 'draft' => self::validCandidate()]);
        self::assertResponseStatusCodeSame(422);

        $id = self::createAgent('old-patch', self::validCandidate(['maxToolCalls' => 4]));
        self::request('PATCH', self::AGENTS.'/'.$id, self::ADMIN, ['draft' => self::validCandidate(['maxToolCalls' => 2])]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(4, self::agent($id)['candidate']['maxToolCalls']);
    }

    public function testStringMaxToolCallsIsRejected(): void
    {
        $id = self::createAgent('string-bound');

        $response = self::request('PATCH', self::AGENTS.'/'.$id, self::ADMIN, ['candidate' => self::validCandidate(['maxToolCalls' => '3'])]);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('candidate/maxToolCalls', self::violationPaths($response));
        self::assertNull(self::agent($id)['candidate']['maxToolCalls'] ?? null);
    }

    public function testInvalidCandidateOnCreateIsRejected(): void
    {
        $response = self::request('POST', self::AGENTS, self::ADMIN, ['name' => 'Bad', 'slug' => self::PREFIX.'bad', 'candidate' => ['maxToolCalls' => 0]]);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('candidate/maxToolCalls', self::violationPaths($response));
        self::assertSame(0, self::agentCount());
    }

    public function testDuplicateSlugIsRejected(): void
    {
        self::createAgent('twin');

        $response = self::request('POST', self::AGENTS, self::ADMIN, ['name' => 'Twin', 'slug' => self::PREFIX.'twin']);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('slug', self::violationPaths($response));
        self::assertSame(1, self::agentCount());
    }

    public function testInvalidSlugIsRejected(): void
    {
        $response = self::request('POST', self::AGENTS, self::ADMIN, ['name' => 'Upper', 'slug' => 'Test-Agent-Upper']);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('slug', self::violationPaths($response));
    }
}
