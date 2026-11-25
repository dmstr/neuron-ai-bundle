<?php

// file generated with AI assistance: Claude Code - 2026-09-30 22:07:50 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Command;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\NeuronAiBundle\Mcp\McpExtension;
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
 * Idempotent seed of one `mcp` connection, e.g. from `make setup`.
 *
 * The connection is keyed by its name within `type = 'mcp'`. The command
 * builds the full configJson (discriminators included) from the arguments:
 * a missing connection is created (active), an existing one whose configJson
 * differs is updated in place (its active flag is kept), and an existing one
 * with an equal configJson is left alone (no flush, `updatedAt` unchanged).
 * The entity is validated with the validator before anything is written, so
 * the unified connection schema and the name uniqueness of `mcp` connections
 * apply exactly as through the API. The command never contacts the server.
 *
 * The token, the client secret and the scan client secret are written in clear; the `onFlush`
 * listener of dmstr/api-configuration-bundle encrypts them. The stored configJson is
 * decrypted before the comparison, so an unchanged definition stays
 * "unchanged" although the database holds a ciphertext.
 */
#[AsCommand(name: 'neuron:mcp:connection:seed', description: 'Create or update an mcp connection idempotently (no server contact)')]
final class McpConnectionSeedCommand extends Command
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
     * Builds the configJson of an `mcp` connection. Pure; schema rules (URL
     * format, token required for bearer and header, client credentials
     * required for client_credentials, enum values, transport, restricted
     * forced for client_credentials) are left to the validator so the seed
     * and the API report the same violations. Only option combinations the
     * schema would silently accept but the seed cannot mean are rejected
     * here.
     *
     * `restricted: true` is written only with $restricted; without it the
     * key is omitted (= not restricted, except for client_credentials, see
     * {@see \Dmstr\NeuronAiBundle\Mcp\McpConnectionRestriction}; optional
     * for forward_jwt).
     *
     * The `scan_*` values are the scan service account of `forward_jwt`
     * (catalogue scan, sync, health and turns without a person's JWT).
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException for a token, header name or client
     *                                   credential (client or scan) that
     *                                   the chosen authentication would
     *                                   ignore
     */
    public static function buildConfig(
        string $url,
        string $auth = 'none',
        ?string $token = null,
        ?string $headerName = null,
        string $transport = 'streamable-http',
        ?int $timeout = null,
        ?string $tokenUrl = null,
        ?string $clientId = null,
        #[\SensitiveParameter]
        ?string $clientSecret = null,
        ?string $scope = null,
        bool $restricted = false,
        ?string $scanTokenUrl = null,
        ?string $scanClientId = null,
        #[\SensitiveParameter]
        ?string $scanClientSecret = null,
        ?string $scanScope = null,
    ): array {
        $token = '' === $token ? null : $token;
        $headerName = '' === $headerName ? null : $headerName;
        $clientCredentials = [
            'token_url' => '' === $tokenUrl ? null : $tokenUrl,
            'client_id' => '' === $clientId ? null : $clientId,
            'client_secret' => '' === $clientSecret ? null : $clientSecret,
            'scope' => '' === $scope ? null : $scope,
        ];
        $scanCredentials = [
            'scan_token_url' => '' === $scanTokenUrl ? null : $scanTokenUrl,
            'scan_client_id' => '' === $scanClientId ? null : $scanClientId,
            'scan_client_secret' => '' === $scanClientSecret ? null : $scanClientSecret,
            'scan_scope' => '' === $scanScope ? null : $scanScope,
        ];

        if (null !== $token && !\in_array($auth, ['bearer', 'header'], true)) {
            throw new \InvalidArgumentException('--token requires --auth=bearer or --auth=header.');
        }
        if (null !== $headerName && 'header' !== $auth) {
            throw new \InvalidArgumentException('--header-name requires --auth=header.');
        }
        foreach ($clientCredentials as $key => $value) {
            if (null !== $value && 'client_credentials' !== $auth) {
                throw new \InvalidArgumentException(\sprintf('--%s requires --auth=client_credentials.', str_replace('_', '-', $key)));
            }
        }
        foreach ($scanCredentials as $key => $value) {
            if (null !== $value && 'forward_jwt' !== $auth) {
                throw new \InvalidArgumentException(\sprintf('--%s requires --auth=forward_jwt.', str_replace('_', '-', $key)));
            }
        }

        $config = [
            McpExtension::NAME => true,
            'type' => McpExtension::NAME,
            'endpoint_type' => 'rest',
            'url' => $url,
            'transport' => $transport,
            'auth_type' => $auth,
        ];
        if (null !== $headerName) {
            $config['header_name'] = $headerName;
        }
        if (null !== $token) {
            $config['token'] = $token;
        }
        foreach ($clientCredentials + $scanCredentials as $key => $value) {
            if (null !== $value) {
                $config[$key] = $value;
            }
        }
        if (null !== $timeout) {
            $config['timeout'] = $timeout;
        }
        if ($restricted) {
            $config['restricted'] = true;
        }

        return $config;
    }

    /**
     * Whether a stored configJson equals the desired one. Key order does not
     * count (the database may return keys in another order), values are
     * compared strictly. Pass the stored configJson decrypted
     * ({@see ConfigSecrets::decrypt()}): a ciphertext never equals the clear
     * value.
     *
     * @param array<mixed> $stored
     * @param array<mixed> $desired
     */
    public static function isSameConfig(array $stored, array $desired): bool
    {
        return self::normalize($stored) === self::normalize($desired);
    }

    /**
     * Parses the --timeout option: null when omitted, a positive-looking
     * integer otherwise (range checks are the schema's job).
     *
     * @throws \InvalidArgumentException for a non-integer value
     */
    public static function parseTimeout(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (\is_int($value)) {
            return $value;
        }
        if (!\is_string($value) || 1 !== preg_match('/^-?\d+$/', $value)) {
            throw new \InvalidArgumentException('--timeout must be an integer number of seconds.');
        }

        return (int) $value;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Connection name (unique among mcp connections)')
            ->addArgument('url', InputArgument::REQUIRED, 'MCP endpoint, e.g. https://mcp.example.org/mcp')
            ->addOption('auth', null, InputOption::VALUE_REQUIRED, 'Authentication: none, bearer, header, client_credentials or forward_jwt', 'none')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Bearer token or custom header value (--auth=bearer|header)')
            ->addOption('header-name', null, InputOption::VALUE_REQUIRED, 'Name of the custom authentication header (--auth=header)')
            ->addOption('token-url', null, InputOption::VALUE_REQUIRED, 'OAuth2 token endpoint (--auth=client_credentials)')
            ->addOption('client-id', null, InputOption::VALUE_REQUIRED, 'OAuth2 client id of the service account (--auth=client_credentials)')
            ->addOption('client-secret', null, InputOption::VALUE_REQUIRED, 'OAuth2 client secret (--auth=client_credentials)')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Optional space-separated OAuth2 scopes (--auth=client_credentials)')
            ->addOption('scan-token-url', null, InputOption::VALUE_REQUIRED, 'OAuth2 token endpoint of the scan service account (--auth=forward_jwt)')
            ->addOption('scan-client-id', null, InputOption::VALUE_REQUIRED, 'OAuth2 client id of the scan service account (--auth=forward_jwt)')
            ->addOption('scan-client-secret', null, InputOption::VALUE_REQUIRED, 'OAuth2 client secret of the scan service account (--auth=forward_jwt)')
            ->addOption('scan-scope', null, InputOption::VALUE_REQUIRED, 'Optional space-separated OAuth2 scopes of the scan service account (--auth=forward_jwt)')
            ->addOption('restricted', null, InputOption::VALUE_NONE, 'Usable only through agent versions or by a caller with neuronai:mcp_connection:use_restricted; implied by --auth=client_credentials, optional for --auth=forward_jwt')
            ->addOption('transport', null, InputOption::VALUE_REQUIRED, 'MCP transport; only streamable-http is supported', 'streamable-http')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'HTTP timeout per request in seconds (1 to 300, default 30)')
            ->setHelp(<<<'HELP'
                Creates the mcp connection <info>name</info> or brings it in line with the given
                definition. Running it again with the same arguments changes nothing, so
                it is safe in setup scripts. The server is not contacted; check it with
                <info>app:api-configuration:health</info> and mirror its tools with
                <info>neuron:mcp-tools:scan --connection=NAME</info>.

                  <info>%command.full_name% docs https://mcp.example.org/mcp</info>
                  <info>%command.full_name% docs https://mcp.example.org/mcp --auth=bearer --token=secret</info>
                  <info>%command.full_name% docs https://mcp.example.org/mcp --auth=header --header-name=X-Api-Key --token=secret</info>
                  <info>%command.full_name% flowable https://flowable.example.org/mcp --auth=client_credentials --token-url=https://keycloak.example.org/realms/example/protocol/openid-connect/token --client-id=mcp-agent --client-secret=secret --restricted</info>
                  <info>%command.full_name% flowable https://flowable.example.org/mcp --auth=forward_jwt --scan-token-url=https://keycloak.example.org/realms/example/protocol/openid-connect/token --scan-client-id=mcp-scan --scan-client-secret=secret</info>

                A connection with <info>--auth=client_credentials</info> is always restricted;
                pass <info>--restricted</info> to store the flag explicitly.

                A connection with <info>--auth=forward_jwt</info> sends the JWT of the person
                chatting in turns and uses the <info>--scan-*</info> service account for scan,
                sync, health and turns without a JWT (only read-only tools then);
                <info>--restricted</info> is optional.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) $input->getArgument('name');

        try {
            $config = self::buildConfig(
                (string) $input->getArgument('url'),
                (string) $input->getOption('auth'),
                self::stringOption($input, 'token'),
                self::stringOption($input, 'header-name'),
                (string) $input->getOption('transport'),
                self::parseTimeout($input->getOption('timeout')),
                self::stringOption($input, 'token-url'),
                self::stringOption($input, 'client-id'),
                self::stringOption($input, 'client-secret'),
                self::stringOption($input, 'scope'),
                true === $input->getOption('restricted'),
                self::stringOption($input, 'scan-token-url'),
                self::stringOption($input, 'scan-client-id'),
                self::stringOption($input, 'scan-client-secret'),
                self::stringOption($input, 'scan-scope'),
            );
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $existing = $this->entityManager->getRepository(ApiConfiguration::class)
            ->findBy(['type' => McpExtension::NAME, 'name' => $name]);

        if (\count($existing) > 1) {
            $io->error(\sprintf('%d mcp connections are named "%s"; resolve the duplicates first.', \count($existing), $name));

            return Command::FAILURE;
        }

        $connection = $existing[0] ?? null;

        if (null !== $connection && self::isSameConfig($this->secrets->decrypt($connection->getConfigJson()), $config)) {
            $io->writeln(\sprintf('MCP connection "%s" unchanged (%s).', $name, $connection->getId()));

            return Command::SUCCESS;
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
                // flush in the same process cannot write it.
                $this->entityManager->refresh($connection);
            }

            $messages = [];
            foreach ($violations as $violation) {
                $path = $violation->getPropertyPath();
                $messages[] = ('' !== $path ? $path.': ' : '').$violation->getMessage();
            }
            $io->error(array_merge([\sprintf('MCP connection "%s" is invalid:', $name)], $messages));

            return Command::FAILURE;
        }

        if (self::RESULT_CREATED === $result) {
            $this->entityManager->persist($connection);
        }
        $this->entityManager->flush();

        $io->success(\sprintf('MCP connection "%s" %s (%s).', $name, $result, $connection->getId()));

        return Command::SUCCESS;
    }

    private static function stringOption(InputInterface $input, string $option): ?string
    {
        $value = $input->getOption($option);

        return \is_string($value) ? $value : null;
    }

    private static function normalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::normalize(...), $value);
    }
}

// - revised 2026-10-09 06:42:28 UTC
// - revised 2026-10-09 13:43:23 UTC
