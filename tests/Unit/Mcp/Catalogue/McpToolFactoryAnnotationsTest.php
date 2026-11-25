<?php

// file generated with AI assistance: Claude Code - 2026-10-09 06:42:28 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Unit\Mcp\Catalogue;

use Dmstr\NeuronAiBundle\Mcp\McpToolFactory;
use Dmstr\NeuronAiBundle\Testing\ConfigSecretsFixture;
use Dmstr\NeuronAiBundle\Testing\FakeMcpTransportFactory;
use Dmstr\NeuronAiBundle\Tests\Support\InMemoryCatalogueTrait;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free check that tools built from catalogue rows carry the
 * annotations the scan stored (no server contact).
 */
final class McpToolFactoryAnnotationsTest extends TestCase
{
    use InMemoryCatalogueTrait;

    public function testCatalogueAnnotationsReachTheTool(): void
    {
        $connection = $this->addConnection('flowable', 'http://mcp.example.org/mcp/flowable');
        $hinted = $this->addTool($connection, 'list_tasks', 'Lists open tasks', ['type' => 'object'])
            ->setAnnotations(['readOnlyHint' => true, 'openWorldHint' => false]);
        $plain = $this->addTool($connection, 'complete_task', 'Completes a task', ['type' => 'object']);
        $fake = new FakeMcpTransportFactory();

        $tools = (new McpToolFactory($this->entityManager(), $fake, null, ConfigSecretsFixture::create()))->forTools([$hinted, $plain]);

        self::assertCount(2, $tools);
        self::assertInstanceOf(Tool::class, $tools[0]);
        self::assertInstanceOf(Tool::class, $tools[1]);
        self::assertSame(['readOnlyHint' => true, 'openWorldHint' => false], $tools[0]->getAnnotations());
        self::assertSame([], $tools[1]->getAnnotations());
        self::assertSame([], $fake->getMethods(), 'building performs no I/O');
    }
}
