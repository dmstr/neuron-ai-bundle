<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\ConversationReplay;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Post;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Agent\AgentVersionPinner;
use Dmstr\NeuronAiBundle\Agent\LimitResolver;
use Dmstr\NeuronAiBundle\Agent\StaleReferenceException;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Conversation\ConversationStatus;
use Dmstr\NeuronAiBundle\Conversation\MessageKind;
use Dmstr\NeuronAiBundle\Conversation\MessageStatus;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\ConversationReplay;
use Dmstr\NeuronAiBundle\Entity\Message;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\Mcp\McpToolFactory;
use Dmstr\NeuronAiBundle\Provider\Connection\ProviderConnectionResolver;
use Dmstr\NeuronAiBundle\Replay\ConversationReplayStatus;
use Dmstr\NeuronAiBundle\State\ConversationReplayProcessor;
use Dmstr\NeuronAiBundle\State\InputBodyValidator;
use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\OpenApiJsonSchema\Service\OperationInputSchemaResolver;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\JobQueueService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Uid\Uuid;

/**
 * Kernel-free checks of `POST /conversations/{id}/replay`
 * (`conversation_replay`): the body against
 * `Conversation/replay.input.json` (400 malformed, 422 schema), the target
 * reference (IRI or full UUID, any version of an enabled agent, also an
 * older one), variables and language (`variables` replaces
 * the source set as a whole, omitted means the source's, null becomes
 * `{}`; `language` omitted means the source's when the target supports it,
 * else the default), the 422 refusals before anything is persisted or
 * queued (no user message, unknown version, disabled agent, invalid or
 * undeclared variables, unsupported language, stale reference with its
 * path), and on success the pinned replay conversation (owner = the
 * caller, `origin` `replay`), the record sharing its id with the frozen
 * source user-message ids, and the queued job. The source stays unchanged.
 * The pinner is the real {@see AgentVersionPinner} over a stubbed entity
 * manager.
 */
final class ConversationReplayProcessorTest extends TestCase
{
    private const string ENTITY_ROOT = __DIR__.'/../../../src/Entity';
    private const string CALLER = 'eval-admin';

    /** @var list<AgentVersion> */
    private array $versions = [];

    /** @var list<Message> */
    private array $messages = [];

    /** @var list<object> */
    private array $persisted = [];

    private int $flushes = 0;

    /** @var list<array{string, array<string, mixed>}> */
    private array $dispatched = [];

    private ?Job $job = null;

    /** The provider connection of every target version (stubbed repository). */
    private ?ApiConfiguration $connection = null;

    private function connection(): ApiConfiguration
    {
        return $this->connection ??= ProviderConnectionFixtures::ollama('replay-target-connection');
    }

    /** The model catalogue entry `test-model` of {@see connection()}, referenced by every target version. */
    private ?ProviderModel $providerModel = null;

    private function providerModel(): ProviderModel
    {
        return $this->providerModel ??= new ProviderModel($this->connection(), 'test-model');
    }

    public function testCreatesThePinnedReplayConversationAndQueuesTheJob(): void
    {
        $version = $this->version();
        $source = $this->source();
        $first = $this->message($source, 1, Message::SPEAKER_USER, 'First question');
        $this->message($source, 2, Message::SPEAKER_ASSISTANT, 'First answer');
        $refused = $this->message($source, 3, Message::SPEAKER_USER, 'Refused question');
        $this->message($source, 4, Message::SPEAKER_ASSISTANT, 'No.', MessageKind::RULE_REFUSAL);
        $last = $this->message($source, 5, Message::SPEAKER_USER, 'Failed question');
        $this->message($source, 6, Message::SPEAKER_ASSISTANT, null, MessageKind::TEXT, MessageStatus::ERROR);
        $sourceBefore = clone $source;

        $replay = $this->replay($source, '{"targetVersion": "/api/agent_versions/'.$version->getId().'"}');

        self::assertSame(ConversationReplayStatus::PENDING, $replay->getStatus());
        self::assertSame(self::CALLER, $replay->getOwnerSubject());
        self::assertSame($source, $replay->getSourceConversation());
        self::assertSame($version, $replay->getTargetVersion());
        self::assertSame([(string) $first->getId(), (string) $refused->getId(), (string) $last->getId()], $replay->getSourceMessageIds());
        self::assertSame(3, $replay->getTurnsTotal());
        self::assertSame(0, $replay->getTurnsReplayed());
        self::assertNull($replay->getOverrides());
        self::assertSame('de', $replay->getLanguage());

        $conversation = $replay->getConversation();
        self::assertTrue($replay->getId()->equals($conversation->getId()));
        self::assertNotSame($source, $conversation);
        self::assertSame(self::CALLER, $conversation->getOwnerSubject());
        self::assertSame(Conversation::ORIGIN_REPLAY, $conversation->getOrigin());
        self::assertSame(ConversationStatus::ACTIVE, $conversation->getStatus());
        self::assertSame($version, $conversation->getAgentVersion());
        self::assertSame($this->connection(), $conversation->getProviderConnection(), 'the target version\'s connection');
        self::assertSame('ollama', $conversation->getProvider());
        self::assertSame('test-model', $conversation->getModel());
        self::assertSame('de', $conversation->getLanguage());
        self::assertSame([], $conversation->getVariables());
        self::assertFalse($conversation->isAnonymous());
        self::assertSame(1, $conversation->getNextSequence());

        self::assertSame([$conversation, $replay], $this->persisted);
        self::assertSame([[ConversationReplay::JOB_TYPE, ['replay' => (string) $replay->getId(), 'ownerSubject' => self::CALLER]]], $this->dispatched);
        self::assertNotNull($this->job);
        self::assertSame($this->job->getId(), $replay->getJobId());
        self::assertSame(1, $this->flushes);

        // The source is unchanged.
        self::assertEquals($sourceBefore, $source);
    }

    public function testAnOlderVersionOfTheAgentIsATarget(): void
    {
        $older = $this->version();
        $agent = $older->getAgent();
        $newer = new AgentVersion($agent, 2, Agent::candidateDefaults(), 'test-admin');
        $agent->setCurrentVersion($newer);
        $this->versions[] = $newer;
        $source = $this->sourceWithMessage();

        $replay = $this->replay($source, '{"targetVersion": "'.$older->getId().'"}');

        self::assertSame($older, $replay->getTargetVersion());
        self::assertSame($older, $replay->getConversation()->getAgentVersion());
    }

    public function testRequestVariablesReplaceTheSourceSetAsAWhole(): void
    {
        $version = $this->version(['startSchema' => self::startSchema(), 'instructions' => ['de' => 'Hallo {{customer}}.']]);
        $source = $this->sourceWithMessage()->setVariables(['customer' => 'ACME', 'region' => 'Nord']);

        $replay = $this->replay($source, '{"targetVersion": "'.$version->getId().'", "variables": {"customer": "Globex"}}');

        self::assertSame(['customer' => 'Globex'], $replay->getConversation()->getVariables());
        self::assertSame(['variables' => ['customer' => 'Globex']], $replay->getOverrides());
        self::assertSame(['customer' => 'ACME', 'region' => 'Nord'], $source->getVariables());
    }

    public function testOmittedVariablesAreTheSourceVariables(): void
    {
        $version = $this->version(['startSchema' => self::startSchema(), 'instructions' => ['de' => 'Hallo {{customer}}.']]);
        $source = $this->sourceWithMessage()->setVariables(['customer' => 'ACME']);

        $replay = $this->replay($source, '{"targetVersion": "'.$version->getId().'"}');

        self::assertSame(['customer' => 'ACME'], $replay->getConversation()->getVariables());
        self::assertNull($replay->getOverrides());
    }

    public function testSourceVariablesTheTargetDoesNotDeclareAnswer422UnlessReplaced(): void
    {
        $version = $this->version();
        $source = $this->sourceWithMessage()->setVariables(['customer' => 'ACME']);

        $this->assertRefused($source, '{"targetVersion": "'.$version->getId().'"}', 'variables/customer');

        $replay = $this->replay($source, '{"targetVersion": "'.$version->getId().'", "variables": {}}');
        self::assertSame([], $replay->getConversation()->getVariables());
        self::assertSame(['variables' => []], $replay->getOverrides());
    }

    public function testInvalidVariablesAnswer422(): void
    {
        $version = $this->version(['startSchema' => self::startSchema(), 'instructions' => ['de' => 'Hallo {{customer}}.']]);
        $source = $this->sourceWithMessage();

        $this->assertRefused($source, '{"targetVersion": "'.$version->getId().'", "variables": {"customer": 42}}', 'variables');
        $this->assertRefused($source, '{"targetVersion": "'.$version->getId().'", "variables": {"customer": "ACME", "unknown": "x"}}', 'variables/unknown');
        // The source of a conversation without an agent has no variables:
        // the placeholder of the target stays unfilled.
        $this->assertRefused($source, '{"targetVersion": "'.$version->getId().'"}', 'customer');
    }

    public function testLanguageFollowsTheSourceWhenTheTargetSupportsIt(): void
    {
        $version = $this->version(['languages' => ['de', 'en'], 'defaultLanguage' => 'de']);

        $english = $this->replay($this->sourceWithMessage()->setLanguage('en'), '{"targetVersion": "'.$version->getId().'"}');
        self::assertSame('en', $english->getLanguage());
        self::assertSame('en', $english->getConversation()->getLanguage());
        self::assertNull($english->getOverrides());

        // Unsupported by the target: its default language.
        $french = $this->replay($this->sourceWithMessage()->setLanguage('fr'), '{"targetVersion": "'.$version->getId().'"}');
        self::assertSame('de', $french->getLanguage());

        // Requested explicitly: stored as override and effective language.
        $requested = $this->replay($this->sourceWithMessage()->setLanguage('de'), '{"targetVersion": "'.$version->getId().'", "language": "en"}');
        self::assertSame('en', $requested->getLanguage());
        self::assertSame(['language' => 'en'], $requested->getOverrides());

        $this->assertRefused($this->sourceWithMessage(), '{"targetVersion": "'.$version->getId().'", "language": "fr"}', 'language');
    }

    public function testAnAgentlessSourceReplaysWithEmptyVariablesAndTheDefaultLanguage(): void
    {
        $version = $this->version(['languages' => ['de', 'en'], 'defaultLanguage' => 'en']);
        $source = $this->sourceWithMessage();
        self::assertNull($source->getAgentVersion());
        self::assertNull($source->getVariables());

        $replay = $this->replay($source, '{"targetVersion": "'.$version->getId().'"}');

        self::assertSame([], $replay->getConversation()->getVariables());
        self::assertSame('en', $replay->getLanguage());
    }

    public function testAnAnonymousSourceBecomesANonAnonymousReplayOfTheCaller(): void
    {
        $version = $this->version();
        $source = new Conversation();
        $source->setOwnerSubject($source->anonymousOwnerSubject())->setAnonymous(true)->setTokenHash(str_repeat('a', 64));
        $this->message($source, 1, Message::SPEAKER_USER, 'Anonymous question');

        $replay = $this->replay($source, '{"targetVersion": "'.$version->getId().'"}');

        self::assertFalse($replay->getConversation()->isAnonymous());
        self::assertNull($replay->getConversation()->getTokenHash());
        self::assertSame(self::CALLER, $replay->getConversation()->getOwnerSubject());
        self::assertSame(1, $replay->getTurnsTotal());
    }

    public function testASourceWithoutUserMessagesAnswers422(): void
    {
        $version = $this->version();
        $source = $this->source();
        $this->message($source, 1, Message::SPEAKER_ASSISTANT, 'Only an answer');

        $this->assertRefused($source, '{"targetVersion": "'.$version->getId().'"}', 'no user message');
    }

    /**
     * @return iterable<string, array{0: \Closure(self): string, 1: string}>
     */
    public static function unknownTargets(): iterable
    {
        yield 'unknown IRI' => [static fn (): string => '/api/agent_versions/00000000-0000-4000-8000-000000000000', 'unknown agent version'];
        yield 'unknown UUID' => [static fn (): string => '00000000-0000-4000-8000-000000000000', 'unknown agent version'];
        yield 'partial UUID' => [static fn (self $test): string => substr((string) $test->version()->getId(), 0, 8), 'unknown agent version'];
        yield 'other IRI' => [static fn (self $test): string => '/api/agents/'.$test->version()->getId(), 'unknown agent version'];
        yield 'disabled agent' => [static fn (self $test): string => (string) $test->version(enabled: false)->getId(), 'is disabled'];
    }

    /**
     * @param \Closure(self): string $reference
     */
    #[DataProvider('unknownTargets')]
    public function testUnusableTargetsAnswer422(\Closure $reference, string $message): void
    {
        $source = $this->sourceWithMessage();

        $this->assertRefused($source, json_encode(['targetVersion' => $reference($this)], \JSON_THROW_ON_ERROR), $message);
    }

    public function testAnInactiveConnectionOfTheTargetAnswers422AtConfigurationModel(): void
    {
        $version = $this->version();
        $this->connection()->setActive(false);

        try {
            $this->replay($this->sourceWithMessage(), '{"targetVersion": "'.$version->getId().'"}');
            self::fail('A stale connection must be refused.');
        } catch (StaleReferenceException $e) {
            self::assertSame('configuration/model', $e->path);
            self::assertStringContainsString('is inactive', $e->getMessage());
        }
        $this->assertNothingStored();
    }

    public function testAStaleReferenceOfTheTargetAnswers422WithItsPath(): void
    {
        $version = $this->version(['collections' => [Uuid::v4()->toRfc4122()]]);

        try {
            $this->replay($this->sourceWithMessage(), '{"targetVersion": "'.$version->getId().'"}');
            self::fail('A stale reference must be refused.');
        } catch (StaleReferenceException $e) {
            self::assertSame('configuration/collections/0', $e->path);
            self::assertSame(422, $e->getStatusCode());
        }
        $this->assertNothingStored();
    }

    /**
     * @return iterable<string, array{0: string, 1: class-string<\Throwable>}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'malformed JSON' => ['{"targetVersion": ', BadRequestHttpException::class];
        yield 'missing targetVersion' => ['{}', UnprocessableEntityHttpException::class];
        yield 'unknown property' => ['{"targetVersion": "x", "maxUserTurns": 1}', UnprocessableEntityHttpException::class];
        yield 'variables not an object' => ['{"targetVersion": "x", "variables": ["a"]}', UnprocessableEntityHttpException::class];
        yield 'null variables' => ['{"targetVersion": "x", "variables": null}', UnprocessableEntityHttpException::class];
        yield 'empty language' => ['{"targetVersion": "x", "language": ""}', UnprocessableEntityHttpException::class];
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('invalidBodies')]
    public function testInvalidBodiesAreRejected(string $body, string $exception): void
    {
        $source = $this->sourceWithMessage();

        $this->expectException($exception);
        try {
            $this->replay($source, $body);
        } finally {
            $this->assertNothingStored();
        }
    }

    public function testWithoutAnAuthenticatedUserNothingIsStored(): void
    {
        $version = $this->version();
        $source = $this->sourceWithMessage();

        $this->expectException(AccessDeniedException::class);
        try {
            $this->replay($source, '{"targetVersion": "'.$version->getId().'"}', null);
        } finally {
            $this->assertNothingStored();
        }
    }

    // --- helpers --------------------------------------------------------

    /**
     * @param array<string, mixed> $configuration overrides of the version configuration
     */
    public function version(array $configuration = [], bool $enabled = true): AgentVersion
    {
        $agent = new Agent()->setName('Target')->setSlug('replay-target-'.\count($this->versions))->setEnabled($enabled);
        $version = new AgentVersion($agent, 1, $configuration + ['model' => $this->providerModel()->getId()->toRfc4122()] + Agent::candidateDefaults(), 'test-admin');
        $agent->setCurrentVersion($version);
        $this->versions[] = $version;

        return $version;
    }

    /**
     * @return array<string, mixed>
     */
    private static function startSchema(): array
    {
        return ['type' => 'object', 'properties' => ['customer' => ['type' => 'string']], 'required' => ['customer'], 'additionalProperties' => false];
    }

    private function source(): Conversation
    {
        return new Conversation()->setOwnerSubject('someone-else')->setProvider('anthropic')->setModel('m')->setRetrievalDepth(4);
    }

    private function sourceWithMessage(): Conversation
    {
        $source = $this->source();
        $this->message($source, 1, Message::SPEAKER_USER, 'A question');

        return $source;
    }

    private function message(Conversation $conversation, int $sequence, string $role, ?string $content, string $kind = MessageKind::TEXT, string $status = MessageStatus::COMPLETE): Message
    {
        $message = new Message()
            ->setConversation($conversation)
            ->setSequence($sequence)
            ->setRole($role)
            ->setKind($kind)
            ->setStatus($status)
            ->setContent($content);
        // Stored out of order: the processor must sort by sequence.
        array_unshift($this->messages, $message);

        return $message;
    }

    private function assertRefused(Conversation $source, string $body, string $message): void
    {
        $persisted = \count($this->persisted);
        $dispatched = \count($this->dispatched);
        try {
            $this->replay($source, $body);
            self::fail('The request must be refused: '.$body);
        } catch (UnprocessableEntityHttpException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertCount($persisted, $this->persisted);
        self::assertCount($dispatched, $this->dispatched);
    }

    private function assertNothingStored(): void
    {
        self::assertSame([], $this->persisted);
        self::assertSame([], $this->dispatched);
        self::assertSame(0, $this->flushes);
    }

    private function replay(Conversation $source, string $body, ?string $user = self::CALLER): ConversationReplay
    {
        $request = Request::create('/api/conversations/'.$source->getId().'/replay', 'POST', content: $body);

        return $this->processor($user)->process($source, new Post(name: Conversation::OPERATION_REPLAY), ['id' => $source->getId()], ['request' => $request]);
    }

    private function processor(?string $user): ConversationReplayProcessor
    {
        $messages = $this->createStub(EntityRepository::class);
        $messages->method('findBy')->willReturnCallback(function (array $criteria, ?array $orderBy = null): array {
            self::assertSame(Message::SPEAKER_USER, $criteria['role'] ?? null);
            self::assertSame(['sequence' => 'ASC'], $orderBy);
            $found = array_values(array_filter(
                $this->messages,
                static fn (Message $message): bool => $message->getConversation() === $criteria['conversation'] && Message::SPEAKER_USER === $message->getRole(),
            ));
            usort($found, static fn (Message $a, Message $b): int => $a->getSequence() <=> $b->getSequence());

            return $found;
        });

        $connections = $this->createStub(EntityRepository::class);
        $connections->method('find')->willReturnCallback(
            fn (mixed $id): ?ApiConfiguration => $id instanceof Uuid && $this->connection()->getId()->equals($id) ? $this->connection() : null,
        );
        $connections->method('findBy')->willReturn([]);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            fn (string $class): EntityRepository => match ($class) {
                Message::class => $messages,
                ApiConfiguration::class => $connections,
                default => self::fail('Unexpected repository '.$class),
            },
        );
        $entityManager->method('find')->willReturnCallback(function (string $class, mixed $id): ?object {
            if (ProviderModel::class === $class) {
                return $id instanceof Uuid && $this->providerModel()->getId()->equals($id) ? $this->providerModel() : null;
            }
            if (AgentVersion::class !== $class) {
                // Collections and MCP connections of the version: gone.
                return null;
            }
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

        $connectionResolver = new McpConnectionResolver($entityManager, $this->createStub(IriConverterInterface::class), new UuidResolver($entityManager));

        return new ConversationReplayProcessor(
            new InputBodyValidator(new OperationInputSchemaResolver([], [[self::ENTITY_ROOT, 'Dmstr\\NeuronAiBundle\\Entity']])),
            $entityManager,
            new AgentVersionPinner(new VersionTurnMapper(
                new ProviderConnectionResolver($entityManager, $this->createStub(IriConverterInterface::class), new UuidResolver($entityManager)),
                $entityManager,
                $connectionResolver,
                new McpToolFactory($entityManager, new FakeMcpTransportFactory()),
                new LimitResolver(),
                null,
                new MockClock('2026-10-03 22:00:00'),
                4,
                null,
            )),
            $jobQueue,
            $tokenStorage,
        );
    }
}
