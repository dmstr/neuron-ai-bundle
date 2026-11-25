<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:41:04 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Command;

use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\Mcp\McpToolScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Console twin of `POST /api/refs/mcp_tools/scan`, run synchronously without
 * a job: mirrors the tools of all active `mcp` connections, or of the one
 * given with --connection (UUID, partial UUID, IRI or name), into the
 * catalogue and prints the summary. Exits non-zero when a connection failed.
 */
#[AsCommand(name: 'neuron:mcp-tools:scan', description: 'Mirror the tools of MCP connections into the tool catalogue')]
final class McpToolsScanCommand extends Command
{
    public function __construct(
        private readonly McpToolScanner $scanner,
        private readonly McpConnectionResolver $connectionResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Scan only this active mcp connection (UUID, partial UUID, IRI or name)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $reference = $input->getOption('connection');

        if (\is_string($reference) && '' !== $reference) {
            try {
                $connection = $this->connectionResolver->resolve($reference);
            } catch (UnprocessableEntityHttpException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
            $summary = $this->scanner->scan([$connection]);
        } else {
            $summary = $this->scanner->scanAll();
        }

        if ([] === $summary['connections']) {
            $io->note('No mcp connections found.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($summary['connections'] as $connection) {
            $rows[] = [
                $connection['name'],
                $connection['status'],
                $connection['created'],
                $connection['updated'],
                $connection['removed'],
                $connection['unchanged'],
                $connection['error'] ?? implode("\n", $connection['warnings']),
            ];
        }
        $io->table(['Connection', 'Status', 'Created', 'Updated', 'Removed', 'Unchanged', 'Error / warnings'], $rows);

        $io->writeln(\sprintf(
            'Created %d, updated %d, removed %d, unchanged %d; %d connection(s) scanned, %d skipped, %d failed.',
            $summary['created'],
            $summary['updated'],
            $summary['removed'],
            $summary['unchanged'],
            $summary['scanned'],
            $summary['skipped'],
            $summary['failed'],
        ));

        if ($summary['failed'] > 0) {
            $io->error(\sprintf('%d connection(s) failed.', $summary['failed']));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
