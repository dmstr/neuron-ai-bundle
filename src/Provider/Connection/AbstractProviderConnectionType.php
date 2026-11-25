<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider\Connection;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiConfiguration\Health\HealthProbeInterface;
use Dmstr\NeuronAiBundle\Provider\ModelListerInterface;
use Dmstr\NeuronAiBundle\Provider\ModelListingException;
use Dmstr\OpenApiJsonSchema\Interface\SchemaProviderInterface;

/**
 * A provider connection type of dmstr/api-configuration-bundle 0.5: the
 * schema branch of `ApiConfiguration.configJson` (SchemaProviderInterface,
 * which is all the bundle needs for validation, the OpenAPI `anyOf`, and
 * the encryption and masking of the `writeOnly` keys) and the health check
 * (HealthProbeInterface, no ApiExtensionInterface client).
 *
 * The health check lists the connection's models through
 * {@see ModelListerInterface} (the test fake under `when@test`) and reports
 * their number in `metadata.custom.modelCount`. Failures map to the codes
 * UNREACHABLE, UNAUTHENTICATED and PROTOCOL (as for `mcp` connections;
 * CONFIGURATION for an unusable configuration); no result contains a
 * credential.
 */
abstract class AbstractProviderConnectionType implements SchemaProviderInterface, HealthProbeInterface
{
    public function __construct(
        private readonly ModelListerInterface $modelLister,
    ) {
    }

    abstract public function getName(): string;

    abstract public function getSchemaPath(): string;

    abstract public function getEndpoint(array $config): string;

    /**
     * @param array<string, mixed> $config decrypted configuration
     *
     * @return array<string, mixed> raw health data for the platform's HealthNormalizer
     */
    public function probe(array $config): array
    {
        // The lister takes a connection; the probe only gets the decrypted
        // configuration, without the name. A transient entity named after the
        // type carries it (never persisted; decrypting clear values again is
        // a no-op).
        $connection = new ApiConfiguration()
            ->setName($this->getName())
            ->setConfigJson($config);

        try {
            $models = $this->modelLister->listModels($connection);
        } catch (ModelListingException $e) {
            return self::failure($e->reason, $e->getMessage(), $e->statusCode);
        } catch (\Throwable $e) {
            // A lister must throw ModelListingException only; anything else
            // is still a result, never an escaping exception. The message of
            // a foreign exception may carry request data, so it is not shown.
            return self::failure(ModelListingException::UNREACHABLE, \sprintf('Models of the %s connection could not be listed (%s).', $this->getName(), $e::class), null);
        }

        $models = array_values(array_filter($models, static fn (string $model): bool => ModelListerInterface::ANY_MODEL !== $model));

        return [
            'status' => 'ok',
            'authenticated' => true,
            'message' => \sprintf('Provider reachable, %d model(s) listed.', \count($models)),
            'metadata' => [
                'apiName' => $this->getName(),
                'custom' => [
                    'modelCount' => \count($models),
                ],
            ],
        ];
    }

    /**
     * An error result. `error.details` is only set when it has content:
     * HealthNormalizer validates against health.json, where `details` is an
     * object, and an empty PHP array would be encoded as a JSON list.
     *
     * @return array<string, mixed>
     */
    private static function failure(string $code, string $message, ?int $statusCode): array
    {
        $error = ['code' => $code, 'message' => $message];
        if (null !== $statusCode) {
            $error['details'] = ['statusCode' => $statusCode];
        }

        return [
            'status' => 'error',
            'authenticated' => false,
            'message' => $message,
            'error' => $error,
        ];
    }
}
