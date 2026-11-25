<?php

// file generated with AI assistance: Claude Code - 2026-10-05 13:30:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp;

use Dmstr\ApiConfiguration\ApiClient\AuthenticationFailedException;
use Dmstr\ApiConfiguration\ApiClient\RestApiClientInterface;
use Dmstr\NeuronAiBundle\Provider\Auth\ProviderAuthenticationException;
use NeuronAI\Exceptions\HttpException;

/**
 * Thin ApiClientInterface adapter for `mcp` connections: it exists for the
 * platform's health check only, the domain methods (projects, todos, ...) are
 * no-ops.
 *
 * getHealthInfo() performs the MCP handshake plus `tools/list` through a
 * fresh transport from {@see McpTransportFactoryInterface} and never throws:
 *  - success: `status: ok`, `authenticated: true`, `metadata.custom` with
 *    `toolCount`, `protocolVersion`, `serverName`, `serverVersion`;
 *  - failure: `status: error` with `error.code` UNAUTHENTICATED (HTTP 401
 *    or 403, a token endpoint that refused the client, a JSON-RPC error
 *    whose message says unauthorized or forbidden; `authenticated: false`),
 *    PROTOCOL (the server answered, but not with usable JSON-RPC) or
 *    UNREACHABLE (everything else: network, TLS, HTTP 5xx, unusable
 *    configuration), plus `message`.
 *
 * The classification reads exception types and structured data only, never
 * exception messages (neuron-ai 4.x): the HTTP status from Neuron's
 * HttpException chained to the vendor's McpException, the status of a
 * {@see ProviderAuthenticationException}, and the JSON-RPC error a
 * {@see McpProtocolException} carries.
 *
 * Construction performs no I/O; the platform's health provider classifies
 * any exception thrown while creating the client as HEALTH_CHECK_ERROR, so
 * the probe belongs in getHealthInfo().
 */
final class McpHealthClient implements RestApiClientInterface
{
    public const string CODE_UNREACHABLE = 'UNREACHABLE';
    public const string CODE_UNAUTHENTICATED = 'UNAUTHENTICATED';
    public const string CODE_PROTOCOL = 'PROTOCOL';

    /** HTTP statuses of a rejected credential. */
    private const array AUTH_STATUSES = [401, 403];

    /** Words of a JSON-RPC error message (sent by the server) that reject the credentials. */
    private const array RPC_AUTH_WORDS = ['unauthorized', 'unauthorised', 'forbidden', 'unauthenticated'];

    private readonly string $url;

    /**
     * @param array<string, mixed> $config the connection's configJson
     *
     * @throws \InvalidArgumentException when the configuration has no URL
     */
    public function __construct(
        private readonly array $config,
        private readonly McpTransportFactoryInterface $transportFactory,
    ) {
        $url = $config['url'] ?? null;
        if (!\is_string($url) || '' === $url) {
            throw new \InvalidArgumentException('MCP connection configuration has no "url".');
        }
        $this->url = $url;
    }

    public function authenticate(): void
    {
        $health = $this->probe();
        if ('ok' !== $health['status']) {
            throw new AuthenticationFailedException($health['message'] ?? 'MCP server probe failed.');
        }
    }

    public function getType(): string
    {
        return 'rest';
    }

    public function getApiName(): string
    {
        return McpExtension::NAME;
    }

    /**
     * @return array<string, mixed> raw health data for the platform's HealthNormalizer
     */
    public function getHealthInfo(): array
    {
        return $this->probe();
    }

    public function getEndpoint(): string
    {
        return $this->url;
    }

    public function getProjects(): array
    {
        return [];
    }

    public function getTodos(string $projectId): array
    {
        return [];
    }

    public function getTodo(string $projectId, string $todoId): ?array
    {
        return null;
    }

    /**
     * Maps a probe failure to UNAUTHENTICATED, PROTOCOL or UNREACHABLE. A
     * token endpoint (`auth_type: client_credentials`) that answered but
     * refused the client counts as UNAUTHENTICATED, one that could not be
     * reached as UNREACHABLE. An HTTP 401 or 403 anywhere in the chain of
     * previous exceptions (the vendor transport chains Neuron's
     * HttpException) counts as UNAUTHENTICATED, as does a JSON-RPC error
     * whose message names a rejected credential. A body that could not be
     * decoded, a malformed JSON-RPC answer and other JSON-RPC errors are
     * PROTOCOL ({@see McpProtocolGuard} raises them as
     * {@see McpProtocolException}).
     */
    public static function classify(\Throwable $e): string
    {
        if ($e instanceof ProviderAuthenticationException && null !== $e->statusCode) {
            return self::CODE_UNAUTHENTICATED;
        }
        for ($current = $e; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof HttpException && \in_array($current->response?->statusCode, self::AUTH_STATUSES, true)) {
                return self::CODE_UNAUTHENTICATED;
            }
            if ($current instanceof McpProtocolException && self::rejectsCredentials($current->rpcMessage)) {
                return self::CODE_UNAUTHENTICATED;
            }
        }

        if ($e instanceof McpProtocolException || $e instanceof \JsonException) {
            return self::CODE_PROTOCOL;
        }

        return self::CODE_UNREACHABLE;
    }

    private static function rejectsCredentials(?string $rpcMessage): bool
    {
        foreach (self::RPC_AUTH_WORDS as $word) {
            if (null !== $rpcMessage && false !== stripos($rpcMessage, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function probe(): array
    {
        try {
            $connector = McpServerConnector::forTransport($this->transportFactory->create($this->config, McpTransportContext::health()));
            $tools = $connector->listTools();
            $initialize = $connector->getInitializeResult();
        } catch (\Throwable $e) {
            return $this->failure($e);
        }

        $serverInfo = \is_array($initialize['serverInfo'] ?? null) ? $initialize['serverInfo'] : [];

        return [
            'status' => 'ok',
            'authenticated' => true,
            'message' => \sprintf('MCP server reachable, %d tool(s) listed.', \count($tools)),
            'metadata' => [
                'apiName' => McpExtension::NAME,
                'custom' => [
                    'toolCount' => \count($tools),
                    'protocolVersion' => self::stringOrNull($initialize['protocolVersion'] ?? null),
                    'serverName' => self::stringOrNull($serverInfo['name'] ?? null),
                    'serverVersion' => self::stringOrNull($serverInfo['version'] ?? null),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(\Throwable $e): array
    {
        $code = self::classify($e);
        $prefix = match ($code) {
            self::CODE_UNAUTHENTICATED => 'MCP server rejected the credentials',
            self::CODE_PROTOCOL => 'MCP protocol error',
            default => 'MCP server unreachable',
        };
        $message = '' !== $e->getMessage() ? $e->getMessage() : $e::class;

        return [
            'status' => 'error',
            'authenticated' => false,
            'message' => $prefix.': '.$message,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => ['exception' => $e::class],
            ],
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_scalar($value) ? (string) $value : null;
    }
}

// - revised 2026-10-09 06:42:28 UTC
// - revised 2026-10-09 13:43:23 UTC
// - revised 2026-10-14
