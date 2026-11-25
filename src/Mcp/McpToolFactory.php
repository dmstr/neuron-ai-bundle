<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:58:11 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\NeuronAiBundle\Entity\Refs\McpTool;
use Dmstr\NeuronAiBundle\Security\CurrentJwtProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\Tools\ToolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Builds Neuron tools from the MCP tool catalogue ({@see McpTool} rows), for
 * the chat and for anything else that hands catalogue tools to an agent.
 *
 * Building performs no I/O: every connection gets one transport (from
 * {@see McpTransportFactoryInterface}, which must not connect) and one
 * {@see McpServerConnector}, shared by all tools of that connection. The
 * connector performs the MCP handshake on the first execution of one of its
 * tools and then sends `tools/call`; tools that are never called never reach
 * the server.
 *
 * The catalogue is a snapshot. Tools are built from the last known
 * definition, including rows whose last scan or sync failed (`lastError`
 * set): a tool that has meanwhile vanished or changed on the server surfaces
 * as an error when it is called, not when the tools are built. Rows whose
 * input schema cannot be expressed as Neuron tool properties are skipped
 * with a warning ({@see forTools()}) or rejected ({@see forToolsStrict()},
 * for an agent version that must never run with fewer tools than it
 * references). Tool names must be unique across the given connections,
 * because a provider cannot tell two tools of the same name apart.
 *
 * Secrets of the connection (`token`) are stored encrypted
 * (dmstr/api-configuration-bundle 0.5); the transport gets the configJson
 * decrypted through {@see ConfigSecrets::decrypt()}. Without $secrets the
 * configJson is passed as stored, which only suits connections without an
 * encrypted secret (unit tests that build the factory by hand).
 *
 * Connections with `auth_type: forward_jwt` call their server as the person
 * chatting: the JWT of the current request ({@see CurrentJwtProviderInterface})
 * is read once per build, while the tools are built (a streamed turn runs
 * its tools after the request has ended), and handed to the transport as
 * {@see McpTransportContext::turn()}. Fail-closed without a JWT (replay and
 * batch evaluation under the system actor, capability tokens, no JWT
 * provider): only the rows whose catalogue annotation `readOnlyHint` is
 * exactly `true` are built, they run with the connection's scan service
 * account, and the other rows of that connection are dropped with one
 * warning per connection. Dropping is not a build error, also not in
 * {@see forToolsStrict()}: the turn runs with fewer tools. Other auth types
 * get `McpTransportContext::turn(null)` and are unaffected.
 *
 *     $connections = $resolver->resolveMany(['docs', '/api/admin/api_configurations/<uuid>']);
 *     $agent->addTool($toolFactory->forConnections($connections));
 */
final class McpToolFactory
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly McpTransportFactoryInterface $transportFactory,
        ?LoggerInterface $logger = null,
        private readonly ?ConfigSecrets $secrets = null,
        private readonly ?CurrentJwtProviderInterface $jwtProvider = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * All catalogued tools of the given connections, per connection in name
     * order. The connections are expected to be resolved already (active,
     * type `mcp`, e.g. through {@see McpConnectionResolver}).
     *
     * @param iterable<ApiConfiguration> $connections
     *
     * @return list<ToolInterface>
     *
     * @throws UnprocessableEntityHttpException when two connections offer a tool of the same name
     */
    public function forConnections(iterable $connections): array
    {
        $rows = [];
        foreach ($connections as $connection) {
            array_push($rows, ...$this->entityManager->getRepository(McpTool::class)
                ->findBy(['apiConfiguration' => $connection], ['name' => 'ASC']));
        }

        return $this->forTools($rows);
    }

    /**
     * Tools for the given catalogue rows, in the given order. Rows of the same
     * connection share one connector.
     *
     * @param iterable<McpTool> $tools
     *
     * @return list<ToolInterface>
     *
     * @throws UnprocessableEntityHttpException when two rows carry the same tool name
     */
    public function forTools(iterable $tools): array
    {
        return $this->build($tools, false);
    }

    /**
     * Like {@see forTools()}, but a row whose input schema cannot be
     * expressed as Neuron tool properties is an error instead of being
     * skipped: the result holds exactly one tool per given row, in the given
     * order, except for the rows of `forward_jwt` connections dropped for a
     * missing JWT (see the class description), which are left out. Used for the tool subset of an agent version
     * ({@see \Dmstr\NeuronAiBundle\Agent\VersionTurnMapper}).
     *
     * @param iterable<McpTool> $tools
     *
     * @return list<ToolInterface>
     *
     * @throws UnprocessableEntityHttpException when two rows carry the same tool name
     * @throws \UnexpectedValueException        for a row that cannot be built; its code is the
     *                                          0-based position of that row in $tools, the
     *                                          previous exception the build error
     */
    public function forToolsStrict(iterable $tools): array
    {
        return $this->build($tools, true);
    }

    /**
     * @param iterable<McpTool> $tools
     *
     * @return list<ToolInterface>
     */
    private function build(iterable $tools, bool $strict): array
    {
        /** @var array<string, McpServerConnector> $connectors connection id => connector */
        $connectors = [];
        /** @var array<string, string> $offeredBy tool name => connection name */
        $offeredBy = [];
        $built = [];
        /** @var array<string, array{connection: string, tools: list<string>}> $dropped connection id => dropped rows */
        $dropped = [];
        // Read once, on the first row of a forward_jwt connection.
        $jwt = null;
        $jwtRead = false;

        $position = -1;
        foreach ($tools as $row) {
            ++$position;
            $connection = $row->getApiConfiguration();
            $name = $row->getName();
            $connectionId = (string) $connection->getId();

            $forwardJwt = StreamableHttpTransportFactory::AUTH_FORWARD_JWT === ($connection->getConfigJson()['auth_type'] ?? null);
            if ($forwardJwt && !$jwtRead) {
                $jwt = $this->jwtProvider?->currentJwt();
                $jwt = '' === $jwt ? null : $jwt;
                $jwtRead = true;
            }
            if ($forwardJwt && null === $jwt && true !== ($row->getAnnotations()['readOnlyHint'] ?? null)) {
                // Fail-closed: without the person's JWT only read-only
                // tools run, with the scan service account.
                $dropped[$connectionId]['connection'] = $connection->getName();
                $dropped[$connectionId]['tools'][] = $name;
                continue;
            }

            if (isset($offeredBy[$name])) {
                throw new UnprocessableEntityHttpException(\sprintf('MCP tool "%s" is offered by both "%s" and "%s"; use only one of these connections.', $name, $offeredBy[$name], $connection->getName()));
            }

            $connector = $connectors[$connectionId] ??= McpServerConnector::forTransport(
                $this->transportFactory->create(
                    $this->secrets?->decrypt($connection->getConfigJson()) ?? $connection->getConfigJson(),
                    McpTransportContext::turn($forwardJwt ? $jwt : null),
                ),
            );

            try {
                $built[] = $connector->createCatalogueTool($name, $row->getDescription(), $row->getInputSchema() ?? [], $row->getAnnotations() ?? []);
            } catch (\Exception|\ValueError $e) {
                // ToolException (neuron-ai 4.x: strict schemas, e.g. a type
                // list, `$ref`, nested `anyOf`), ArrayPropertyException,
                // ValueError from unsupported property types.
                if ($strict) {
                    throw new \UnexpectedValueException(\sprintf('MCP tool "%s" of connection "%s" cannot be built: %s', $name, $connection->getName(), $e->getMessage()), $position, $e);
                }
                $this->logger->warning('Skipping MCP tool "{tool}" of connection "{connection}": {error}', [
                    'tool' => $name,
                    'connection' => $connection->getName(),
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $offeredBy[$name] = $connection->getName();
        }

        foreach ($dropped as $entry) {
            $this->logger->warning('MCP connection "{connection}" forwards the JWT of the person chatting, but the turn has none; tools without readOnlyHint dropped: {tools}', [
                'connection' => $entry['connection'],
                'tools' => implode(', ', $entry['tools']),
            ]);
        }

        return $built;
    }
}

// - revised 2026-10-09 06:42:28 UTC
// - revised 2026-10-09 13:43:23 UTC
// - revised 2026-10-14
