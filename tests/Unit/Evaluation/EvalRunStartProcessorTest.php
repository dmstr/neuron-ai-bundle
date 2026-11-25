<?php

// file generated with AI assistance: Claude Code - 2026-10-03 19:10:10 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Evaluation;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Agent\AgentConfigurationValidator;
use Dmstr\NeuronAiBundle\Agent\AgentPreviewFactory;
use Dmstr\NeuronAiBundle\Agent\AgentReferenceResolver;
use Dmstr\NeuronAiBundle\Agent\AgentVersionKind;
use Dmstr\NeuronAiBundle\Conversation\ConversationReferences;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Dmstr\NeuronAiBundle\Entity\EvalRun;
use Dmstr\NeuronAiBundle\Evaluation\BatchRunner;
use Dmstr\NeuronAiBundle\Evaluation\EvalQuestionStatus;
use Dmstr\NeuronAiBundle\Evaluation\EvalRunStatus;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\Provider\ModelAvailabilityCheck;
use Dmstr\NeuronAiBundle\Provider\ModelListerInterface;
use Dmstr\NeuronAiBundle\Provider\ProviderModelResolver;
use Dmstr\NeuronAiBundle\State\EvalRunStartProcessor;
use Dmstr\NeuronAiBundle\State\InputBodyValidator;
use Dmstr\OpenApiJsonSchema\Service\OperationInputSchemaResolver;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\JobQueueService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Uid\Uuid;

/**
 * Kernel-free checks of `POST /eval_runs/start`: the body against
 * `EvalRun/start.input.json` (400 malformed, 422 schema), the version
 * reference (IRI or full UUID, never partial), the 422 refusals (unknown
 * version, disabled agent, stale or unpublished version, no active
 * question, `mode` `retrieval` without knowledge collections) before
 * anything is persisted or queued, the stored run with the queued job on
 * success, and the run mode (derived from the version's
 * collections, overridable, always stored) and the retrieval-only switch
 * (implies `retrieval`, refused with mode `agent` or without
 * collections, stored on the run). Previews: a preview of an enabled
 * agent is startable whatever the agent's current version is, a preview
 * of a disabled agent is 422, and messages name it `preview <hash8>`. The
 * candidate branch (`agent` with `candidate: true`): the agent is resolved
 * by IRI, UUID or slug and handed to {@see AgentPreviewFactory} (shown by
 * its refusals: an invalid candidate at `candidate/<field>`, a disabled
 * agent), an unknown agent is 422, and both ways in one body answer 422
 * naming the keys. Creating and reusing a preview needs the database
 * (integration test `EvalRunCandidateTest`). Entity manager
 * and job queue are test doubles over in-memory agents.
 */
final class EvalRunStartProcessorTest extends TestCase
{
    private const string ENTITY_ROOT = __DIR__.'/../../../src/Entity';

    /** @var list<AgentVersion> */
    private array $versions = [];

    private int $activeQuestions = 2;

    /** @var list<object> */
    private array $persisted = [];

    private int $flushes = 0;

    /** @var list<array{string, array<string, mixed>}> */
    private array $dispatched = [];

    private ?Job $job = null;

    /** @var list<Agent> agents the reference resolver finds */
    private array $agents = [];

    /**
     * @param list<string> $collections collection UUID strings of the version's configuration
     */
    private function version(bool $enabled = true, bool $current = true, int $number = 1, array $collections = []): AgentVersion
    {
        $agent = new Agent()->setName('Agent')->setSlug('eval-agent')->setEnabled($enabled);
        $version = new AgentVersion($agent, $number, ['collections' => $collections] + Agent::candidateDefaults(), 'test-admin');
        if ($current) {
            $agent->setCurrentVersion($version);
        }
        $this->versions[] = $version;

        return $version;
    }

    /**
     * A preview of an agent (number 0, kind preview); the agent's current
     * version is a published version 1 when $published.
     *
     * @param list<string> $collections collection UUID strings of the preview's configuration
     */
    private function preview(bool $enabled = true, bool $published = false, array $collections = []): AgentVersion
    {
        $agent = new Agent()->setName('Agent')->setSlug('eval-agent')->setEnabled($enabled);
        if ($published) {
            $agent->setCurrentVersion(new AgentVersion($agent, 1, Agent::candidateDefaults(), 'test-admin'));
        }
        $preview = new AgentVersion($agent, 0, ['collections' => $collections] + Agent::candidateDefaults(), 'eval-admin', null, AgentVersionKind::PREVIEW, str_repeat('ab', 32));
        $this->versions[] = $preview;

        return $preview;
    }

    /**
     * An agent the reference resolver finds, with $candidate as its candidate.
     *
     * @param array<string, mixed> $candidate
     */
    private function candidateAgent(array $candidate, bool $enabled = true): Agent
    {
        $agent = new Agent()->setName('Agent')->setSlug('eval-agent')->setEnabled($enabled)->setCandidate($candidate);
        $this->agents[] = $agent;

        return $agent;
    }

    private function processor(?string $user = 'eval-admin'): EvalRunStartProcessor
    {
        $questions = $this->createStub(EntityRepository::class);
        $questions->method('count')->willReturnCallback(function (array $criteria): int {
            self::assertSame(['status' => EvalQuestionStatus::ACTIVE], $criteria);

            return $this->activeQuestions;
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $agents = $this->createStub(EntityRepository::class);
        $agents->method('find')->willReturnCallback(function (mixed $id): ?Agent {
            foreach ($this->agents as $agent) {
                if ($id instanceof Uuid && $agent->getId()->equals($id)) {
                    return $agent;
                }
            }

            return null;
        });
        $agents->method('findOneBy')->willReturnCallback(function (array $criteria): ?Agent {
            foreach ($this->agents as $agent) {
                if ($agent->getSlug() === ($criteria['slug'] ?? null)) {
                    return $agent;
                }
            }

            return null;
        });

        $entityManager->method('getRepository')->willReturnCallback(
            fn (string $class): EntityRepository => match ($class) {
                EvalQuestion::class => $questions,
                Agent::class => $agents,
                default => self::fail('Unexpected repository '.$class),
            },
        );
        $entityManager->method('find')->willReturnCallback(function (string $class, mixed $id): ?AgentVersion {
            self::assertSame(AgentVersion::class, $class);
            foreach ($this->versions as $version) {
                if ($id instanceof Uuid && $version->getId()->equals($id)) {
                    return $version;
                }
            }

            return null;
        });
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        $jobQueue = $this->createStub(JobQueueService::class);
        $jobQueue->method('createAndDispatch')->willReturnCallback(function (string $type, array $input): Job {
            $this->dispatched[] = [$type, $input];
            $this->job = new Job()->setType($type)->setInputData($input);

            return $this->job;
        });

        $tokenStorage = new TokenStorage();
        if (null !== $user) {
            $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser($user, null, ['ROLE_EVAL_ADMIN']), 'api', ['ROLE_EVAL_ADMIN']));
        }

        // The factory's validator refuses the candidates of these tests
        // before any reference is resolved, so its doubles are never used.
        $iriConverter = $this->createStub(IriConverterInterface::class);
        $uuidResolver = $this->createStub(UuidResolver::class);
        $connectionResolver = new McpConnectionResolver($entityManager, $iriConverter, $uuidResolver);
        $previewFactory = new AgentPreviewFactory($entityManager, new AgentConfigurationValidator(
            new ProviderModelResolver($iriConverter, $uuidResolver),
            new ModelAvailabilityCheck($this->createStub(ModelListerInterface::class)),
            new ConversationReferences($entityManager, $connectionResolver),
            $connectionResolver,
            $entityManager,
        ));

        return new EvalRunStartProcessor(
            new InputBodyValidator(new OperationInputSchemaResolver([], [[self::ENTITY_ROOT, 'Dmstr\\NeuronAiBundle\\Entity']])),
            $entityManager,
            $jobQueue,
            $tokenStorage,
            new AgentReferenceResolver($entityManager),
            $previewFactory,
        );
    }

    private function start(EvalRunStartProcessor $processor, string $body): EvalRun
    {
        $request = Request::create('/api/eval_runs/start', 'POST', content: $body);

        return $processor->process(null, new Post(name: EvalRun::OPERATION_START), [], ['request' => $request]);
    }

    private function assertNothingStored(): void
    {
        self::assertSame([], $this->persisted);
        self::assertSame([], $this->dispatched);
        self::assertSame(0, $this->flushes);
    }

    public function testStartsARunOnTheCurrentVersionAndQueuesItsJob(): void
    {
        $version = $this->version();
        $this->activeQuestions = 3;

        $run = $this->start($this->processor(), json_encode(['agentVersion' => '/api/agent_versions/'.$version->getId()], \JSON_THROW_ON_ERROR));

        self::assertSame([$run], $this->persisted);
        self::assertSame($version, $run->getAgentVersion());
        self::assertSame('eval-admin', $run->getOwnerSubject());
        self::assertSame(EvalRunStatus::PENDING, $run->getStatus());
        self::assertSame(3, $run->getQuestionsTotal());
        self::assertSame(BatchRunner::MODE_AGENT, $run->getMode());
        self::assertFalse($run->isRetrievalOnly());
        self::assertNull($run->getStartedAt());
        self::assertSame([[EvalRun::JOB_TYPE_START, ['run' => (string) $run->getId(), 'ownerSubject' => 'eval-admin']]], $this->dispatched);
        self::assertInstanceOf(Job::class, $this->job);
        self::assertSame($this->job->getId(), $run->getJobId());
        self::assertSame(1, $this->flushes);
    }

    public function testAcceptsAFullUuid(): void
    {
        $version = $this->version();

        $run = $this->start($this->processor(), json_encode(['agentVersion' => (string) $version->getId()], \JSON_THROW_ON_ERROR));

        self::assertSame($version, $run->getAgentVersion());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'missing agentVersion' => ['{}', 'agentVersion'];
        yield 'empty body' => ['', 'agentVersion'];
        yield 'unknown property' => ['{"agentVersion": "x", "speed": "fast"}', 'speed'];
        yield 'mode outside the enum' => ['{"agentVersion": "x", "mode": "fast"}', 'mode'];
        yield 'mode not a string' => ['{"agentVersion": "x", "mode": true}', 'mode'];
        yield 'retrievalOnly not a boolean' => ['{"agentVersion": "x", "retrievalOnly": "yes"}', 'retrievalOnly'];
        yield 'not a string' => ['{"agentVersion": 7}', 'agentVersion'];
        yield 'empty string' => ['{"agentVersion": ""}', 'agentVersion'];
        yield 'agent without candidate' => ['{"agent": "eval-agent"}', 'candidate'];
        yield 'candidate without agent' => ['{"candidate": true}', 'agent'];
        yield 'candidate false' => ['{"agent": "eval-agent", "candidate": false}', 'candidate'];
        yield 'empty agent' => ['{"agent": "", "candidate": true}', 'agent'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bothVersionSources(): iterable
    {
        yield 'agentVersion and agent' => ['{"agentVersion": "x", "agent": "eval-agent"}'];
        yield 'agentVersion and candidate' => ['{"agentVersion": "x", "candidate": true}'];
        yield 'all three' => ['{"agentVersion": "x", "agent": "eval-agent", "candidate": true}'];
    }

    #[DataProvider('bothVersionSources')]
    public function testBothVersionSourcesAnswer422NamingTheKeys(string $body): void
    {
        $this->version();

        try {
            $this->start($this->processor(), $body);
            self::fail('Expected a 422.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertSame('"agentVersion" cannot be combined with "agent" or "candidate": give either an agent version or an agent with "candidate": true.', $e->getMessage());
        }
        $this->assertNothingStored();
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function previewAgents(): iterable
    {
        yield 'agent without published version' => [false];
        yield 'agent with another current version' => [true];
    }

    #[DataProvider('previewAgents')]
    public function testPreviewOfAnEnabledAgentIsStartable(bool $published): void
    {
        $preview = $this->preview(published: $published);

        $run = $this->start($this->processor(), json_encode(['agentVersion' => '/api/agent_versions/'.$preview->getId()], \JSON_THROW_ON_ERROR));

        self::assertSame($preview, $run->getAgentVersion());
        self::assertSame(BatchRunner::MODE_AGENT, $run->getMode());
        self::assertSame([$run], $this->persisted);
        self::assertSame([[EvalRun::JOB_TYPE_START, ['run' => (string) $run->getId(), 'ownerSubject' => 'eval-admin']]], $this->dispatched);
    }

    public function testPreviewOfADisabledAgentAnswers422(): void
    {
        $preview = $this->preview(enabled: false);

        try {
            $this->start($this->processor(), json_encode(['agentVersion' => (string) $preview->getId()], \JSON_THROW_ON_ERROR));
            self::fail('Expected a 422.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertSame(\sprintf('Agent "eval-agent" of version "%s" is disabled.', $preview->getId()), $e->getMessage());
        }
        $this->assertNothingStored();
    }

    public function testRetrievalOnlyOnAPreviewWithoutCollectionsNamesThePreview(): void
    {
        $preview = $this->preview();

        try {
            $this->start($this->processor(), json_encode(['agentVersion' => (string) $preview->getId(), 'retrievalOnly' => true], \JSON_THROW_ON_ERROR));
            self::fail('Expected a 422.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertSame('retrievalOnly: true needs an agent version with knowledge collections; preview abababab of agent "eval-agent" has none.', $e->getMessage());
        }
        $this->assertNothingStored();
    }

    public function testRetrievalOnlyWorksOnAPreviewWithCollections(): void
    {
        $preview = $this->preview(collections: [(string) Uuid::v4()]);

        $run = $this->start($this->processor(), json_encode(['agentVersion' => (string) $preview->getId(), 'retrievalOnly' => true], \JSON_THROW_ON_ERROR));

        self::assertSame($preview, $run->getAgentVersion());
        self::assertSame(BatchRunner::MODE_RETRIEVAL, $run->getMode());
        self::assertTrue($run->isRetrievalOnly());
    }

    /**
     * @return iterable<string, array{\Closure(Agent): string}>
     */
    public static function agentReferences(): iterable
    {
        yield 'slug' => [static fn (Agent $agent): string => $agent->getSlug()];
        yield 'full UUID' => [static fn (Agent $agent): string => $agent->getId()->toRfc4122()];
        yield 'IRI' => [static fn (Agent $agent): string => '/api/agents/'.$agent->getId()->toRfc4122()];
    }

    /**
     * The factory validates the candidate like a publish: reaching its
     * violation at `candidate/maxToolCalls` shows that the branch resolved
     * the agent and called the factory; nothing is stored.
     *
     * @param \Closure(Agent): string $reference
     */
    #[DataProvider('agentReferences')]
    public function testCandidateBranchHandsTheAgentToThePreviewFactory(\Closure $reference): void
    {
        $agent = $this->candidateAgent(['maxToolCalls' => 'many']);

        try {
            $this->start($this->processor(), json_encode(['agent' => $reference($agent), 'candidate' => true], \JSON_THROW_ON_ERROR));
            self::fail('Expected a validation exception.');
        } catch (ValidationException $e) {
            $paths = [];
            foreach ($e->getConstraintViolationList() as $violation) {
                $paths[] = $violation->getPropertyPath();
            }
            self::assertContains('candidate/maxToolCalls', $paths);
        }
        $this->assertNothingStored();
    }

    public function testCandidateOfADisabledAgentAnswers422(): void
    {
        $this->candidateAgent(['maxToolCalls' => 'many'], enabled: false);

        try {
            $this->start($this->processor(), '{"agent": "eval-agent", "candidate": true}');
            self::fail('Expected a 422.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertSame('Agent "eval-agent" is disabled.', $e->getMessage());
        }
        $this->assertNothingStored();
    }

    public function testCandidateOfAnUnknownAgentAnswers422(): void
    {
        $this->candidateAgent([]);

        try {
            $this->start($this->processor(), '{"agent": "/api/agents/'.Uuid::v4().'", "candidate": true}');
            self::fail('Expected a 422.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertStringStartsWith('Unknown agent "/api/agents/', $e->getMessage());
        }
        $this->assertNothingStored();
    }

    #[DataProvider('invalidBodies')]
    public function testSchemaViolationsAnswer422(string $body, string $mentioned): void
    {
        $this->version();

        try {
            $this->start($this->processor(), $body);
            self::fail('Expected a 422.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertStringContainsString('eval_run_start', $e->getMessage());
            self::assertStringContainsString($mentioned, $e->getMessage());
        }
        $this->assertNothingStored();
    }

    public function testMalformedJsonAnswers400(): void
    {
        $this->expectException(BadRequestHttpException::class);

        try {
            $this->start($this->processor(), '{"agentVersion": ');
        } finally {
            $this->assertNothingStored();
        }
    }

    /**
     * @return iterable<string, array{\Closure(self): string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'unknown version' => [static fn (self $test): string => (string) Uuid::v4(), 'Unknown agent version'];
        yield 'partial UUID' => [static fn (self $test): string => substr((string) $test->version()->getId(), 0, 13), 'Unknown agent version'];
        yield 'IRI of another resource' => [static fn (self $test): string => '/api/agents/'.$test->version()->getId(), 'Unknown agent version'];
        yield 'disabled agent' => [static fn (self $test): string => (string) $test->version(enabled: false)->getId(), 'is disabled'];
        yield 'unpublished agent' => [static fn (self $test): string => (string) $test->version(current: false)->getId(), 'is not the current published version'];
        yield 'stale version' => [static function (self $test): string {
            $old = $test->version();
            $agent = $old->getAgent();
            $agent->setCurrentVersion(new AgentVersion($agent, 2, Agent::candidateDefaults(), 'test-admin'));

            return (string) $old->getId();
        }, '(current: version 2)'];
    }

    /**
     * @param \Closure(self): string $reference
     */
    #[DataProvider('refusals')]
    public function testUnstartableVersionsAnswer422BeforeAnythingIsStored(\Closure $reference, string $message): void
    {
        $processor = $this->processor();

        try {
            $this->start($processor, json_encode(['agentVersion' => $reference($this)], \JSON_THROW_ON_ERROR));
            self::fail('Expected a 422.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
        $this->assertNothingStored();
    }

    public function testNoActiveQuestionAnswers422(): void
    {
        $version = $this->version();
        $this->activeQuestions = 0;

        try {
            $this->start($this->processor(), json_encode(['agentVersion' => (string) $version->getId()], \JSON_THROW_ON_ERROR));
            self::fail('Expected a 422.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertStringContainsString('no active evaluation question', $e->getMessage());
        }
        $this->assertNothingStored();
    }

    /**
     * @return iterable<string, array{list<string>, string|null, string}>
     */
    public static function modes(): iterable
    {
        $collection = (string) Uuid::v4();

        yield 'derived: collections' => [[$collection], null, BatchRunner::MODE_RETRIEVAL];
        yield 'derived: no collections' => [[], null, BatchRunner::MODE_AGENT];
        yield 'override: agent with collections' => [[$collection], BatchRunner::MODE_AGENT, BatchRunner::MODE_AGENT];
        yield 'explicit: retrieval with collections' => [[$collection], BatchRunner::MODE_RETRIEVAL, BatchRunner::MODE_RETRIEVAL];
        yield 'explicit: agent without collections' => [[], BatchRunner::MODE_AGENT, BatchRunner::MODE_AGENT];
    }

    /**
     * @param list<string> $collections
     */
    #[DataProvider('modes')]
    public function testModeIsDerivedOrTakenAndAlwaysStored(array $collections, ?string $requested, string $expected): void
    {
        $version = $this->version(collections: $collections);
        $body = ['agentVersion' => (string) $version->getId()];
        if (null !== $requested) {
            $body['mode'] = $requested;
        }

        $run = $this->start($this->processor(), json_encode($body, \JSON_THROW_ON_ERROR));

        self::assertSame($expected, $run->getMode());
        self::assertSame([$run], $this->persisted);
    }

    public function testRetrievalModeWithoutCollectionsAnswers422BeforeAnythingIsStored(): void
    {
        $version = $this->version();

        try {
            $this->start($this->processor(), json_encode(['agentVersion' => (string) $version->getId(), 'mode' => 'retrieval'], \JSON_THROW_ON_ERROR));
            self::fail('Expected a 422.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertStringContainsString('mode: "retrieval" needs an agent version with knowledge collections', $e->getMessage());
            self::assertStringContainsString('agent "eval-agent"', $e->getMessage());
        }
        $this->assertNothingStored();
    }

    /**
     * @return iterable<string, array{string|null, bool}>
     */
    public static function retrievalOnlyBodies(): iterable
    {
        yield 'mode omitted' => [null, true];
        yield 'explicit mode retrieval' => [BatchRunner::MODE_RETRIEVAL, true];
        yield 'retrievalOnly false' => [null, false];
    }

    #[DataProvider('retrievalOnlyBodies')]
    public function testRetrievalOnlyImpliesRetrievalAndIsStored(?string $mode, bool $retrievalOnly): void
    {
        $version = $this->version(collections: [(string) Uuid::v4()]);
        $body = ['agentVersion' => (string) $version->getId(), 'retrievalOnly' => $retrievalOnly];
        if (null !== $mode) {
            $body['mode'] = $mode;
        }

        $run = $this->start($this->processor(), json_encode($body, \JSON_THROW_ON_ERROR));

        self::assertSame(BatchRunner::MODE_RETRIEVAL, $run->getMode());
        self::assertSame($retrievalOnly, $run->isRetrievalOnly());
        self::assertSame([$run], $this->persisted);
        self::assertSame([[EvalRun::JOB_TYPE_START, ['run' => (string) $run->getId(), 'ownerSubject' => 'eval-admin']]], $this->dispatched);
    }

    public function testRetrievalOnlyWithModeAgentAnswers422BeforeAnythingIsStored(): void
    {
        $version = $this->version(collections: [(string) Uuid::v4()]);

        try {
            $this->start($this->processor(), json_encode(['agentVersion' => (string) $version->getId(), 'mode' => 'agent', 'retrievalOnly' => true], \JSON_THROW_ON_ERROR));
            self::fail('Expected a 422.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertSame('retrievalOnly: true needs mode "retrieval"; it cannot be combined with mode "agent" (omit mode or set it to "retrieval").', $e->getMessage());
        }
        $this->assertNothingStored();
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function modesWithoutCollections(): iterable
    {
        yield 'mode omitted' => [null];
        yield 'explicit mode retrieval' => [BatchRunner::MODE_RETRIEVAL];
    }

    #[DataProvider('modesWithoutCollections')]
    public function testRetrievalOnlyWithoutCollectionsAnswers422BeforeAnythingIsStored(?string $mode): void
    {
        $version = $this->version();
        $body = ['agentVersion' => (string) $version->getId(), 'retrievalOnly' => true];
        if (null !== $mode) {
            $body['mode'] = $mode;
        }

        try {
            $this->start($this->processor(), json_encode($body, \JSON_THROW_ON_ERROR));
            self::fail('Expected a 422.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertSame('retrievalOnly: true needs an agent version with knowledge collections; version 1 of agent "eval-agent" has none.', $e->getMessage());
        }
        $this->assertNothingStored();
    }

    public function testWithoutUserIsDenied(): void
    {
        $version = $this->version();

        $this->expectException(AccessDeniedException::class);

        try {
            $this->start($this->processor(null), json_encode(['agentVersion' => (string) $version->getId()], \JSON_THROW_ON_ERROR));
        } finally {
            $this->assertNothingStored();
        }
    }
}

// - revised 2026-10-13
