<?php

// file generated with AI assistance: Claude Code - 2026-10-02 22:05:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Agent;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\DoctrineAuditLog\Entity\LogEntry;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use PHPUnit\Framework\Attributes\Group;

/**
 * Audit of agents: creating an agent writes a
 * `create` entry, publish and rollback each an `update` entry (Gedmo
 * `Versioned` on `currentVersionNumber`, which names the version that
 * became current; the association itself is not versioned, its Uuid
 * identifier cannot be stored in the log), all with the acting user, and
 * every entry stays readable; a candidate edit
 * is not logged (the candidate is not versioned), and a rejected request
 * (403, 422) writes nothing. Follows the conversation DeletionAuditTest.
 *
 * Uses the fixtures of {@see AgentFixtures}; its cleanUp() removes the
 * log entries by `objectClass`. Needs the migrated test database; runs in
 * CI.
 */
#[Group('database')]
final class AgentAuditTest extends ApiTestCase
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

    public function testCreatePublishAndRollbackAreAuditedWithTheActingUser(): void
    {
        $id = AgentFixtures::createAgent('audit', null, AgentFixtures::AGENT_ADMIN);
        self::assertSame([['create', TestHeaderAuthenticator::USER_IDENTIFIER]], $this->trail($id));

        $first = AgentFixtures::publish($id, AgentFixtures::AGENT_ADMIN);
        self::assertSame(1, $first['versionNumber'] ?? null);
        AgentFixtures::publish($id);
        AgentFixtures::rollback($id, ['version' => $first['@id']], AgentFixtures::AGENT_ADMIN);
        self::assertResponseStatusCodeSame(200);

        self::assertSame([
            ['create', TestHeaderAuthenticator::USER_IDENTIFIER],
            ['update', TestHeaderAuthenticator::USER_IDENTIFIER],
            ['update', TestHeaderAuthenticator::ADMIN_IDENTIFIER],
            ['update', TestHeaderAuthenticator::USER_IDENTIFIER],
        ], $this->trail($id));

        $entries = $this->entries($id);
        // The create entry holds scalars only: enabled and, if listed at all, a null version number.
        $created = $entries[0]->getData() ?? [];
        self::assertTrue($created['enabled'] ?? null);
        self::assertNull($created['currentVersionNumber'] ?? null);
        self::assertArrayNotHasKey('currentVersion', $created);
        self::assertSame(
            [['currentVersionNumber' => 1], ['currentVersionNumber' => 2], ['currentVersionNumber' => 1]],
            array_map(static fn (LogEntry $entry): ?array => $entry->getData(), \array_slice($entries, 1)),
        );
        foreach ($entries as $entry) {
            self::assertSame(Agent::class, $entry->getObjectClass());
        }
    }

    public function testEnabledChangeIsAuditedButACandidateEditIsNot(): void
    {
        $id = AgentFixtures::createAgent('flags');

        AgentFixtures::request('PATCH', AgentFixtures::AGENTS.'/'.$id, AgentFixtures::ADMIN, ['candidate' => AgentFixtures::validCandidate(['maxToolCalls' => 3])]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['create'], array_column($this->trail($id), 0));

        AgentFixtures::request('PATCH', AgentFixtures::AGENTS.'/'.$id, AgentFixtures::ADMIN, ['enabled' => false]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['create', 'update'], array_column($this->trail($id), 0));
    }

    public function testRejectedRequestsAreNotAudited(): void
    {
        $id = AgentFixtures::createAgent('rejected');
        $own = AgentFixtures::publish($id);
        $foreign = AgentFixtures::publish(AgentFixtures::createAgent('rejected-foreign'));
        $before = $this->trail($id);
        self::assertSame(['create', 'update'], array_column($before, 0));
        self::assertSame(1, $own['versionNumber'] ?? null);

        // 403: ROLE_USER may not publish, roll back or change the agent.
        AgentFixtures::request('POST', AgentFixtures::AGENTS.'/'.$id.'/publish', AgentFixtures::USER);
        self::assertResponseStatusCodeSame(403);
        AgentFixtures::rollback($id, ['version' => $own['@id']], AgentFixtures::USER);
        self::assertResponseStatusCodeSame(403);
        AgentFixtures::request('PATCH', AgentFixtures::AGENTS.'/'.$id, AgentFixtures::USER, ['enabled' => false]);
        self::assertResponseStatusCodeSame(403);

        // 422: a version of another agent, an invalid candidate on publish.
        AgentFixtures::rollback($id, ['version' => $foreign['@id']]);
        self::assertResponseStatusCodeSame(422);
        AgentFixtures::request('PATCH', AgentFixtures::AGENTS.'/'.$id, AgentFixtures::ADMIN, ['candidate' => AgentFixtures::validCandidate(['collections' => [AgentFixtures::PREFIX.'missing']])]);
        self::assertResponseStatusCodeSame(200);
        AgentFixtures::request('POST', AgentFixtures::AGENTS.'/'.$id.'/publish', AgentFixtures::ADMIN);
        self::assertResponseStatusCodeSame(422);

        self::assertSame($before, $this->trail($id));
    }

    public function testRejectedCreationIsNotAudited(): void
    {
        AgentFixtures::request('POST', AgentFixtures::AGENTS, AgentFixtures::USER, ['name' => 'Denied', 'slug' => AgentFixtures::PREFIX.'denied']);
        self::assertResponseStatusCodeSame(403);

        AgentFixtures::request('POST', AgentFixtures::AGENTS, AgentFixtures::ADMIN, ['name' => 'Invalid', 'slug' => AgentFixtures::PREFIX.'invalid', 'candidate' => ['maxToolCalls' => 0]]);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(0, AgentFixtures::agentCount());
        self::assertSame(0, (int) AgentFixtures::dbal()->fetchOne('SELECT COUNT(*) FROM dmstr_log_entries WHERE object_class = :class', ['class' => Agent::class]));
    }

    /**
     * Action and username of every log entry of the agent, oldest first.
     *
     * @return list<array{string, ?string}>
     */
    private function trail(string $id): array
    {
        return array_map(
            static fn (LogEntry $entry): array => [$entry->getAction(), $entry->getUsername()],
            $this->entries($id),
        );
    }

    /**
     * @return list<LogEntry>
     */
    private function entries(string $id): array
    {
        $entityManager = AgentFixtures::entityManager();
        $entityManager->clear();

        return array_values($entityManager->getRepository(LogEntry::class)->findBy(
            ['objectClass' => Agent::class, 'objectId' => $id],
            ['version' => 'ASC'],
        ));
    }
}
