<?php

// file generated with AI assistance: Claude Code - 2026-10-06 21:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Provider;

use Dmstr\NeuronAiBundle\Entity\Agent;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\ModelOptions;
use Dmstr\NeuronAiBundle\Provider\ModelOptionsSchema;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use PHPUnit\Framework\TestCase;

/**
 * {@see ModelOptionsSchema}: the options per provider type, the sampling
 * rule for Claude models, the narrowing by a catalogue row and the per-key
 * errors.
 */
final class ModelOptionsSchemaTest extends TestCase
{
    public function testOllamaWithoutRowOffersEveryOllamaOption(): void
    {
        $schema = ModelOptionsSchema::of('ollama', 'llama3.2');

        self::assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        self::assertSame('object', $schema['type']);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['temperature', 'topP', 'topK', 'maxTokens', 'repeatPenalty', 'seed', 'numCtx', 'think'], array_keys($schema['properties']));
        self::assertArrayNotHasKey('maximum', $schema['properties']['numCtx']);
    }

    public function testDefinitionsComeFromTheCandidateSchema(): void
    {
        $definitions = Agent::candidateSchema()['properties']['modelOptions']['properties'];

        self::assertSame($definitions['temperature'], ModelOptionsSchema::of('ollama', 'llama3.2')['properties']['temperature']);
        self::assertSame(array_keys($definitions), array_values(array_unique(array_merge(...array_values(ModelOptionsSchema::PROVIDER_OPTIONS)))), 'Every option of the candidate schema belongs to a provider.');
    }

    public function testAnthropicSamplingFollowsTheRuleTable(): void
    {
        self::assertSame(['temperature', 'topP', 'topK', 'maxTokens', 'effort', 'thinkingDisplay'], array_keys(ModelOptionsSchema::of('anthropic', 'claude-haiku-4-5')['properties']));
        self::assertSame(['temperature', 'topP', 'topK', 'maxTokens', 'effort', 'thinkingDisplay'], array_keys(ModelOptionsSchema::of('anthropic', 'claude-new-line-1')['properties']), 'unknown model: sampling allowed');
        self::assertSame(['maxTokens', 'effort', 'thinkingDisplay'], array_keys(ModelOptionsSchema::of('anthropic', 'claude-opus-5-5')['properties']));
        self::assertSame(ModelOptions::EFFORT_LEVELS, ModelOptionsSchema::of('anthropic', 'claude-opus-5-5')['properties']['effort']['enum']);
    }

    public function testOllamaRowNarrowsNumCtxAndThink(): void
    {
        $row = $this->row('ollama', 'qwen3:14b')->setCapabilities(['completion', 'tools'])->setMaxContextLength(40960);

        $schema = ModelOptionsSchema::of('ollama', 'qwen3:14b', $row);
        self::assertSame(40960, $schema['properties']['numCtx']['maximum']);
        self::assertArrayNotHasKey('think', $schema['properties']);

        $row->setCapabilities(['completion', 'thinking', 'tools']);
        self::assertArrayHasKey('think', ModelOptionsSchema::of('ollama', 'qwen3:14b', $row)['properties']);

        $row->setCapabilities(null);
        self::assertArrayHasKey('think', ModelOptionsSchema::of('ollama', 'qwen3:14b', $row)['properties'], 'unknown capabilities: think allowed');
    }

    public function testOllamaRowNamesTheModelfileContextLength(): void
    {
        $row = $this->row('ollama', 'qwen3:14b')->setMaxContextLength(40960)->setProviderData(['parameters' => ['num_ctx' => 8192, 'stop' => ['<|im_end|>']]]);

        self::assertSame(8192, $row->getDefaultContextLength());
        $numCtx = ModelOptionsSchema::of('ollama', 'qwen3:14b', $row)['properties']['numCtx'];
        self::assertStringEndsWith('Without a value the model runs with 8192 (Modelfile).', $numCtx['description']);
        self::assertArrayNotHasKey('default', $numCtx, 'a form would store the default');

        $row->setProviderData(['parameters' => []]);
        self::assertNull($row->getDefaultContextLength());
        self::assertSame([], $row->modelParameters());
        self::assertStringNotContainsString('runs with', ModelOptionsSchema::of('ollama', 'qwen3:14b', $row)['properties']['numCtx']['description']);
    }

    public function testAnthropicRowNarrowsMaxTokensEffortThinkingAndSampling(): void
    {
        $row = $this->row('anthropic', 'claude-x')
            ->setCapabilities(['completion', 'thinking', 'tools'])
            ->setMaxOutputTokens(64000)
            ->setSupportsSampling(false)
            ->setProviderData(['capabilities' => [
                'effort' => ['supported' => true, 'low' => ['supported' => true], 'medium' => ['supported' => true], 'high' => ['supported' => true], 'xhigh' => ['supported' => false], 'max' => ['supported' => true]],
                'thinking' => ['supported' => true, 'types' => ['enabled' => ['supported' => false], 'adaptive' => ['supported' => true]]],
            ]]);

        $schema = ModelOptionsSchema::of('anthropic', 'claude-x', $row);
        self::assertSame(['maxTokens', 'effort', 'thinkingDisplay'], array_keys($schema['properties']));
        self::assertSame(64000, $schema['properties']['maxTokens']['maximum']);
        self::assertSame(['low', 'medium', 'high', 'max'], $schema['properties']['effort']['enum']);

        $row->setSupportsSampling(true)->setProviderData(['capabilities' => [
            'effort' => ['supported' => false],
            'thinking' => ['supported' => true, 'types' => ['adaptive' => ['supported' => false]]],
        ]]);
        self::assertSame(['temperature', 'topP', 'topK', 'maxTokens'], array_keys(ModelOptionsSchema::of('anthropic', 'claude-x', $row)['properties']));
    }

    public function testRowWithoutReportedCapabilitiesKeepsTheProviderOptions(): void
    {
        $row = $this->row('anthropic', 'claude-sonnet-4-5')->setCapabilities(['completion', 'tools']);

        self::assertSame(['temperature', 'topP', 'topK', 'maxTokens', 'effort'], array_keys(ModelOptionsSchema::of('anthropic', 'claude-sonnet-4-5', $row)['properties']), 'no thinking capability: no thinkingDisplay');
    }

    public function testErrorsPerOption(): void
    {
        $schema = ModelOptionsSchema::of('ollama', 'qwen3:14b', $this->row('ollama', 'qwen3:14b')->setCapabilities(['completion'])->setMaxContextLength(8192));

        self::assertSame([], ModelOptionsSchema::errors($schema, ['numCtx' => 8192, 'temperature' => 0], 'Model "qwen3:14b"'));

        $errors = ModelOptionsSchema::errors($schema, ['effort' => 'high', 'numCtx' => 16384, 'temperature' => 'warm'], 'Model "qwen3:14b"');
        self::assertSame(['effort', 'numCtx', 'temperature'], array_keys($errors));
        self::assertSame('Model "qwen3:14b" does not take the option "effort" (it takes: temperature, topP, topK, maxTokens, repeatPenalty, seed, numCtx).', $errors['effort']);
        self::assertStringStartsWith('Model "qwen3:14b": ', $errors['numCtx']);
        self::assertStringContainsString('8192', $errors['numCtx']);
    }

    public function testAnthropicTemperatureGoesUpToOne(): void
    {
        $schema = ModelOptionsSchema::of('anthropic', 'claude-haiku-4-5');
        self::assertSame(ModelOptionsSchema::ANTHROPIC_MAX_TEMPERATURE, $schema['properties']['temperature']['maximum']);
        self::assertSame(2, ModelOptionsSchema::of('ollama', 'qwen3:14b')['properties']['temperature']['maximum']);

        $errors = ModelOptionsSchema::errors($schema, ['temperature' => 1.5], 'Model "claude-haiku-4-5"');
        self::assertSame(['temperature'], array_keys($errors));
        self::assertSame([], ModelOptionsSchema::errors($schema, ['temperature' => 1], 'Model "claude-haiku-4-5"'));
    }

    public function testAnthropicTemperatureAndTopPAreExclusive(): void
    {
        $schema = ModelOptionsSchema::of('anthropic', 'claude-haiku-4-5');
        self::assertSame(['temperature', 'topP'], $schema['not']['required']);
        self::assertArrayNotHasKey('not', ModelOptionsSchema::of('ollama', 'qwen3:14b'));
        self::assertArrayNotHasKey('not', ModelOptionsSchema::of('anthropic', 'claude-opus-5-5'), 'no sampling: nothing to exclude');

        self::assertSame([], ModelOptionsSchema::errors($schema, ['temperature' => 0.2, 'topK' => 5], 'Model "claude-haiku-4-5"'));
        self::assertSame([], ModelOptionsSchema::errors($schema, ['topP' => 0.9], 'Model "claude-haiku-4-5"'));
        self::assertSame(
            ['topP' => 'Model "claude-haiku-4-5" does not take the options temperature and topP together: set at most one of them.'],
            ModelOptionsSchema::errors($schema, ['temperature' => 0.2, 'topP' => 0.9], 'Model "claude-haiku-4-5"'),
        );
    }

    public function testUnknownProviderTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModelOptionsSchema::of('mcp', 'x');
    }

    private function row(string $type, string $name): ProviderModel
    {
        $connection = 'anthropic' === $type ? ProviderConnectionFixtures::anthropic() : ProviderConnectionFixtures::ollama();

        return new ProviderModel($connection, $name);
    }
}

// - revised 2026-10-07
