<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Command;

use Dmstr\NeuronAiBundle\Provider\Connection\ProviderConnectionReferenceException;
use Dmstr\NeuronAiBundle\Provider\Connection\ProviderConnectionResolver;
use Dmstr\NeuronAiBundle\Provider\ProviderModelScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console twin of `POST /api/refs/provider_models/scan`, run synchronously
 * without a job: mirrors the models of all active `ollama` and `anthropic`
 * connections, or of the one given with --connection (UUID, partial UUID,
 * IRI or name), into the model catalogue and prints the summary. Exits
 * non-zero when a connection failed.
 *
 * The first scan of an Ollama server describes every model (one
 * `POST /show` each, capped by `provider_models.max_describe_calls`);
 * later scans describe only new or changed models.
 */
#[AsCommand(name: 'neuron:provider-models:scan', description: 'Mirror the models of provider connections into the model catalogue')]
final class ProviderModelsScanCommand extends Command
{
    public function __construct(
        private readonly ProviderModelScanner $scanner,
        private readonly ProviderConnectionResolver $connectionResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Scan only this active ollama or anthropic connection (UUID, partial UUID, IRI or name)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $reference = $input->getOption('connection');

        if (\is_string($reference) && '' !== $reference) {
            try {
                $connection = $this->connectionResolver->resolveChat($reference);
            } catch (ProviderConnectionReferenceException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
            $summary = $this->scanner->scan([$connection]);
        } else {
            $summary = $this->scanner->scanAll();
        }

        if ([] === $summary['connections']) {
            $io->note('No provider connections found.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($summary['connections'] as $connection) {
            $rows[] = [
                $connection['name'],
                $connection['status'],
                $connection['created'],
                $connection['updated'],
                $connection['unavailable'],
                $connection['unchanged'],
                $connection['error'] ?? implode("\n", $connection['warnings']),
            ];
        }
        $io->table(['Connection', 'Status', 'Created', 'Updated', 'Unavailable', 'Unchanged', 'Error / warnings'], $rows);

        $io->writeln(\sprintf(
            'Created %d, updated %d, unavailable %d, unchanged %d; %d connection(s) scanned, %d skipped, %d failed.',
            $summary['created'],
            $summary['updated'],
            $summary['unavailable'],
            $summary['unchanged'],
            $summary['scanned'],
            $summary['skipped'],
            $summary['failed'],
        ));

        foreach ($summary['connections'] as $connection) {
            if (null !== $connection['error'] && [] !== $connection['warnings']) {
                foreach ($connection['warnings'] as $warning) {
                    $io->warning(\sprintf('%s: %s', $connection['name'], $warning));
                }
            }
        }

        if ($summary['failed'] > 0) {
            $io->error(\sprintf('%d connection(s) failed.', $summary['failed']));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
