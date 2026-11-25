<?php

// file generated with AI assistance: Claude Code - 2026-10-06 17:40:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\ProviderConnection;

use Dmstr\NeuronAiBundle\DmstrNeuronAiBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The `provider_models` config node of the bundle, without a kernel.
 */
final class ProviderModelsConfigTest extends TestCase
{
    public function testDescribeBudgetDefaultsTo200(): void
    {
        self::assertSame(200, $this->process([])['provider_models']['max_describe_calls']);
        self::assertSame(20, $this->process(['provider_models' => ['max_describe_calls' => 20]])['provider_models']['max_describe_calls']);
    }

    public function testZeroIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process(['provider_models' => ['max_describe_calls' => 0]]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        $extension = (new DmstrNeuronAiBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertNotNull($configuration);

        return (new Processor())->processConfiguration($configuration, [$config]);
    }
}
