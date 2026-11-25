<?php

// file generated with AI assistance: Claude Code - 2026-10-06 17:34:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\ProviderConnection\Catalogue;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Provider\CapabilityVerdict;
use Dmstr\NeuronAiBundle\Provider\ModelCapabilityCheck;
use Dmstr\NeuronAiBundle\Provider\ToolModelCheck;
use Dmstr\NeuronAiBundle\Tests\Support\InMemoryProviderCatalogueTrait;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the catalogue lookup behind the tools-capability
 * check: supported, missing and unknown verdicts, the exact-name
 * preference over the `:latest` equivalence, stale rows, and the violation
 * texts of ToolModelCheck, including the allow-list `tool_models`.
 */
final class ModelCapabilityCheckTest extends TestCase
{
    use InMemoryProviderCatalogueTrait;

    public function testKnownCapabilitiesAreSupported(): void
    {
        $local = $this->addOllama('local');
        $this->addModel($local, 'qwen2.5:7b', ['completion', 'tools']);

        $verdict = $this->check()->verdict($local, 'qwen2.5:7b', ['completion', 'tools']);

        self::assertSame(CapabilityVerdict::SUPPORTED, $verdict->status);
        self::assertFalse($verdict->isMissing());
    }

    public function testMissingCapabilitiesAreNamedWithTheKnownOnesAndTheRow(): void
    {
        $local = $this->addOllama('local');
        $row = $this->addModel($local, 'deepseek-r1:8b', ['completion', 'thinking']);

        $verdict = $this->check()->verdict($local, 'deepseek-r1:8b', ['completion', 'tools']);

        self::assertTrue($verdict->isMissing());
        self::assertSame(['tools'], $verdict->missing);
        self::assertSame(['completion', 'thinking'], $verdict->known);
        self::assertSame($row->getId()->toRfc4122(), $verdict->modelId);
    }

    public function testNoRowIsUnknown(): void
    {
        $local = $this->addOllama('local');
        $this->addModel($this->addOllama('other'), 'qwen2.5:7b', ['completion']);

        self::assertSame(CapabilityVerdict::UNKNOWN, $this->check()->verdict($local, 'qwen2.5:7b', ['tools'])->status);
    }

    public function testNeverDescribedRowIsUnknown(): void
    {
        $local = $this->addOllama('local');
        $this->addModel($local, 'qwen2.5:7b');

        self::assertSame(CapabilityVerdict::UNKNOWN, $this->check()->verdict($local, 'qwen2.5:7b', ['tools'])->status);
    }

    public function testStaleRowIsUnknown(): void
    {
        $local = $this->addOllama('local');
        $this->addModel($local, 'qwen2.5:7b', ['completion']);
        $local->setConfigJson(['url' => 'http://elsewhere:11434/api'] + $local->getConfigJson());

        self::assertSame(CapabilityVerdict::UNKNOWN, $this->check()->verdict($local, 'qwen2.5:7b', ['tools'])->status);
    }

    public function testLatestTagIsEquivalentInBothDirections(): void
    {
        $local = $this->addOllama('local');
        $this->addModel($local, 'mistral:latest', ['completion']);
        $this->addModel($local, 'llama3.1', ['completion', 'tools']);

        self::assertTrue($this->check()->verdict($local, 'mistral', ['tools'])->isMissing());
        self::assertSame(CapabilityVerdict::SUPPORTED, $this->check()->verdict($local, 'llama3.1:latest', ['tools'])->status);
    }

    public function testExactNameIsPreferredOverAnEquivalentOne(): void
    {
        $local = $this->addOllama('local');
        $this->addModel($local, 'mistral', ['completion']);
        $exact = $this->addModel($local, 'mistral:latest', ['completion', 'tools']);

        self::assertSame($exact, $this->check()->find($local, 'mistral:latest'));
        self::assertSame(CapabilityVerdict::SUPPORTED, $this->check()->verdict($local, 'mistral:latest', ['tools'])->status);
    }

    public function testToolModelCheckRejectsAModelWithoutTools(): void
    {
        $local = $this->addOllama('local');
        $row = $this->addModel($local, 'qwen2.5-coder:1.5b', ['completion', 'insert']);

        self::assertSame(
            \sprintf('Model "qwen2.5-coder:1.5b" of connection "local" does not support tool calls (capabilities: completion, insert); choose a model with the tools capability or remove mcpTools. If the model was re-pulled, refresh it with neuron:provider-models:sync %s.', $row->getId()->toRfc4122()),
            $this->toolModelCheck()->violation($local, 'qwen2.5-coder:1.5b', 'mcpTools'),
        );
    }

    public function testToolModelCheckRejectsAnEmbeddingModelAsNoChatModel(): void
    {
        $local = $this->addOllama('local');
        $this->addModel($local, 'nomic-embed-text', ['embedding']);

        $message = (string) $this->toolModelCheck()->violation($local, 'nomic-embed-text', 'mcpConnections');

        self::assertStringContainsString('is not a chat model and does not support tool calls (capabilities: embedding)', $message);
        self::assertStringContainsString('or remove mcpConnections.', $message);
    }

    public function testToolModelCheckNamesNoCapabilitiesForAnEmptyList(): void
    {
        $local = $this->addOllama('local');
        $this->addModel($local, 'odd', []);

        self::assertStringContainsString('(capabilities: none)', (string) $this->toolModelCheck()->violation($local, 'odd', 'mcpTools'));
    }

    public function testToolModelCheckPassesToolModelsUnknownModelsAndAnthropic(): void
    {
        $local = $this->addOllama('local');
        $claude = $this->addAnthropic('claude');
        $this->addModel($local, 'llama3.1:8b', ['completion', 'tools']);
        $this->addModel($claude, 'claude-sonnet-5-5', ['completion', 'thinking', 'tools', 'vision']);

        self::assertNull($this->toolModelCheck()->violation($local, 'llama3.1:8b', 'mcpTools'));
        self::assertNull($this->toolModelCheck()->violation($local, 'never-scanned', 'mcpTools'));
        self::assertNull($this->toolModelCheck()->violation($claude, 'claude-sonnet-5-5', 'mcpTools'));
        self::assertNull($this->toolModelCheck()->violation($claude, 'claude-unscanned', 'mcpTools'));
    }

    public function testAllowListRejectsAnUnlistedModelEvenWithoutCatalogueRow(): void
    {
        $local = $this->addAllowListedOllama('local', ['qwen3:14b', 'gemma4:26b']);
        $this->addModel($local, 'llama3.1:8b', ['completion', 'tools']);

        self::assertSame(
            'Model "llama3.1:8b" is not released for tools on connection "local" (tool_models: qwen3:14b, gemma4:26b); choose one of these models or remove mcpTools.',
            $this->toolModelCheck()->violation($local, 'llama3.1:8b', 'mcpTools'),
        );
        self::assertStringContainsString('or remove mcpConnections.', (string) $this->toolModelCheck()->violation($local, 'never-scanned', 'mcpConnections'));
    }

    public function testAllowListPassesListedModelsWithTheDefaultTagEquivalence(): void
    {
        $local = $this->addAllowListedOllama('local', ['qwen3:14b', 'mistral']);

        self::assertNull($this->toolModelCheck()->violation($local, 'qwen3:14b', 'mcpTools'));
        self::assertNull($this->toolModelCheck()->violation($local, 'mistral:latest', 'mcpTools'));
        self::assertNotNull($this->toolModelCheck()->violation($local, 'qwen3:8b', 'mcpTools'));
    }

    public function testCapabilityVerdictComesBeforeTheAllowList(): void
    {
        $local = $this->addAllowListedOllama('local', ['deepseek-r1:8b']);
        $this->addModel($local, 'deepseek-r1:8b', ['completion', 'thinking']);

        self::assertStringContainsString('does not support tool calls (capabilities: completion, thinking)', (string) $this->toolModelCheck()->violation($local, 'deepseek-r1:8b', 'mcpTools'));
    }

    public function testEmptyOrMissingAllowListAllowsEveryModel(): void
    {
        $empty = $this->addAllowListedOllama('empty', []);
        $claude = $this->addAnthropic('claude');

        self::assertNull($this->toolModelCheck()->violation($empty, 'llama3.1:8b', 'mcpTools'));
        self::assertNull($this->toolModelCheck()->violation($claude, 'claude-sonnet-5-5', 'mcpTools'));
        self::assertSame([], ToolModelCheck::allowedModels($claude));
    }

    public function testAllowedModelsIgnoresMalformedEntries(): void
    {
        $local = $this->addAllowListedOllama('local', ['qwen3:14b', '', 42, null]);

        self::assertSame(['qwen3:14b'], ToolModelCheck::allowedModels($local));
    }

    public function testAllowListOfAnthropicConnection(): void
    {
        $claude = $this->addAnthropic('claude');
        $claude->setConfigJson(['tool_models' => ['claude-sonnet-5-5']] + $claude->getConfigJson());

        self::assertNull($this->toolModelCheck()->violation($claude, 'claude-sonnet-5-5', 'mcpTools'));
        self::assertStringContainsString('is not released for tools on connection "claude"', (string) $this->toolModelCheck()->violation($claude, 'claude-haiku-4-5', 'mcpTools'));
    }

    /**
     * @param list<mixed> $toolModels
     */
    private function addAllowListedOllama(string $name, array $toolModels): ApiConfiguration
    {
        $connection = $this->addOllama($name);
        $connection->setConfigJson(['tool_models' => $toolModels] + $connection->getConfigJson());

        return $connection;
    }

    private function check(): ModelCapabilityCheck
    {
        return new ModelCapabilityCheck($this->entityManager());
    }

    private function toolModelCheck(): ToolModelCheck
    {
        return new ToolModelCheck($this->check());
    }
}
