<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Command;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\NeuronAiBundle\Provider\ProviderType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Idempotent seed of provider connections (`ollama`, `anthropic`), e.g.
 * from `make seed`.
 *
 * Input: one connection from the arguments (`name --type=… --config=JSON`)
 * and/or a seed file (`--file=…`), a JSON list of
 * `{"type": …, "name": …, "config": {…}}` (template
 * resources/provider-connections.dist.json in the application). `config`
 * holds the type's fields; the discriminators (`<type>: true`, `type`,
 * `endpoint_type`) are added. The single connection is seeded first, then
 * the file in order.
 *
 * Per entry, keyed by the name across both provider types (names are
 * unique there): a missing connection is created (active), an existing one
 * whose configJson differs is updated in place (its active flag is kept),
 * an existing one with an equal configJson is left alone ("unchanged", no
 * flush). The stored configJson is decrypted before the comparison, so an
 * encrypted `api_key` does not count as a change. An existing connection of
 * the other provider type is an error, not a type change. Every entity is
 * validated before it is written, so the connection schemas and the name
 * uniqueness apply exactly as through the API; secrets are written in
 * clear and encrypted by the `onFlush` listener of
 * dmstr/api-configuration-bundle.
 *
 * The command never contacts a provider and never prints a configuration
 * or a secret. A failing entry does not stop the others; the exit code is
 * 1 when any entry failed.
 *
 * Seeding never fills the model catalogue: the scan is the separate
 * command `neuron:provider-models:scan`, run by hand after seeding. The
 * option name `--scan` stays reserved; there is none today.
 */
#[AsCommand(name: 'neuron:provider:connection:seed', description: 'Create or update provider connections (ollama, anthropic) idempotently (no provider contact)')]
final class ProviderConnectionSeedCommand extends Command
{
    public const string RESULT_CREATED = 'created';
    public const string RESULT_UPDATED = 'updated';
    public const string RESULT_UNCHANGED = 'unchanged';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly ConfigSecrets $secrets,
    ) {
        parent::__construct();
    }

    /**
     * Builds the configJson of a provider connection: the given fields plus
     * the discriminators. Pure; schema rules are left to the validator.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException for a type that is no provider type
     */
    public static function buildConfig(string $type, array $config): array
    {
        if (!\in_array($type, ProviderType::CHAT_TYPES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown provider connection type "%s" (expected one of: %s).', $type, implode(', ', ProviderType::CHAT_TYPES)));
        }

        unset($config[$type], $config['type'], $config['endpoint_type']);

        return [$type => true, 'type' => $type, 'endpoint_type' => 'rest'] + $config;
    }

    /**
     * Parses the entries of a seed file. Messages name the entry index and
     * the problem, never a value.
     *
     * @return list<array{type: string, name: string, config: array<string, mixed>}>
     *
     * @throws \InvalidArgumentException for malformed JSON or entries
     */
    public static function parseEntries(string $json): array
    {
        try {
            $data = json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Seed file is not valid JSON: '.$e->getMessage().'.');
        }
        if (!\is_array($data) || !array_is_list($data)) {
            throw new \InvalidArgumentException('Seed file must contain a JSON list of {"type", "name", "config"} objects.');
        }

        $entries = [];
        foreach ($data as $index => $entry) {
            $entries[] = self::entry($entry, \sprintf('Entry %d', $index));
        }

        return $entries;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'Name of a single connection (unique across ollama and anthropic); needs --type')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Type of the single connection: ollama or anthropic')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Fields of the single connection as a JSON object, e.g. {"url": "http://ollama:11434/api"}', '{}')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Seed file: JSON list of {"type", "name", "config"} objects')
            ->setHelp(<<<'HELP'
                Creates provider connections or brings them in line with the given
                definitions. Running it again with the same input changes nothing, so it is
                safe in setup scripts. No provider is contacted; check a connection with
                <info>app:api-configuration:health</info>.

                  <info>%command.full_name% ollama-local --type=ollama --config='{"url": "http://ollama:11434/api"}'</info>
                  <info>%command.full_name% --file=resources/provider-connections.json</info>

                Keep API keys in the seed file (gitignored) rather than on the command line.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $entries = $this->entriesFrom($input);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ([] === $entries) {
            $io->error('Nothing to seed: give a connection name with --type, or --file.');

            return Command::FAILURE;
        }

        $failed = 0;
        foreach ($entries as $entry) {
            if (!$this->seed($entry, $io)) {
                ++$failed;
            }
        }

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return list<array{type: string, name: string, config: array<string, mixed>}>
     *
     * @throws \InvalidArgumentException
     */
    private function entriesFrom(InputInterface $input): array
    {
        $entries = [];

        $name = $input->getArgument('name');
        if (\is_string($name) && '' !== $name) {
            $config = json_decode((string) $input->getOption('config'), true);
            if (!\is_array($config) || ([] !== $config && array_is_list($config))) {
                throw new \InvalidArgumentException('--config must be a JSON object.');
            }
            $entries[] = self::entry(['type' => $input->getOption('type'), 'name' => $name, 'config' => $config], 'The connection');
        } elseif (null !== $input->getOption('type')) {
            throw new \InvalidArgumentException('--type needs a connection name.');
        }

        $file = $input->getOption('file');
        if (\is_string($file) && '' !== $file) {
            if (!is_file($file) || !is_readable($file)) {
                throw new \InvalidArgumentException(\sprintf('Seed file "%s" does not exist or is not readable.', $file));
            }
            $entries = [...$entries, ...self::parseEntries((string) file_get_contents($file))];
        }

        return $entries;
    }

    /**
     * @return array{type: string, name: string, config: array<string, mixed>}
     *
     * @throws \InvalidArgumentException
     */
    private static function entry(mixed $entry, string $label): array
    {
        if (!\is_array($entry)) {
            throw new \InvalidArgumentException(\sprintf('%s must be an object with "type", "name" and "config".', $label));
        }
        $type = $entry['type'] ?? null;
        if (!\is_string($type) || !\in_array($type, ProviderType::CHAT_TYPES, true)) {
            throw new \InvalidArgumentException(\sprintf('%s: "type" must be one of: %s.', $label, implode(', ', ProviderType::CHAT_TYPES)));
        }
        $name = $entry['name'] ?? null;
        if (!\is_string($name) || '' === trim($name)) {
            throw new \InvalidArgumentException(\sprintf('%s: "name" must be a non-empty string.', $label));
        }
        $config = $entry['config'] ?? [];
        if (!\is_array($config) || ([] !== $config && array_is_list($config))) {
            throw new \InvalidArgumentException(\sprintf('%s ("%s"): "config" must be an object.', $label, $name));
        }
        $unknown = array_diff(array_keys($entry), ['type', 'name', 'config']);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('%s ("%s"): unknown keys %s (expected "type", "name", "config").', $label, $name, implode(', ', $unknown)));
        }

        return ['type' => $type, 'name' => trim($name), 'config' => $config];
    }

    /**
     * Seeds one entry; reports the result and returns false on failure.
     *
     * @param array{type: string, name: string, config: array<string, mixed>} $entry
     */
    private function seed(array $entry, SymfonyStyle $io): bool
    {
        $name = $entry['name'];
        $config = self::buildConfig($entry['type'], $entry['config']);

        $existing = $this->entityManager->getRepository(ApiConfiguration::class)
            ->findBy(['type' => ProviderType::CHAT_TYPES, 'name' => $name]);

        if (\count($existing) > 1) {
            $io->error(\sprintf('%d provider connections are named "%s"; resolve the duplicates first.', \count($existing), $name));

            return false;
        }

        $connection = $existing[0] ?? null;

        if (null !== $connection && $connection->getType() !== $entry['type']) {
            $io->error(\sprintf('Provider connection "%s" exists with type "%s"; delete it or choose another name for the %s connection.', $name, $connection->getType(), $entry['type']));

            return false;
        }

        if (null !== $connection && McpConnectionSeedCommand::isSameConfig($this->secrets->decrypt($connection->getConfigJson()), $config)) {
            $io->writeln(\sprintf('Provider connection "%s" unchanged (%s).', $name, $connection->getId()));

            return true;
        }

        if (null === $connection) {
            $result = self::RESULT_CREATED;
            $connection = (new ApiConfiguration())
                ->setName($name)
                ->setConfigJson($config)
                ->setActive(true);
        } else {
            $result = self::RESULT_UPDATED;
            $connection->setConfigJson($config);
        }

        $violations = $this->validator->validate($connection);
        if (\count($violations) > 0) {
            if (self::RESULT_UPDATED === $result) {
                // Drop the rejected change from the managed entity, so a later
                // flush (next entry) cannot write it.
                $this->entityManager->refresh($connection);
            }

            // Violation messages name fields and rules, never values.
            $messages = [];
            foreach ($violations as $violation) {
                $path = $violation->getPropertyPath();
                $messages[] = ('' !== $path ? $path.': ' : '').$violation->getMessage();
            }
            $io->error(array_merge([\sprintf('Provider connection "%s" is invalid:', $name)], $messages));

            return false;
        }

        if (self::RESULT_CREATED === $result) {
            $this->entityManager->persist($connection);
        }
        $this->entityManager->flush();

        $io->success(\sprintf('Provider connection "%s" %s (%s).', $name, $result, $connection->getId()));

        return true;
    }
}
