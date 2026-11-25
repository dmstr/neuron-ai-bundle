<?php

// file generated with AI assistance: Claude Code - 2026-10-13 23:25:27 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Command;

use Dmstr\NeuronAiBundle\Agent\Preview\AgentPreviewPurger;
use Dmstr\NeuronAiBundle\Agent\Preview\PurgeAgentPreviewsMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Runs the purge of agent preview versions once, outside the `neuron`
 * schedule ({@see AgentPreviewPurger}): previews older than the retention
 * are deleted with their evaluation runs and conversations, unless a skip
 * rule keeps them. `--dry-run` reports what would be deleted. With
 * `--async` the {@see PurgeAgentPreviewsMessage} is dispatched on the
 * message bus instead, so a worker runs it (on the transport the
 * application routes it to; without a routing it is handled right away).
 */
#[AsCommand(name: 'neuron:agent:previews:purge', description: 'Delete agent preview versions past their retention, with their evaluation runs and conversations')]
final class AgentPreviewsPurgeCommand extends Command
{
    public function __construct(
        private readonly AgentPreviewPurger $purger,
        private readonly ?MessageBusInterface $messageBus = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted without deleting anything')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Dispatch the purge message on the message bus instead of running the purge here');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = true === $input->getOption('dry-run');

        if (true === $input->getOption('async')) {
            if ($dryRun) {
                $io->error('--dry-run cannot be combined with --async: the dispatched purge always deletes.');

                return Command::INVALID;
            }
            if (null === $this->messageBus) {
                $io->error('No message bus is available; run the command without --async.');

                return Command::FAILURE;
            }
            $this->messageBus->dispatch(new PurgeAgentPreviewsMessage());
            $io->success('Purge message dispatched.');

            return Command::SUCCESS;
        }

        $report = $this->purger->run($dryRun);
        if (!$report->ran) {
            $io->warning('Nothing purged: the application supplies no agent preview retention.');

            return Command::SUCCESS;
        }
        if (0 === $report->retentionDays) {
            $io->success('Nothing purged: the retention of agent previews is 0 (keep forever).');

            return Command::SUCCESS;
        }

        $io->success(\sprintf('Agent previews (retention %d days): %s.', $report->retentionDays, $report));

        return Command::SUCCESS;
    }
}
