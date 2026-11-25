<?php

// file generated with AI assistance: Claude Code - 2026-10-02 22:05:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Integration\Agent;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Testing\FakeModelLister;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use Dmstr\NeuronAiBundle\Tests\Kernel\TestDatabase;
use Dmstr\NeuronAiBundle\Tests\Support\AgentFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Publish validation through the API: every invalid value and every dangling
 * reference answers 422 with the field as `propertyPath`
 * (`candidate/<field>`, `candidate/<list>/<index>/<key>`), creates no version and
 * leaves `currentVersion` unset.
 *
 * Reference cases (the model reference with its connection, collections,
 * MCP connections and tools, schemas, languages) pass the structure check
 * of PATCH and are created through the API. Range and structure cases
 * (`modelOptions`, `maxToolCalls`, `maxUserTurns`, `retrievalDepth`, an
 * empty `model`, the removed keys `provider` and `connection`) are already rejected by
 * POST/PATCH, so the
 * candidate is written directly through the entity manager to prove that the
 * publish checks them again; the PATCH rejection of the same candidates is
 * asserted as well.
 *
 * The live model check runs against the test environment's
 * `Testing\FakeModelLister` (every model available unless a test scripts
 * it on the container that serves the publish request). Rules and
 * templates: their structure is a
 * PATCH check, their references, languages, placeholders and pattern
 * compilation are publish checks reported together in one 422.
 * Candidates reference `Refs\ProviderModel` entries seeded directly
 * through the entity manager ({@see AgentFixtures::model()}); the tools
 * capability of the model reads their capabilities (described from the
 * connection's current endpoint unless a test changes the fingerprint). The allow-list
 * `tool_models` lives in the configJson of an own provider connection.
 * Uses the fixtures of {@see AgentFixtures}. Needs the
 * migrated test database; runs in CI.
 */
#[Group('database')]
final class AgentPublishValidationTest extends ApiTestCase
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function referenceCases(): iterable
    {
        yield 'missing instructions of the default language' => ['missing-instructions', 'candidate/instructions'];
        yield 'default language outside languages' => ['default-language', 'candidate/defaultLanguage'];
        yield 'instructions of a foreign language' => ['foreign-instructions', 'candidate/instructions/fr'];
        yield 'unknown collection' => ['unknown-collection', 'candidate/collections/1'];
        yield 'inactive connection' => ['inactive-connection', 'candidate/mcpTools/0/connection'];
        yield 'non-mcp connection' => ['non-mcp-connection', 'candidate/mcpTools/0/connection'];
        yield 'unknown connection' => ['unknown-connection', 'candidate/mcpTools/0/connection'];
        yield 'unknown tool name' => ['unknown-tool', 'candidate/mcpTools/0/name'];
        yield 'tool name from two connections' => ['duplicate-tool', 'candidate/mcpTools/1/name'];
        yield 'MCP tool named like an artifact tool' => ['artifact-tool-name', 'candidate/mcpTools/0/name'];
        yield 'missing model' => ['missing-model', 'candidate/model'];
        yield 'model name instead of a reference' => ['model-name', 'candidate/model'];
        yield 'unknown model entry' => ['unknown-model', 'candidate/model'];
        yield 'model of an inactive provider connection' => ['inactive-provider-connection', 'candidate/model'];
        yield 'model no longer offered' => ['unavailable-model', 'candidate/model'];
        yield 'invalid response schema' => ['response-schema', 'candidate/responseSchema'];
        yield 'start schema of type array' => ['start-schema', 'candidate/startSchema'];
    }

    #[DataProvider('referenceCases')]
    public function testDanglingReferenceOrInvalidValueIsRejectedOnPublish(string $case, string $path): void
    {
        $id = AgentFixtures::createAgent($case, $this->candidate($case));

        $response = AgentFixtures::request('POST', AgentFixtures::AGENTS.'/'.$id.'/publish', AgentFixtures::ADMIN);

        self::assertResponseStatusCodeSame(422);
        self::assertContains($path, AgentFixtures::violationPaths($response), $response->getContent(false));
        $this->assertNothingPublished($id);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function structureCases(): iterable
    {
        yield 'removed key provider' => [['provider' => 'anthropic'], 'candidate'];
        yield 'removed key connection' => [['connection' => AgentFixtures::ANTHROPIC_CONNECTION], 'candidate/connection'];
        yield 'empty model' => [['model' => ''], 'candidate/model'];
        yield 'temperature 3' => [['modelOptions' => ['temperature' => 3]], 'candidate/modelOptions/temperature'];
        yield 'removed key temperature' => [['temperature' => 0.2], 'candidate'];
        yield 'maxToolCalls 0' => [['maxToolCalls' => 0], 'candidate/maxToolCalls'];
        yield 'maxUserTurns 0' => [['maxUserTurns' => 0], 'candidate/maxUserTurns'];
        yield 'retrievalDepth 0' => [['retrievalDepth' => 0], 'candidate/retrievalDepth'];
        yield 'string maxToolCalls' => [['maxToolCalls' => '3'], 'candidate/maxToolCalls'];
        yield 'string artifactTools' => [['artifactTools' => 'true'], 'candidate/artifactTools'];
        yield 'unknown key' => [['chatModel' => 'x'], 'candidate'];
        yield 'unknown rule match type' => [['rules' => [['match' => ['type' => 'regex', 'pattern' => 'a'], 'outcome' => 'refuse', 'template' => 'refusal']]], 'candidate/rules/0/match/type'];
        yield 'unknown rule outcome' => [['rules' => [['match' => ['type' => 'keywords', 'keywords' => ['a']], 'outcome' => 'block', 'template' => 'refusal']]], 'candidate/rules/0/outcome'];
        yield 'disallowed pattern flag' => [['rules' => [['match' => ['type' => 'pattern', 'pattern' => 'a', 'flags' => 'g'], 'outcome' => 'refuse', 'template' => 'refusal']]], 'candidate/rules/0/match/flags'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('structureCases')]
    public function testOutOfRangeValueIsRejectedOnPublishAndOnPatch(array $overrides, string $path): void
    {
        $id = AgentFixtures::storeAgent('structure', AgentFixtures::validCandidate($overrides));

        $response = AgentFixtures::request('POST', AgentFixtures::AGENTS.'/'.$id.'/publish', AgentFixtures::ADMIN);
        self::assertResponseStatusCodeSame(422);
        self::assertContains($path, AgentFixtures::violationPaths($response), $response->getContent(false));
        $this->assertNothingPublished($id);

        $response = AgentFixtures::request('PATCH', AgentFixtures::AGENTS.'/'.$id, AgentFixtures::ADMIN, ['candidate' => AgentFixtures::validCandidate($overrides)]);
        self::assertResponseStatusCodeSame(422);
        self::assertContains($path, AgentFixtures::violationPaths($response), $response->getContent(false));
    }

    public function testAllViolationsOfOneCandidateAreReportedTogether(): void
    {
        $id = AgentFixtures::createAgent('several', AgentFixtures::validCandidate([
            'collections' => [AgentFixtures::PREFIX.'missing'],
            'model' => AgentFixtures::PREFIX.'nowhere',
            'defaultLanguage' => 'en',
        ]));

        $response = AgentFixtures::request('POST', AgentFixtures::AGENTS.'/'.$id.'/publish', AgentFixtures::ADMIN);

        self::assertResponseStatusCodeSame(422);
        $paths = AgentFixtures::violationPaths($response);
        foreach (['candidate/model', 'candidate/collections/0', 'candidate/defaultLanguage', 'candidate/instructions'] as $expected) {
            self::assertContains($expected, $paths);
        }
        $this->assertNothingPublished($id);
    }

    public function testPublishStoresTheModelUuidForAnyReference(): void
    {
        $id = AgentFixtures::model('ollama')->getId()->toRfc4122();

        foreach (['uuid' => $id, 'partial' => substr($id, 0, 8), 'iri' => ProviderModel::COLLECTION_PATH.'/'.$id] as $kind => $reference) {
            $agent = AgentFixtures::createAgent('ref-'.$kind, AgentFixtures::validCandidate(['model' => $reference]));

            $version = AgentFixtures::publish($agent);

            self::assertResponseStatusCodeSame(201);
            self::assertSame($id, $version['configuration']['model'], $kind);
            self::assertArrayNotHasKey('connection', $version['configuration'], $kind);
            self::assertArrayNotHasKey('provider', $version['configuration'], $kind);
        }
    }

    public function testModelNotOfferedByTheConnectionIsRejectedAtTheModel(): void
    {
        $id = AgentFixtures::createAgent('not-offered', AgentFixtures::validCandidate(['model' => AgentFixtures::model('anthropic', 'claude-retired')->iri()]));

        $response = $this->publishWith($id, static fn (FakeModelLister $lister): FakeModelLister => $lister->offer([FakeProviderFactory::ANTHROPIC_MODEL]));

        self::assertResponseStatusCodeSame(422);
        $violations = array_column($response->toArray(false)['violations'] ?? [], 'message', 'propertyPath');
        self::assertSame(['candidate/model'], array_keys($violations));
        self::assertSame(\sprintf('Model "claude-retired" is not offered by connection "%s" (offered: %s).', AgentFixtures::ANTHROPIC_CONNECTION, FakeProviderFactory::ANTHROPIC_MODEL), $violations['candidate/model']);
        $this->assertNothingPublished($id);
    }

    public function testUnreachableProviderIsRejectedAtTheModelWithoutTheKey(): void
    {
        $id = AgentFixtures::createAgent('unreachable', AgentFixtures::validCandidate());

        $response = $this->publishWith($id, static fn (FakeModelLister $lister): FakeModelLister => $lister->unreachable());

        self::assertResponseStatusCodeSame(422);
        $violations = array_column($response->toArray(false)['violations'] ?? [], 'message', 'propertyPath');
        self::assertSame(['candidate/model'], array_keys($violations));
        self::assertStringStartsWith(\sprintf('Model "%s" could not be verified (provider unreachable: ', FakeProviderFactory::ANTHROPIC_MODEL), $violations['candidate/model']);
        self::assertStringNotContainsString(ProviderConnectionFixtures::PLACEHOLDER_API_KEY, $response->getContent(false));
        $this->assertNothingPublished($id);
    }

    public function testModelKnownToLackToolsIsRejectedWithMcpTools(): void
    {
        $ollama = AgentFixtures::providerConnections()['ollama'];
        $row = self::providerModel($ollama, FakeProviderFactory::OLLAMA_MODEL, ['completion', 'insert']);
        $id = AgentFixtures::createAgent('no-tools', self::toolModelCandidate($ollama));

        $response = AgentFixtures::request('POST', AgentFixtures::AGENTS.'/'.$id.'/publish', AgentFixtures::ADMIN);

        self::assertResponseStatusCodeSame(422);
        $violations = array_column($response->toArray(false)['violations'] ?? [], 'message', 'propertyPath');
        self::assertSame(['candidate/model'], array_keys($violations));
        self::assertStringContainsString('does not support tool calls (capabilities: completion, insert)', $violations['candidate/model']);
        self::assertStringContainsString('neuron:provider-models:sync '.$row->getId()->toRfc4122(), $violations['candidate/model']);
        $this->assertNothingPublished($id);

        AgentFixtures::dbal()->executeStatement(
            'UPDATE neuron_provider_model SET capabilities = :capabilities WHERE id = :id',
            ['capabilities' => '["completion","tools"]', 'id' => $row->getId()->toRfc4122()],
        );

        AgentFixtures::publish($id);
        self::assertResponseStatusCodeSame(201);
    }

    public function testModelWithUnknownCapabilitiesOrAStaleEntryIsPublishedWithMcpTools(): void
    {
        $ollama = AgentFixtures::providerConnections()['ollama'];
        $id = AgentFixtures::createAgent('unscanned', self::toolModelCandidate($ollama));

        AgentFixtures::publish($id);
        self::assertResponseStatusCodeSame(201);

        // Described without tools, but from another endpoint: stale.
        AgentFixtures::dbal()->executeStatement(
            'UPDATE neuron_provider_model SET capabilities = :capabilities, endpoint_fingerprint = :fingerprint WHERE id = :id',
            ['capabilities' => '["completion"]', 'fingerprint' => str_repeat('0', 64), 'id' => AgentFixtures::model('ollama')->getId()->toRfc4122()],
        );

        AgentFixtures::publish($id);
        self::assertResponseStatusCodeSame(201);
    }

    public function testModelOutsideTheAllowListIsRejectedWithMcpTools(): void
    {
        $ollama = AgentFixtures::providerConnection('allow-list');
        $ollama->setConfigJson(['tool_models' => ['qwen3:14b']] + $ollama->getConfigJson());
        AgentFixtures::entityManager()->flush();
        $id = AgentFixtures::createAgent('allow-list', self::toolModelCandidate($ollama));

        $response = AgentFixtures::request('POST', AgentFixtures::AGENTS.'/'.$id.'/publish', AgentFixtures::ADMIN);

        self::assertResponseStatusCodeSame(422);
        $violations = array_column($response->toArray(false)['violations'] ?? [], 'message', 'propertyPath');
        self::assertSame(['candidate/model'], array_keys($violations));
        self::assertSame(
            \sprintf('Model "%s" is not released for tools on connection "%s" (tool_models: qwen3:14b); choose one of these models or remove mcpTools.', FakeProviderFactory::OLLAMA_MODEL, $ollama->getName()),
            $violations['candidate/model'],
        );
        $this->assertNothingPublished($id);

        // Without mcpTools the allow-list does not apply.
        $plain = AgentFixtures::createAgent('allow-list-plain', AgentFixtures::validCandidate([
            'model' => self::modelIri($ollama),
        ]));
        AgentFixtures::publish($plain);
        self::assertResponseStatusCodeSame(201);

        // The publish requests rebooted the kernel: release the model on a fresh copy.
        AgentFixtures::dbal()->executeStatement(
            'UPDATE dmstr_api_configuration SET config_json = :config WHERE name = :name',
            ['config' => json_encode(['tool_models' => ['qwen3:14b', FakeProviderFactory::OLLAMA_MODEL]] + $ollama->getConfigJson(), \JSON_THROW_ON_ERROR), 'name' => $ollama->getName()],
        );

        AgentFixtures::publish($id);
        self::assertResponseStatusCodeSame(201);
    }

    public function testAllRuleAndTemplateViolationsAreReportedTogether(): void
    {
        $id = AgentFixtures::createAgent('rule-violations', AgentFixtures::validCandidate([
            'languages' => ['de', 'en'],
            'rules' => [
                ['match' => ['type' => 'pattern', 'pattern' => '(ab'], 'outcome' => 'refuse', 'template' => 'refusal'],
                ['match' => ['type' => 'language', 'languages' => ['fr']], 'outcome' => 'answer', 'template' => 'missing'],
                ['match' => ['type' => 'keywords', 'keywords' => ['x']], 'outcome' => 'continue', 'template' => 'refusal'],
                ['match' => ['type' => 'keywords', 'keywords' => ['y']], 'outcome' => 'refuse', 'template' => 'frame'],
            ],
            'templates' => [
                'refusal' => ['en' => 'No, {{customer}}.', 'fr' => 'Non.'],
                'frame' => ['de' => '{{answer}}'],
            ],
        ]));

        $response = AgentFixtures::request('POST', AgentFixtures::AGENTS.'/'.$id.'/publish', AgentFixtures::ADMIN);

        self::assertResponseStatusCodeSame(422);
        $paths = AgentFixtures::violationPaths($response);
        foreach ([
            'candidate/rules/0/match/pattern',
            'candidate/rules/1/match/languages/0',
            'candidate/rules/1/template',
            'candidate/rules/2/template',
            'candidate/rules/3/template',
            'candidate/templates/refusal',
            'candidate/templates/refusal/en',
            'candidate/templates/refusal/fr',
        ] as $expected) {
            self::assertContains($expected, $paths, $response->getContent(false));
        }
        self::assertStringContainsString('{{customer}}', $response->getContent(false));
        $this->assertNothingPublished($id);
    }

    public function testCandidateWithRulesAndTemplatesIsPublished(): void
    {
        $rules = [
            ['name' => 'prices', 'match' => ['type' => 'keywords', 'keywords' => ['Preis'], 'wholeWord' => true], 'outcome' => 'refuse', 'template' => 'refusal'],
            ['name' => null, 'match' => ['type' => 'pattern', 'pattern' => '^hallo$', 'flags' => 'i'], 'outcome' => 'answer', 'template' => 'greeting'],
            ['name' => null, 'match' => ['type' => 'language', 'languages' => ['de']], 'outcome' => 'continue', 'template' => null],
        ];
        $templates = [
            'refusal' => ['de' => 'Dazu sage ich nichts ({{rule}}).', 'en' => 'No comment.'],
            'greeting' => ['de' => 'Hallo, hier ist {{agentName}}.'],
        ];
        $id = AgentFixtures::createAgent('rules', AgentFixtures::validCandidate([
            'languages' => ['de', 'en'],
            'rules' => $rules,
            'templates' => $templates,
        ]));

        $version = AgentFixtures::publish($id);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($rules, $version['configuration']['rules']);
        self::assertSame($templates, $version['configuration']['templates']);
        self::assertSame(1, AgentFixtures::versionCount());
    }

    public function testCandidateWithEmptyRulesAndTemplatesIsPublished(): void
    {
        $id = AgentFixtures::createAgent('no-rules', AgentFixtures::validCandidate(['rules' => [], 'templates' => []]));

        $version = AgentFixtures::publish($id);

        self::assertResponseStatusCodeSame(201);
        self::assertSame([], $version['configuration']['rules']);
        self::assertEmpty($version['configuration']['templates']);
    }

    /**
     * The candidate of a reference case; fixtures are created on the way.
     *
     * @return array<string, mixed>
     */
    private function candidate(string $case): array
    {
        return match ($case) {
            'missing-instructions' => AgentFixtures::validCandidate(['instructions' => ['en' => 'Hello.'], 'languages' => ['de', 'en']]),
            'default-language' => AgentFixtures::validCandidate(['defaultLanguage' => 'en']),
            'foreign-instructions' => AgentFixtures::validCandidate(['instructions' => ['de' => 'Hallo.', 'fr' => 'Bonjour.']]),
            'unknown-collection' => AgentFixtures::validCandidate(['collections' => [AgentFixtures::collection('known')->getName(), AgentFixtures::PREFIX.'missing']]),
            'inactive-connection' => self::toolCandidate(AgentFixtures::connection('inactive', false)),
            'non-mcp-connection' => self::toolCandidate(AgentFixtures::connection('github', true, 'github'), true),
            'unknown-connection' => AgentFixtures::validCandidate(['mcpTools' => [['connection' => AgentFixtures::PREFIX.'nowhere', 'name' => 'search']]]),
            'unknown-tool' => AgentFixtures::validCandidate(['mcpTools' => [['connection' => AgentFixtures::connection('tools')->getName(), 'name' => 'missing']]]),
            'duplicate-tool' => $this->duplicateToolCandidate(),
            'artifact-tool-name' => AgentFixtures::validCandidate(['artifactTools' => true, 'mcpTools' => [['connection' => AgentFixtures::connection('tools')->getName(), 'name' => 'read_file']]]),
            'missing-model' => AgentFixtures::validCandidate(['model' => null]),
            'model-name' => AgentFixtures::validCandidate(['model' => FakeProviderFactory::ANTHROPIC_MODEL]),
            'unknown-model' => AgentFixtures::validCandidate(['model' => ProviderModel::COLLECTION_PATH.'/'.Uuid::v4()->toRfc4122()]),
            'inactive-provider-connection' => AgentFixtures::validCandidate(['model' => self::modelIri(AgentFixtures::providerConnection('offline', 'ollama', false))]),
            'unavailable-model' => AgentFixtures::validCandidate(['model' => $this->unavailableModel()->iri()]),
            'response-schema' => AgentFixtures::validCandidate(['responseSchema' => ['type' => 'nonsense']]),
            'start-schema' => AgentFixtures::validCandidate(['startSchema' => ['type' => 'array', 'items' => ['type' => 'string']]]),
        };
    }

    /**
     * One tool reference to the connection, whose catalogue has the tool.
     *
     * @return array<string, mixed>
     */
    private static function toolCandidate(ApiConfiguration $connection, bool $byId = false): array
    {
        AgentFixtures::tool($connection, 'search');

        return AgentFixtures::validCandidate(['mcpTools' => [[
            'connection' => $byId ? $connection->getId()->toRfc4122() : $connection->getName(),
            'name' => 'search',
        ]]]);
    }

    /**
     * A candidate on the connection's fixture model with one catalogued MCP tool.
     *
     * @return array<string, mixed>
     */
    private static function toolModelCandidate(ApiConfiguration $connection): array
    {
        $tools = AgentFixtures::connection('tools');
        AgentFixtures::tool($tools, 'search');

        return AgentFixtures::validCandidate([
            'model' => self::modelIri($connection),
            'mcpTools' => [['connection' => $tools->getName(), 'name' => 'search']],
        ]);
    }

    /**
     * The IRI of the catalogue entry of the connection for
     * {@see FakeProviderFactory::OLLAMA_MODEL} (created when missing).
     */
    private static function modelIri(ApiConfiguration $connection): string
    {
        return AgentFixtures::catalogueModelIri(AgentFixtures::entityManager(), $connection, FakeProviderFactory::OLLAMA_MODEL);
    }

    /**
     * An entry of the `anthropic` fixture connection that the connection
     * no longer offers (`available` false, as a scan leaves it).
     */
    private function unavailableModel(): ProviderModel
    {
        $model = AgentFixtures::model('anthropic', 'claude-retired')->setAvailable(false);
        AgentFixtures::entityManager()->flush();

        return $model;
    }

    /**
     * A model catalogue row of the connection, described from its current
     * endpoint; it cascades away with the connection in cleanUp().
     *
     * @param list<string>|null $capabilities
     */
    private static function providerModel(ApiConfiguration $connection, string $name, ?array $capabilities): ProviderModel
    {
        $model = new ProviderModel($connection, $name)
            ->setCapabilities($capabilities)
            ->setSyncedAt(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        AgentFixtures::entityManager()->persist($model);
        AgentFixtures::entityManager()->flush();

        return $model;
    }

    /**
     * Two connections that both offer `search`, both referenced.
     *
     * @return array<string, mixed>
     */
    private function duplicateToolCandidate(): array
    {
        $first = AgentFixtures::connection('first');
        $second = AgentFixtures::connection('second');
        AgentFixtures::tool($first, 'search');
        AgentFixtures::tool($second, 'search');

        return AgentFixtures::validCandidate(['mcpTools' => [
            ['connection' => $first->getName(), 'name' => 'search'],
            ['connection' => $second->getName(), 'name' => 'search'],
        ]]);
    }

    /**
     * Publishes on a fresh client whose container's model lister was
     * scripted first (the next request of a client boots a new kernel).
     *
     * @param callable(FakeModelLister): FakeModelLister $script
     */
    private function publishWith(string $id, callable $script): ResponseInterface
    {
        $client = static::createClient();
        $lister = static::getContainer()->get(FakeModelLister::class);
        self::assertInstanceOf(FakeModelLister::class, $lister);
        $script($lister);

        $response = AgentFixtures::send($client, 'POST', AgentFixtures::AGENTS.'/'.$id.'/publish', AgentFixtures::ADMIN);
        $response->getStatusCode();

        return $response;
    }

    private function assertNothingPublished(string $id): void
    {
        self::assertSame(0, AgentFixtures::versionCount());
        self::assertNull(AgentFixtures::dbal()->fetchOne('SELECT current_version_id FROM neuron_agent WHERE id = :id', ['id' => $id]));
    }
}
