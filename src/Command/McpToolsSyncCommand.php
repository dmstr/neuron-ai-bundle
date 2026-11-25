<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:41:04 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Command;

use Dmstr\NeuronAiBundle\Entity\Refs\McpTool;
use Dmstr\NeuronAiBundle\Mcp\McpToolScanner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Console twin of `POST /api/refs/mcp_tools/{id}/sync`: refreshes one
 * catalogue entry from its MCP server. A vanished tool keeps its entry and
 * gets `lastError`; the command then exits non-zero.
 */
#[AsCommand(name: 'neuron:mcp-tools:sync', description: 'Refresh one tool catalogue entry from its MCP server')]
final class McpToolsSyncCommand extends Command
{
    public function __construct(
        private readonly McpToolScanner $scanner,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'UUID of the catalogue entry (McpTool)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (string) $input->getArgument('id');

        $tool = Uuid::isValid($id) ? $this->entityManager->find(McpTool::class, Uuid::fromString($id)) : null;
        if (!$tool instanceof McpTool) {
            $io->error(\sprintf('MCP tool "%s" not found.', $id));

            return Command::FAILURE;
        }

        $synced = $this->scanner->syncOne($tool);

        $io->definitionList(
            ['Tool' => $tool->getName()],
            ['Connection' => $tool->getApiConfiguration()->getName()],
            ['Synced at' => $tool->getSyncedAt()->format(\DATE_ATOM)],
            ['Last error' => $tool->getLastError() ?? '-'],
        );

        if (!$synced) {
            $io->error((string) $tool->getLastError());

            return Command::FAILURE;
        }

        $io->success('Tool refreshed.');

        return Command::SUCCESS;
    }
}
