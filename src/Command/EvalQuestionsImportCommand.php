<?php

// file generated with AI assistance: Claude Code - 2026-10-03 19:07:47 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Command;

use Dmstr\NeuronAiBundle\Evaluation\EvalQuestionImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports evaluation questions from a JSON file, upserting by `key`
 * ({@see EvalQuestionImporter}). An unreadable or invalid file prints every
 * problem and exits with 1 without writing anything; a valid file prints
 * `created: N, updated: N, unchanged: N` and exits with 0.
 */
#[AsCommand(name: 'neuron:eval:questions:import', description: 'Import evaluation questions from a JSON file (upsert by key)')]
final class EvalQuestionsImportCommand extends Command
{
    public function __construct(
        private readonly EvalQuestionImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'JSON file with a list of questions')
            ->setHelp(<<<'HELP'
                The file contains a JSON list of question objects:

                  [{"key": "support.opening-hours", "question": "…", "expectedAnswer": "…",
                    "requestData": {…}, "status": "active", "markers": ["…"], "negative": false}]

                <comment>key</comment>, <comment>question</comment> and <comment>expectedAnswer</comment> are required; <comment>requestData</comment> (object or null,
                passed as the conversation variables of evaluation runs), <comment>status</comment> (active|archived),
                <comment>markers</comment> (null or a list of strings) and <comment>negative</comment> (boolean, not together
                with markers) are optional, other properties are an error. The API
                (POST/PATCH /api/eval_questions) applies the same rules to markers and negative.
                The whole file is validated first; any problem (reported with the zero-based
                item index) exits with 1 and writes nothing. Then questions are upserted by key
                in one transaction: missing ones are created, changed ones updated in place.
                A missing status means "active" for new questions and keeps the status of
                existing ones; questions missing from the file are left alone.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $file = (string) $input->getArgument('file');
        if (!is_file($file) || !is_readable($file)) {
            $io->error(\sprintf('The file "%s" does not exist or is not readable.', $file));

            return Command::FAILURE;
        }
        $json = file_get_contents($file);
        if (false === $json) {
            $io->error(\sprintf('The file "%s" could not be read.', $file));

            return Command::FAILURE;
        }

        $report = $this->importer->import($json);
        if (!$report->isValid()) {
            $io->error(\sprintf('The file "%s" is invalid; nothing was imported.', $file));
            $io->listing($report->problems);

            return Command::FAILURE;
        }

        $io->success((string) $report);

        return Command::SUCCESS;
    }
}
