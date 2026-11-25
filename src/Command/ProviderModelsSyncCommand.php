<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Command;

use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\ProviderModelScanner;
use Dmstr\NeuronAiBundle\Provider\ScanInProgressException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Console twin of `POST /api/refs/provider_models/{id}/sync`: describes one
 * catalogue entry afresh from its provider connection. A model that is no
 * longer offered or cannot be described keeps its entry and gets
 * `lastError`; the command then exits non-zero, as it does
 * while a scan of the same connection is running.
 */
#[AsCommand(name: 'neuron:provider-models:sync', description: 'Describe one model catalogue entry afresh from its provider connection')]
final class ProviderModelsSyncCommand extends Command
{
    public function __construct(
        private readonly ProviderModelScanner $scanner,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'UUID of the catalogue entry (ProviderModel)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (string) $input->getArgument('id');

        $model = Uuid::isValid($id) ? $this->entityManager->find(ProviderModel::class, Uuid::fromString($id)) : null;
        if (!$model instanceof ProviderModel) {
            $io->error(\sprintf('Provider model "%s" not found.', $id));

            return Command::FAILURE;
        }

        try {
            $synced = $this->scanner->syncOne($model);
        } catch (ScanInProgressException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $capabilities = $model->getCapabilities();
        $io->definitionList(
            ['Model' => $model->getName()],
            ['Connection' => $model->getApiConfiguration()->getName()],
            ['Capabilities' => null === $capabilities ? 'unknown' : implode(', ', $capabilities)],
            ['Max context length' => (string) ($model->getMaxContextLength() ?? '-')],
            ['Default context length' => (string) ($model->getDefaultContextLength() ?? '-')],
            ['Stale' => $model->isStale() ? 'yes' : 'no'],
            ['Synced at' => $model->getSyncedAt()?->format(\DATE_ATOM) ?? '-'],
            ['Last error' => $model->getLastError() ?? '-'],
        );

        if (!$synced) {
            $io->error((string) $model->getLastError());

            return Command::FAILURE;
        }

        $io->success('Model refreshed.');

        return Command::SUCCESS;
    }
}

// - revised 2026-10-07
