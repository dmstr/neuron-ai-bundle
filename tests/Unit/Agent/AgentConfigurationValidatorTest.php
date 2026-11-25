<?php

// file generated with AI assistance: Claude Code - 2026-10-02 21:49:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Agent;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Agent\AgentConfiguration;
use Dmstr\NeuronAiBundle\Agent\AgentConfigurationValidator;
use Dmstr\NeuronAiBundle\Conversation\ConversationReferences;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Entity\Refs\McpTool;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\Provider\ModelAvailabilityCheck;
use Dmstr\NeuronAiBundle\Provider\ModelCapabilityCheck;
use Dmstr\NeuronAiBundle\Provider\ProviderModelResolver;
use Dmstr\NeuronAiBundle\Provider\ToolModelCheck;
use Dmstr\NeuronAiBundle\Testing\FakeModelLister;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Kernel-free checks of the publish rules. The real
 * ProviderModelResolver, ModelAvailabilityCheck (over the
 * FakeModelLister), ConversationReferences and McpConnectionResolver run
 * against an in-memory entity manager holding one collection, four MCP and
 * REST connections with their catalogue tools and three provider
 * connections: `claude` (anthropic, offers `claude-default`,
 * `claude-other` and `claude-opus-5-5`), `local` (ollama, offers `llama-default:latest` and
 * `llama-big`) and the inactive ollama connection `offline`. The
 * candidate references its model as the UUID of an entry in
 * $providerModels ({@see ref()}, which creates the entry without
 * capabilities unless given, so the model is unknown to the capability,
 * tool-model and model options checks and passes them).
 */
final class AgentConfigurationValidatorTest extends TestCase
{
    private Collection $docs;
    private ApiConfiguration $tools;
    private ApiConfiguration $otherTools;
    private ApiConfiguration $inactive;
    private ApiConfiguration $rest;
    private ApiConfiguration $claude;
    private ApiConfiguration $local;
    private ApiConfiguration $offline;
    private FakeModelLister $lister;

    /** @var list<McpTool> */
    private array $catalogue = [];

    /** @var list<ProviderModel> */
    private array $providerModels = [];

    private bool $withToolModelCheck = true;

    protected function setUp(): void
    {
        $this->docs = new Collection()->setName('docs');
        $this->tools = self::connection('tools', 'mcp', true);
        $this->otherTools = self::connection('other-tools', 'mcp', true);
        $this->inactive = self::connection('inactive', 'mcp', false);
        $this->rest = self::connection('rest', 'rest', true);
        $this->claude = ProviderConnectionFixtures::anthropic('claude');
        $this->local = ProviderConnectionFixtures::ollama('local');
        $this->offline = ProviderConnectionFixtures::ollama('offline', active: false);
        $this->lister = new FakeModelLister()
            ->offer(['claude-default', 'claude-other', 'claude-opus-5-5'], 'claude')
            ->offer(['llama-default:latest', 'llama-big'], 'local');
        $this->catalogue = [
            new McpTool($this->tools, 'search'),
            new McpTool($this->tools, 'finish'),
            new McpTool($this->otherTools, 'search'),
            new McpTool($this->tools, 'read_file'),
        ];
    }

    public function testValidCandidateIsResolvedAndFrozen(): void
    {
        $configuration = $this->validator()->validateForPublish($this->candidate([
            'collections' => ['docs', $this->docs->getId()->toRfc4122()],
            'mcpTools' => [
                ['connection' => 'tools', 'name' => 'search'],
                ['connection' => $this->tools->getId()->toRfc4122(), 'name' => 'finish', 'terminal' => true],
            ],
            'responseSchema' => ['type' => 'object', 'properties' => ['answer' => ['type' => 'string']], 'required' => ['answer']],
            'startSchema' => ['type' => 'object', 'properties' => []],
            'maxToolCalls' => 3,
        ]));

        self::assertSame($this->ref($this->claude, 'claude-default'), $configuration->model);
        self::assertSame(['claude'], $this->lister->getListedConnections());
        self::assertSame([$this->docs->getId()->toRfc4122()], $configuration->collections);
        self::assertSame([
            ['connection' => $this->tools->getId()->toRfc4122(), 'name' => 'search', 'terminal' => false],
            ['connection' => $this->tools->getId()->toRfc4122(), 'name' => 'finish', 'terminal' => true],
        ], $configuration->mcpTools);
        self::assertSame(3, $configuration->maxToolCalls);
        self::assertSame(['de' => 'Du bist hilfreich.'], $configuration->instructions);
    }

    public function testOllamaModelIsStoredAsTheUuidOfItsEntry(): void
    {
        $id = $this->ref($this->local, 'llama-big');

        $configuration = $this->validator()->validateForPublish($this->candidate(['model' => $id]));

        self::assertSame($id, $configuration->model);
        self::assertSame(['local'], $this->lister->getListedConnections());
    }

    public function testEntryWithoutTheLatestTagPassesTheLiveCheck(): void
    {
        $id = $this->ref($this->local, 'llama-default');

        self::assertSame($id, $this->validator()->validateForPublish($this->candidate(['model' => $id]))->model);
    }

    public function testModelByIriAndPartialUuidIsStoredAsTheFullUuid(): void
    {
        $id = $this->ref($this->local, 'llama-big');

        foreach (['/api/refs/provider_models/'.$id, substr($id, 0, 8), strtoupper($id)] as $reference) {
            $configuration = $this->validator()->validateForPublish($this->candidate(['model' => $reference]));
            self::assertSame($id, $configuration->model, $reference);
        }
    }

    public function testConnectionKeyIsRejectedWithAHintAtTheModel(): void
    {
        $messages = $this->violationMessages(['connection' => 'claude'] + $this->candidate());

        self::assertSame(['candidate/connection' => AgentConfiguration::CONNECTION_REMOVED], $messages);
        self::assertSame([], $this->lister->getListedConnections());
    }

    public function testMissingModelIsRejected(): void
    {
        $messages = $this->violationMessages($this->candidate(['model' => null]));

        self::assertSame(['candidate/model'], array_keys($messages));
        self::assertStringContainsString('model is required', $messages['candidate/model']);
        self::assertSame([], $this->lister->getListedConnections());
    }

    /**
     * @return iterable<string, array{0: callable(self): string, 1: string}>
     */
    public static function unusableModels(): iterable
    {
        yield 'model name' => [static fn (self $test): string => 'claude-default', 'Unknown model "claude-default"'];
        yield 'unknown uuid' => [static fn (self $test): string => '01234567-89ab-4def-8123-456789abcdef', 'Unknown model "01234567-89ab-4def-8123-456789abcdef"'];
        yield 'iri of a connection' => [static fn (self $test): string => '/api/admin/api_configurations/'.$test->claude->getId()->toRfc4122(), 'Unknown model'];
        yield 'inactive connection' => [static fn (self $test): string => $test->ref($test->offline, 'llama-big'), 'the connection is inactive'];
        yield 'no longer offered' => [static function (self $test): string {
            $id = $test->ref($test->local, 'llama-old');
            $test->providerModels[\count($test->providerModels) - 1]->setAvailable(false);

            return $id;
        }, 'is no longer offered by the connection'];
        yield 'known to lack completion' => [static fn (self $test): string => $test->ref($test->local, 'nomic-embed-text', ['embedding']), 'lacks the capability "completion"'];
    }

    /**
     * @param callable(self): string $reference
     */
    #[DataProvider('unusableModels')]
    public function testUnusableModelIsRejectedWithoutALiveCheck(callable $reference, string $message): void
    {
        $messages = $this->violationMessages($this->candidate(['model' => $reference($this)]));

        self::assertSame(['candidate/model'], array_keys($messages));
        self::assertStringContainsString($message, $messages['candidate/model']);
        self::assertSame([], $this->lister->getListedConnections(), 'No live check without a usable entry.');
    }

    public function testModelNotOfferedIsRejectedAtTheModel(): void
    {
        $messages = $this->violationMessages($this->candidate(['model' => $this->ref($this->claude, 'claude-unknown')]));

        self::assertSame(['candidate/model'], array_keys($messages));
        self::assertSame('Model "claude-unknown" is not offered by connection "claude" (offered: claude-default, claude-other, claude-opus-5-5).', $messages['candidate/model']);
    }

    public function testModelOfAnotherConnectionIsNotOffered(): void
    {
        self::assertSame(['candidate/model'], $this->violationPaths($this->candidate(['model' => $this->ref($this->local, 'claude-default')])));
    }

    public function testUnreachableProviderIsRejectedAtTheModelWithItsOwnText(): void
    {
        $this->lister->unreachable('claude');

        $messages = $this->violationMessages($this->candidate());

        self::assertSame(['candidate/model'], array_keys($messages));
        self::assertStringStartsWith('Model "claude-default" could not be verified (provider unreachable: ', $messages['candidate/model']);
        self::assertStringNotContainsString('is not offered', $messages['candidate/model']);
        self::assertStringNotContainsString(ProviderConnectionFixtures::PLACEHOLDER_API_KEY, $messages['candidate/model']);
    }

    public function testRejectedCredentialsCannotVerifyTheModel(): void
    {
        $this->lister->unauthenticated('claude');

        $messages = $this->violationMessages($this->candidate());

        self::assertStringStartsWith('Model "claude-default" could not be verified', $messages['candidate/model']);
        self::assertStringNotContainsString(ProviderConnectionFixtures::PLACEHOLDER_API_KEY, $messages['candidate/model']);
    }

    public function testMissingInstructionsOfTheDefaultLanguage(): void
    {
        self::assertSame(['candidate/instructions'], $this->violationPaths($this->candidate(['instructions' => []])));
    }

    public function testDefaultLanguageOutsideTheLanguages(): void
    {
        $paths = $this->violationPaths($this->candidate(['defaultLanguage' => 'en', 'instructions' => ['en' => 'Hi']]));

        self::assertContains('candidate/defaultLanguage', $paths);
    }

    public function testInstructionsForAnUnknownLanguage(): void
    {
        self::assertSame(['candidate/instructions/fr'], $this->violationPaths($this->candidate(['instructions' => ['de' => 'Hallo', 'fr' => 'Salut']])));
    }

    public function testContextInstructionsForAnUnknownLanguage(): void
    {
        $messages = $this->violationMessages($this->candidate(['contextInstructions' => ['de' => 'Nur aus dem Kontext.', 'fr' => 'Du contexte seulement.']]));

        self::assertSame(['candidate/contextInstructions/fr'], array_keys($messages));
        self::assertStringContainsString('"fr"', $messages['candidate/contextInstructions/fr']);
    }

    public function testContextKeysAreFrozenWithoutADefaultLanguageEntry(): void
    {
        $configuration = $this->validator()->validateForPublish($this->candidate([
            'languages' => ['de', 'en'],
            'contextInstructions' => ['en' => 'Answer from the context only.'],
            'contextMaxChunks' => 4,
            'contextMaxChars' => 3000,
            'contextExpansionMaxChars' => 0,
        ]));

        self::assertSame(['en' => 'Answer from the context only.'], $configuration->contextInstructions);
        self::assertSame(4, $configuration->contextMaxChunks);
        self::assertSame(3000, $configuration->contextMaxChars);
        self::assertSame(0, $configuration->contextExpansionMaxChars);
        // A published version stores every key, so it never reads as legacy.
        self::assertArrayHasKey('contextInstructions', $configuration->toArray());
    }

    public function testUnknownCollection(): void
    {
        self::assertSame(['candidate/collections/1'], $this->violationPaths($this->candidate(['collections' => ['docs', 'missing']])));
    }

    public function testInactiveConnection(): void
    {
        self::assertSame(['candidate/mcpTools/0/connection'], $this->violationPaths($this->candidate(['mcpTools' => [['connection' => 'inactive', 'name' => 'search']]])));
    }

    public function testNonMcpConnection(): void
    {
        $paths = $this->violationPaths($this->candidate(['mcpTools' => [['connection' => $this->rest->getId()->toRfc4122(), 'name' => 'search']]]));

        self::assertSame(['candidate/mcpTools/0/connection'], $paths);
    }

    public function testUnknownConnection(): void
    {
        self::assertSame(['candidate/mcpTools/0/connection'], $this->violationPaths($this->candidate(['mcpTools' => [['connection' => 'nowhere', 'name' => 'search']]])));
    }

    public function testUnknownToolName(): void
    {
        self::assertSame(['candidate/mcpTools/0/name'], $this->violationPaths($this->candidate(['mcpTools' => [['connection' => 'tools', 'name' => 'nothing']]])));
    }

    public function testToolNameFromTwoConnections(): void
    {
        $paths = $this->violationPaths($this->candidate(['mcpTools' => [
            ['connection' => 'tools', 'name' => 'search'],
            ['connection' => 'other-tools', 'name' => 'search'],
        ]]));

        self::assertSame(['candidate/mcpTools/1/name'], $paths);
    }

    public function testModelKnownToLackToolsIsRejectedAtTheModel(): void
    {
        $row = $this->providerModel($this->local, 'llama-big', ['completion', 'insert']);

        $messages = $this->violationMessages($this->candidate([
            'model' => $this->ref($this->local, 'llama-big'),
            'mcpTools' => [['connection' => 'tools', 'name' => 'search']],
        ]));

        self::assertSame(['candidate/model'], array_keys($messages));
        self::assertSame(
            \sprintf('Model "llama-big" of connection "local" does not support tool calls (capabilities: completion, insert); choose a model with the tools capability or remove mcpTools. If the model was re-pulled, refresh it with neuron:provider-models:sync %s.', $row->getId()->toRfc4122()),
            $messages['candidate/model'],
        );
    }

    public function testEmbeddingOnlyModelIsNoChatModel(): void
    {
        $messages = $this->violationMessages($this->candidate([
            'model' => $this->ref($this->local, 'llama-big', ['embedding']),
            'mcpTools' => [['connection' => 'tools', 'name' => 'search']],
        ]));

        self::assertSame(['candidate/model'], array_keys($messages));
        self::assertStringContainsString('lacks the capability "completion"', $messages['candidate/model']);
    }

    public function testToolModelIsMatchedWithoutTheLatestTag(): void
    {
        $this->providerModel($this->local, 'llama-default:latest', ['completion']);

        self::assertSame(['candidate/model'], $this->violationPaths($this->candidate([
            'model' => $this->ref($this->local, 'llama-default'),
            'mcpTools' => [['connection' => 'tools', 'name' => 'search']],
        ])));
    }

    public function testModelWithToolsIsPublished(): void
    {
        $this->providerModel($this->local, 'llama-big', ['completion', 'tools']);

        $configuration = $this->validator()->validateForPublish($this->candidate([
            'model' => $this->ref($this->local, 'llama-big'),
            'mcpTools' => [['connection' => 'tools', 'name' => 'search']],
        ]));

        self::assertSame($this->ref($this->local, 'llama-big'), $configuration->model);
    }

    public function testModelWithoutToolsIsPublishedWithoutMcpTools(): void
    {
        $this->providerModel($this->local, 'llama-big', ['completion']);

        $configuration = $this->validator()->validateForPublish($this->candidate(['model' => $this->ref($this->local, 'llama-big')]));

        self::assertSame([], $configuration->mcpTools);
    }

    public function testUnknownOrStaleModelPassesTheToolsCheck(): void
    {
        $tools = ['mcpTools' => [['connection' => 'tools', 'name' => 'search']]];

        $unknown = $this->ref($this->local, 'llama-big');
        self::assertSame($unknown, $this->validator()->validateForPublish($this->candidate(['model' => $unknown] + $tools))->model);

        $this->providerModels = [];
        $stale = $this->ref($this->local, 'llama-big', ['completion']);
        $this->providerModels[0]->setEndpointFingerprint(str_repeat('0', 64));
        self::assertSame($stale, $this->validator()->validateForPublish($this->candidate(['model' => $stale] + $tools))->model);
    }

    public function testToolsCheckIsSkippedWhenNotInjected(): void
    {
        $this->withToolModelCheck = false;
        $this->providerModel($this->local, 'llama-big', ['completion']);

        $configuration = $this->validator()->validateForPublish($this->candidate([
            'model' => $this->ref($this->local, 'llama-big'),
            'mcpTools' => [['connection' => 'tools', 'name' => 'search']],
        ]));

        self::assertSame($this->ref($this->local, 'llama-big'), $configuration->model);
    }

    public function testToolsCheckDoesNotRunAfterTheLiveCheckFailed(): void
    {
        $this->providerModel($this->local, 'llama-gone', ['completion']);

        $messages = $this->violationMessages($this->candidate([
            'model' => $this->ref($this->local, 'llama-gone'),
            'mcpTools' => [['connection' => 'tools', 'name' => 'search']],
        ]));

        self::assertSame(['candidate/model'], array_keys($messages));
        self::assertStringNotContainsString('tool calls', $messages['candidate/model']);
    }

    public function testArtifactToolsAreFrozenOffByDefault(): void
    {
        self::assertFalse($this->validator()->validateForPublish($this->candidate())->artifactTools);
        self::assertTrue($this->validator()->validateForPublish($this->candidate(['artifactTools' => true]))->artifactTools);
    }

    /**
     * The artifact tools need a tool-capable model as well, even
     * without any MCP tool; the message names the field that attaches them.
     */
    public function testModelKnownToLackToolsIsRejectedForArtifactTools(): void
    {
        $row = $this->providerModel($this->local, 'llama-big', ['completion', 'insert']);

        $messages = $this->violationMessages($this->candidate([
            'model' => $this->ref($this->local, 'llama-big'),
            'artifactTools' => true,
        ]));

        self::assertSame(['candidate/model'], array_keys($messages));
        self::assertSame(
            \sprintf('Model "llama-big" of connection "local" does not support tool calls (capabilities: completion, insert); choose a model with the tools capability or remove artifactTools. If the model was re-pulled, refresh it with neuron:provider-models:sync %s.', $row->getId()->toRfc4122()),
            $messages['candidate/model'],
        );
    }

    public function testToolModelMessageNamesBothToolFields(): void
    {
        $this->providerModel($this->local, 'llama-big', ['completion']);

        $messages = $this->violationMessages($this->candidate([
            'model' => $this->ref($this->local, 'llama-big'),
            'mcpTools' => [['connection' => 'tools', 'name' => 'search']],
            'artifactTools' => true,
        ]));

        self::assertSame(['candidate/model'], array_keys($messages));
        self::assertStringContainsString('or remove mcpTools and artifactTools.', $messages['candidate/model']);
    }

    public function testModelWithToolsIsPublishedWithArtifactTools(): void
    {
        $this->providerModel($this->local, 'llama-big', ['completion', 'tools']);

        $configuration = $this->validator()->validateForPublish($this->candidate([
            'model' => $this->ref($this->local, 'llama-big'),
            'artifactTools' => true,
        ]));

        self::assertTrue($configuration->artifactTools);
    }

    /**
     * An MCP tool named like an artifact tool would reach the provider twice
     * under one name in a conversation turn; with `artifactTools` on that is
     * a violation at the entry's name, without it the tool publishes.
     */
    public function testMcpToolNamedLikeAnArtifactToolCollides(): void
    {
        $tools = ['mcpTools' => [['connection' => 'tools', 'name' => 'search'], ['connection' => 'tools', 'name' => 'read_file']]];

        $messages = $this->violationMessages($this->candidate($tools + ['artifactTools' => true]));

        self::assertSame(['candidate/mcpTools/1/name'], array_keys($messages));
        self::assertSame(
            'MCP tool "read_file" collides with the artifact tool of the same name, which artifactTools adds to every turn of a conversation; a tool name may occur only once, because a provider cannot tell two tools of the same name apart. Remove the entry or turn artifactTools off.',
            $messages['candidate/mcpTools/1/name'],
        );

        $configuration = $this->validator()->validateForPublish($this->candidate($tools));
        self::assertSame(['search', 'read_file'], array_column($configuration->mcpTools, 'name'));
        self::assertFalse($configuration->artifactTools);
    }

    public function testInvalidResponseSchema(): void
    {
        self::assertSame(['candidate/responseSchema'], $this->violationPaths($this->candidate(['responseSchema' => ['type' => 'nonsense']])));
    }

    public function testNestedInvalidResponseSchema(): void
    {
        $paths = $this->violationPaths($this->candidate(['responseSchema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'nonsense']]]]));

        self::assertSame(['candidate/responseSchema'], $paths);
    }

    public function testUnresolvableReferenceInResponseSchema(): void
    {
        self::assertSame(['candidate/responseSchema'], $this->violationPaths($this->candidate(['responseSchema' => ['$ref' => '#/$defs/missing']])));
    }

    public function testUnsupportedSchemaDraft(): void
    {
        self::assertSame(['candidate/responseSchema'], $this->violationPaths($this->candidate(['responseSchema' => ['$schema' => 'https://example.com/schema', 'type' => 'object']])));
    }

    public function testStartSchemaMustBeAnObjectSchema(): void
    {
        self::assertSame(['candidate/startSchema'], $this->violationPaths($this->candidate(['startSchema' => ['type' => 'array']])));
    }

    /**
     * @return iterable<string, array{0: string, 1: mixed}>
     */
    public static function outOfRange(): iterable
    {
        yield 'maxToolCalls 0' => ['maxToolCalls', 0];
        yield 'maxToolCalls as string' => ['maxToolCalls', '3'];
        yield 'maxUserTurns 0' => ['maxUserTurns', 0];
        yield 'retrievalDepth 0' => ['retrievalDepth', 0];
        yield 'contextMaxChunks 0' => ['contextMaxChunks', 0];
        yield 'contextMaxChars 0' => ['contextMaxChars', 0];
        yield 'contextExpansionMaxChars negative' => ['contextExpansionMaxChars', -1];
        yield 'answerabilityVotes even' => ['answerabilityVotes', 2];
        yield 'answerabilityVotes above 7' => ['answerabilityVotes', 9];
        yield 'answerabilityVotes 0' => ['answerabilityVotes', 0];
        yield 'empty languages' => ['languages', []];
    }

    #[DataProvider('outOfRange')]
    public function testValueRanges(string $field, mixed $value): void
    {
        self::assertSame(['candidate/'.$field], $this->violationPaths($this->candidate([$field => $value])));
    }

    /**
     * @return iterable<string, array{0: string, 1: mixed}>
     */
    public static function modelOptionsOutOfRange(): iterable
    {
        yield 'temperature 3' => ['temperature', 3];
        yield 'temperature below 0' => ['temperature', -0.1];
        yield 'topP above 1' => ['topP', 1.5];
        yield 'maxTokens 0' => ['maxTokens', 0];
        yield 'numCtx as string' => ['numCtx', '4096'];
        yield 'unknown effort' => ['effort', 'huge'];
    }

    #[DataProvider('modelOptionsOutOfRange')]
    public function testModelOptionRanges(string $option, mixed $value): void
    {
        self::assertSame(['candidate/modelOptions/'.$option], $this->violationPaths($this->candidate(['modelOptions' => [$option => $value]])));
    }

    public function testTopLevelTemperatureIsAnUnknownKey(): void
    {
        self::assertSame(['candidate'], $this->violationPaths($this->candidate(['temperature' => 0.2])));
    }

    public function testModelOptionsAreFrozen(): void
    {
        $anthropic = $this->validator()->validateForPublish($this->candidate(['modelOptions' => ['effort' => 'high', 'maxTokens' => 2048, 'temperature' => 0.2]]));
        self::assertSame(['effort' => 'high', 'maxTokens' => 2048, 'temperature' => 0.2], $anthropic->modelOptions);

        $ollama = $this->validator()->validateForPublish($this->candidate(['model' => $this->ref($this->local, 'llama-big'), 'modelOptions' => ['numCtx' => 32768, 'think' => true, 'seed' => 7]]));
        self::assertSame(['numCtx' => 32768, 'think' => true, 'seed' => 7], $ollama->modelOptions);
    }

    public function testOptionsOfTheOtherProviderAreRejected(): void
    {
        $messages = $this->violationMessages($this->candidate(['model' => $this->ref($this->local, 'llama-big'), 'modelOptions' => ['effort' => 'high', 'temperature' => 0.3]]));
        self::assertSame(['candidate/modelOptions/effort'], array_keys($messages));
        self::assertSame('Model "llama-big" of connection "local" does not take the option "effort" (it takes: temperature, topP, topK, maxTokens, repeatPenalty, seed, numCtx, think).', $messages['candidate/modelOptions/effort']);

        self::assertSame(['candidate/modelOptions/numCtx', 'candidate/modelOptions/think'], $this->violationPaths($this->candidate(['modelOptions' => ['numCtx' => 4096, 'think' => true]])));
    }

    public function testSamplingIsRejectedForAClaudeModelWithoutSampling(): void
    {
        $messages = $this->violationMessages($this->candidate(['model' => $this->ref($this->claude, 'claude-opus-5-5'), 'modelOptions' => ['temperature' => 0.2, 'topK' => 5, 'effort' => 'max']]));

        self::assertSame(['candidate/modelOptions/temperature', 'candidate/modelOptions/topK'], array_keys($messages));
        self::assertStringContainsString('(it takes: maxTokens, effort, thinkingDisplay)', $messages['candidate/modelOptions/temperature']);
    }

    public function testCatalogueRowNarrowsOllamaOptions(): void
    {
        $this->providerModel($this->local, 'llama-big', ['completion', 'tools'])->setMaxContextLength(8192);

        $messages = $this->violationMessages($this->candidate(['model' => $this->ref($this->local, 'llama-big'), 'modelOptions' => ['numCtx' => 16384, 'think' => true]]));
        self::assertSame(['candidate/modelOptions/numCtx', 'candidate/modelOptions/think'], array_keys($messages));
        self::assertStringStartsWith('Model "llama-big" of connection "local": ', $messages['candidate/modelOptions/numCtx']);

        $this->providerModels = [];
        $this->providerModel($this->local, 'llama-big', ['completion', 'thinking'])->setMaxContextLength(8192);
        self::assertSame(['numCtx' => 8192, 'think' => false], $this->validator()->validateForPublish($this->candidate(['model' => $this->ref($this->local, 'llama-big'), 'modelOptions' => ['numCtx' => 8192, 'think' => false]]))->modelOptions);
    }

    public function testCatalogueRowLimitsAnthropicMaxTokensAndEffortLevels(): void
    {
        $this->providerModel($this->claude, 'claude-default', ['completion', 'thinking', 'tools'])
            ->setMaxOutputTokens(4096)
            ->setSupportsSampling(false)
            ->setProviderData(['capabilities' => [
                'effort' => ['supported' => true, 'low' => ['supported' => true], 'high' => ['supported' => true], 'max' => ['supported' => false]],
                'thinking' => ['supported' => true, 'types' => ['adaptive' => ['supported' => true]]],
            ]]);

        self::assertSame(
            ['candidate/modelOptions/maxTokens', 'candidate/modelOptions/effort', 'candidate/modelOptions/temperature'],
            $this->violationPaths($this->candidate(['modelOptions' => ['maxTokens' => 8000, 'effort' => 'max', 'temperature' => 0.5]])),
        );
        self::assertSame(
            ['maxTokens' => 4096, 'effort' => 'high', 'thinkingDisplay' => 'summarized'],
            $this->validator()->validateForPublish($this->candidate(['modelOptions' => ['maxTokens' => 4096, 'effort' => 'high', 'thinkingDisplay' => 'summarized']]))->modelOptions,
        );
    }

    public function testStaleRowIsIgnoredForTheOptions(): void
    {
        $this->providerModel($this->local, 'llama-big', ['completion'])->setMaxContextLength(1024)->setEndpointFingerprint(str_repeat('0', 64));

        self::assertSame(['numCtx' => 4096, 'think' => true], $this->validator()->validateForPublish($this->candidate(['model' => $this->ref($this->local, 'llama-big'), 'modelOptions' => ['numCtx' => 4096, 'think' => true]]))->modelOptions);
    }

    public function testOptionsCheckDoesNotRunAfterTheLiveCheckFailed(): void
    {
        self::assertSame(['candidate/model'], $this->violationPaths($this->candidate(['model' => $this->ref($this->local, 'llama-gone'), 'modelOptions' => ['effort' => 'high']])));
    }

    public function testUnknownKeyIsReportedOnTheCandidate(): void
    {
        self::assertSame(['candidate'], $this->violationPaths($this->candidate(['tools' => []])));
    }

    public function testAllViolationsAreCollected(): void
    {
        $paths = $this->violationPaths($this->candidate([
            'model' => $this->ref($this->claude, 'claude-unknown'),
            'collections' => ['missing'],
            'mcpTools' => [['connection' => 'tools', 'name' => 'nothing']],
            'startSchema' => ['type' => 'array'],
            'instructions' => [],
        ]));

        self::assertSame([
            'candidate/model',
            'candidate/collections/0',
            'candidate/mcpTools/0/name',
            'candidate/startSchema',
            'candidate/instructions',
        ], $paths);
    }

    public function testEmptyRulesAndTemplatesArePublished(): void
    {
        $configuration = $this->validator()->validateForPublish($this->candidate(['rules' => [], 'templates' => []]));

        self::assertSame([], $configuration->rules);
        self::assertSame([], $configuration->templates);
    }

    public function testRulesAndTemplatesAreFrozen(): void
    {
        $configuration = $this->validator()->validateForPublish($this->ruleCandidate());

        self::assertSame([
            ['name' => 'prices', 'match' => ['type' => 'keywords', 'keywords' => ['Preis'], 'wholeWord' => true], 'outcome' => 'refuse', 'template' => 'refusal'],
            ['name' => null, 'match' => ['type' => 'pattern', 'pattern' => '^(hallo|hi)/?$', 'flags' => 'i'], 'outcome' => 'answer', 'template' => 'greeting'],
            ['name' => null, 'match' => ['type' => 'language', 'languages' => ['de']], 'outcome' => 'continue', 'template' => null],
        ], $configuration->rules);
        self::assertSame($this->ruleCandidate()['templates'], $configuration->templates);
    }

    /**
     * Every rejection path of rules and templates, each
     * on its own.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function ruleViolations(): iterable
    {
        yield 'pattern does not compile' => [['rules' => [self::rule(['type' => 'pattern', 'pattern' => '(ab'], 'refuse', 'refusal')]], 'candidate/rules/0/match/pattern'];
        yield 'disallowed flag' => [['rules' => [self::rule(['type' => 'pattern', 'pattern' => 'ab', 'flags' => 'g'], 'refuse', 'refusal')]], 'candidate/rules/0/match/flags'];
        yield 'unknown match type' => [['rules' => [self::rule(['type' => 'regex', 'pattern' => 'ab'], 'refuse', 'refusal')]], 'candidate/rules/0/match/type'];
        yield 'unknown outcome' => [['rules' => [self::rule(['type' => 'keywords', 'keywords' => ['a']], 'block', 'refusal')]], 'candidate/rules/0/outcome'];
        yield 'unknown template' => [['rules' => [self::rule(['type' => 'keywords', 'keywords' => ['a']], 'refuse', 'missing')]], 'candidate/rules/0/template'];
        yield 'answer without template' => [['rules' => [self::rule(['type' => 'keywords', 'keywords' => ['a']], 'answer', null)]], 'candidate/rules/0/template'];
        yield 'template on continue' => [['rules' => [self::rule(['type' => 'keywords', 'keywords' => ['a']], 'continue', 'refusal')]], 'candidate/rules/0/template'];
        yield 'reserved template on a rule' => [['rules' => [self::rule(['type' => 'keywords', 'keywords' => ['a']], 'refuse', 'frame')], 'templates' => ['frame' => ['de' => '{{answer}}']]], 'candidate/rules/0/template'];
        yield 'rule language outside languages' => [['rules' => [self::rule(['type' => 'language', 'languages' => ['de', 'fr']], 'refuse', 'refusal')]], 'candidate/rules/0/match/languages/1'];
        yield 'template without default language' => [['templates' => ['refusal' => ['en' => 'No.']], 'languages' => ['de', 'en']], 'candidate/templates/refusal'];
        yield 'template language outside languages' => [['templates' => ['refusal' => ['de' => 'Nein.', 'fr' => 'Non.']]], 'candidate/templates/refusal/fr'];
        yield 'unknown placeholder' => [['templates' => ['refusal' => ['de' => 'Nein, {{customer}}.']]], 'candidate/templates/refusal/de'];
        yield 'slot placeholder in a rule template' => [['templates' => ['refusal' => ['de' => '{{answer}}']]], 'candidate/templates/refusal/de'];
        yield 'placeholder of another slot' => [['templates' => ['frame' => ['de' => '{{answer}} {{toolCalls}}']]], 'candidate/templates/frame/de'];
        yield 'rule placeholder in a slot' => [['templates' => ['forcedCloseFallback' => ['de' => '{{rule}}']]], 'candidate/templates/forcedCloseFallback/de'];
        yield 'invalid template name' => [['templates' => ['bad-name' => ['de' => 'x']]], 'candidate/templates'];
        yield 'frame without answer' => [['templates' => ['frame' => ['de' => 'Hinweis: ']]], 'candidate/templates/frame/de'];
        yield 'frame with two answers' => [['templates' => ['frame' => ['de' => '{{answer}} und {{ answer }}']]], 'candidate/templates/frame/de'];
        yield 'injectionRefusal on a rule' => [['rules' => [self::rule(['type' => 'keywords', 'keywords' => ['a']], 'refuse', 'injectionRefusal')], 'templates' => ['injectionRefusal' => ['de' => 'Nein.']]], 'candidate/rules/0/template'];
        yield 'unanswerableRefusal on a rule' => [['rules' => [self::rule(['type' => 'keywords', 'keywords' => ['a']], 'answer', 'unanswerableRefusal')], 'templates' => ['unanswerableRefusal' => ['de' => 'Weiß ich nicht.']]], 'candidate/rules/0/template'];
        yield 'rule placeholder in a refusal slot' => [['templates' => ['injectionRefusal' => ['de' => '{{rule}}']]], 'candidate/templates/injectionRefusal/de'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('ruleViolations')]
    public function testRuleAndTemplateViolation(array $overrides, string $path): void
    {
        $overrides += ['templates' => ['refusal' => ['de' => 'Nein.']]];

        self::assertSame([$path], $this->violationPaths($this->candidate($overrides)));
    }

    public function testEmptyLanguageMapOfATemplateIsReportedAsATypeError(): void
    {
        // `{"refusal": {}}` decodes to ['refusal' => []]: a list, so the
        // structure check reports a type error rather than the missing
        // default language. Accepted.
        $messages = $this->violationMessages($this->candidate(['templates' => ['refusal' => []]]));

        self::assertSame(['candidate/templates/refusal'], array_keys($messages));
    }

    public function testMessagesNameThePlaceholderAndThePcreError(): void
    {
        $messages = $this->violationMessages($this->candidate([
            'rules' => [self::rule(['type' => 'pattern', 'pattern' => '(ab'], 'refuse', 'refusal')],
            'templates' => ['refusal' => ['de' => 'Nein, {{customer}}.']],
        ]));

        self::assertStringContainsString('Internal error', $messages['candidate/rules/0/match/pattern']);
        self::assertStringContainsString('missing closing parenthesis', $messages['candidate/rules/0/match/pattern']);
        self::assertStringContainsString('{{customer}}', $messages['candidate/templates/refusal/de']);
    }

    public function testCommonAndContextPlaceholdersAreAllowed(): void
    {
        $configuration = $this->validator()->validateForPublish($this->candidate([
            'startSchema' => ['type' => 'object', 'properties' => ['customer' => ['type' => 'string']]],
            'templates' => [
                'refusal' => ['de' => '{{date}} {{language}} {{agentName}} {{customer}} {{rule}} {{outcome}}'],
                'frame' => ['de' => '{{customer}}: {{answer}}'],
                'limitReason' => ['de' => '{{toolCalls}}/{{maxToolCalls}}'],
                'turnLimitReason' => ['de' => '{{turn}}/{{maxUserTurns}}'],
                'forcedCloseFallback' => ['de' => 'Sorry, {{agentName}}.'],
            ],
        ]));

        self::assertCount(5, $configuration->templates);
    }

    public function testAllRuleAndTemplateViolationsAreCollected(): void
    {
        $paths = $this->violationPaths($this->candidate([
            'languages' => ['de', 'en'],
            'rules' => [
                self::rule(['type' => 'pattern', 'pattern' => '[a-'], 'refuse', 'refusal'),
                self::rule(['type' => 'language', 'languages' => ['fr']], 'answer', 'missing'),
                self::rule(['type' => 'keywords', 'keywords' => ['x']], 'continue', 'refusal'),
                self::rule(['type' => 'keywords', 'keywords' => ['y']], 'refuse', 'limitReason'),
            ],
            'templates' => [
                'refusal' => ['en' => 'No, {{customer}}.', 'fr' => 'Non.'],
            ],
        ]));

        self::assertSame([
            'candidate/rules/0/match/pattern',
            'candidate/rules/1/match/languages/0',
            'candidate/rules/1/template',
            'candidate/rules/2/template',
            'candidate/rules/3/template',
            'candidate/templates/refusal',
            'candidate/templates/refusal/en',
            'candidate/templates/refusal/fr',
        ], $paths);
    }

    /**
     * `frame` needs `{{answer}}` exactly once per language
     * text, `{{answer}}` is the frame's alone and `frame` cannot use another
     * slot's placeholders; all collected in the one 422.
     */
    public function testFrameAnswerViolationsAreCollectedWithTheOthers(): void
    {
        $messages = $this->violationMessages($this->candidate([
            'languages' => ['de', 'en'],
            'templates' => [
                'frame' => ['de' => '{{answer}} ({{toolCalls}})', 'en' => 'Note: {{answer}}{{answer}}'],
                'refusal' => ['de' => 'Nein: {{ answer }}'],
                'limitReason' => ['de' => 'Ende.'],
            ],
        ]));

        self::assertSame(['candidate/templates/frame/de', 'candidate/templates/frame/en', 'candidate/templates/refusal/de'], array_keys($messages));
        self::assertStringContainsString('{{toolCalls}}', $messages['candidate/templates/frame/de']);
        self::assertSame('The template "frame" must contain the placeholder "{{answer}}" exactly once (found 2).', $messages['candidate/templates/frame/en']);
        self::assertStringContainsString('{{answer}}', $messages['candidate/templates/refusal/de']);
    }

    public function testFrameWithoutAnswerIsReportedPerLanguage(): void
    {
        $paths = $this->violationPaths($this->candidate([
            'languages' => ['de', 'en'],
            'templates' => ['frame' => ['de' => '{{answer}}', 'en' => 'No answer here.']],
        ]));

        self::assertSame(['candidate/templates/frame/en'], $paths);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function gateSlotViolations(): iterable
    {
        yield 'guard without slot' => [['injectionGuard' => true], ['candidate/templates/injectionRefusal']];
        yield 'answerability without slot' => [['answerabilityCheck' => true], ['candidate/templates/unanswerableRefusal']];
        yield 'both without slots' => [['injectionGuard' => true, 'answerabilityCheck' => true], ['candidate/templates/injectionRefusal', 'candidate/templates/unanswerableRefusal']];
        yield 'guard with an empty default text' => [['injectionGuard' => true, 'templates' => ['injectionRefusal' => ['de' => ' ']]], ['candidate/templates/injectionRefusal']];
        yield 'guard with another language only' => [['injectionGuard' => true, 'languages' => ['de', 'en'], 'templates' => ['injectionRefusal' => ['en' => 'No.']]], ['candidate/templates/injectionRefusal']];
        yield 'answerability with the other slot only' => [['answerabilityCheck' => true, 'templates' => ['injectionRefusal' => ['de' => 'Nein.']]], ['candidate/templates/unanswerableRefusal']];
    }

    /**
     * A gate that is on needs its refusal slot with a default-language
     * text; the path is reported once, also when the template check finds
     * the same gap.
     *
     * @param array<string, mixed> $overrides
     * @param list<string>         $paths
     */
    #[DataProvider('gateSlotViolations')]
    public function testGateSlotViolation(array $overrides, array $paths): void
    {
        self::assertSame($paths, $this->violationPaths($this->candidate($overrides)));
    }

    public function testGatesWithTheirSlotsArePublishedAndFrozen(): void
    {
        $configuration = $this->validator()->validateForPublish($this->candidate([
            'languages' => ['de', 'en'],
            'instructions' => ['de' => 'Du bist hilfreich.'],
            'injectionGuard' => true,
            'answerabilityCheck' => true,
            'answerabilityVotes' => 3,
            'followUps' => true,
            'templates' => [
                'injectionRefusal' => ['de' => 'Dazu kann ich nichts sagen, {{agentName}}.', 'en' => 'I cannot help with that.'],
                'unanswerableRefusal' => ['de' => 'Das steht nicht in meinen Quellen.'],
            ],
        ]));

        self::assertTrue($configuration->injectionGuard);
        self::assertTrue($configuration->answerabilityCheck);
        self::assertSame(3, $configuration->answerabilityVotes);
        self::assertTrue($configuration->followUps);
    }

    public function testGatesOffNeedNoSlotsAndAreFrozenOff(): void
    {
        $configuration = $this->validator()->validateForPublish($this->candidate());

        self::assertFalse($configuration->injectionGuard);
        self::assertFalse($configuration->answerabilityCheck);
        self::assertSame(1, $configuration->answerabilityVotes);
        self::assertFalse($configuration->followUps);
    }

    public function testValidationExceptionIsUnprocessable(): void
    {
        try {
            $this->validator()->validateForPublish($this->candidate(['maxToolCalls' => 0]));
            self::fail('No ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
        }
    }

    /**
     * @param array<string, mixed> $candidate
     *
     * @return list<string>
     */
    private function violationPaths(array $candidate): array
    {
        try {
            $this->validator()->validateForPublish($candidate);
        } catch (ValidationException $e) {
            $paths = [];
            foreach ($e->getConstraintViolationList() as $violation) {
                $paths[] = $violation->getPropertyPath();
            }

            return $paths;
        }

        self::fail('The candidate was accepted.');
    }

    /**
     * @param array<string, mixed> $candidate
     *
     * @return array<string, string> property path => message
     */
    private function violationMessages(array $candidate): array
    {
        try {
            $this->validator()->validateForPublish($candidate);
        } catch (ValidationException $e) {
            $messages = [];
            foreach ($e->getConstraintViolationList() as $violation) {
                $messages[$violation->getPropertyPath()] = (string) $violation->getMessage();
            }

            return $messages;
        }

        self::fail('The candidate was accepted.');
    }

    /**
     * @param array<string, mixed> $match
     *
     * @return array<string, mixed>
     */
    private static function rule(array $match, string $outcome, ?string $template): array
    {
        return ['match' => $match, 'outcome' => $outcome] + (null === $template ? [] : ['template' => $template]);
    }

    /**
     * A valid candidate with one rule of each match type.
     *
     * @return array<string, mixed>
     */
    private function ruleCandidate(): array
    {
        return $this->candidate([
            'languages' => ['de', 'en'],
            'instructions' => ['de' => 'Du bist hilfreich.', 'en' => 'You are helpful.'],
            'rules' => [
                ['name' => 'prices'] + self::rule(['type' => 'keywords', 'keywords' => ['Preis']], 'refuse', 'refusal'),
                self::rule(['type' => 'pattern', 'pattern' => '^(hallo|hi)/?$', 'flags' => 'i'], 'answer', 'greeting'),
                self::rule(['type' => 'language', 'languages' => ['de']], 'continue', null),
            ],
            'templates' => [
                'refusal' => ['de' => 'Dazu sage ich nichts ({{rule}}, {{outcome}}).', 'en' => 'No comment ({{rule}}).'],
                'greeting' => ['de' => 'Hallo, hier ist {{agentName}}.'],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function candidate(array $overrides = []): array
    {
        return $overrides + [
            'instructions' => ['de' => 'Du bist hilfreich.'],
            'model' => $this->ref($this->claude, 'claude-default'),
            'languages' => ['de'],
            'defaultLanguage' => 'de',
        ];
    }

    private function validator(): AgentConfigurationValidator
    {
        $entityManager = $this->entityManager();
        $uuidResolver = $this->createStub(UuidResolver::class);
        $uuidResolver->method('findByPartialUuid')->willReturnCallback(
            fn (string $class, string $id): ?object => ProviderModel::class === $class
                ? array_find($this->providerModels, static fn (ProviderModel $model): bool => str_starts_with($model->getId()->toRfc4122(), strtolower($id)))
                : $this->connectionById($id),
        );
        $iriConverter = $this->createStub(IriConverterInterface::class);
        $iriConverter->method('getResourceFromIri')->willReturnCallback(
            fn (string $iri): object => array_find($this->providerModels, static fn (ProviderModel $model): bool => $model->iri() === $iri)
                ?? throw new ItemNotFoundException(\sprintf('No resource for "%s".', $iri)),
        );
        $connectionResolver = new McpConnectionResolver($entityManager, $this->createStub(IriConverterInterface::class), $uuidResolver);

        return new AgentConfigurationValidator(
            new ProviderModelResolver($iriConverter, $uuidResolver),
            new ModelAvailabilityCheck($this->lister),
            new ConversationReferences($entityManager, $connectionResolver),
            $connectionResolver,
            $entityManager,
            $this->withToolModelCheck ? new ToolModelCheck(new ModelCapabilityCheck($entityManager)) : null,
        );
    }

    /**
     * The UUID of the catalogue entry of $connection for $name, as a
     * candidate references it: the entry {@see ModelCapabilityCheck::find()}
     * finds (exact name first, else with or without `:latest`), else a new
     * one with $capabilities. An existing entry is returned unchanged.
     *
     * @param list<string>|null $capabilities
     */
    private function ref(ApiConfiguration $connection, string $name, ?array $capabilities = null): string
    {
        $model = new ModelCapabilityCheck($this->entityManager())->find($connection, $name)
            ?? $this->providerModel($connection, $name, $capabilities);

        return $model->getId()->toRfc4122();
    }

    /**
     * A catalogue row described from the connection's current endpoint.
     *
     * @param list<string>|null $capabilities
     */
    private function providerModel(ApiConfiguration $connection, string $name, ?array $capabilities = null): ProviderModel
    {
        return $this->providerModels[] = new ProviderModel($connection, $name)->setCapabilities($capabilities);
    }

    private function entityManager(): EntityManagerInterface
    {
        $collections = $this->createStub(EntityRepository::class);
        $collections->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?Collection => $criteria['name'] === $this->docs->getName() ? $this->docs : null,
        );

        $connections = $this->createStub(EntityRepository::class);
        $connections->method('findBy')->willReturnCallback(
            fn (array $criteria): array => array_values(array_filter(
                $this->connections(),
                static fn (ApiConfiguration $c): bool => \in_array($c->getType(), (array) $criteria['type'], true) && $c->getName() === $criteria['name'],
            )),
        );

        $tools = $this->createStub(EntityRepository::class);
        $tools->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?McpTool => array_find(
                $this->catalogue,
                static fn (McpTool $tool): bool => $tool->getApiConfiguration() === $criteria['apiConfiguration'] && $tool->getName() === $criteria['name'],
            ),
        );

        $providerModels = $this->createStub(EntityRepository::class);
        $providerModels->method('findBy')->willReturnCallback(
            fn (array $criteria): array => array_values(array_filter(
                $this->providerModels,
                static fn (ProviderModel $model): bool => $model->getApiConfiguration() === $criteria['apiConfiguration'],
            )),
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(static fn (string $class): EntityRepository => match ($class) {
            Collection::class => $collections,
            ApiConfiguration::class => $connections,
            McpTool::class => $tools,
            ProviderModel::class => $providerModels,
        });
        $entityManager->method('find')->willReturnCallback(
            fn (string $class, Uuid $id): ?Collection => Collection::class === $class && $id->equals($this->docs->getId()) ? $this->docs : null,
        );

        return $entityManager;
    }

    /**
     * @return list<ApiConfiguration>
     */
    private function connections(): array
    {
        return [$this->tools, $this->otherTools, $this->inactive, $this->rest, $this->claude, $this->local, $this->offline];
    }

    private function connectionById(string $id): ?ApiConfiguration
    {
        return array_find($this->connections(), static fn (ApiConfiguration $c): bool => str_starts_with($c->getId()->toRfc4122(), strtolower($id)));
    }

    private static function connection(string $name, string $type, bool $active): ApiConfiguration
    {
        return new ApiConfiguration()
            ->setName($name)
            ->setConfigJson(['type' => $type])
            ->setActive($active);
    }
}
// - revised 2026-10-16
