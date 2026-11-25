<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:37:11 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Reindex\CollectionReindexer;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /admin/collections/{id}/reindex/cancel`: drops the pending model and
 * its rows ({@see CollectionReindexer::cancel()}) and returns the
 * collection. A running reindex job notices the cancel at its next
 * checkpoint and ends without a swap. Nothing pending: 422.
 *
 * @implements ProcessorInterface<Collection, Collection>
 */
final class CollectionReindexCancelProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly CollectionReindexer $reindexer,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Collection
    {
        if (!$data instanceof Collection) {
            throw new NotFoundHttpException('Not Found');
        }

        try {
            $this->reindexer->cancel($data);
        } catch (\LogicException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return $data;
    }
}
