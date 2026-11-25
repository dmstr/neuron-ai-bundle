<?php

// file generated with AI assistance: Claude Code - 2026-10-02 22:05:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Agent;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Agent\AgentConfigurationValidator;
use Dmstr\NeuronAiBundle\Agent\AgentPublisher;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Conversation\ConversationReferences;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\Provider\ModelAvailabilityCheck;
use Dmstr\NeuronAiBundle\Provider\ProviderModelResolver;
use Dmstr\NeuronAiBundle\State\AgentPublishProcessor;
use Dmstr\NeuronAiBundle\Testing\FakeModelLister;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Kernel-free checks of `agent_publish`: the publishing user is the token's
 * user identifier, no token or user denies before anything is validated or
 * written, and the real AgentPublisher numbers the version after the
 * highest existing one and makes it current. The entity manager is a stub:
 * the transaction runs the callback, the max query answers a fixed number,
 * the candidate references the catalogue entry `claude-default` of the
 * `anthropic` connection `claude` by IRI; the model lister is the fake
 * (every model available).
 */
final class AgentPublishProcessorTest extends TestCase
{
    private int $persists = 0;
    private int $flushes = 0;

    private ?ApiConfiguration $connection = null;
    private ProviderModel $model;

    protected function setUp(): void
    {
        $this->connection = ProviderConnectionFixtures::anthropic('claude');
        $this->model = new ProviderModel($this->connection, 'claude-default');
    }

    public function testVersionIsPublishedByTheTokenUser(): void
    {
        $agent = $this->agent();

        $version = $this->processor(self::tokenStorage('test-publisher'), 1)->process($agent, new Post());

        self::assertInstanceOf(AgentVersion::class, $version);
        self::assertSame('test-publisher', $version->getPublishedBy());
        self::assertSame(2, $version->getVersionNumber());
        self::assertSame($agent, $version->getAgent());
        self::assertSame($version, $agent->getCurrentVersion());
        self::assertSame($this->model->getId()->toRfc4122(), $version->getConfiguration()['model']);
        self::assertArrayNotHasKey('connection', $version->getConfiguration());
        self::assertArrayNotHasKey('provider', $version->getConfiguration());
        self::assertSame(1, $this->persists);
        self::assertSame(1, $this->flushes);
    }

    public function testFirstVersionIsNumberOne(): void
    {
        $version = $this->processor(self::tokenStorage('test-publisher'), null)->process($this->agent(), new Post());

        self::assertSame(1, $version->getVersionNumber());
    }

    public function testMissingTokenIsDenied(): void
    {
        $this->assertDenied(new TokenStorage());
    }

    public function testTokenWithoutUserIsDenied(): void
    {
        $storage = new TokenStorage();
        $storage->setToken(new NullToken());

        $this->assertDenied($storage);
    }

    public function testInvalidCandidateIsNotPublished(): void
    {
        $agent = $this->agent()->setCandidate(['instructions' => [], 'model' => $this->model->iri(), 'languages' => ['de'], 'defaultLanguage' => 'de']);

        try {
            $this->processor(self::tokenStorage('test-publisher'), 1)->process($agent, new Post());
            self::fail('Expected a validation exception.');
        } catch (ValidationException $e) {
            self::assertSame('candidate/instructions', $e->getConstraintViolationList()->get(0)->getPropertyPath());
        }

        self::assertNull($agent->getCurrentVersion());
        self::assertSame(0, $this->persists);
        self::assertSame(0, $this->flushes);
    }

    public function testOtherDataIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->processor(self::tokenStorage('test-publisher'), 1)->process(new \stdClass(), new Post());
    }

    private function assertDenied(TokenStorage $storage): void
    {
        $agent = $this->agent();

        try {
            $this->processor($storage, 1)->process($agent, new Post());
            self::fail('Expected an access denial.');
        } catch (AccessDeniedException) {
        }

        self::assertNull($agent->getCurrentVersion());
        self::assertSame(0, $this->persists);
        self::assertSame(0, $this->flushes);
    }

    private function agent(): Agent
    {
        return new Agent()->setName('Support')->setSlug('support')->setCandidate([
            'instructions' => ['de' => 'Du bist hilfreich.'],
            'model' => $this->model->iri(),
            'languages' => ['de'],
            'defaultLanguage' => 'de',
        ]);
    }

    private static function tokenStorage(string $identifier): TokenStorage
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new InMemoryUser($identifier, null, ['ROLE_AGENT_ADMIN']), 'api', ['ROLE_AGENT_ADMIN']));

        return $storage;
    }

    /**
     * @param int|null $maxVersion highest existing version number, null = none
     */
    private function processor(TokenStorage $tokenStorage, ?int $maxVersion): AgentPublishProcessor
    {
        $query = $this->createStub(Query::class);
        $query->method('getSingleScalarResult')->willReturn($maxVersion);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'setParameter'] as $method) {
            $queryBuilder->method($method)->willReturnSelf();
        }
        $queryBuilder->method('getQuery')->willReturn($query);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $entityManager->method('createQueryBuilder')->willReturn($queryBuilder);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            self::assertInstanceOf(AgentVersion::class, $entity);
            ++$this->persists;
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturnCallback(fn (array $criteria): array => null !== $this->connection && $criteria['name'] === $this->connection->getName() ? [$this->connection] : []);
        $entityManager->method('getRepository')->willReturn($repository);

        $connectionResolver = new McpConnectionResolver($entityManager, $this->createStub(IriConverterInterface::class), $this->createStub(UuidResolver::class));
        $iriConverter = $this->createStub(IriConverterInterface::class);
        $iriConverter->method('getResourceFromIri')->willReturnCallback(fn (string $iri): object => $iri === $this->model->iri() ? $this->model : throw new ItemNotFoundException('Not found'));
        $validator = new AgentConfigurationValidator(
            new ProviderModelResolver($iriConverter, $this->createStub(UuidResolver::class)),
            new ModelAvailabilityCheck(new FakeModelLister()),
            new ConversationReferences($entityManager, $connectionResolver),
            $connectionResolver,
            $entityManager,
        );

        return new AgentPublishProcessor(new AgentPublisher($entityManager, $validator), $tokenStorage);
    }
}
