<?php

// file generated with AI assistance: Claude Code - 2026-10-02 21:49:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Agent;

use Dmstr\NeuronAiBundle\Agent\AgentConfiguration;
use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\Model\ModelCapability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free: candidate defaults from `Entity/Agent/candidate.json`, the value
 * object's round trip and the structural check.
 */
final class AgentConfigurationTest extends TestCase
{
    /** The context-shaping keys, absent from every version stored before. */
    private const array CONTEXT_KEYS = ['contextMaxChunks' => true, 'contextMaxChars' => true, 'contextExpansionMaxChars' => true, 'contextInstructions' => true];

    /** The answer-gate keys, absent from every version stored before. */
    private const array GATE_KEYS = ['injectionGuard' => true, 'answerabilityCheck' => true, 'answerabilityVotes' => true, 'followUps' => true];

    /** The key added on 2026-10-14, absent from every version stored before. */
    private const array START_KEYS = ['startMessage' => true];

    /** The artifact-tools key, absent from every version stored before. */
    private const array ARTIFACT_KEYS = ['artifactTools' => true];

    private const array KEYS = [
        'instructions', 'model', 'collections', 'retrievalDepth', 'hybrid', 'hybridWeight',
        'contextMaxChunks', 'contextMaxChars', 'contextExpansionMaxChars', 'contextInstructions', 'mcpTools', 'artifactTools',
        'responseSchema', 'startSchema', 'startMessage', 'modelOptions', 'maxToolCalls', 'maxUserTurns', 'languages', 'defaultLanguage',
        'rules', 'templates', 'injectionGuard', 'answerabilityCheck', 'answerabilityVotes', 'followUps',
    ];

    public function testCandidateDefaultsHaveAllKeys(): void
    {
        $defaults = Agent::candidateDefaults();

        self::assertSame(self::KEYS, array_keys($defaults));
        self::assertSame(['de'], $defaults['languages']);
        self::assertSame('de', $defaults['defaultLanguage']);
        self::assertSame([], $defaults['instructions']);
        self::assertSame([], $defaults['collections']);
        self::assertSame([], $defaults['mcpTools']);
        self::assertSame([], $defaults['rules']);
        self::assertSame([], $defaults['templates']);
        self::assertSame([], $defaults['modelOptions']);
        foreach (['model', 'responseSchema', 'startSchema', 'maxToolCalls', 'maxUserTurns'] as $key) {
            self::assertNull($defaults[$key], $key);
        }
        // New candidates retrieve 10 chunks, vector-only, with an even
        // keyword share once hybrid is switched on.
        self::assertSame(10, $defaults['retrievalDepth']);
        self::assertFalse($defaults['hybrid']);
        self::assertSame(0.5, $defaults['hybridWeight']);
        // Context limits and expansion budget of new versions.
        self::assertSame(10, $defaults['contextMaxChunks']);
        self::assertSame(15000, $defaults['contextMaxChars']);
        self::assertSame(6000, $defaults['contextExpansionMaxChars']);
        self::assertSame([], $defaults['contextInstructions']);
        // Every answer gate is off, one answerability vote.
        self::assertFalse($defaults['injectionGuard']);
        self::assertFalse($defaults['answerabilityCheck']);
        self::assertSame(1, $defaults['answerabilityVotes']);
        self::assertFalse($defaults['followUps']);
        // No file tools unless the version switches them on.
        self::assertFalse($defaults['artifactTools']);
    }

    public function testNewCandidateDefaultsMapToTheNewContextLimits(): void
    {
        $configuration = AgentConfiguration::fromArray([]);

        self::assertSame(10, $configuration->contextMaxChunks);
        self::assertSame(15000, $configuration->contextMaxChars);
        self::assertSame(6000, $configuration->contextExpansionMaxChars);
        self::assertSame([], $configuration->contextInstructions);
    }

    public function testNewCandidateDefaultsMapToDepthTenVectorOnly(): void
    {
        $configuration = AgentConfiguration::fromArray([]);

        self::assertSame(10, $configuration->retrievalDepth);
        self::assertFalse($configuration->hybrid);
        self::assertSame(0.5, $configuration->hybridWeight);
    }

    public function testStoredVersionWithoutHybridKeysSearchesVectorOnly(): void
    {
        // A version frozen before hybrid retrieval holds 15 keys after the model
        // reference migration (16 before it dropped `connection`), among
        // them an explicit `retrievalDepth: null` (toArray() always wrote it).
        $stored = array_replace(
            array_diff_key(self::legacyDefaults(), ['hybrid' => true, 'hybridWeight' => true] + self::CONTEXT_KEYS + self::GATE_KEYS),
            ['retrievalDepth' => null, 'collections' => ['docs']],
        );
        self::assertCount(15, $stored);

        $configuration = AgentConfiguration::fromArray($stored);

        self::assertNull($configuration->retrievalDepth);
        self::assertFalse($configuration->hybrid);
        self::assertSame(0.5, $configuration->hybridWeight);
        self::assertSame(self::mapped($stored), array_intersect_key($configuration->toArray(), self::mapped($stored)));
    }

    public function testNewAgentDefaultsDoNotChangeAStoredVersionWithoutTheKeys(): void
    {
        $configuration = AgentConfiguration::fromArray([]);

        self::assertFalse($configuration->hybrid);
        self::assertSame(0.5, $configuration->hybridWeight);
        self::assertSame([], $configuration->modelOptions);
    }

    public function testNewAgentGetsTheDefaults(): void
    {
        $agent = new Agent();

        self::assertSame(array_replace(Agent::candidateDefaults(), Agent::NEW_AGENT_DEFAULTS), $agent->getCandidate());
        self::assertTrue($agent->isEnabled());
        self::assertNull($agent->getCurrentVersion());
    }

    public function testModelHasThePickerOfTheCatalogueAndNoConnectionKey(): void
    {
        $properties = Agent::candidateSchema()['properties'];

        self::assertArrayNotHasKey('connection', $properties);
        self::assertSame(ProviderModel::modelCollectionUrl(ModelCapability::COMPLETION), $properties['model']['x-collection']);
        self::assertSame('iri-reference', $properties['model']['format'] ?? null, 'the type-ahead resolves only on format iri-reference');
        self::assertSame($properties['model'], AgentVersion::getJsonSchema('configuration')['properties']['model'] ?? null);
    }

    public function testFromEmptyArrayEqualsTheDefaults(): void
    {
        self::assertSame(Agent::candidateDefaults(), AgentConfiguration::fromArray([])->toArray());
    }

    public function testRoundTripKeepsEveryField(): void
    {
        $data = [
            'instructions' => ['de' => 'Hallo', 'en' => 'Hello'],
            'model' => '0199a6b4-1c2d-7e3f-8a4b-5c6d7e8f9a0c',
            'collections' => ['docs'],
            'retrievalDepth' => 4,
            'hybrid' => true,
            'hybridWeight' => 0.25,
            'contextMaxChunks' => 3,
            'contextMaxChars' => 2000,
            'contextExpansionMaxChars' => 0,
            'contextInstructions' => ['en' => 'Answer from the context only.'],
            'mcpTools' => [['connection' => 'tools', 'name' => 'search', 'terminal' => true]],
            'artifactTools' => true,
            'responseSchema' => ['type' => 'object'],
            'startSchema' => ['type' => 'object', 'properties' => ['topic' => ['type' => 'string']]],
            'startMessage' => ['en' => 'Start with {{topic}}, {{agentName}}.'],
            'modelOptions' => ['temperature' => 0.5, 'maxTokens' => 1000],
            'maxToolCalls' => 3,
            'maxUserTurns' => 10,
            'languages' => ['de', 'en'],
            'defaultLanguage' => 'en',
            'rules' => [
                ['name' => 'prices', 'match' => ['type' => 'keywords', 'keywords' => ['Preis'], 'wholeWord' => false], 'outcome' => 'refuse', 'template' => 'refusal'],
                ['name' => null, 'match' => ['type' => 'pattern', 'pattern' => '^hi$', 'flags' => 'i'], 'outcome' => 'continue', 'template' => null],
                ['name' => 'foreign', 'match' => ['type' => 'language', 'languages' => ['de']], 'outcome' => 'answer', 'template' => 'german_only'],
            ],
            'templates' => [
                'refusal' => ['de' => 'Nein.', 'en' => 'No.'],
                'german_only' => ['en' => 'German only, {{agentName}}.'],
                'injectionRefusal' => ['de' => 'Nein.'],
            ],
            'injectionGuard' => true,
            'answerabilityCheck' => true,
            'answerabilityVotes' => 5,
            'followUps' => true,
        ];

        self::assertSame($data, AgentConfiguration::fromArray($data)->toArray());
    }

    public function testRuleOptionalFieldsAreFilled(): void
    {
        $configuration = AgentConfiguration::fromArray(['rules' => [
            ['match' => ['type' => 'keywords', 'keywords' => ['a']], 'outcome' => 'refuse', 'template' => 'no'],
            ['match' => ['type' => 'pattern', 'pattern' => 'b'], 'outcome' => 'continue'],
        ]]);

        self::assertSame([
            ['name' => null, 'match' => ['type' => 'keywords', 'keywords' => ['a'], 'wholeWord' => true], 'outcome' => 'refuse', 'template' => 'no'],
            ['name' => null, 'match' => ['type' => 'pattern', 'pattern' => 'b', 'flags' => ''], 'outcome' => 'continue', 'template' => null],
        ], $configuration->rules);
    }

    public function testStoredVersionWithoutRuleKeysMapsUnchanged(): void
    {
        // A version frozen before rules and templates holds 13 keys only (after the
        // model reference migration, which dropped `connection`).
        $stored = array_replace(
            array_diff_key(self::legacyDefaults(), ['rules' => true, 'templates' => true, 'hybrid' => true, 'hybridWeight' => true] + self::CONTEXT_KEYS + self::GATE_KEYS),
            ['instructions' => ['de' => 'Hallo'], 'model' => '0199a6b4-1c2d-7e3f-8a4b-5c6d7e8f9a0c', 'retrievalDepth' => null],
        );
        self::assertCount(13, $stored);

        $array = AgentConfiguration::fromArray($stored)->toArray();

        self::assertSame(self::mapped($stored), array_intersect_key($array, self::mapped($stored)));
        self::assertSame([], $array['rules']);
        self::assertSame([], $array['templates']);
    }

    /**
     * A version published before provider connections stores a `provider`
     * string, one the model reference migration could not rewrite still a
     * `connection`; fromArray() drops both keys and keeps the model as
     * stored, which the mapper reports as stale.
     */
    public function testStoredProviderAndConnectionKeysAreDropped(): void
    {
        $stored = array_replace(
            Agent::candidateDefaults(),
            ['instructions' => ['de' => 'Hallo'], 'provider' => 'ollama', 'connection' => '0199a6b4-1c2d-7e3f-8a4b-5c6d7e8f9a0b', 'model' => 'llama3.2'],
        );

        $configuration = AgentConfiguration::fromArray($stored);

        self::assertSame('llama3.2', $configuration->model);
        self::assertArrayNotHasKey('provider', $configuration->toArray());
        self::assertArrayNotHasKey('connection', $configuration->toArray());
    }

    public function testCandidateWithProviderKeyIsStructurallyInvalid(): void
    {
        $errors = AgentConfiguration::structureErrors(['provider' => 'ollama', 'model' => 'llama3.2']);

        self::assertNotSame([], $errors);
        self::assertSame([''], array_keys($errors), json_encode($errors, \JSON_THROW_ON_ERROR));
    }

    public function testCandidateConnectionKeyIsReportedWithAHintAtTheModel(): void
    {
        self::assertSame(['/connection' => AgentConfiguration::CONNECTION_REMOVED], AgentConfiguration::structureErrors(['connection' => 'ollama-local', 'model' => 'llama3.2']));
        self::assertSame(['/connection' => AgentConfiguration::CONNECTION_REMOVED], AgentConfiguration::structureErrors(['connection' => null]));
        self::assertSame(['/connection', '/model'], array_keys(AgentConfiguration::structureErrors(['connection' => 'x', 'model' => ''])));
    }

    public function testCandidateModelMustBeANonEmptyStringOrNull(): void
    {
        self::assertSame([], AgentConfiguration::structureErrors(['model' => null]));
        self::assertSame([], AgentConfiguration::structureErrors(['model' => '/api/refs/provider_models/0199a6b4-1c2d-7e3f-8a4b-5c6d7e8f9a0c']));
        self::assertArrayHasKey('/model', AgentConfiguration::structureErrors(['model' => '']));
        self::assertArrayHasKey('/model', AgentConfiguration::structureErrors(['model' => 42]));
    }

    public function testMissingKeysAndTerminalAreFilled(): void
    {
        $configuration = AgentConfiguration::fromArray([
            'mcpTools' => [['connection' => 'tools', 'name' => 'search']],
        ]);

        $array = $configuration->toArray();
        self::assertSame(self::KEYS, array_keys($array));
        self::assertSame([['connection' => 'tools', 'name' => 'search', 'terminal' => false]], $array['mcpTools']);
        self::assertSame([], $configuration->modelOptions);
        self::assertSame('de', $configuration->defaultLanguage);
    }

    /**
     * A version stored before `modelOptions` carries top-level
     * `temperature` and `maxTokens`: they become model options (null
     * dropped), an option set in `modelOptions` wins, and `toArray()` has
     * only `modelOptions`.
     */
    public function testTopLevelTemperatureAndMaxTokensBecomeModelOptions(): void
    {
        self::assertSame(['temperature' => 1, 'maxTokens' => 200], AgentConfiguration::fromArray(['temperature' => 1, 'maxTokens' => 200])->modelOptions);
        self::assertSame(['maxTokens' => 200], AgentConfiguration::fromArray(['temperature' => null, 'maxTokens' => 200])->modelOptions);
        self::assertSame(['temperature' => 0.1, 'maxTokens' => 200], AgentConfiguration::fromArray(['temperature' => 0.9, 'maxTokens' => 200, 'modelOptions' => ['temperature' => 0.1]])->modelOptions);

        $array = AgentConfiguration::fromArray(['temperature' => 1])->toArray();
        self::assertArrayNotHasKey('temperature', $array);
        self::assertArrayNotHasKey('maxTokens', $array);
        self::assertSame(['temperature' => 1], $array['modelOptions']);
    }

    public function testModelOptionsStructure(): void
    {
        self::assertSame([], AgentConfiguration::structureErrors(['modelOptions' => []]));
        self::assertSame([], AgentConfiguration::structureErrors(['modelOptions' => ['temperature' => 0.2, 'topP' => 0.9, 'topK' => 40, 'maxTokens' => 512, 'repeatPenalty' => 1.1, 'seed' => 7, 'numCtx' => 32768, 'think' => true, 'effort' => 'xhigh', 'thinkingDisplay' => 'summarized']]));
        self::assertArrayHasKey('/modelOptions/temperature', AgentConfiguration::structureErrors(['modelOptions' => ['temperature' => 3]]));
        self::assertArrayHasKey('/modelOptions/effort', AgentConfiguration::structureErrors(['modelOptions' => ['effort' => 'huge']]));
        self::assertArrayHasKey('/modelOptions', AgentConfiguration::structureErrors(['modelOptions' => ['num_ctx' => 4096]]));
        // The top-level keys of before are gone from the candidate schema.
        self::assertArrayHasKey('', AgentConfiguration::structureErrors(['temperature' => 0.2]));
    }

    public function testDefaultsAreStructurallyValid(): void
    {
        self::assertSame([], AgentConfiguration::structureErrors(Agent::candidateDefaults()));
        self::assertSame([], AgentConfiguration::structureErrors([]));
    }

    public function testEmptyObjectsDecodedAsArraysAreValid(): void
    {
        // `{}` decodes to [] in PHP; instructions and schemas take objects.
        self::assertSame([], AgentConfiguration::structureErrors([
            'instructions' => [],
            'responseSchema' => [],
            'startSchema' => ['type' => 'object', 'properties' => []],
            'templates' => [],
            'rules' => [],
        ]));
    }

    public function testRuleAndTemplateStructureIsValid(): void
    {
        self::assertSame([], AgentConfiguration::structureErrors([
            'rules' => [
                ['name' => 'prices', 'match' => ['type' => 'keywords', 'keywords' => ['Preis'], 'wholeWord' => false], 'outcome' => 'refuse', 'template' => 'refusal'],
                ['match' => ['type' => 'pattern', 'pattern' => '^hi$', 'flags' => 'imsx'], 'outcome' => 'continue'],
                ['match' => ['type' => 'language', 'languages' => ['de']], 'outcome' => 'answer', 'template' => 'german_only'],
            ],
            'templates' => ['refusal' => ['de' => 'Nein.'], 'german_only' => ['en' => 'German only.']],
        ]));
    }

    public function testEmptyLanguageMapOfATemplateIsATypeError(): void
    {
        // `{"refusal": {}}` decodes to ['refusal' => []], which is a list.
        self::assertArrayHasKey('/templates/refusal', AgentConfiguration::structureErrors(['templates' => ['refusal' => []]]));
    }

    public function testStructureErrorsArePointers(): void
    {
        $errors = AgentConfiguration::structureErrors([
            'maxToolCalls' => 'three',
            'modelOptions' => ['temperature' => 3],
            'mcpTools' => [['connection' => 'tools', 'name' => 'a'], ['connection' => 'tools', 'name' => 5]],
        ]);

        self::assertArrayHasKey('/maxToolCalls', $errors);
        self::assertArrayHasKey('/modelOptions/temperature', $errors);
        self::assertArrayHasKey('/mcpTools/1/name', $errors);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidHybridValues(): iterable
    {
        yield 'hybridWeight below 0' => ['hybridWeight', -0.1];
        yield 'hybridWeight above 1' => ['hybridWeight', 1.01];
        yield 'hybridWeight string' => ['hybridWeight', '0.5'];
        yield 'hybridWeight null' => ['hybridWeight', null];
        yield 'hybrid string' => ['hybrid', 'true'];
        yield 'hybrid integer' => ['hybrid', 1];
        yield 'hybrid null' => ['hybrid', null];
    }

    #[DataProvider('invalidHybridValues')]
    public function testInvalidHybridValuesAreRejected(string $key, mixed $value): void
    {
        self::assertArrayHasKey('/'.$key, AgentConfiguration::structureErrors([$key => $value]));
    }

    public function testHybridBoundsAreValid(): void
    {
        foreach ([0, 0.0, 0.5, 1, 1.0] as $weight) {
            self::assertSame([], AgentConfiguration::structureErrors(['hybrid' => true, 'hybridWeight' => $weight]), (string) $weight);
        }
        self::assertSame([], AgentConfiguration::structureErrors(['hybrid' => false, 'retrievalDepth' => null]));
        self::assertSame(1.0, AgentConfiguration::fromArray(['hybridWeight' => 1])->hybridWeight);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidContextValues(): iterable
    {
        yield 'contextMaxChunks 0' => ['contextMaxChunks', 0];
        yield 'contextMaxChunks null' => ['contextMaxChunks', null];
        yield 'contextMaxChunks float' => ['contextMaxChunks', 2.5];
        yield 'contextMaxChars 0' => ['contextMaxChars', 0];
        yield 'contextMaxChars string' => ['contextMaxChars', '6000'];
        yield 'contextExpansionMaxChars negative' => ['contextExpansionMaxChars', -1];
        yield 'contextExpansionMaxChars null' => ['contextExpansionMaxChars', null];
        yield 'contextInstructions list' => ['contextInstructions', ['text']];
        yield 'contextInstructions string' => ['contextInstructions', 'text'];
    }

    #[DataProvider('invalidContextValues')]
    public function testInvalidContextValuesAreRejected(string $key, mixed $value): void
    {
        self::assertArrayHasKey('/'.$key, AgentConfiguration::structureErrors([$key => $value]));
    }

    public function testContextBoundsAreValid(): void
    {
        self::assertSame([], AgentConfiguration::structureErrors([
            'contextMaxChunks' => 1,
            'contextMaxChars' => 1,
            'contextExpansionMaxChars' => 0,
            'contextInstructions' => [],
        ]));
        self::assertSame([], AgentConfiguration::structureErrors(['contextInstructions' => ['de' => 'Nur aus dem Kontext.']]));
        self::assertArrayHasKey('/contextInstructions/de', AgentConfiguration::structureErrors(['contextInstructions' => ['de' => 5]]));
    }

    /**
     * Regression: a version stored before the answer gates (all
     * retrieval keys, none of the gate keys) maps through fromArray() to its
     * own values plus the gate defaults, i.e. every gate off.
     */
    public function testStoredVersionWithoutGateKeysHasEveryGateOff(): void
    {
        $stored = array_replace(
            array_diff_key(self::legacyDefaults(), self::GATE_KEYS),
            ['instructions' => ['de' => 'Hallo'], 'model' => '0199a6b4-1c2d-7e3f-8a4b-5c6d7e8f9a0c', 'collections' => ['docs'], 'hybrid' => true],
        );
        self::assertCount(21, $stored);

        $configuration = AgentConfiguration::fromArray($stored);

        self::assertSame(self::mapped($stored) + ['injectionGuard' => false, 'answerabilityCheck' => false, 'answerabilityVotes' => 1, 'followUps' => false], array_diff_key($configuration->toArray(), self::START_KEYS + self::ARTIFACT_KEYS));
        self::assertNull($configuration->startMessage, 'a version stored before startMessage starts no turn');
        self::assertFalse($configuration->injectionGuard);
        self::assertFalse($configuration->answerabilityCheck);
        self::assertSame(1, $configuration->answerabilityVotes);
        self::assertFalse($configuration->followUps);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidGateValues(): iterable
    {
        yield 'injectionGuard string' => ['injectionGuard', 'true'];
        yield 'injectionGuard null' => ['injectionGuard', null];
        yield 'answerabilityCheck integer' => ['answerabilityCheck', 1];
        yield 'followUps null' => ['followUps', null];
        yield 'answerabilityVotes 0' => ['answerabilityVotes', 0];
        yield 'answerabilityVotes even' => ['answerabilityVotes', 2];
        yield 'answerabilityVotes 4' => ['answerabilityVotes', 4];
        yield 'answerabilityVotes 9' => ['answerabilityVotes', 9];
        yield 'answerabilityVotes negative' => ['answerabilityVotes', -1];
        yield 'answerabilityVotes string' => ['answerabilityVotes', '3'];
        yield 'answerabilityVotes null' => ['answerabilityVotes', null];
    }

    #[DataProvider('invalidGateValues')]
    public function testInvalidGateValuesAreRejected(string $key, mixed $value): void
    {
        self::assertArrayHasKey('/'.$key, AgentConfiguration::structureErrors([$key => $value]));
    }

    public function testOddVotesUpToSevenAreValid(): void
    {
        foreach ([1, 3, 5, 7] as $votes) {
            self::assertSame([], AgentConfiguration::structureErrors(['answerabilityCheck' => true, 'answerabilityVotes' => $votes]), (string) $votes);
        }
        self::assertSame([], AgentConfiguration::structureErrors(['injectionGuard' => true, 'followUps' => true]));
    }

    /**
     * A version stored before `artifactTools` (every other key
     * present) maps to its own values with the artifact tools off, and
     * toArray() writes the key from then on.
     */
    public function testStoredVersionWithoutArtifactToolsOffersNoFileTools(): void
    {
        $stored = array_replace(
            array_diff_key(Agent::candidateDefaults(), self::ARTIFACT_KEYS),
            ['instructions' => ['de' => 'Hallo'], 'model' => '0199a6b4-1c2d-7e3f-8a4b-5c6d7e8f9a0c', 'mcpTools' => [['connection' => 'tools', 'name' => 'search', 'terminal' => false]]],
        );
        self::assertCount(\count(self::KEYS) - 1, $stored);

        $configuration = AgentConfiguration::fromArray($stored);

        self::assertFalse($configuration->artifactTools);
        self::assertSame(self::KEYS, array_keys($configuration->toArray()));
        self::assertSame($stored, array_diff_key($configuration->toArray(), self::ARTIFACT_KEYS));
        self::assertFalse($configuration->toArray()['artifactTools']);
    }

    public function testArtifactToolsMustBeABoolean(): void
    {
        self::assertSame([], AgentConfiguration::structureErrors(['artifactTools' => true]));
        self::assertSame([], AgentConfiguration::structureErrors(['artifactTools' => false]));
        foreach (['true', 1, null, ['read_file']] as $value) {
            self::assertArrayHasKey('/artifactTools', AgentConfiguration::structureErrors(['artifactTools' => $value]), json_encode($value, \JSON_THROW_ON_ERROR));
        }
        self::assertTrue(AgentConfiguration::fromArray(['artifactTools' => true])->artifactTools);
    }

    public function testUnknownKeyIsReportedOnTheCandidate(): void
    {
        self::assertArrayHasKey('', AgentConfiguration::structureErrors(['unknown' => true]));
    }

    public function testToJsonValue(): void
    {
        self::assertEquals(new \stdClass(), AgentConfiguration::toJsonValue([]));
        self::assertSame([], AgentConfiguration::toJsonValue([], false));
        self::assertEquals((object) ['required' => [], 'properties' => new \stdClass()], AgentConfiguration::toJsonValue(['required' => [], 'properties' => []]));
        self::assertEquals((object) ['languages' => ['de']], AgentConfiguration::toJsonValue(['languages' => ['de']]));
    }

    /**
     * The candidate defaults in the shape of a version stored before
     * `modelOptions`: `temperature` and `maxTokens` (null) in its place.
     *
     * @return array<string, mixed>
     */
    private static function legacyDefaults(): array
    {
        $defaults = [];
        foreach (Agent::candidateDefaults() as $key => $value) {
            if (isset(self::START_KEYS[$key]) || isset(self::ARTIFACT_KEYS[$key])) {
                continue;
            }
            if ('modelOptions' === $key) {
                $defaults['temperature'] = null;
                $defaults['maxTokens'] = null;

                continue;
            }
            $defaults[$key] = $value;
        }

        return $defaults;
    }

    /**
     * $stored as toArray() writes it: `temperature` and `maxTokens` (null)
     * become an empty `modelOptions` in their place.
     *
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    private static function mapped(array $stored): array
    {
        $mapped = [];
        foreach ($stored as $key => $value) {
            if ('temperature' === $key) {
                $mapped['modelOptions'] = [];
            } elseif ('maxTokens' !== $key) {
                $mapped[$key] = $value;
            }
        }

        return $mapped;
    }
}
// - revised 2026-10-14
// - revised 2026-10-16
