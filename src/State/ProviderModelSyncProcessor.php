<?php

// file generated with AI assistance: Claude Code - 2026-10-06 18:00:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\ProviderModelScanner;
use Dmstr\NeuronAiBundle\Provider\ScanInProgressException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /refs/provider_models/{id}/sync`: describes one catalogue entry
 * afresh and returns it (200). A model the connection no longer offers or
 * a failing provider sets `lastError` on the entry and still answers 200
 *; 409 while a scan of the same connection is running.
 *
 * @implements ProcessorInterface<ProviderModel, ProviderModel>
 */
final class ProviderModelSyncProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ProviderModelScanner $scanner,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProviderModel
    {
        if (!$data instanceof ProviderModel) {
            throw new NotFoundHttpException('Provider model not found.');
        }

        try {
            $this->scanner->syncOne($data);
        } catch (ScanInProgressException $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }

        return $data;
    }
}
