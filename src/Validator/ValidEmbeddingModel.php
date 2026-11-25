<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:17:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Validator;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint for {@see \Dmstr\NeuronAiBundle\Entity\Collection}:
 *
 * - the embedding model is required on create (there is no default model):
 *   the reference `embeddingModel` must resolve to an entry of the model
 *   catalogue that is usable for embeddings and whose name matches a model
 *   registered under `dmstr_neuron_ai.embeddings.models`
 *   ({@see \Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelResolver}); the
 *   entry names the embedding connection,
 * - a given embedding dimension must equal the registered dimension of the
 *   model,
 * - the model (entry, label and connection) of a stored collection cannot
 *   be changed directly; a change goes through a reindex.
 *
 * Every violation is at `embeddingModel`, except the dimension's at
 * `embeddingDimension`. The validator builds them at {@see self::PATH},
 * the PHP property behind the API field: the violation root is the
 * collection, and API Platform runs the `propertyPath` through the
 * serializer's name converter for the root's class, where the PHP property
 * `embeddingModel` (the label) is serialized as `embeddingModelName`.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidEmbeddingModel extends Constraint
{
    /** The entry's name is not registered in the embedding registry. */
    public const UNKNOWN_MODEL_ERROR = 'b3c1e0d2-6a0f-4f53-9a55-2f9a4e8c1a11';
    public const DIMENSION_MISMATCH_ERROR = 'b3c1e0d2-6a0f-4f53-9a55-2f9a4e8c1a12';
    public const MODEL_CHANGE_ERROR = 'b3c1e0d2-6a0f-4f53-9a55-2f9a4e8c1a13';
    public const MODEL_REQUIRED_ERROR = 'b3c1e0d2-6a0f-4f53-9a55-2f9a4e8c1a14';

    /** The reference does not resolve to a usable catalogue entry, or a label set by code does not match it. */
    public const INVALID_MODEL_ERROR = 'b3c1e0d2-6a0f-4f53-9a55-2f9a4e8c1a18';

    /**
     * Violation path of the model reference: the PHP property
     * `embeddingModelReference` of the collection, serialized (and reported
     * by API Platform) as `embeddingModel`.
     */
    public const PATH = 'embeddingModelReference';

    protected const ERROR_NAMES = [
        self::UNKNOWN_MODEL_ERROR => 'UNKNOWN_MODEL_ERROR',
        self::DIMENSION_MISMATCH_ERROR => 'DIMENSION_MISMATCH_ERROR',
        self::MODEL_CHANGE_ERROR => 'MODEL_CHANGE_ERROR',
        self::MODEL_REQUIRED_ERROR => 'MODEL_REQUIRED_ERROR',
        self::INVALID_MODEL_ERROR => 'INVALID_MODEL_ERROR',
    ];

    public string $dimensionMessage = 'The embedding dimension {{ given }} does not match the dimension {{ expected }} of the embedding model {{ model }}.';

    public string $modelChangeMessage = 'Changing the embedding model requires a reindex: POST /api/admin/collections/{id}/reindex with the "model" to switch to.';

    public string $modelRequiredMessage = 'The embedding model is required: IRI or UUID of an available entry of the model catalogue (/api/refs/provider_models) with the capability "embedding", whose name is registered in dmstr_neuron_ai.embeddings.models. Known models: {{ known }}.';

    public string $labelMismatchMessage = 'The embedding model name {{ given }} does not match the catalogue entry {{ entry }}, registered as {{ expected }}.';

    /**
     * @param string[]|null $groups
     */
    #[HasNamedArguments]
    public function __construct(
        ?string $dimensionMessage = null,
        ?string $modelChangeMessage = null,
        ?string $modelRequiredMessage = null,
        ?string $labelMismatchMessage = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);

        $this->dimensionMessage = $dimensionMessage ?? $this->dimensionMessage;
        $this->modelChangeMessage = $modelChangeMessage ?? $this->modelChangeMessage;
        $this->modelRequiredMessage = $modelRequiredMessage ?? $this->modelRequiredMessage;
        $this->labelMismatchMessage = $labelMismatchMessage ?? $this->labelMismatchMessage;
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
