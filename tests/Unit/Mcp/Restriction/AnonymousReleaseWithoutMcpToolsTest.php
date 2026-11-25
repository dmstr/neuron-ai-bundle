<?php

// file generated with AI assistance: Claude Code - 2026-10-09 07:01:30 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp\Restriction;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Agent\AgentReferenceResolver;
use Dmstr\NeuronAiBundle\Agent\LimitResolver;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousAccessPolicy;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousAccessPolicyInterface;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousConversationCreator;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousCreationRateLimiter;
use Dmstr\NeuronAiBundle\Anonymous\CapabilityToken;
use Dmstr\NeuronAiBundle\Anonymous\ClientAddressHasher;
use Dmstr\NeuronAiBundle\Anonymous\SystemActor;
use Dmstr\NeuronAiBundle\Conversation\ConversationReferences;
use Dmstr\NeuronAiBundle\Conversation\ProviderSelection;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\Mcp\McpToolFactory;
use Dmstr\NeuronAiBundle\Provider\Connection\ProviderConnectionResolver;
use Dmstr\NeuronAiBundle\Provider\ModelAvailabilityCheck;
use Dmstr\NeuronAiBundle\Provider\ProviderModelResolver;
use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use Dmstr\NeuronAiBundle\Testing\FakeModelLister;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

/**
 * Kernel-free checks that anonymous conversations get no MCP tools at
 * creation ({@see AnonymousConversationCreator}): a release naming
 * `mcp_connections` (with or without `agent`) and a release whose agent's
 * current version has `mcpTools` answer 422 `misconfigured (...)`, logged
 * at error, before anything is resolved or persisted. A release without
 * MCP connections and an agent without MCP tools are not affected (covered
 * end to end by the integration test `AnonymousCreationTest`).
 */
final class AnonymousReleaseWithoutMcpToolsTest extends TestCase
{
    private ?Agent $agent = null;

    private int $persisted = 0;

    /** @var list<string> */
    private array $errors = [];

    public function testReleasedMcpConnectionsAnswer422(): void
    {
        $this->assertRefused($this->creator($this->policy(mcpConnections: ['flowable'])), 'mcp_connections');
    }

    public function testReleasedMcpConnectionsNextToAnAgentAnswer422(): void
    {
        $this->agent = $this->publishedAgent([]);

        $this->assertRefused($this->creator($this->policy(mcpConnections: ['flowable'], agent: 'support')), 'mcp_connections');
    }

    public function testAgentVersionWithMcpToolsAnswers422(): void
    {
        $this->agent = $this->publishedAgent([
            ['connection' => '0199a1b2-0000-7000-8000-000000000001', 'name' => 'flowable_task_list', 'terminal' => false],
        ]);

        $this->assertRefused($this->creator($this->policy(agent: 'support')), 'agent');
    }

    /**
     * Collects the error log lines of the creator (called by its logger).
     */
    public function recordError(string $message): void
    {
        $this->errors[] = $message;
    }

    private function assertRefused(AnonymousConversationCreator $creator, string $setting): void
    {
        try {
            $creator->create('2001:db8::7');
            self::fail('Expected a 422.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertSame(\sprintf('Anonymous conversations are misconfigured (%s): anonymous conversations cannot use MCP tools; please contact the operator.', $setting), $e->getMessage());
        }

        self::assertSame(0, $this->persisted, 'nothing is stored');
        self::assertCount(1, $this->errors);
        self::assertStringContainsString('cannot use MCP tools', $this->errors[0]);
    }

    /**
     * @param list<string> $mcpConnections
     */
    private function policy(array $mcpConnections = [], ?string $agent = null): AnonymousAccessPolicyInterface
    {
        $policy = new AnonymousAccessPolicy(
            collections: [],
            mcpConnections: $mcpConnections,
            model: null,
            retentionDays: 30,
            creationsPerAddressAndHour: 20,
            maxTurnsPerConversation: 10,
            maxTokensPerConversation: 100000,
            deletionDelayDays: 7,
            agent: $agent,
        );

        $stub = $this->createStub(AnonymousAccessPolicyInterface::class);
        $stub->method('current')->willReturn($policy);

        return $stub;
    }

    /**
     * @param list<array{connection: string, name: string, terminal: bool}> $mcpTools
     */
    private function publishedAgent(array $mcpTools): Agent
    {
        $agent = new Agent()->setName('Support Bot')->setSlug('support');
        $agent->setCurrentVersion(new AgentVersion($agent, 1, ['mcpTools' => $mcpTools] + Agent::candidateDefaults(), 'alice'));

        return $agent;
    }

    private function creator(AnonymousAccessPolicyInterface $policy): AnonymousConversationCreator
    {
        $entityManager = $this->entityManager();
        $iriConverter = $this->createStub(IriConverterInterface::class);
        $iriConverter->method('getResourceFromIri')->willThrowException(new ItemNotFoundException('Not found'));
        $uuidResolver = $this->createStub(UuidResolver::class);
        $uuidResolver->method('findByPartialUuid')->willReturn(null);
        $connectionResolver = new McpConnectionResolver($entityManager, $iriConverter, $uuidResolver);

        $test = $this;
        $logger = new class($test) extends AbstractLogger {
            public function __construct(private readonly AnonymousReleaseWithoutMcpToolsTest $test)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ('error' === $level) {
                    $this->test->recordError((string) $message);
                }
            }
        };

        return new AnonymousConversationCreator(
            $policy,
            new ClientAddressHasher('test-key'),
            new AnonymousCreationRateLimiter(new CacheStorage(new ArrayAdapter())),
            new CapabilityToken(),
            new ConversationReferences($entityManager, $connectionResolver),
            new ProviderSelection(new ProviderModelResolver($iriConverter, $uuidResolver), new ModelAvailabilityCheck(new FakeModelLister())),
            new SystemActor(new TokenStorage()),
            $entityManager,
            4,
            $logger,
            agentResolver: new AgentReferenceResolver($entityManager),
            turnMapper: new VersionTurnMapper(
                new ProviderConnectionResolver($entityManager, $iriConverter, $uuidResolver),
                $entityManager,
                $connectionResolver,
                new McpToolFactory($entityManager, new FakeMcpTransportFactory()),
                new LimitResolver(),
                null,
                new MockClock(),
                4,
                null,
            ),
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturn([]);
        $repository->method('find')->willReturn(null);
        $repository->method('findOneBy')->willReturnCallback(fn (array $criteria): ?Agent => null !== $this->agent && $this->agent->getSlug() === ($criteria['slug'] ?? null) ? $this->agent : null);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('persist')->willReturnCallback(function (): void {
            ++$this->persisted;
        });

        return $entityManager;
    }
}
