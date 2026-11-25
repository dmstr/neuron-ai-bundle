<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:14:26 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Command;

use Dmstr\NeuronAiBundle\Anonymous\AnonymousConversationPurger;
use Dmstr\NeuronAiBundle\Anonymous\PurgeAnonymousConversationsMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Runs the purge of anonymous conversations once, outside the `neuron`
 * schedule: closes those past their retention and deletes those past
 * their deletion delay ({@see AnonymousConversationPurger}). With `--async`
 * the {@see PurgeAnonymousConversationsMessage} is dispatched on the
 * message bus instead, so a worker runs it (routed to the transport the
 * application chose, e.g. `maintenance`).
 */
#[AsCommand(name: 'neuron:anonymous:purge', description: 'Close anonymous conversations past their retention and delete those past their deletion delay')]
final class AnonymousPurgeCommand extends Command
{
    public function __construct(
        private readonly AnonymousConversationPurger $purger,
        private readonly ?MessageBusInterface $messageBus = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('async', null, InputOption::VALUE_NONE, 'Dispatch the purge message on the message bus instead of running the purge here');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (true === $input->getOption('async')) {
            if (null === $this->messageBus) {
                $io->error('No message bus is available; run the command without --async.');

                return Command::FAILURE;
            }
            $this->messageBus->dispatch(new PurgeAnonymousConversationsMessage());
            $io->success('Purge message dispatched.');

            return Command::SUCCESS;
        }

        $report = $this->purger->run();
        if (!$report->ran) {
            $io->warning('Nothing purged: the application supplies no anonymous access policy.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf('Anonymous conversations: %s.', $report));

        return Command::SUCCESS;
    }
}
