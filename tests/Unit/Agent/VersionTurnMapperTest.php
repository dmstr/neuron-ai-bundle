<?php

// file generated with AI assistance: Claude Code - 2026-10-03 10:33:41 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Agent;

use ApiPlatform\Metadata\IriConverterInterface;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Tests\Support\ArtifactTestFiles;
use Dmstr\NeuronAiBundle\Tests\Support\ArtifactTestLimits;
use Dmstr\NeuronAiBundle\Agent\AgentConfiguration;
use Dmstr\NeuronAiBundle\Agent\AgentLimitDefaultsInterface;
use Dmstr\NeuronAiBundle\Agent\LimitResolver;
use Dmstr\NeuronAiBundle\Agent\RetrievalScope;
use Dmstr\NeuronAiBundle\Agent\StaleReferenceException;
use Dmstr\NeuronAiBundle\Agent\TurnConfiguration;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Artifact\ArtifactAccess;
use Dmstr\NeuronAiBundle\Artifact\ArtifactAccessResolver;
use Dmstr\NeuronAiBundle\Artifact\ArtifactScope;
use Dmstr\NeuronAiBundle\Artifact\ArtifactToolkit;
use Dmstr\NeuronAiBundle\Artifact\ArtifactToolkitFactory;
use Dmstr\NeuronAiBundle\Conversation\ContextLimits;
use Dmstr\NeuronAiBundle\Conversation\ContextRetriever;
use Dmstr\NeuronAiBundle\Conversation\ForcedClose;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Dmstr\NeuronAiBundle\Entity\Refs\McpTool;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\Mcp\McpToolFactory;
use Dmstr\NeuronAiBundle\Mcp\StreamableHttpTransportFactory;
use Dmstr\NeuronAiBundle\Provider\Connection\ProviderConnectionResolver;
use Dmstr\NeuronAiBundle\Provider\ModelOptions;
use Dmstr\NeuronAiBundle\Provider\ModelReferenceMigrationSql;
use Dmstr\NeuronAiBundle\Testing\FakeCurrentJwtProvider;
use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use Dmstr\NeuronAiBundle\Testing\InMemoryArtifactLedger;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use NeuronAI\Tools\ToolInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Uid\Uuid;

/**
 * Kernel-free checks of the mapping step: the real
 * ProviderConnectionResolver (connection checks of the model entry), McpConnectionResolver, McpToolFactory (fake
 * transports, nothing is contacted) and LimitResolver run against an
 * in-memory entity manager with one collection, three MCP connections and
 * their catalogue rows and three provider connections (`claude`, `local`,
 * the inactive `offline`) with the model catalogue entries of
 * $providerModels; no model lister exists here, the mapper never
 * checks a model live. The clock is fixed at 2026-10-03 23:30 in Berlin,
 * which is 21:30 UTC.
 */
final class VersionTurnMapperTest extends TestCase
{
    private const array SEARCH_SCHEMA = ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]];

    private Agent $agent;
    private Collection $docs;
    private ApiConfiguration $tools;
    private ApiConfiguration $wiki;
    private ApiConfiguration $inactive;
    private ApiConfiguration $claude;
    private ApiConfiguration $local;
    private ApiConfiguration $offline;
    private FakeMcpTransportFactory $transports;

    /** @var list<McpTool> */
    private array $catalogue = [];

    /** @var list<ProviderModel> model catalogue entries, found by {@see EntityManagerInterface::find()} */
    private array $providerModels = [];

    private ?int $settingsMaxToolCalls = null;
    private ?int $settingsMaxUserTurns = null;

    /** @var list<array{string, string, array<string, mixed>}> level, message, context */
    private array $logged = [];

    /** Temporary artifact storage of a test, removed in tearDown(). */
    private ?string $artifactBase = null;

    protected function setUp(): void
    {
        $this->agent = new Agent()->setName('Support Bot')->setSlug('support');
        $this->docs = new Collection()->setName('docs');
        $this->tools = self::connection('tools', true);
        $this->wiki = self::connection('wiki', true);
        $this->inactive = self::connection('inactive', false);
        $this->claude = ProviderConnectionFixtures::anthropic('claude');
        $this->local = ProviderConnectionFixtures::ollama('local');
        $this->offline = ProviderConnectionFixtures::ollama('offline', active: false);
        $this->catalogue = [
            self::row($this->tools, 'search', self::SEARCH_SCHEMA),
            self::row($this->tools, 'finish', ['type' => 'object']),
            self::row($this->tools, 'unused', ['type' => 'object']),
            self::row($this->tools, 'broken', ['type' => 'object', 'properties' => ['nothing' => ['type' => 'null']]]),
            self::row($this->wiki, 'page', ['type' => 'object']),
            self::row($this->inactive, 'search', self::SEARCH_SCHEMA),
        ];
        $this->transports = new FakeMcpTransportFactory();
    }

    protected function tearDown(): void
    {
        if (null !== $this->artifactBase) {
            ArtifactTestFiles::remove($this->artifactBase);
        }
    }

    public function testHappyPath(): void
    {
        $version = $this->version([
            'instructions' => ['de' => 'Hallo {{name}}.', 'en' => 'Hello {{name}}, today is {{date}}.'],
            'languages' => ['de', 'en'],
            'collections' => [$this->docs->getId()->toRfc4122()],
            'retrievalDepth' => 6,
            'mcpTools' => [
                ['connection' => $this->id($this->tools), 'name' => 'search', 'terminal' => false],
                ['connection' => $this->id($this->wiki), 'name' => 'page', 'terminal' => true],
                ['connection' => $this->id($this->tools), 'name' => 'finish', 'terminal' => true],
            ],
            'responseSchema' => ['type' => 'object'],
            'startSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'modelOptions' => ['temperature' => 0.2, 'maxTokens' => 1024, 'effort' => 'high'],
        ]);

        $turn = $this->mapper()->map($version, ['name' => 'Ada'], 'en');

        self::assertInstanceOf(TurnConfiguration::class, $turn);
        self::assertSame($version, $turn->version);
        self::assertSame($this->claude, $turn->getConnection());
        self::assertSame('anthropic', $turn->provider);
        self::assertSame('claude-default', $turn->model);
        self::assertSame($this->providerModels[0], $turn->providerModel);
        self::assertSame('Hello Ada, today is 2026-10-03.', $turn->instructions);
        self::assertSame(['type' => 'object'], $turn->responseSchema);
        self::assertSame([$this->docs->getId()->toRfc4122()], $turn->collections);
        self::assertSame(6, $turn->retrievalDepth);
        self::assertSame(['search', 'page', 'finish'], array_map(static fn (ToolInterface $tool): string => $tool->getName(), $turn->tools));
        self::assertSame(['tools', 'wiki'], $turn->mcpConnections);
        self::assertSame(['page', 'finish'], $turn->terminalTools);
        self::assertEquals(new ModelOptions(temperature: 0.2, maxTokens: 1024, effort: 'high'), $turn->modelOptions);
        self::assertSame('en', $turn->language);
        self::assertSame([], $this->transports->getMethods(), 'No MCP server is contacted.');
    }

    public function testRulesAndTemplatesAreCompiledWithTheInstructionValues(): void
    {
        $version = $this->version([
            'languages' => ['de', 'en'],
            'startSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'unset' => ['type' => 'string']]],
            'rules' => [
                ['name' => 'opening', 'match' => ['type' => 'keywords', 'keywords' => ['öffnungszeiten'], 'wholeWord' => true], 'outcome' => 'answer', 'template' => 'hours'],
                ['name' => null, 'match' => ['type' => 'pattern', 'pattern' => 'passwor(t|d)', 'flags' => 'i'], 'outcome' => 'refuse', 'template' => 'refusal'],
            ],
            'templates' => [
                'hours' => ['de' => '{{agentName}} für {{name}} ({{language}}, {{date}}): 9–17 Uhr.{{unset}}', 'en' => 'Open 9 to 5, {{name}}.'],
                'refusal' => ['de' => 'Nein ({{rule}}/{{outcome}}).'],
            ],
        ]);

        $turn = $this->mapper()->map($version, ['name' => 'Ada'], 'de');

        self::assertFalse($turn->rules->isEmpty());
        self::assertTrue($turn->templates->has('hours'));
        self::assertSame('Open 9 to 5, {{name}}.', $turn->templates->text('hours', 'en'));

        $answer = $turn->rules->evaluate('Wann sind die ÖFFNUNGSZEITEN?', $turn->language);
        self::assertNotNull($answer);
        self::assertSame([0, 'opening', 'keywords', 'answer', 'hours', 'de'], [$answer->index, $answer->name, $answer->match, $answer->outcome, $answer->template, $answer->language]);
        self::assertSame('Support Bot für Ada (de, 2026-10-03): 9–17 Uhr.', $answer->text);

        $refusal = $turn->rules->evaluate('Mein PASSWORD bitte', 'en');
        self::assertNotNull($refusal);
        self::assertSame([1, null, 'pattern', 'refuse'], [$refusal->index, $refusal->name, $refusal->match, $refusal->outcome]);
        self::assertSame('Nein (/refuse).', $refusal->text, 'Default-language text when the variant is missing.');

        self::assertNull($turn->rules->evaluate('Etwas anderes', 'de'));
    }

    public function testVersionWithoutRuleKeysMapsToEmptySets(): void
    {
        $turn = $this->mapper()->map($this->version());

        self::assertTrue($turn->rules->isEmpty());
        self::assertNull($turn->rules->evaluate('Öffnungszeiten? Passwort!', 'de'));
        self::assertFalse($turn->templates->has('hours'));
        self::assertNull($turn->templates->text('refusal', 'de'));
    }

    public function testFrameAndTurnTextsAreRenderedWithTheInstructionValues(): void
    {
        $version = $this->version([
            'languages' => ['de', 'en'],
            'startSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'unset' => ['type' => 'string']]],
            'templates' => [
                'frame' => ['de' => '{{agentName}} für {{name}}:{{unset}} {{ answer }} ({{language}}, {{date}})', 'en' => 'Hi {{name}}: {{answer}}'],
                'limitReason' => ['de' => '{{name}}: {{toolCalls}} von {{maxToolCalls}} Werkzeugaufrufen ({{language}}).'],
                'turnLimitReason' => ['de' => 'Runde {{turn}}/{{maxUserTurns}} für {{agentName}}.'],
                'forcedCloseFallback' => ['de' => 'Leider keine Antwort, {{name}}.', 'en' => 'Sorry, {{name}}.'],
            ],
        ]);

        $de = $this->mapper()->map($version, ['name' => 'Ada'], 'de');

        self::assertSame(['prefix' => 'Support Bot für Ada: ', 'suffix' => ' (de, 2026-10-03)'], $de->frame);
        self::assertSame('Ada: 3 von 2 Werkzeugaufrufen (de).', $de->texts->limitReason(3, 2));
        self::assertSame('Runde 4/4 für Support Bot.', $de->texts->turnLimitReason(4, 4));
        self::assertSame('Leider keine Antwort, Ada.', $de->texts->fallback());

        $en = $this->mapper()->map($version, ['name' => 'Ada'], 'en');

        self::assertSame(['prefix' => 'Hi Ada: ', 'suffix' => ''], $en->frame);
        self::assertSame('Sorry, Ada.', $en->texts->fallback());
        self::assertSame('Ada: 1 von 1 Werkzeugaufrufen (en).', $en->texts->limitReason(1, 1), 'Default-language text when the variant is missing.');
    }

    public function testVersionWithoutTemplatesHasNoFrameAndTheEnglishTexts(): void
    {
        $turn = $this->mapper()->map($this->version());

        self::assertNull($turn->frame);
        self::assertSame(ForcedClose::limitReason(5, 4), $turn->texts->limitReason(5, 4));
        self::assertSame(ForcedClose::turnLimitReason(3), $turn->texts->turnLimitReason(3, 3));
        self::assertNull($turn->texts->fallback());

        $other = $this->mapper()->map($this->version(['templates' => ['hours' => ['de' => '9–17 Uhr.']]]));
        self::assertNull($other->frame, 'Only the reserved slot frames.');
        self::assertNull($other->texts->fallback());
    }

    public function testRuntimeRegexFailureIsLoggedWithAgentAndVersion(): void
    {
        $version = $this->version([
            'rules' => [['name' => 'slow', 'match' => ['type' => 'pattern', 'pattern' => '(a+)+$', 'flags' => ''], 'outcome' => 'refuse', 'template' => 'refusal']],
            'templates' => ['refusal' => ['de' => 'Nein.']],
        ]);

        $turn = $this->mapper(logger: $this->logger())->map($version);

        self::assertNull($turn->rules->evaluate(str_repeat('a', 5000).'!', 'de'));
        self::assertCount(1, $this->logged);
        [$level, , $context] = $this->logged[0];
        self::assertSame('warning', $level);
        self::assertSame('support', $context['agent']);
        self::assertSame(3, $context['version']);
        self::assertSame(0, $context['ruleIndex']);
        self::assertSame('slow', $context['ruleName']);
    }

    /**
     * A version published before `modelOptions` keeps its top-level
     * `temperature` and `maxTokens`; they become the model options of the
     * turn, and a null value stays unset.
     */
    public function testVersionStoredBeforeModelOptionsMapsTemperatureAndMaxTokens(): void
    {
        $legacy = $this->version();
        $configuration = $legacy->getConfiguration();
        unset($configuration['modelOptions']);
        $version = new AgentVersion($this->agent, 3, ['temperature' => 0.4, 'maxTokens' => 300] + $configuration, 'alice');
        $nullTemperature = new AgentVersion($this->agent, 3, ['temperature' => null, 'maxTokens' => 300] + $configuration, 'alice');

        self::assertEquals(new ModelOptions(temperature: 0.4, maxTokens: 300), $this->mapper()->map($version)->modelOptions);
        self::assertEquals(new ModelOptions(maxTokens: 300), $this->mapper()->map($nullTemperature)->modelOptions);
    }

    public function testBundleDefaultsWithoutVersionValues(): void
    {
        $turn = $this->mapper()->map($this->version());

        self::assertSame('anthropic', $turn->provider);
        self::assertSame('claude-default', $turn->model);
        self::assertSame(4, $turn->retrievalDepth);
        self::assertSame([], $turn->tools);
        self::assertSame([], $turn->mcpConnections);
        self::assertSame([], $turn->terminalTools);
        self::assertNull($turn->modelOptions);
        self::assertSame('de', $turn->language);
    }

    /**
     * The shared accessor answers exactly what map() puts into the turn,
     * with the version's depth and with the bundle default; it maps
     * nothing else, so a stale MCP reference does not stop it.
     */
    public function testRetrievalScopeEqualsTheMappedValues(): void
    {
        $collection = $this->docs->getId()->toRfc4122();
        $mapper = $this->mapper();

        foreach ([[6, 6], [null, 4]] as [$depth, $expected]) {
            $version = $this->version(['collections' => [$collection], 'retrievalDepth' => $depth]);
            $turn = $mapper->map($version);
            $scope = $mapper->retrievalScope($version);

            self::assertInstanceOf(RetrievalScope::class, $scope);
            self::assertSame([$collection], $scope->collections);
            self::assertSame($expected, $scope->depth);
            self::assertSame([$turn->collections, $turn->retrievalDepth], [$scope->collections, $scope->depth]);
            self::assertTrue($scope->hasCollections());
        }

        $none = $mapper->retrievalScope($this->version());
        self::assertSame([], $none->collections);
        self::assertSame(4, $none->depth);
        self::assertFalse($none->hasCollections());

        $stale = $this->version(['collections' => [$collection], 'mcpTools' => [['connection' => (string) Uuid::v4(), 'name' => 'search', 'terminal' => false]]]);
        self::assertSame([$collection], $mapper->retrievalScope($stale)->collections);
    }

    /**
     * `hybrid` and `hybridWeight` reach the turn and the shared
     * scope alike; the fixture version of {@see version()} is a stored
     * version from before hybrid retrieval (no hybrid keys, explicit `retrievalDepth: null`)
     * and keeps depth 4, vector-only.
     */
    public function testHybridReachesTurnAndScope(): void
    {
        $collection = $this->docs->getId()->toRfc4122();
        $mapper = $this->mapper();

        $hybrid = $this->version(['collections' => [$collection], 'hybrid' => true, 'hybridWeight' => 0.3]);
        $turn = $mapper->map($hybrid);
        $scope = $mapper->retrievalScope($hybrid);

        self::assertTrue($turn->hybrid);
        self::assertSame(0.3, $turn->hybridWeight);
        self::assertTrue($scope->hybrid);
        self::assertSame(0.3, $scope->hybridWeight);
        self::assertSame(4, $scope->depth);

        $legacy = $this->version(['collections' => [$collection]]);
        self::assertArrayNotHasKey('hybrid', $legacy->getConfiguration());
        self::assertNull($legacy->getConfiguration()['retrievalDepth']);
        $turn = $mapper->map($legacy);
        $scope = $mapper->retrievalScope($legacy);

        self::assertFalse($turn->hybrid);
        self::assertSame(0.5, $turn->hybridWeight);
        self::assertSame(4, $turn->retrievalDepth);
        self::assertEquals(new RetrievalScope([$collection], 4, false, 0.5), $scope);
    }

    /**
     * A version published from a new candidate (schema defaults)
     * retrieves 10 chunks, vector-only.
     */
    public function testNewCandidateDefaultsGiveDepthTen(): void
    {
        $collection = $this->docs->getId()->toRfc4122();
        $configuration = ['collections' => [$collection], 'instructions' => ['de' => 'Hallo.']] + Agent::candidateDefaults();
        $version = new AgentVersion($this->agent, 4, ['model' => $this->modelId($this->claude, 'claude-default')] + $configuration, 'alice');

        $turn = $this->mapper()->map($version);

        self::assertSame(10, $turn->retrievalDepth);
        self::assertFalse($turn->hybrid);
        self::assertSame(0.5, $turn->hybridWeight);
        self::assertEquals(new RetrievalScope([$collection], 10, false, 0.5), $this->mapper()->retrievalScope($version));
    }

    /**
     * {@see \Dmstr\NeuronAiBundle\Agent\AgentConfiguration::fromArray()} fills the context
     * keys with the new defaults, so the mapper reads the raw stored array.
     * The fixture version of {@see version()} has none of the keys and keeps
     * the bundle cap, no expansion and the legacy instruction text.
     */
    public function testStoredVersionWithoutContextKeysKeepsTheLegacyContext(): void
    {
        $legacy = $this->version();
        foreach (['contextMaxChunks', 'contextMaxChars', 'contextExpansionMaxChars', 'contextInstructions'] as $key) {
            self::assertArrayNotHasKey($key, $legacy->getConfiguration());
        }

        $turn = $this->mapper()->map($legacy);

        self::assertEquals(new ContextLimits(8, 6000, 0), $turn->contextLimits);
        self::assertSame(ContextRetriever::LEGACY_INSTRUCTION, $turn->contextInstruction);

        // The legacy cap follows the bundle configuration, as before.
        $turn = $this->mapper(contextMaxChunks: 5, contextMaxChars: 2500, contextInstruction: 'Bundle text.')->map($legacy);

        self::assertEquals(new ContextLimits(5, 2500, 0), $turn->contextLimits);
        self::assertSame(ContextRetriever::LEGACY_INSTRUCTION, $turn->contextInstruction);
    }

    /**
     * Each key is checked on its own: a version holding some of the keys
     * takes their values and the legacy values for the others.
     */
    public function testContextKeysAreCheckedOneByOne(): void
    {
        $turn = $this->mapper()->map($this->version(['contextMaxChars' => 1200]));

        self::assertEquals(new ContextLimits(8, 1200, 0), $turn->contextLimits);
        self::assertSame(ContextRetriever::LEGACY_INSTRUCTION, $turn->contextInstruction);

        $turn = $this->mapper()->map($this->version(['contextExpansionMaxChars' => 900, 'contextInstructions' => []]));

        self::assertEquals(new ContextLimits(8, 6000, 900), $turn->contextLimits);
        self::assertSame(ContextRetriever::DEFAULT_INSTRUCTION, $turn->contextInstruction);
    }

    /**
     * A version published from a new candidate gets 10 chunks, 15000 characters,
     * an expansion budget of 6000 and the bundle's instruction text.
     */
    public function testNewCandidateDefaultsGiveTheNewContextLimits(): void
    {
        $configuration = ['instructions' => ['de' => 'Hallo.']] + Agent::candidateDefaults();
        $version = new AgentVersion($this->agent, 4, ['model' => $this->modelId($this->claude, 'claude-default')] + $configuration, 'alice');

        $turn = $this->mapper(contextInstruction: 'Bundle text.')->map($version);

        self::assertEquals(new ContextLimits(10, 15000, 6000), $turn->contextLimits);
        self::assertSame('Bundle text.', $turn->contextInstruction);
    }

    /**
     * The override of the effective language, then that of the default
     * language, then the bundle default; an empty text counts as missing.
     * The text is used literally, placeholders stay as they are.
     */
    public function testContextInstructionFallsBackToTheDefaultLanguageThenTheBundle(): void
    {
        $mapper = $this->mapper(contextInstruction: 'Bundle text.');
        $version = $this->version([
            'instructions' => ['de' => 'Du bist hilfreich.'],
            'languages' => ['de', 'en', 'fr'],
            'contextInstructions' => ['de' => 'Nur aus dem Kontext, {{agentName}}.', 'en' => 'From the context only.', 'fr' => ''],
        ]);

        self::assertSame('From the context only.', $mapper->map($version, language: 'en')->contextInstruction);
        self::assertSame('Nur aus dem Kontext, {{agentName}}.', $mapper->map($version)->contextInstruction);
        self::assertSame('Nur aus dem Kontext, {{agentName}}.', $mapper->map($version, language: 'fr')->contextInstruction);

        $withoutDefault = $this->version([
            'languages' => ['de', 'en'],
            'contextInstructions' => ['en' => 'From the context only.'],
        ]);

        self::assertSame('From the context only.', $mapper->map($withoutDefault, language: 'en')->contextInstruction);
        self::assertSame('Bundle text.', $mapper->map($withoutDefault)->contextInstruction);
    }

    public function testVersionContextLimitsReachTheTurn(): void
    {
        $turn = $this->mapper()->map($this->version([
            'contextMaxChunks' => 3,
            'contextMaxChars' => 4000,
            'contextExpansionMaxChars' => 0,
        ]));

        self::assertEquals(new ContextLimits(3, 4000, 0), $turn->contextLimits);
    }

    /**
     * The fixture version of {@see version()} holds none of the
     * gate keys and maps to a turn with every gate off.
     */
    public function testStoredVersionWithoutGateKeysHasEveryGateOff(): void
    {
        $legacy = $this->version();
        foreach (['injectionGuard', 'answerabilityCheck', 'answerabilityVotes', 'followUps'] as $key) {
            self::assertArrayNotHasKey($key, $legacy->getConfiguration());
        }

        $turn = $this->mapper()->map($legacy);

        self::assertFalse($turn->injectionGuard);
        self::assertFalse($turn->answerabilityCheck);
        self::assertSame(1, $turn->answerabilityVotes);
        self::assertFalse($turn->followUps);
    }

    public function testGateSwitchesReachTheTurn(): void
    {
        $turn = $this->mapper()->map($this->version([
            'injectionGuard' => true,
            'answerabilityCheck' => true,
            'answerabilityVotes' => 5,
            'followUps' => true,
            'templates' => ['injectionRefusal' => ['de' => 'Nein, {{agentName}}.']],
        ]));

        self::assertTrue($turn->injectionGuard);
        self::assertTrue($turn->answerabilityCheck);
        self::assertSame(5, $turn->answerabilityVotes);
        self::assertTrue($turn->followUps);
        self::assertSame('Nein, '.$this->agent->getName().'.', $turn->texts->slot('injectionRefusal'));
        self::assertNull($turn->texts->slot('unanswerableRefusal'));
    }

    public function testSystemValuesAreSubstituted(): void
    {
        $turn = $this->mapper()->map($this->version(['instructions' => ['de' => '{{agentName}} / {{language}} / {{date}}']]));

        self::assertSame('Support Bot / de / 2026-10-03', $turn->instructions);
    }

    public function testWhitespaceRepeatsAndJsonText(): void
    {
        $turn = $this->mapper()->map(
            $this->version([
                'instructions' => ['de' => "{{ name }} und {{name}} und {{\tname  }}. Antworte so: {\"user\": \"{{name}}\", \"lang\": \"{{ language }}\", \"n\": {{count}}, \"ok\": {{ok}}}"],
                'startSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'count' => ['type' => 'number'], 'ok' => ['type' => 'boolean']]],
            ]),
            ['name' => 'Ada', 'count' => 2.5, 'ok' => false],
        );

        self::assertSame('Ada und Ada und Ada. Antworte so: {"user": "Ada", "lang": "de", "n": 2.5, "ok": false}', $turn->instructions);
    }

    public function testSubstitutionIsASinglePass(): void
    {
        $turn = $this->mapper()->map(
            $this->version(['instructions' => ['de' => 'Hi {{name}}'], 'startSchema' => ['type' => 'object']]),
            ['name' => '{{date}}'],
        );

        self::assertSame('Hi {{date}}', $turn->instructions);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<array-key, mixed>, 2: string}>
     */
    public static function invalidVariables(): iterable
    {
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'maxLength' => 3]]];
        $closed = $schema + ['additionalProperties' => false];

        yield 'reserved name' => [['startSchema' => $schema], ['date' => '2020-01-01'], 'variables/date: "date" is a system value and cannot be supplied.'];
        yield 'reserved name without schema' => [[], ['agentName' => 'x'], 'variables/agentName: "agentName" is a system value and cannot be supplied.'];
        yield 'non-scalar value' => [['startSchema' => $schema], ['name' => ['a']], 'variables/name: must be a string, number or boolean.'];
        yield 'null value' => [['startSchema' => $schema], ['name' => null], 'variables/name: must be a string, number or boolean.'];
        yield 'undeclared without a start schema' => [[], ['name' => 'Ada'], 'variables/name: not declared by the agent version.'];
        yield 'undeclared with additionalProperties false' => [['startSchema' => $closed], ['other' => 'x'], 'variables/other: not declared by the agent version.'];
        yield 'unfilled placeholder' => [['instructions' => ['de' => 'Hi {{name}} {{ missing }}'], 'startSchema' => $schema], ['name' => 'Ada'], 'variables/missing: required by the instructions.'];
        yield 'optional variable not supplied' => [['instructions' => ['de' => 'Hi {{name}}'], 'startSchema' => $schema], [], 'variables/name: required by the instructions.'];
    }

    /**
     * @param array<string, mixed>    $overrides
     * @param array<array-key, mixed> $variables
     */
    #[DataProvider('invalidVariables')]
    public function testInvalidVariables(array $overrides, array $variables, string $detail): void
    {
        $e = $this->unprocessable(fn () => $this->mapper()->map($this->version($overrides), $variables));

        self::assertNotInstanceOf(StaleReferenceException::class, $e);
        self::assertSame($detail, $e->getMessage());
    }

    public function testSchemaViolationNamesThePointer(): void
    {
        $e = $this->unprocessable(fn () => $this->mapper()->map(
            $this->version(['startSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'maxLength' => 3]]]]),
            ['name' => 'Adelheid'],
        ));

        self::assertStringStartsWith('variables/name: ', $e->getMessage());
    }

    public function testMissingRequiredVariableIsReportedOnTheObject(): void
    {
        $e = $this->unprocessable(fn () => $this->mapper()->map(
            $this->version(['startSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']]]),
        ));

        self::assertStringStartsWith('variables: ', $e->getMessage());
        self::assertStringContainsString('name', $e->getMessage());
    }

    public function testDeclaredByPatternProperties(): void
    {
        $turn = $this->mapper()->map(
            $this->version(['instructions' => ['de' => '{{x_1}}'], 'startSchema' => ['type' => 'object', 'patternProperties' => ['^x_' => ['type' => 'string']], 'additionalProperties' => false]]),
            ['x_1' => 'ok'],
        );

        self::assertSame('ok', $turn->instructions);
    }

    public function testUnsupportedLanguage(): void
    {
        $e = $this->unprocessable(fn () => $this->mapper()->map($this->version(['languages' => ['de', 'en']]), [], 'fr'));

        self::assertSame('language: "fr" is not supported by agent "support" (supported: de, en).', $e->getMessage());
    }

    public function testFallbackToTheDefaultLanguageText(): void
    {
        $turn = $this->mapper()->map($this->version(['languages' => ['de', 'en'], 'instructions' => ['de' => 'Sprache {{language}}']]), [], 'en');

        self::assertSame('Sprache en', $turn->instructions);
        self::assertSame('en', $turn->language);
    }

    /**
     * @return iterable<string, array{0: ?int, 1: ?int, 2: ?int, 3: ?int, 4: ?int}>
     */
    public static function maxToolCallsMatrix(): iterable
    {
        // version, settings, bundle, request => expected
        yield 'version, no request' => [5, 7, 9, null, 5];
        yield 'version, request lowers' => [5, 7, 9, 3, 3];
        yield 'version, request cannot raise' => [5, 7, 9, 8, 5];
        yield 'settings, no request' => [null, 7, 9, null, 7];
        yield 'settings, request lowers' => [null, 7, 9, 4, 4];
        yield 'settings, request cannot raise' => [null, 7, 9, 10, 7];
        yield 'bundle, no request' => [null, null, 9, null, 9];
        yield 'bundle, request lowers' => [null, null, 9, 2, 2];
        yield 'bundle, request cannot raise' => [null, null, 9, 12, 9];
        yield 'unbounded, no request' => [null, null, null, null, null];
        yield 'unbounded, request applies' => [null, null, null, 6, 6];
    }

    #[DataProvider('maxToolCallsMatrix')]
    public function testMaxToolCalls(?int $version, ?int $settings, ?int $bundle, ?int $request, ?int $expected): void
    {
        $this->settingsMaxToolCalls = $settings;

        $turn = $this->mapper(maxToolCallsDefault: $bundle)->map($this->version(['maxToolCalls' => $version]), [], null, $request);

        self::assertSame($expected, $turn->maxToolCalls);
    }

    public function testMaxToolCallsWithoutSettingsService(): void
    {
        $turn = $this->mapper(maxToolCallsDefault: 9, withSettings: false)->map($this->version(), [], null, 3);

        self::assertSame(3, $turn->maxToolCalls);
    }

    public function testMaxUserTurnsBase(): void
    {
        $this->settingsMaxUserTurns = 7;

        self::assertSame(5, $this->mapper()->map($this->version(['maxUserTurns' => 5]))->maxUserTurns);
        self::assertSame(7, $this->mapper()->map($this->version())->maxUserTurns);
        self::assertNull($this->mapper(withSettings: false)->map($this->version())->maxUserTurns);
    }

    public function testOllamaEntryGivesTheOllamaProvider(): void
    {
        $turn = $this->mapper()->map($this->version(['model' => $this->modelId($this->local, 'llama3.2')]));

        self::assertSame($this->local, $turn->getConnection());
        self::assertSame('ollama', $turn->provider);
        self::assertSame('llama3.2', $turn->model);
        self::assertSame($this->local, $turn->providerModel?->getApiConfiguration());
    }

    /**
     * The mapper runs on every turn, gate call and judge call: the entry is
     * taken as it is, without a provider call and without its `available`
     * flag (the live check sits at the publish); this keeps the placeholder
     * entries of the migration running.
     */
    public function testUnavailableOrStaleEntryIsMappedWithoutALiveCheck(): void
    {
        $id = $this->modelId($this->claude, 'claude-retired');
        $this->providerModels[0]->setAvailable(false)->setEndpointFingerprint(ModelReferenceMigrationSql::PLACEHOLDER_FINGERPRINT);

        $turn = $this->mapper()->map($this->version(['model' => $id]));

        self::assertSame('claude-retired', $turn->model);
        self::assertSame($this->claude, $turn->getConnection());
    }

    /**
     * A version published before provider connections stores `provider`
     * and a model name; one the migration could not rewrite keeps
     * `connection` and the model name. Neither references a model entry.
     *
     * @return iterable<string, array{0: callable(self): array<string, mixed>}>
     */
    public static function unmigratedVersions(): iterable
    {
        yield 'provider string' => [static fn (self $test): array => ['provider' => 'anthropic', 'model' => 'claude-default']];
        yield 'connection and model name' => [static fn (self $test): array => ['connection' => $test->id($test->claude), 'model' => 'claude-default']];
    }

    /**
     * @param callable(self): array<string, mixed> $keys
     */
    #[DataProvider('unmigratedVersions')]
    public function testUnmigratedVersionIsStaleAtTheModel(callable $keys): void
    {
        $version = $this->version($keys($this));

        $e = $this->stale(fn () => $this->mapper()->map($version));

        self::assertSame('configuration/model', $e->path);
        self::assertSame(
            \sprintf('Agent version 3 of "support" (/api/agent_versions/%s): configuration/model: The version references no entry of the model catalogue (it was published before model references and could not be migrated); publish the agent again with a model.', $version->getId()->toRfc4122()),
            $e->getMessage(),
        );
        self::assertSame(422, $e->getStatusCode());
    }

    /**
     * @return iterable<string, array{0: callable(self): string, 1: string}>
     */
    public static function staleModels(): iterable
    {
        $unknown = Uuid::v4()->toRfc4122();
        yield 'removed entry' => [static fn (self $test): string => $unknown, \sprintf('The model entry "%s" does not exist (any more)', $unknown)];
        yield 'deactivated connection' => [static fn (self $test): string => $test->modelId($test->offline, 'llama3.2'), 'Provider connection "offline" is inactive.'];
        yield 'mcp connection' => [static fn (self $test): string => $test->modelId($test->tools, 'search'), 'is not a provider connection'];
    }

    /**
     * @param callable(self): string $model
     */
    #[DataProvider('staleModels')]
    public function testUnresolvableModelIsStaleAtTheModel(callable $model, string $message): void
    {
        $e = $this->stale(fn () => $this->mapper()->map($this->version(['model' => $model($this)])));

        self::assertSame('configuration/model', $e->path);
        self::assertStringContainsString('configuration/model: ', $e->getMessage());
        self::assertStringContainsString($message, $e->getMessage());
    }

    public function testVersionWithoutModelIsStaleAtTheModel(): void
    {
        self::assertSame('configuration/model', $this->stale(fn () => $this->mapper()->map($this->version(['model' => null])))->path);
    }

    public function testStaleCollection(): void
    {
        $missing = Uuid::v4()->toRfc4122();
        $version = $this->version(['collections' => [$this->docs->getId()->toRfc4122(), $missing]]);

        $e = $this->stale(fn () => $this->mapper()->map($version));

        self::assertSame('configuration/collections/1', $e->path);
        self::assertSame(
            \sprintf('Agent version 3 of "support" (/api/agent_versions/%s): configuration/collections/1: Collection "%s" does not exist (any more).', $version->getId()->toRfc4122(), $missing),
            $e->getMessage(),
        );
        self::assertSame(422, $e->getStatusCode());
    }

    public function testStaleConnection(): void
    {
        $version = $this->version(['mcpTools' => [
            ['connection' => $this->id($this->tools), 'name' => 'search', 'terminal' => false],
            ['connection' => $this->id($this->inactive), 'name' => 'search', 'terminal' => false],
        ]]);

        $e = $this->stale(fn () => $this->mapper()->map($version));

        self::assertSame('configuration/mcpTools/1/connection', $e->path);
        self::assertStringEndsWith(\sprintf('configuration/mcpTools/1/connection: MCP connection "%s" is inactive.', $this->id($this->inactive)), $e->getMessage());
    }

    public function testUnknownConnection(): void
    {
        $unknown = Uuid::v4()->toRfc4122();

        $e = $this->stale(fn () => $this->mapper()->map($this->version(['mcpTools' => [['connection' => $unknown, 'name' => 'search', 'terminal' => false]]])));

        self::assertSame('configuration/mcpTools/0/connection', $e->path);
        self::assertStringEndsWith(\sprintf('Unknown MCP connection "%s".', $unknown), $e->getMessage());
    }

    public function testMissingCatalogueRow(): void
    {
        $e = $this->stale(fn () => $this->mapper()->map($this->version(['mcpTools' => [
            ['connection' => $this->id($this->tools), 'name' => 'search', 'terminal' => false],
            ['connection' => $this->id($this->wiki), 'name' => 'vanished', 'terminal' => false],
        ]])));

        self::assertSame('configuration/mcpTools/1/name', $e->path);
        self::assertStringEndsWith('configuration/mcpTools/1/name: MCP connection "wiki" offers no tool "vanished" in the tool catalogue.', $e->getMessage());
    }

    public function testUnbuildableRow(): void
    {
        $e = $this->stale(fn () => $this->mapper()->map($this->version(['mcpTools' => [
            ['connection' => $this->id($this->tools), 'name' => 'search', 'terminal' => false],
            ['connection' => $this->id($this->wiki), 'name' => 'page', 'terminal' => false],
            ['connection' => $this->id($this->tools), 'name' => 'broken', 'terminal' => false],
        ]])));

        self::assertSame('configuration/mcpTools/2/name', $e->path);
        self::assertStringEndsWith('configuration/mcpTools/2/name: MCP connection "tools" offers no tool "broken" in the tool catalogue.', $e->getMessage());
    }

    public function testStrictFactoryKeepsTheLenientOne(): void
    {
        $factory = new McpToolFactory($this->entityManager(), $this->transports);
        $rows = [$this->catalogue[3], $this->catalogue[0]];

        self::assertSame(['search'], array_map(static fn (ToolInterface $tool): string => $tool->getName(), $factory->forTools($rows)));

        try {
            $factory->forToolsStrict($rows);
            self::fail('No exception for the unbuildable row.');
        } catch (\UnexpectedValueException $e) {
            self::assertSame(0, $e->getCode());
            self::assertNotNull($e->getPrevious());
        }
    }

    /**
     * A turn that must not offer MCP tools (anonymous conversation): the
     * tool factory is not called, so no MCP transport is created, and the
     * version's `mcpTools` are not checked (a stale entry is no 422).
     */
    public function testWithoutMcpToolsNothingIsBuiltOrChecked(): void
    {
        $version = $this->version(['mcpTools' => [
            ['connection' => $this->id($this->tools), 'name' => 'search', 'terminal' => true],
            ['connection' => $this->id($this->inactive), 'name' => 'search', 'terminal' => false],
        ]]);

        $turn = $this->mapper()->map($version, withMcpTools: false);

        self::assertSame([], $turn->tools);
        self::assertSame([], $turn->mcpConnections);
        self::assertSame([], $turn->terminalTools);
        self::assertSame(0, $this->transports->getTransportCount(), 'The tool factory builds no tool.');

        // Control: with MCP tools the factory builds them through a transport.
        $turn = $this->mapper()->map($this->version(['mcpTools' => [['connection' => $this->id($this->tools), 'name' => 'search', 'terminal' => true]]]));

        self::assertSame(['search'], $turn->terminalTools);
        self::assertSame(1, $this->transports->getTransportCount());
    }

    /**
     * A forward_jwt connection without the person's JWT (replay, batch
     * evaluation) builds only its read-only tools. The version's terminal
     * tools are limited to the tools built, so a dropped write tool is not
     * forced by ForcedClose::arm(); with a JWT every tool stays terminal.
     */
    public function testTerminalToolsDroppedWithoutJwtAreNotTerminal(): void
    {
        $designer = new ApiConfiguration()
            ->setName('designer')
            ->setConfigJson(['type' => 'mcp', 'url' => 'https://designer.example.org/mcp', 'auth_type' => StreamableHttpTransportFactory::AUTH_FORWARD_JWT])
            ->setActive(true);
        $this->catalogue[] = self::row($designer, 'status', ['type' => 'object'])->setAnnotations(['readOnlyHint' => true]);
        $this->catalogue[] = self::row($designer, 'deploy', ['type' => 'object'])->setAnnotations(['readOnlyHint' => false]);
        $version = $this->version(['mcpTools' => [
            ['connection' => $this->id($designer), 'name' => 'deploy', 'terminal' => true],
            ['connection' => $this->id($designer), 'name' => 'status', 'terminal' => true],
            ['connection' => $this->id($this->tools), 'name' => 'finish', 'terminal' => true],
        ]]);
        $connections = [$designer];

        $turn = $this->mapper(jwt: new FakeCurrentJwtProvider(), extraConnections: $connections)->map($version);

        self::assertSame(['status', 'finish'], array_map(static fn (ToolInterface $tool): string => $tool->getName(), $turn->tools));
        self::assertSame(['status', 'finish'], $turn->terminalTools);

        $turn = $this->mapper(jwt: new FakeCurrentJwtProvider('header.payload.signature'), extraConnections: $connections)->map($version);

        self::assertSame(['deploy', 'status', 'finish'], array_map(static fn (ToolInterface $tool): string => $tool->getName(), $turn->tools));
        self::assertSame(['deploy', 'status', 'finish'], $turn->terminalTools);
    }


    // --- artifact tools ---------------------------------------

    /**
     * Without an access (every caller but a conversation turn) a version
     * with `artifactTools` maps exactly as before: no artifact tool, no
     * guidelines.
     */
    public function testWithoutArtifactAccessTheTurnHasNoArtifactTools(): void
    {
        $version = $this->artifactVersion();

        $turn = $this->mapper(artifactToolkits: $this->artifactToolkits())->map($version);

        self::assertSame(['search'], self::toolNames($turn));
        self::assertSame('Du bist hilfreich.', $turn->instructions);
    }

    /**
     * @return iterable<string, array{bool, list<string>}>
     */
    public static function artifactAccesses(): iterable
    {
        yield 'writable' => [true, ArtifactToolkit::TOOL_NAMES];
        yield 'read-only' => [false, ['read_file', 'grep_file_content', 'glob_path']];
    }

    /**
     * With an access the toolkit's tools follow the MCP tools, terminal
     * tools are untouched, and the guidelines are appended to the
     * rendered instructions without any server path.
     *
     * @param list<string> $expected
     */
    #[DataProvider('artifactAccesses')]
    public function testArtifactToolsFollowTheMcpToolsAndTheGuidelinesTheInstructions(bool $writable, array $expected): void
    {
        $access = $this->artifactAccess($writable);

        $turn = $this->mapper(artifactToolkits: $this->artifactToolkits())->map($this->artifactVersion(), artifacts: $access);

        self::assertSame(['search', ...$expected], self::toolNames($turn));
        self::assertSame(['tools'], $turn->mcpConnections);
        self::assertSame([], $turn->terminalTools);
        self::assertNotContains('bash', self::toolNames($turn));
        self::assertNotContains('delete_file', self::toolNames($turn));
        self::assertNotContains('parse_file', self::toolNames($turn));

        self::assertStringStartsWith("Du bist hilfreich.\n\n<TOOLS-GUIDELINES>\n# ".implode(', ', $expected)."\n", $turn->instructions);
        self::assertStringEndsWith('</TOOLS-GUIDELINES>', $turn->instructions);
        self::assertStringContainsString($access->conversationId.'/', $turn->instructions);
        self::assertStringContainsString('glob_path', $turn->instructions);
        self::assertStringNotContainsString($access->scopeRoot, $turn->instructions);
        self::assertStringNotContainsString((string) $this->artifactBase, $turn->instructions);
        self::assertSame($writable, str_contains($turn->instructions, 'write_file'));
    }

    /**
     * A turn that offers no MCP tools (anonymous) can still carry artifact
     * tools in principle; the gate, not the mapper, keeps them away.
     */
    public function testArtifactToolsWithoutMcpTools(): void
    {
        $turn = $this->mapper(artifactToolkits: $this->artifactToolkits())->map($this->artifactVersion(), withMcpTools: false, artifacts: $this->artifactAccess());

        self::assertSame(ArtifactToolkit::TOOL_NAMES, self::toolNames($turn));
        self::assertSame([], $turn->mcpConnections);
    }

    /**
     * An MCP tool named like an artifact tool is the runtime twin of the
     * publish check: 422, not a stale reference. Without the access the
     * same version maps.
     */
    public function testMcpToolNamedLikeAnArtifactToolIsRefused(): void
    {
        $this->catalogue[] = self::row($this->tools, 'read_file', ['type' => 'object']);
        $version = $this->artifactVersion([['connection' => $this->id($this->tools), 'name' => 'read_file', 'terminal' => false]]);
        $mapper = $this->mapper(artifactToolkits: $this->artifactToolkits());

        $e = $this->unprocessable(fn () => $mapper->map($version, artifacts: $this->artifactAccess()));

        self::assertNotInstanceOf(StaleReferenceException::class, $e);
        self::assertSame('MCP tool "read_file" collides with the artifact tool of the same name; remove it from mcpTools or turn artifactTools off.', $e->getMessage());
        self::assertSame(['read_file'], self::toolNames($mapper->map($version)));
    }

    public function testArtifactAccessWithoutToolkitFactoryIsALogicError(): void
    {
        $this->expectException(\LogicException::class);

        $this->mapper()->map($this->artifactVersion(), artifacts: $this->artifactAccess());
    }

    /**
     * {@see VersionTurnMapper::artifactAccessFor()}: nothing for a version
     * without the key (no log), a warning when the artifact services are
     * missing, and the resolver's access otherwise.
     */
    public function testArtifactAccessForAsksTheResolver(): void
    {
        $conversation = new Conversation()->setOwnerSubject('user-1');
        $on = AgentConfiguration::fromArray($this->artifactVersion()->getConfiguration());
        $off = AgentConfiguration::fromArray($this->version()->getConfiguration());

        $unconfigured = $this->mapper(logger: $this->logger());
        self::assertNull($unconfigured->artifactAccessFor($conversation, $off));
        self::assertSame([], $this->logged);
        self::assertNull($unconfigured->artifactAccessFor($conversation, $on));
        self::assertCount(1, $this->logged);
        self::assertSame('warning', $this->logged[0][0]);
        self::assertStringContainsString('the artifact services are not configured', $this->logged[0][1]);

        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('user-1', null, ['ROLE_USER']), 'main', ['ROLE_USER']));
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn(true);
        $resolver = new ArtifactAccessResolver($tokens, $checker, new ArtifactScope($this->artifactRoot()));
        $mapper = $this->mapper(artifactToolkits: $this->artifactToolkits(), artifactAccessResolver: $resolver);

        self::assertNull($mapper->artifactAccessFor($conversation, $off));
        $access = $mapper->artifactAccessFor($conversation, $on);
        self::assertInstanceOf(ArtifactAccess::class, $access);
        self::assertSame(['user-1', $conversation->getId()->toRfc4122(), true], [$access->ownerSubject, $access->conversationId, $access->writable]);
        self::assertDirectoryExists($access->scopeRoot.'/'.$access->conversationId);
    }

    /**
     * @param list<array{connection: string, name: string, terminal: bool}>|null $mcpTools null: `search` of `tools`
     */
    private function artifactVersion(?array $mcpTools = null): AgentVersion
    {
        return $this->version([
            'artifactTools' => true,
            'mcpTools' => $mcpTools ?? [['connection' => $this->id($this->tools), 'name' => 'search', 'terminal' => false]],
        ]);
    }

    /**
     * The storage root of the test, created on first use.
     */
    private function artifactRoot(): string
    {
        $this->artifactBase ??= ArtifactTestFiles::makeTempDir();
        if (!is_dir($this->artifactBase.'/root')) {
            mkdir($this->artifactBase.'/root');
        }

        return $this->artifactBase.'/root';
    }

    private function artifactAccess(bool $writable = true): ArtifactAccess
    {
        $conversationId = '0199e3a4-6b7c-7d8e-9f01-23456789abcd';

        return new ArtifactAccess('user-1', $conversationId, new ArtifactScope($this->artifactRoot())->ensure('user-1', $conversationId), $writable);
    }

    private function artifactToolkits(): ArtifactToolkitFactory
    {
        return new ArtifactToolkitFactory(new ArtifactTestLimits(), new InMemoryArtifactLedger(), new LockFactory(new InMemoryStore()));
    }

    /**
     * @return list<string>
     */
    private static function toolNames(TurnConfiguration $turn): array
    {
        return array_map(static fn (ToolInterface $tool): string => $tool->getName(), $turn->tools);
    }

    /**
     * @param list<ApiConfiguration> $extraConnections MCP connections of a single test, resolvable next to the shared ones
     */
    private function mapper(?int $maxToolCallsDefault = null, bool $withSettings = true, ?LoggerInterface $logger = null, int $contextMaxChunks = 8, int $contextMaxChars = 6000, string $contextInstruction = ContextRetriever::DEFAULT_INSTRUCTION, ?FakeCurrentJwtProvider $jwt = null, array $extraConnections = [], ?ArtifactToolkitFactory $artifactToolkits = null, ?ArtifactAccessResolver $artifactAccessResolver = null): VersionTurnMapper
    {
        $entityManager = $this->entityManager();
        $uuidResolver = $this->createStub(UuidResolver::class);
        $uuidResolver->method('findByPartialUuid')->willReturnCallback(
            fn (string $class, string $id): ?ApiConfiguration => array_find(
                [$this->tools, $this->wiki, $this->inactive, $this->claude, $this->local, $this->offline, ...$extraConnections],
                static fn (ApiConfiguration $c): bool => str_starts_with($c->getId()->toRfc4122(), strtolower($id)),
            ),
        );

        $limitDefaults = null;
        if ($withSettings) {
            $limitDefaults = $this->createStub(AgentLimitDefaultsInterface::class);
            $limitDefaults->method('maxToolCalls')->willReturnCallback(fn (): ?int => $this->settingsMaxToolCalls);
            $limitDefaults->method('maxUserTurns')->willReturnCallback(fn (): ?int => $this->settingsMaxUserTurns);
        }

        return new VersionTurnMapper(
            new ProviderConnectionResolver($entityManager, $this->createStub(IriConverterInterface::class), $uuidResolver),
            $entityManager,
            new McpConnectionResolver($entityManager, $this->createStub(IriConverterInterface::class), $uuidResolver),
            new McpToolFactory($entityManager, $this->transports, jwtProvider: $jwt),
            new LimitResolver(),
            $limitDefaults,
            new MockClock('2026-10-03 23:30:00', 'Europe/Berlin'),
            4,
            $maxToolCallsDefault,
            $logger,
            $contextMaxChunks,
            $contextMaxChars,
            $contextInstruction,
            $artifactToolkits,
            $artifactAccessResolver,
        );
    }

    private function logger(): LoggerInterface
    {
        $logged = &$this->logged;

        return new class($logged) extends AbstractLogger {
            /**
             * @param list<array{string, string, array<string, mixed>}> $logged
             */
            public function __construct(private array &$logged)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = [(string) $level, (string) $message, $context];
            }
        };
    }

    private function entityManager(): EntityManagerInterface
    {
        $connections = $this->createStub(EntityRepository::class);
        $connections->method('findBy')->willReturn([]);

        $tools = $this->createStub(EntityRepository::class);
        $tools->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?McpTool => array_find(
                $this->catalogue,
                static fn (McpTool $tool): bool => $tool->getApiConfiguration() === $criteria['apiConfiguration'] && $tool->getName() === $criteria['name'],
            ),
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(static fn (string $class): EntityRepository => match ($class) {
            ApiConfiguration::class => $connections,
            McpTool::class => $tools,
        });
        $entityManager->method('find')->willReturnCallback(
            fn (string $class, Uuid $id): ?object => match ($class) {
                Collection::class => $id->equals($this->docs->getId()) ? $this->docs : null,
                ProviderModel::class => array_find($this->providerModels, static fn (ProviderModel $model): bool => $model->getId()->equals($id)),
                default => null,
            },
        );

        return $entityManager;
    }

    /**
     * The UUID of the model catalogue entry of $connection for $name, as a
     * version stores it; created on first use.
     */
    private function modelId(ApiConfiguration $connection, string $name): string
    {
        $model = array_find($this->providerModels, static fn (ProviderModel $model): bool => $model->getApiConfiguration() === $connection && $model->getName() === $name)
            ?? $this->providerModels[] = new ProviderModel($connection, $name);

        return $model->getId()->toRfc4122();
    }

    /**
     * A version as the publisher freezes it: the model as the UUID of its
     * catalogue entry (`claude-default` of `claude`).
     *
     * @param array<string, mixed> $overrides
     */
    private function version(array $overrides = []): AgentVersion
    {
        return new AgentVersion($this->agent, 3, $overrides + [
            'instructions' => ['de' => 'Du bist hilfreich.'],
            'model' => $this->modelId($this->claude, 'claude-default'),
            'collections' => [],
            'retrievalDepth' => null,
            'mcpTools' => [],
            'responseSchema' => null,
            'startSchema' => null,
            'modelOptions' => [],
            'maxToolCalls' => null,
            'maxUserTurns' => null,
            'languages' => ['de'],
            'defaultLanguage' => 'de',
        ], 'alice');
    }

    private function unprocessable(callable $map): UnprocessableEntityHttpException
    {
        try {
            $map();
        } catch (UnprocessableEntityHttpException $e) {
            return $e;
        }

        self::fail('No 422.');
    }

    private function stale(callable $map): StaleReferenceException
    {
        $e = $this->unprocessable($map);
        self::assertInstanceOf(StaleReferenceException::class, $e);

        return $e;
    }

    private function id(ApiConfiguration $connection): string
    {
        return $connection->getId()->toRfc4122();
    }

    /**
     * @param array<string, mixed> $inputSchema
     */
    private static function row(ApiConfiguration $connection, string $name, array $inputSchema): McpTool
    {
        return new McpTool($connection, $name)->setInputSchema($inputSchema);
    }

    private static function connection(string $name, bool $active): ApiConfiguration
    {
        return new ApiConfiguration()
            ->setName($name)
            ->setConfigJson(['type' => 'mcp', 'url' => \sprintf('https://%s.example.org/mcp', $name)])
            ->setActive($active);
    }
}

// - revised 2026-10-09
// - revised 2026-10-16
