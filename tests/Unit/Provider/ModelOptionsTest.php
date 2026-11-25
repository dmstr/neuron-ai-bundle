<?php

// file generated with AI assistance: Claude Code - 2026-10-03 10:32:35 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Provider;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Conversation\TurnRequest;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Provider\AnthropicWithToolChoice;
use Dmstr\NeuronAiBundle\Provider\ModelOptions;
use Dmstr\NeuronAiBundle\ProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ConfigSecretsFixture;
use Dmstr\NeuronAiBundle\Testing\FakeProviderFactory;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Ollama\Ollama;
use PHPUnit\Framework\TestCase;

/**
 * {@see ModelOptions} and their mapping by {@see ProviderFactory::chatProvider()}
 * to the Ollama and Anthropic request parameters.
 */
final class ModelOptionsTest extends TestCase
{
    private const string ANTHROPIC = FakeProviderFactory::ANTHROPIC_MODEL;
    private const string OLLAMA = FakeProviderFactory::OLLAMA_MODEL;

    public function testFromArrayIsNullWithoutOptions(): void
    {
        self::assertNull(ModelOptions::fromArray([]));
        self::assertNull(ModelOptions::fromArray(['temperature' => null, 'unknown' => 1]));
    }

    public function testFromArrayAndToArrayKeepOnlySetOptions(): void
    {
        $options = ModelOptions::fromArray(['temperature' => 1, 'maxTokens' => 512, 'think' => false, 'effort' => 'high', 'seed' => null]);

        self::assertInstanceOf(ModelOptions::class, $options);
        self::assertSame(1.0, $options->temperature);
        self::assertFalse($options->think);
        self::assertSame(['temperature' => 1.0, 'maxTokens' => 512, 'think' => false, 'effort' => 'high'], $options->toArray());
    }

    public function testAnthropicWithoutOptionsUsesTheConnectionDefaults(): void
    {
        $provider = $this->factory()->chatProvider($this->anthropic(), self::ANTHROPIC);

        self::assertInstanceOf(AnthropicWithToolChoice::class, $provider);
        self::assertSame(8192, $this->property($provider, 'max_tokens'));
        self::assertSame(['cache_control' => ['type' => 'ephemeral']], $this->property($provider, 'parameters'));

        $empty = $this->factory()->chatProvider($this->anthropic(), self::ANTHROPIC, new ModelOptions());
        self::assertSame(8192, $this->property($empty, 'max_tokens'));
        self::assertSame(['cache_control' => ['type' => 'ephemeral']], $this->property($empty, 'parameters'));
    }

    public function testAnthropicMapsSamplingEffortAndThinking(): void
    {
        $provider = $this->factory()->chatProvider($this->anthropic(), self::ANTHROPIC, new ModelOptions(temperature: 0.3, topP: 0.9, topK: 20, maxTokens: 1024, effort: 'high', thinkingDisplay: 'summarized'));

        self::assertSame(1024, $this->property($provider, 'max_tokens'));
        self::assertSame([
            'cache_control' => ['type' => 'ephemeral'],
            'temperature' => 0.3,
            'top_k' => 20,
            'output_config' => ['effort' => 'high'],
            'thinking' => ['type' => 'adaptive', 'display' => 'summarized'],
        ], $this->property($provider, 'parameters'), 'temperature wins, top_p is dropped');

        $topP = $this->factory()->chatProvider($this->anthropic(), self::ANTHROPIC, new ModelOptions(topP: 0.9));
        self::assertSame(['cache_control' => ['type' => 'ephemeral'], 'top_p' => 0.9], $this->property($topP, 'parameters'));
    }

    public function testAnthropicNeverSendsSamplingToAModelThatRejectsIt(): void
    {
        $provider = $this->factory()->chatProvider($this->anthropic(), 'claude-opus-5-5', new ModelOptions(temperature: 0.3, topK: 20, effort: 'max'));

        self::assertSame(['cache_control' => ['type' => 'ephemeral'], 'output_config' => ['effort' => 'max']], $this->property($provider, 'parameters'));
    }

    public function testAnthropicIgnoresOllamaOptions(): void
    {
        $provider = $this->factory()->chatProvider($this->anthropic(), self::ANTHROPIC, new ModelOptions(repeatPenalty: 1.1, seed: 3, numCtx: 4096, think: true));

        self::assertSame(8192, $this->property($provider, 'max_tokens'));
        self::assertSame(['cache_control' => ['type' => 'ephemeral']], $this->property($provider, 'parameters'));
    }

    public function testOllamaWithoutOptionsSendsNoNumCtx(): void
    {
        // A connection from before the migration still carrying num_ctx: ignored,
        // the server applies the model's Modelfile num_ctx.
        self::assertSame([], $this->property($this->factory()->chatProvider($this->ollama(4096), self::OLLAMA), 'parameters'));
        self::assertSame([], $this->property($this->factory()->chatProvider($this->ollama(), self::OLLAMA), 'parameters'));
        self::assertSame([], $this->property($this->factory()->chatProvider($this->ollama(), self::OLLAMA, new ModelOptions()), 'parameters'));
    }

    public function testOllamaMapsEveryOption(): void
    {
        $provider = $this->factory()->chatProvider($this->ollama(4096), self::OLLAMA, new ModelOptions(temperature: 0.7, topP: 0.8, topK: 30, maxTokens: 300, repeatPenalty: 1.2, seed: 9, numCtx: 32768, think: true, effort: 'high', thinkingDisplay: 'omitted'));

        self::assertInstanceOf(Ollama::class, $provider);
        self::assertSame([
            'options' => ['num_ctx' => 32768, 'temperature' => 0.7, 'top_p' => 0.8, 'top_k' => 30, 'num_predict' => 300, 'repeat_penalty' => 1.2, 'seed' => 9],
            'think' => true,
        ], $this->property($provider, 'parameters'));
    }

    public function testOllamaMapsEachValueOnlyWhenSet(): void
    {
        self::assertSame(['options' => ['temperature' => 0.7]], $this->property($this->factory()->chatProvider($this->ollama(), self::OLLAMA, new ModelOptions(temperature: 0.7)), 'parameters'));
        self::assertSame(['options' => ['num_predict' => 300]], $this->property($this->factory()->chatProvider($this->ollama(4096), self::OLLAMA, new ModelOptions(maxTokens: 300)), 'parameters'));
        self::assertSame(['think' => false], $this->property($this->factory()->chatProvider($this->ollama(), self::OLLAMA, new ModelOptions(think: false)), 'parameters'));
    }

    public function testFakeFactoryRecordsTemperatureMaxTokensAndNulls(): void
    {
        $factory = new FakeProviderFactory($this->registry());

        $factory->chatProvider(ProviderConnectionFixtures::anthropic(), self::ANTHROPIC);
        $factory->chatProvider(ProviderConnectionFixtures::ollama(), FakeProviderFactory::OLLAMA_MODEL, new ModelOptions(temperature: 0.5, maxTokens: 100, numCtx: 8192));
        $factory->chatProvider(ProviderConnectionFixtures::anthropic(), self::ANTHROPIC, new ModelOptions(maxTokens: 64));

        self::assertSame([
            ['provider' => 'anthropic', 'connection' => FakeProviderFactory::ANTHROPIC_CONNECTION, 'model' => self::ANTHROPIC, 'temperature' => null, 'maxTokens' => null],
            ['provider' => 'ollama', 'connection' => FakeProviderFactory::OLLAMA_CONNECTION, 'model' => FakeProviderFactory::OLLAMA_MODEL, 'temperature' => 0.5, 'maxTokens' => 100],
            ['provider' => 'anthropic', 'connection' => FakeProviderFactory::ANTHROPIC_CONNECTION, 'model' => self::ANTHROPIC, 'temperature' => null, 'maxTokens' => 64],
        ], $factory->getRequestedProviders());
    }

    public function testWithContextKeepsTheModelOptions(): void
    {
        $options = new ModelOptions(temperature: 0.1, maxTokens: 42);
        $request = new TurnRequest('anthropic', 'model', 'question', modelOptions: $options);

        self::assertSame($options, $request->withContext([])->modelOptions);
        self::assertNull(new TurnRequest('anthropic', 'model', 'question')->withContext([])->modelOptions);
    }

    private function factory(): ProviderFactory
    {
        return new ProviderFactory($this->registry(), ConfigSecretsFixture::create());
    }

    private function anthropic(): ApiConfiguration
    {
        return ProviderConnectionFixtures::anthropic();
    }

    /**
     * @param int|null $legacyNumCtx a `num_ctx` as connections carried it before the migration
     */
    private function ollama(?int $legacyNumCtx = null): ApiConfiguration
    {
        return ProviderConnectionFixtures::ollama(options: null !== $legacyNumCtx ? ['num_ctx' => $legacyNumCtx] : []);
    }

    private function registry(): EmbeddingModelRegistry
    {
        return new EmbeddingModelRegistry([
            'nomic-embed-text' => ['provider' => 'ollama', 'dimension' => 768],
        ]);
    }

    private function property(AIProviderInterface $provider, string $name): mixed
    {
        return new \ReflectionProperty($provider, $name)->getValue($provider);
    }
}

// - revised 2026-10-07
