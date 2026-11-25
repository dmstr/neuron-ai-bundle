<?php

// file generated with AI assistance: Claude Code - 2026-10-06 21:30:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\ProviderConnection\Catalogue;

use ApiPlatform\Metadata\Get;
use ApiPlatform\State\ProviderInterface;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\State\ProviderModelOptionsSchemaProvider;
use Dmstr\NeuronAiBundle\Testing\ProviderConnectionFixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * {@see ProviderModelOptionsSchemaProvider} without a kernel: the row comes
 * from a stubbed item provider; a stale row gives the schema without the
 * limits of the row.
 */
final class ProviderModelOptionsSchemaProviderTest extends TestCase
{
    public function testSchemaOfACurrentRow(): void
    {
        $row = new ProviderModel(ProviderConnectionFixtures::anthropic('claude'), 'claude-opus-5-5')
            ->setCapabilities(['completion', 'thinking', 'tools'])
            ->setMaxOutputTokens(128000)
            ->setSupportsSampling(false);

        $output = $this->provider($row)->provide(new Get(), ['id' => $row->getId()]);

        self::assertSame('claude-opus-5-5', $output->model);
        self::assertSame('claude', $output->connection);
        self::assertSame('anthropic', $output->type);
        self::assertFalse($output->stale);
        self::assertSame(['maxTokens', 'effort', 'thinkingDisplay'], array_keys($output->schema['properties']));
        self::assertSame(128000, $output->schema['properties']['maxTokens']['maximum']);
    }

    public function testStaleRowHasNoLimitsOfTheModel(): void
    {
        $row = new ProviderModel(ProviderConnectionFixtures::ollama('local'), 'llama3.1:8b')
            ->setCapabilities(['completion', 'tools'])
            ->setMaxContextLength(8192)
            ->setEndpointFingerprint(str_repeat('0', 64));

        $output = $this->provider($row)->provide(new Get(), ['id' => $row->getId()]);

        self::assertTrue($output->stale);
        self::assertArrayNotHasKey('maximum', $output->schema['properties']['numCtx']);
        self::assertArrayHasKey('think', $output->schema['properties']);
    }

    public function testMissingRowIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->provider(null)->provide(new Get(), ['id' => 'x']);
    }

    private function provider(?ProviderModel $row): ProviderModelOptionsSchemaProvider
    {
        $items = $this->createStub(ProviderInterface::class);
        $items->method('provide')->willReturn($row);

        return new ProviderModelOptionsSchemaProvider($items);
    }
}
