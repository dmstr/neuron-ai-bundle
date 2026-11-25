<?php

// file generated with AI assistance: Claude Code - 2026-10-03 06:58:57 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Conversation;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Agent\AgentConfiguration;
use Dmstr\NeuronAiBundle\Agent\AgentLimitDefaultsInterface;
use Dmstr\NeuronAiBundle\Agent\LimitResolver;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Conversation\ProviderSelection;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\Mcp\McpToolFactory;
use Dmstr\NeuronAiBundle\Provider\Connection\ProviderConnectionResolver;
use Dmstr\NeuronAiBundle\Provider\ModelAvailabilityCheck;
use Dmstr\NeuronAiBundle\Provider\ProviderModelResolver;
use Dmstr\NeuronAiBundle\State\ConversationCreateProcessor;
use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use Dmstr\NeuronAiBundle\Testing\FakeModelLister;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Uid\Uuid;

/**
 * Kernel-free checks of the agent part of `POST /conversations`: the
 * agent reference (IRI, full UUID, slug; never a partial UUID) pins the
 * current published version, `maxUserTurns` follows LimitResolver
 * (version, settings default, request may only lower), without an agent
 * the request value applies as given, and the three 422 messages. Run
 * 5a-2b: version-fixed keys and the shape of `variables` are checked on the
 * raw body of `$context['request']`, `variables` without agent answer 422,
 * and the version values are copied (provider connection, its type and
 * the model from the {@see VersionTurnMapper}, the connection UUIDs of
 * `mcpTools`, the effective language, `variables`). Without an agent
 * `model` is required and is a reference to the model catalogue (violations
 * at `model` only): the entry is resolved, the model checked by the fake
 * lister, the entry, its connection and its name are stored; `provider` and
 * `connection` in the body answer 422. The agent, connection and catalogue
 * repositories are stubs over in-memory lists; nothing is persisted.
 */
final class ConversationCreateProcessorTest extends TestCase
{
    /** @var list<Agent> */
    private array $agents = [];

    /** @var list<string> criteria/ids the repository was asked for */
    private array $lookups = [];

    private ?ApiConfiguration $connection = null;
    private ?ProviderModel $providerModel = null;

    private FakeModelLister $lister;

    protected function setUp(): void
    {
        $this->lister = new FakeModelLister();
    }

    /**
     * The `anthropic` connection of the stubbed connection repository.
     */
    private function connection(): ApiConfiguration
    {
        return $this->connection ??= ProviderConnectionFixtures::anthropic('test-anthropic');
    }

    /**
     * The model catalogue entry `test-model` of {@see connection()}, which
     * agent versions reference.
     */
    private function providerModel(): ProviderModel
    {
        return $this->providerModel ??= new ProviderModel($this->connection(), 'test-model');
    }

    /**
     * An enabled agent whose current version has the given `maxUserTurns`.
     */
    /**
     * @param array<string, mixed> $configuration overrides of the version configuration
     */
    private function agent(string $slug, ?int $maxUserTurns, bool $enabled = true, bool $published = true, array $configuration = []): Agent
    {
        $agent = new Agent()->setName('Agent '.$slug)->setSlug($slug)->setEnabled($enabled);
        if ($published) {
            $agent->setCurrentVersion(new AgentVersion($agent, 1, ['maxUserTurns' => $maxUserTurns] + $configuration + ['model' => $this->providerModel()->getId()->toRfc4122()] + Agent::candidateDefaults(), 'test-admin'));
        }
        $this->agents[] = $agent;

        return $agent;
    }

    private function processor(?int $settingsMaxUserTurns = null, bool $withDefaults = true): ConversationCreateProcessor
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('find')->willReturnCallback(function (mixed $id): ?Agent {
            $this->lookups[] = 'id:'.$id;
            foreach ($this->agents as $agent) {
                if ($id instanceof Uuid && $agent->getId()->equals($id)) {
                    return $agent;
                }
            }

            return null;
        });
        $repository->method('findOneBy')->willReturnCallback(function (array $criteria): ?Agent {
            $this->lookups[] = 'slug:'.($criteria['slug'] ?? '?');
            foreach ($this->agents as $agent) {
                if (['slug' => $agent->getSlug()] === $criteria) {
                    return $agent;
                }
            }

            return null;
        });

        $connections = $this->createStub(EntityRepository::class);
        $connections->method('find')->willReturnCallback(
            fn (mixed $id): ?ApiConfiguration => $id instanceof Uuid && $this->connection()->getId()->equals($id) ? $this->connection() : null,
        );
        $connections->method('findBy')->willReturnCallback(
            fn (array $criteria): array => ($criteria['name'] ?? null) === $this->connection()->getName() ? [$this->connection()] : [],
        );

        $models = $this->createStub(EntityRepository::class);
        $models->method('find')->willReturnCallback(
            fn (mixed $id): ?ProviderModel => $id instanceof Uuid && $this->providerModel()->getId()->equals($id) ? $this->providerModel() : null,
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            fn (string $class): EntityRepository => match ($class) {
                Agent::class => $repository,
                ApiConfiguration::class => $connections,
                ProviderModel::class => $models,
                default => self::fail('Unexpected repository '.$class),
            },
        );
        $entityManager->method('find')->willReturnCallback(
            fn (string $class, mixed $id): ?object => ProviderModel::class === $class && $id instanceof Uuid && $this->providerModel()->getId()->equals($id) ? $this->providerModel() : null,
        );
        $providerConnections = new ProviderConnectionResolver($entityManager, $this->createStub(IriConverterInterface::class), new UuidResolver($entityManager));

        $modelIris = $this->createStub(IriConverterInterface::class);
        $modelIris->method('getResourceFromIri')->willReturnCallback(
            fn (string $iri): object => $iri === $this->providerModel()->iri() ? $this->providerModel() : throw new ItemNotFoundException('Not found'),
        );

        $persist = $this->createStub(ProcessorInterface::class);
        $persist->method('process')->willReturnArgument(0);

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser('test-user', null, ['ROLE_USER']), 'main', ['ROLE_USER']));

        $defaults = null;
        if ($withDefaults) {
            $defaults = $this->createStub(AgentLimitDefaultsInterface::class);
            $defaults->method('maxUserTurns')->willReturn($settingsMaxUserTurns);
            $defaults->method('maxToolCalls')->willReturn(null);
        }

        $connectionResolver = new McpConnectionResolver($entityManager, $this->createStub(IriConverterInterface::class), new UuidResolver($entityManager));

        return new ConversationCreateProcessor(
            $persist,
            $tokenStorage,
            new ProviderSelection(new ProviderModelResolver($modelIris, new UuidResolver($entityManager)), new ModelAvailabilityCheck($this->lister)),
            $connectionResolver,
            $entityManager,
            4,
            null,
            new LimitResolver(),
            $defaults,
            null,
            new VersionTurnMapper(
                $providerConnections,
                $entityManager,
                $connectionResolver,
                new McpToolFactory($entityManager, new FakeMcpTransportFactory()),
                new LimitResolver(),
                $defaults,
                new MockClock('2026-10-03 12:00:00'),
                4,
                null,
            ),
        );
    }

    /**
     * The full UUID of {@see providerModel()}, as a request references it.
     */
    private function modelId(): string
    {
        return $this->providerModel()->getId()->toRfc4122();
    }

    /**
     * Without $agent the conversation references the catalogue entry `test-model` of the stubbed connection.
     */
    private function create(ConversationCreateProcessor $processor, ?string $agent, ?int $maxUserTurns = null): Conversation
    {
        $data = new Conversation()->setAgent($agent)->setMaxUserTurns($maxUserTurns);
        if (null === $agent) {
            $data->setModelReference($this->modelId());
        }

        return $processor->process($data, new Post());
    }

    /**
     * @return iterable<string, array{?int, ?int, ?int, ?int}>
     */
    public static function limits(): iterable
    {
        // version, settings default, request, expected
        yield 'version lowers the request' => [3, null, 5, 3];
        yield 'request lowers the version' => [3, null, 2, 2];
        yield 'version wins over settings' => [3, 4, null, 3];
        yield 'settings without version, request lowers' => [null, 4, 2, 2];
        yield 'settings without version, request above' => [null, 4, 6, 4];
        yield 'settings without version or request' => [null, 4, null, 4];
        yield 'request only' => [null, null, 7, 7];
        yield 'nothing' => [null, null, null, null];
    }

    #[DataProvider('limits')]
    public function testMaxUserTurnsOfAnAgentConversation(?int $version, ?int $settings, ?int $request, ?int $expected): void
    {
        $agent = $this->agent('support', $version);

        $conversation = $this->create($this->processor($settings), 'support', $request);

        self::assertSame($expected, $conversation->getMaxUserTurns());
        self::assertSame($agent->getCurrentVersion(), $conversation->getAgentVersion());
        self::assertNull($conversation->getAgent());
    }

    public function testWithoutLimitDefaultsTheSettingsStepIsSkipped(): void
    {
        $this->agent('support', null);

        self::assertNull($this->create($this->processor(null, false), 'support')->getMaxUserTurns());
        self::assertSame(2, $this->create($this->processor(null, false), 'support', 2)->getMaxUserTurns());
    }

    public function testWithoutAgentTheSettingsDefaultDoesNotApply(): void
    {
        $processor = $this->processor(4);

        $unlimited = $this->create($processor, null);
        self::assertNull($unlimited->getMaxUserTurns());
        self::assertNull($unlimited->getAgentVersion());

        self::assertSame(9, $this->create($processor, null, 9)->getMaxUserTurns());
        self::assertSame([], $this->lookups);
    }

    /**
     * @return iterable<string, array{callable(Agent): string}>
     */
    public static function references(): iterable
    {
        yield 'slug' => [static fn (Agent $agent): string => $agent->getSlug()];
        yield 'full UUID' => [static fn (Agent $agent): string => $agent->getId()->toRfc4122()];
        yield 'IRI' => [static fn (Agent $agent): string => '/api/agents/'.$agent->getId()->toRfc4122()];
        yield 'surrounding blanks' => [static fn (Agent $agent): string => ' '.$agent->getSlug().' '];
    }

    /**
     * @param callable(Agent): string $reference
     */
    #[DataProvider('references')]
    public function testAgentReferenceIsResolved(callable $reference): void
    {
        $this->agent('other', 1);
        $agent = $this->agent('support', 3);

        $conversation = $this->create($this->processor(), $reference($agent));

        self::assertSame($agent->getCurrentVersion(), $conversation->getAgentVersion());
        self::assertSame(3, $conversation->getMaxUserTurns());
    }

    /**
     * A UUID-shaped slug is found by its slug when no agent has that id.
     */
    public function testUuidShapedSlugFallsBackToTheSlug(): void
    {
        $slug = Uuid::v4()->toRfc4122();
        $agent = $this->agent($slug, 2);

        self::assertSame($agent->getCurrentVersion(), $this->create($this->processor(), $slug)->getAgentVersion());
        self::assertSame(['id:'.$slug, 'slug:'.$slug], $this->lookups);
    }

    /**
     * @return iterable<string, array{callable(Agent): string}>
     */
    public static function unknownReferences(): iterable
    {
        yield 'empty' => [static fn (Agent $agent): string => ''];
        yield 'blank' => [static fn (Agent $agent): string => '  '];
        yield 'unknown slug' => [static fn (Agent $agent): string => 'nobody'];
        yield 'unknown UUID' => [static fn (Agent $agent): string => Uuid::v4()->toRfc4122()];
        yield 'partial UUID' => [static fn (Agent $agent): string => substr($agent->getId()->toRfc4122(), 0, 8)];
        yield 'IRI with partial UUID' => [static fn (Agent $agent): string => '/api/agents/'.substr($agent->getId()->toRfc4122(), 0, 8)];
        yield 'IRI with slug' => [static fn (Agent $agent): string => '/api/agents/'.$agent->getSlug()];
        yield 'foreign IRI' => [static fn (Agent $agent): string => '/api/agent_versions/'.$agent->getId()->toRfc4122()];
        yield 'not a slug' => [static fn (Agent $agent): string => 'Support Agent'];
    }

    /**
     * @param callable(Agent): string $reference
     */
    #[DataProvider('unknownReferences')]
    public function testUnknownAgentIsRejected(callable $reference): void
    {
        $agent = $this->agent('support', 3);
        $given = $reference($agent);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage(\sprintf('Unknown agent "%s".', $given));

        $this->create($this->processor(), $given);
    }

    public function testPartialUuidIsNeverLookedUpById(): void
    {
        $agent = $this->agent('support', 3);
        $partial = substr($agent->getId()->toRfc4122(), 0, 8);

        try {
            $this->create($this->processor(), $partial);
            self::fail('A partial UUID must not resolve.');
        } catch (UnprocessableEntityHttpException) {
        }

        self::assertSame(['slug:'.$partial], $this->lookups);
    }

    public function testDisabledAgentIsRejected(): void
    {
        $this->agent('support', 3, enabled: false);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Agent "support" is disabled.');

        $this->create($this->processor(), 'support');
    }

    public function testUnpublishedAgentIsRejected(): void
    {
        $this->agent('candidate-only', null, published: false);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Agent "candidate-only" has no published version.');

        $this->create($this->processor(), 'candidate-only');
    }

    /**
     * The serializer context with the request whose raw body is `$body`.
     *
     * @param array<string, mixed> $body
     *
     * @return array{request: Request}
     */
    private static function context(array $body): array
    {
        return ['request' => Request::create('/api/conversations', 'POST', content: json_encode($body, \JSON_THROW_ON_ERROR))];
    }

    /**
     * `$data` stands for what the serializer denormalised from `$raw`.
     */
    private static function createRaw(ConversationCreateProcessor $processor, Conversation $data, string $raw): Conversation
    {
        return $processor->process($data, new Post(), [], ['request' => Request::create('/api/conversations', 'POST', content: $raw)]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function versionFixedKeys(): iterable
    {
        foreach (ConversationCreateProcessor::VERSION_FIXED_KEYS as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('versionFixedKeys')]
    public function testVersionFixedKeyIsRejectedBeforeTheAgentIsResolved(string $key): void
    {
        $this->agent('support', 3);

        try {
            $this->processor()->process(new Conversation()->setAgent('support'), new Post(), [], self::context(['agent' => 'support', $key => 'x']));
            self::fail($key.' must be rejected.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertSame(\sprintf('"%s" is fixed by the agent version and cannot be set when creating a conversation with an agent.', $key), $e->getMessage());
        }

        self::assertSame([], $this->lookups);
    }

    public function testRulesAndTemplatesAreFixedByTheVersion(): void
    {
        self::assertContains('rules', ConversationCreateProcessor::VERSION_FIXED_KEYS);
        self::assertContains('templates', ConversationCreateProcessor::VERSION_FIXED_KEYS);
    }

    public function testVersionFixedKeysAreAllowedWithoutAgent(): void
    {
        $conversation = $this->processor()->process(new Conversation()->setModelReference($this->providerModel()->iri()), new Post(), [], self::context(['model' => $this->providerModel()->iri(), 'instructions' => 'x']));

        self::assertSame($this->providerModel(), $conversation->getProviderModel());
        self::assertSame($this->providerModel()->iri(), $conversation->getModelReference(), 'the output `model` is the IRI of the entry');
        self::assertSame('test-model', $conversation->getModel());
        self::assertSame($this->connection(), $conversation->getProviderConnection());
        self::assertSame('anthropic', $conversation->getProvider());
        self::assertSame('test-anthropic', $conversation->getConnection());
        self::assertSame(['test-anthropic'], $this->lister->getListedConnections(), 'the model was checked live once');
    }

    public function testModelIsRequiredWithoutAgent(): void
    {
        try {
            $this->processor()->process(new Conversation(), new Post(), [], self::context(['title' => 'x']));
            self::fail('A conversation without a model must be rejected.');
        } catch (ValidationException $e) {
            self::assertSame(['model' => ProviderSelection::modelRequired()], self::violations($e));
        }
        self::assertSame([], $this->lister->getListedConnections());
    }

    public function testModelNameAndAModelTheConnectionDoesNotOfferAreViolations(): void
    {
        try {
            $this->processor()->process(new Conversation()->setModelReference('test-model'), new Post());
            self::fail('A model name must be rejected.');
        } catch (ValidationException $e) {
            self::assertSame(['model' => 'Unknown model "test-model": expected the IRI or UUID of an entry of the model catalogue (/api/refs/provider_models).'], self::violations($e));
        }

        $this->lister->offer(['other-model']);
        try {
            $this->processor()->process(new Conversation()->setModelReference($this->modelId()), new Post());
            self::fail('A model the connection does not offer must be rejected.');
        } catch (ValidationException $e) {
            self::assertSame(['model' => 'Model "test-model" is not offered by connection "test-anthropic" (offered: other-model).'], self::violations($e));
        }
        self::assertSame([], $this->lookups);
    }

    public function testUnavailableModelIsAViolation(): void
    {
        $this->providerModel()->setAvailable(false);

        try {
            $this->processor()->process(new Conversation()->setModelReference($this->modelId()), new Post());
            self::fail('An unavailable entry must be rejected.');
        } catch (ValidationException $e) {
            self::assertSame(['model'], array_keys(self::violations($e)));
            self::assertStringContainsString('is no longer offered by the connection', self::violations($e)['model']);
        }
        self::assertSame([], $this->lister->getListedConnections(), 'no live check for an unavailable entry');
    }

    public function testProviderInTheBodyIsRejected(): void
    {
        $this->agent('support', 3);

        foreach ([['provider' => 'ollama', 'model' => $this->modelId()], ['agent' => 'support', 'provider' => 'anthropic']] as $body) {
            try {
                $this->processor()->process(new Conversation()->setAgent($body['agent'] ?? null)->setModelReference($body['model'] ?? null), new Post(), [], self::context($body));
                self::fail('"provider" must be rejected.');
            } catch (UnprocessableEntityHttpException $e) {
                self::assertStringStartsWith('"provider" is read-only', $e->getMessage());
            }
        }
        self::assertSame([], $this->lookups);
    }

    public function testConnectionInTheBodyIsRejected(): void
    {
        $this->agent('support', 3);

        foreach ([['connection' => 'test-anthropic', 'model' => $this->modelId()], ['agent' => 'support', 'connection' => 'test-anthropic']] as $body) {
            try {
                $this->processor()->process(new Conversation()->setAgent($body['agent'] ?? null)->setModelReference($body['model'] ?? null), new Post(), [], self::context($body));
                self::fail('"connection" must be rejected.');
            } catch (UnprocessableEntityHttpException $e) {
                self::assertSame(ConversationCreateProcessor::CONNECTION_REMOVED, $e->getMessage());
            }
        }
        self::assertSame([], $this->lookups);
        self::assertSame([], $this->lister->getListedConnections());
        self::assertNotContains('connection', ConversationCreateProcessor::VERSION_FIXED_KEYS);
    }

    /**
     * propertyPath => message of the violations of $e.
     *
     * @return array<string, string>
     */
    private static function violations(ValidationException $e): array
    {
        $violations = [];
        foreach ($e->getConstraintViolationList() as $violation) {
            $violations[$violation->getPropertyPath()] = (string) $violation->getMessage();
        }

        return $violations;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonObjectVariables(): iterable
    {
        yield 'list' => ['["a"]'];
        yield 'empty list' => ['[]'];
        yield 'string' => ['"a"'];
        yield 'number' => ['1'];
    }

    #[DataProvider('nonObjectVariables')]
    public function testVariablesMustBeAnObject(string $variables): void
    {
        $this->agent('support', 3);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('"variables" must be a JSON object.');

        self::createRaw($this->processor(), new Conversation()->setAgent('support'), '{"agent": "support", "variables": '.$variables.'}');
    }

    public function testVariablesRequireAnAgent(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('"variables" require an agent.');

        self::createRaw($this->processor(), new Conversation()->setVariables([]), '{"variables": {}}');
    }

    public function testVersionValuesLanguageAndVariablesAreCopied(): void
    {
        $connection = Uuid::v4()->toRfc4122();
        $other = Uuid::v4()->toRfc4122();
        $agent = $this->agent('support', 3, configuration: [
            'instructions' => ['de' => 'Hallo {{customer}}.', 'en' => 'Hello {{customer}}.'],
            'languages' => ['de', 'en'],
            'defaultLanguage' => 'de',
            'startSchema' => ['type' => 'object', 'properties' => ['customer' => ['type' => 'string']]],
            'retrievalDepth' => 7,
        ]);
        $processor = $this->processor();

        $conversation = self::createRaw(
            $processor,
            new Conversation()->setAgent('support')->setVariables(['customer' => 'ACME']),
            '{"agent": "support", "variables": {"customer": "ACME"}}',
        );

        self::assertSame($agent->getCurrentVersion(), $conversation->getAgentVersion());
        self::assertSame('anthropic', $conversation->getProvider());
        self::assertSame($this->connection(), $conversation->getProviderConnection());
        self::assertSame('test-anthropic', $conversation->getConnection());
        self::assertSame('test-model', $conversation->getModel());
        self::assertSame($this->providerModel(), $conversation->getProviderModel());
        self::assertSame($this->providerModel()->iri(), $conversation->getModelReference());
        self::assertSame([], $this->lister->getListedConnections(), 'no live model check for an agent conversation');
        self::assertSame(7, $conversation->getRetrievalDepth());
        self::assertSame([], $conversation->getCollections());
        self::assertSame([], $conversation->getMcpConnections());
        self::assertSame('de', $conversation->getLanguage());
        self::assertSame(['customer' => 'ACME'], $conversation->getVariables());

        $english = self::createRaw(
            $processor,
            new Conversation()->setAgent('support')->setLanguage('en')->setVariables(['customer' => 'ACME']),
            '{"agent": "support", "language": "en", "variables": {"customer": "ACME"}}',
        );
        self::assertSame('en', $english->getLanguage());

        // The connection UUIDs of mcpTools, unique, order of first use (the
        // mapper is not involved: the copy reads the version configuration).
        $reflection = new \ReflectionMethod(ConversationCreateProcessor::class, 'connectionIds');
        self::assertSame([$connection, $other], $reflection->invoke(null, AgentConfiguration::fromArray([
            'mcpTools' => [
                ['connection' => $connection, 'name' => 'a'],
                ['connection' => $other, 'name' => 'b'],
                ['connection' => $connection, 'name' => 'c'],
            ],
        ] + Agent::candidateDefaults())));
    }

    public function testAbsentVariablesAreStoredAsAnEmptyObject(): void
    {
        $this->agent('support', 3);

        $conversation = self::createRaw($this->processor(), new Conversation()->setAgent('support'), '{"agent": "support"}');

        self::assertSame([], $conversation->getVariables());
        self::assertSame('de', $conversation->getLanguage());
    }

    public function testUnsupportedLanguageIsTheMappersError(): void
    {
        $this->agent('support', 3);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('language: "fr" is not supported by agent "support" (supported: de).');

        self::createRaw($this->processor(), new Conversation()->setAgent('support')->setLanguage('fr'), '{"agent": "support", "language": "fr"}');
    }

    public function testUndeclaredVariableIsTheMappersError(): void
    {
        $this->agent('support', 3);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('variables/customer');

        self::createRaw($this->processor(), new Conversation()->setAgent('support')->setVariables(['customer' => 'ACME']), '{"agent": "support", "variables": {"customer": "ACME"}}');
    }
}
