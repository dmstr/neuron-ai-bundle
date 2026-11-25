<?php

// file generated with AI assistance: Claude Code - 2026-10-06 21:15:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\OpenApi;

use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\OpenApiJsonSchema\Interface\InputSchemaResolverInterface;

/**
 * Input schema of one of the bundle's operations: the static
 * `*.input.json` of the file resolver plus the picker keywords of one
 * reference field (at least `x-collection`), which a form turns into a
 * picker. They are built in PHP from the entity that defines them, so the
 * static file does not repeat them. One instance per operation and field:
 *
 *  - `POST /refs/provider_models/scan`, field `apiConfiguration`:
 *    {@see ProviderModel::connectionCollectionUrl()}, built from
 *    {@see ProviderModel::CONNECTION_TYPES}, so the scan, the connection
 *    picker of the form and the OpenAPI request body cannot disagree about
 *    the connection types;
 *  - `POST /admin/collections/{id}/reindex`, field `model`:
 *    {@see ProviderModel::EMBEDDING_MODEL_PICKER}, the embedding models
 *    of the catalogue.
 *
 * A schema without the field is returned unchanged.
 */
final class PickerSchemaResolver implements InputSchemaResolverInterface
{
    /**
     * @param array<string, string> $keywords schema keywords merged into the field, e.g. `x-collection`
     */
    public function __construct(
        private readonly InputSchemaResolverInterface $fileResolver,
        private readonly string $operationName,
        private readonly string $field,
        private readonly array $keywords,
    ) {
    }

    public function supports(string $operationName, array $context = []): bool
    {
        return $this->operationName === $operationName
            && $this->fileResolver->supports($operationName, $context);
    }

    public function resolve(string $operationName, array $context = []): ?array
    {
        $schema = $this->fileResolver->resolve($operationName, $context);
        if (null === $schema || !isset($schema['properties'][$this->field])) {
            return $schema;
        }

        $schema['properties'][$this->field] = [...$schema['properties'][$this->field], ...$this->keywords];

        return $schema;
    }
}

// - revised 2026-10-09
