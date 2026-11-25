<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\Connection\ProviderConnectionResolver;
use Dmstr\NeuronAiBundle\Provider\ProviderModelScanQueue;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Symfony\Component\HttpFoundation\Request;

/**
 * `POST /refs/provider_models/scan`: validates the body and queues a job of
 * type `provider_model_scan` through {@see ProviderModelScanQueue}.
 *
 * The job queue validates only against schemas below the app's own entity
 * directory, so the body is validated here against the bundle's
 * `Entity/Refs/ProviderModel/scan.input.json` ({@see InputBodyValidator}:
 * malformed JSON 400, schema violation 422), and the optional connection
 * is resolved before a job exists (unknown, inactive, ambiguous or
 * non-provider connection: 422).
 *
 * The job input is `{}` (all active provider connections) or
 * `{"apiConfiguration": "<connection uuid>"}`. While a `pending` or
 * `running` scan job with the same input exists, that job is returned
 * (201) instead of a new one.
 *
 * @implements ProcessorInterface<null, Job>
 */
final class ProviderModelScanProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly InputBodyValidator $inputBodyValidator,
        private readonly ProviderConnectionResolver $connectionResolver,
        private readonly ProviderModelScanQueue $scanQueue,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Job
    {
        $request = $context['request'] ?? null;
        $body = $request instanceof Request ? $request->getContent() : '';

        $input = $this->inputBodyValidator->validate($operation->getName() ?? ProviderModel::OPERATION_SCAN, $body);

        // An empty reference is what a form sends for the untouched optional
        // field; it means the same as an omitted one: all connections.
        $reference = trim((string) ($input['apiConfiguration'] ?? ''));
        $connection = '' !== $reference
            ? $this->connectionResolver->resolveChat($reference)
            : null;

        return $this->scanQueue->queue($connection);
    }
}
