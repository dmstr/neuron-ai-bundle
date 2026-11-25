<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:22:22 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Mcp;

use Dmstr\ApiConfiguration\ApiClient\ApiClientInterface;
use Dmstr\ApiConfiguration\Extension\ApiExtensionInterface;
use Dmstr\OpenApiJsonSchema\Interface\SchemaProviderInterface;

/**
 * The `mcp` connection type of dmstr/api-configuration-bundle.
 *
 * One class serves both registries: as ApiExtensionInterface it creates the
 * health client for `GET /api/admin/api_configurations/{id}/health` and
 * `app:api-configuration:health`; as SchemaProviderInterface it contributes
 * the `mcp` branch (schema.json) to the unified `ApiConfiguration.configJson`
 * anyOf. The bundle tags the service explicitly for both
 * (`dmstr_api_configuration.extension`, `dmstr_openapi_json_schema.provider`).
 */
final class McpExtension implements ApiExtensionInterface, SchemaProviderInterface
{
    public const string NAME = 'mcp';

    public function __construct(
        private readonly McpTransportFactoryInterface $transportFactory,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getType(): string
    {
        return 'rest';
    }

    public function getSchemaPath(): string
    {
        return __DIR__.'/schema.json';
    }

    /**
     * Performs no I/O: the transport is created and contacted only when a
     * probe method of the client is called.
     *
     * @param array<string, mixed> $config
     */
    public function createClient(array $config): ApiClientInterface
    {
        return new McpHealthClient($config, $this->transportFactory);
    }
}
