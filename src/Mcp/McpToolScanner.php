<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:41:04 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\NeuronAiBundle\Entity\Refs\McpTool;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mirrors the tools of `mcp` connections into the catalogue
 * ({@see McpTool}, table `neuron_mcp_tool`).
 *
 * Scan (per connection): `tools/list` through a fresh transport from
 * {@see McpTransportFactoryInterface}; tools missing in the catalogue are
 * created, entries with a changed title, description, input schema,
 * annotations or `_meta` updated, entries the server no longer lists
 * removed. Every listed entry gets
 * `syncedAt = now` and `lastError = null`. Inactive connections are skipped
 * without any transport call. When the server cannot be listed, the
 * connection's existing entries are kept and get `lastError`; the failure is
 * reported in the summary and does not stop the scan of other connections.
 *
 * Sync (one entry): the tool is looked up in `tools/list` of
 * its connection. Still listed: title, description, input schema,
 * annotations and `_meta` are refreshed, `lastError` cleared, `syncedAt`
 * set. Vanished, transport failure or inactive connection: the entry is
 * kept, `lastError` set, `syncedAt` left unchanged. Sync never creates or
 * removes entries.
 *
 * Scan summary (JSON-serialisable, also stored as the job result):
 *
 *     [
 *         'created' => int, 'updated' => int, 'removed' => int, 'unchanged' => int,
 *         'scanned' => int, 'skipped' => int, 'failed' => int,
 *         'connections' => list<ConnectionSummary>,
 *     ]
 *
 * ConnectionSummary: `id`, `name`, `status` (`ok`, `skipped` or `error`),
 * `created`, `updated`, `removed`, `unchanged`, `error` (message or null) and
 * `warnings` (list of messages, e.g. tool names that do not fit the column).
 */
final class McpToolScanner
{
    public const string STATUS_OK = 'ok';
    public const string STATUS_SKIPPED = 'skipped';
    public const string STATUS_ERROR = 'error';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly McpTransportFactoryInterface $transportFactory,
        private readonly ConfigSecrets $secrets,
    ) {
    }

    /**
     * Scans every `mcp` connection; inactive ones appear as `skipped`.
     *
     * @return array<string, mixed> scan summary, see the class docblock
     */
    public function scanAll(): array
    {
        $connections = $this->entityManager->getRepository(ApiConfiguration::class)
            ->findBy(['type' => McpExtension::NAME], ['name' => 'ASC']);

        return $this->scan($connections);
    }

    /**
     * Scans the given connections (e.g. one resolved by McpConnectionResolver).
     *
     * @param iterable<ApiConfiguration> $connections
     *
     * @return array<string, mixed> scan summary, see the class docblock
     */
    public function scan(iterable $connections): array
    {
        $summary = [
            'created' => 0,
            'updated' => 0,
            'removed' => 0,
            'unchanged' => 0,
            'scanned' => 0,
            'skipped' => 0,
            'failed' => 0,
            'connections' => [],
        ];

        foreach ($connections as $connection) {
            $result = $this->scanConnection($connection);
            $summary['connections'][] = $result;

            match ($result['status']) {
                self::STATUS_SKIPPED => ++$summary['skipped'],
                self::STATUS_ERROR => ++$summary['failed'],
                default => ++$summary['scanned'],
            };
            foreach (['created', 'updated', 'removed', 'unchanged'] as $counter) {
                $summary[$counter] += $result[$counter];
            }
        }

        return $summary;
    }

    /**
     * Scans one connection and flushes its changes.
     *
     * @return array<string, mixed> connection summary, see the class docblock
     */
    public function scanConnection(ApiConfiguration $connection): array
    {
        $result = [
            'id' => (string) $connection->getId(),
            'name' => $connection->getName(),
            'status' => self::STATUS_OK,
            'created' => 0,
            'updated' => 0,
            'removed' => 0,
            'unchanged' => 0,
            'error' => null,
            'warnings' => [],
        ];

        if (!self::isMcp($connection)) {
            $result['status'] = self::STATUS_ERROR;
            $result['error'] = \sprintf('Connection "%s" is not an MCP connection.', $connection->getName());

            return $result;
        }

        if (!$connection->isActive()) {
            $result['status'] = self::STATUS_SKIPPED;

            return $result;
        }

        $existing = [];
        foreach ($this->toolsOf($connection) as $tool) {
            $existing[$tool->getName()] = $tool;
        }

        try {
            $definitions = $this->listTools($connection);
        } catch (\Exception $e) {
            $message = self::errorMessage($e);
            foreach ($existing as $tool) {
                $tool->setLastError($message);
            }
            $this->entityManager->flush();

            $result['status'] = self::STATUS_ERROR;
            $result['error'] = $message;

            return $result;
        }

        $now = new \DateTimeImmutable();
        $seen = [];
        foreach ($definitions as $definition) {
            $name = $definition['name'];
            if (isset($seen[$name])) {
                $result['warnings'][] = \sprintf('Tool "%s" is listed more than once; the first definition is used.', $name);
                continue;
            }
            if (mb_strlen($name) > McpTool::MAX_NAME_LENGTH) {
                $result['warnings'][] = \sprintf('Tool name "%s…" exceeds %d characters and is ignored.', mb_substr($name, 0, 40), McpTool::MAX_NAME_LENGTH);
                continue;
            }
            $seen[$name] = true;

            $tool = $existing[$name] ?? null;
            if (null === $tool) {
                $tool = new McpTool($connection, $name);
                self::apply($tool, $definition);
                $this->entityManager->persist($tool);
                ++$result['created'];
            } elseif (self::apply($tool, $definition)) {
                ++$result['updated'];
            } else {
                ++$result['unchanged'];
            }

            $tool->setSyncedAt($now);
            $tool->setLastError(null);
        }

        foreach ($existing as $name => $tool) {
            if (!isset($seen[$name])) {
                $this->entityManager->remove($tool);
                ++$result['removed'];
            }
        }

        $this->entityManager->flush();

        return $result;
    }

    /**
     * Refreshes one entry from its server and flushes it.
     *
     * @return bool true when the server still lists the tool and the entry
     *              was refreshed; false when `lastError` was set instead
     */
    public function syncOne(McpTool $tool): bool
    {
        $connection = $tool->getApiConfiguration();

        if (!$connection->isActive()) {
            return $this->fail($tool, \sprintf('MCP connection "%s" is inactive.', $connection->getName()));
        }

        try {
            $definitions = $this->listTools($connection);
        } catch (\Exception $e) {
            return $this->fail($tool, self::errorMessage($e));
        }

        foreach ($definitions as $definition) {
            if ($definition['name'] === $tool->getName()) {
                self::apply($tool, $definition);
                $tool->setSyncedAt(new \DateTimeImmutable());
                $tool->setLastError(null);
                $this->entityManager->flush();

                return true;
            }
        }

        return $this->fail($tool, \sprintf('Tool "%s" is no longer listed by MCP connection "%s".', $tool->getName(), $connection->getName()));
    }

    /**
     * @return list<array<string, mixed>> raw definitions, each with a non-empty string `name`
     *
     * @throws \Exception on configuration, transport, authentication or protocol failure
     */
    private function listTools(ApiConfiguration $connection): array
    {
        // Secrets are stored encrypted; the transport needs the clear values.
        $connector = McpServerConnector::forTransport($this->transportFactory->create($this->secrets->decrypt($connection->getConfigJson()), McpTransportContext::scan()));

        $definitions = [];
        foreach ($connector->listTools() as $definition) {
            $name = $definition['name'] ?? null;
            if (\is_string($name) && '' !== $name) {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * @return list<McpTool>
     */
    private function toolsOf(ApiConfiguration $connection): array
    {
        return array_values($this->entityManager->getRepository(McpTool::class)
            ->findBy(['apiConfiguration' => $connection], ['name' => 'ASC']));
    }

    private function fail(McpTool $tool, string $message): bool
    {
        $tool->setLastError($message);
        $this->entityManager->flush();

        return false;
    }

    /**
     * Copies title, description, input schema, annotations and `_meta` from
     * a definition. Neuron's McpClient returns the definitions of
     * `tools/list` unchanged, so all MCP fields are available here. A title
     * longer than McpTool::MAX_TITLE_LENGTH is cut; annotations and `_meta`
     * that are no JSON object, or an empty one, are stored as null.
     *
     * @param array<string, mixed> $definition
     *
     * @return bool whether anything changed
     */
    private static function apply(McpTool $tool, array $definition): bool
    {
        $title = $definition['title'] ?? null;
        $title = \is_string($title) && '' !== trim($title) ? mb_substr($title, 0, McpTool::MAX_TITLE_LENGTH) : null;
        $description = $definition['description'] ?? null;
        $description = \is_string($description) && '' !== $description ? $description : null;
        $inputSchema = \is_array($definition['inputSchema'] ?? null) ? $definition['inputSchema'] : null;
        $annotations = self::jsonObject($definition['annotations'] ?? null);
        $meta = self::jsonObject($definition['_meta'] ?? null);

        $changed = $tool->getTitle() !== $title
            || $tool->getDescription() !== $description
            || self::canonical($tool->getInputSchema()) !== self::canonical($inputSchema)
            || self::canonical($tool->getAnnotations()) !== self::canonical($annotations)
            || self::canonical($tool->getMeta()) !== self::canonical($meta);

        $tool->setTitle($title);
        $tool->setDescription($description);
        $tool->setInputSchema($inputSchema);
        $tool->setAnnotations($annotations);
        $tool->setMeta($meta);

        return $changed;
    }

    /**
     * A non-empty JSON object as decoded by the transport (associative
     * array, or stdClass from a fake), otherwise null.
     *
     * @return array<string, mixed>|null
     */
    private static function jsonObject(mixed $value): ?array
    {
        if ($value instanceof \stdClass) {
            $value = json_decode((string) json_encode($value), true);
        }
        if (!\is_array($value) || [] === $value || array_is_list($value)) {
            return null;
        }

        return $value;
    }

    /**
     * Key-order independent representation of a JSON value, so a server that
     * reorders object keys does not count as a change.
     */
    private static function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }

    private static function isMcp(ApiConfiguration $connection): bool
    {
        return McpExtension::NAME === ($connection->getConfigJson()['type'] ?? null);
    }

    private static function errorMessage(\Exception $e): string
    {
        $message = trim($e->getMessage());

        return '' !== $message ? $message : $e::class;
    }
}

// - revised 2026-10-09 06:42:28 UTC
// - revised 2026-10-09 13:43:23 UTC
