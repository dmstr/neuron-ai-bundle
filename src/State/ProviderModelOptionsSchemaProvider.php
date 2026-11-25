<?php

// file generated with AI assistance: Claude Code - 2026-10-06 21:30:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\Model\ModelOptionsSchemaOutput;
use Dmstr\NeuronAiBundle\Provider\ModelOptionsSchema;
use Dmstr\NeuronAiBundle\Provider\ProviderType;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET /refs/provider_models/{id}/options_schema`: loads the catalogue row
 * through the Doctrine item provider and returns the schema of the
 * `modelOptions` its model accepts. A stale row gives the schema without
 * the limits of the row, exactly as the agent publish checks it.
 *
 * @implements ProviderInterface<ModelOptionsSchemaOutput>
 */
final class ProviderModelOptionsSchemaProvider implements ProviderInterface
{
    /**
     * @param ProviderInterface<object> $itemProvider API Platform's Doctrine ORM item provider
     */
    public function __construct(
        private readonly ProviderInterface $itemProvider,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ModelOptionsSchemaOutput
    {
        $row = $this->itemProvider->provide($operation->withClass(ProviderModel::class), $uriVariables, $context);
        if (!$row instanceof ProviderModel) {
            throw new NotFoundHttpException('Provider model not found.');
        }

        $connection = $row->getApiConfiguration();
        $type = ProviderType::of($connection);

        return new ModelOptionsSchemaOutput(
            model: $row->getName(),
            connection: ProviderType::nameOf($connection),
            type: $type,
            stale: $row->isStale(),
            schema: ModelOptionsSchema::of($type, $row->getName(), $row->isStale() ? null : $row),
        );
    }
}
