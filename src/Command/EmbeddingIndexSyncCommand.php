<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:37:11 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Command;

use Dmstr\NeuronAiBundle\Embeddings\EmbeddingIndexManager;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates the missing per-model vector indexes of the store table for every
 * model in use: the current and the pending embedding model of all
 * collections. Existing indexes are left alone and nothing is ever dropped,
 * so the command is safe to run on every deploy (e.g. after restoring a
 * database dump without the runtime-created indexes).
 *
 * Exits non-zero when a collection uses a model that is not registered
 * (anymore); the indexes of the registered models are created anyway.
 */
#[AsCommand(name: 'neuron:embedding:index:sync', description: 'Create the missing vector indexes of the embedding models used by collections')]
final class EmbeddingIndexSyncCommand extends Command
{
    /**
     * The index manager is resolved on execute only: the store behind it
     * connects to the database when it is created, which would break
     * `--help` without a database.
     *
     * @param \Closure(): EmbeddingIndexManager $indexManager
     */
    public function __construct(
        private readonly \Closure $indexManager,
        private readonly EmbeddingModelRegistry $registry,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $models = $this->modelsInUse();
        if ([] === $models) {
            $io->note('No collections found; nothing to do.');

            return Command::SUCCESS;
        }

        $unknown = array_values(array_filter($models, fn (string $model): bool => !$this->registry->has($model)));
        $known = array_values(array_diff($models, $unknown));

        $indexManager = ($this->indexManager)();
        $result = $indexManager->ensureAll($known);

        $rows = [];
        foreach ($known as $model) {
            $rows[] = [
                $model,
                $indexManager->indexName($model),
                \in_array($model, $result['created'], true) ? 'created' : 'existing',
            ];
        }
        foreach ($unknown as $model) {
            $rows[] = [$model, '-', 'not registered'];
        }
        $io->table(['Model', 'Index', 'Status'], $rows);

        if ([] !== $unknown) {
            $io->error(\sprintf(
                'Collections use embedding models that are not registered: %s. Registered: %s.',
                implode(', ', $unknown),
                implode(', ', $this->registry->names()),
            ));

            return Command::FAILURE;
        }

        $io->success(\sprintf('%d index(es) created, %d already existed.', \count($result['created']), \count($result['existing'])));

        return Command::SUCCESS;
    }

    /**
     * Distinct current and pending models of all collections, sorted.
     *
     * @return list<string>
     */
    private function modelsInUse(): array
    {
        $current = $this->entityManager
            ->createQuery(\sprintf('SELECT DISTINCT c.embeddingModel FROM %s c', Collection::class))
            ->getSingleColumnResult();
        $pending = $this->entityManager
            ->createQuery(\sprintf('SELECT DISTINCT c.pendingEmbeddingModel FROM %s c WHERE c.pendingEmbeddingModel IS NOT NULL', Collection::class))
            ->getSingleColumnResult();

        $models = array_values(array_unique(array_map('strval', [...$current, ...$pending])));
        sort($models);

        return $models;
    }
}
