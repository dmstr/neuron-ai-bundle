<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Provider\EndpointFingerprint;
use Dmstr\NeuronAiBundle\Provider\ProviderModelScanQueue;
use Dmstr\NeuronAiBundle\Provider\ProviderType;
use Psr\Log\LoggerInterface;

/**
 * Decorates API Platform's Doctrine persist processor: after an `ollama` or
 * `anthropic` connection was written through the API, a model catalogue
 * scan of that connection is queued when the connection
 *  - was created active,
 *  - was activated, or
 *  - points to another endpoint (`url`, `base_url`) while active.
 *
 * Renaming or other config changes queue nothing. The trigger sits in the
 * API write path on purpose and not in a Doctrine listener, so that the
 * seed command (`neuron:provider-connection:seed`) and other EntityManager
 * writes stay free of side effects. Every other resource passes through
 * unchanged. A failure to queue the job is logged and does not fail the
 * write, which has already been flushed.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
final class ProviderConnectionScanTrigger implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<mixed, mixed> $decorated
     */
    public function __construct(
        private readonly ProcessorInterface $decorated,
        private readonly ProviderModelScanQueue $scanQueue,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $result = $this->decorated->process($data, $operation, $uriVariables, $context);

        if ($result instanceof ApiConfiguration && self::needsScan($result, $context['previous_data'] ?? null)) {
            try {
                $this->scanQueue->queueAfterChange($result);
            } catch (\Throwable $e) {
                $this->logger?->warning('Could not queue the model catalogue scan of provider connection {connection}: {error}', [
                    'connection' => ProviderType::nameOf($result),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    public static function needsScan(ApiConfiguration $connection, mixed $previous): bool
    {
        if (!ProviderType::isProvider($connection) || !$connection->isActive()) {
            return false;
        }

        if (!$previous instanceof ApiConfiguration || !$previous->isActive() || !ProviderType::isProvider($previous)) {
            return true;
        }

        return EndpointFingerprint::of($previous) !== EndpointFingerprint::of($connection);
    }
}
