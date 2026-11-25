<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:41:04 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\NeuronAiBundle\Entity\Refs\McpTool;
use Dmstr\NeuronAiBundle\Mcp\McpToolScanner;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /refs/mcp_tools/{id}/sync`: refreshes one catalogue entry from its
 * MCP server and returns it (200). A vanished tool or a failing server sets
 * `lastError` on the entry and still answers 200.
 *
 * @implements ProcessorInterface<McpTool, McpTool>
 */
final class McpToolSyncProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly McpToolScanner $scanner,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): McpTool
    {
        if (!$data instanceof McpTool) {
            throw new NotFoundHttpException('MCP tool not found.');
        }

        $this->scanner->syncOne($data);

        return $data;
    }
}
