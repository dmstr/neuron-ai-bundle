<?php

// file generated with AI assistance: Claude Code - 2026-10-06 21:30:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider\Model;

/**
 * Response of `GET /refs/provider_models/{id}/options_schema`: the JSON
 * schema of the `modelOptions` an agent on this catalogue model may set
 * ({@see \Dmstr\NeuronAiBundle\Provider\ModelOptionsSchema}).
 */
final class ModelOptionsSchemaOutput
{
    /**
     * @param string               $model      the model name on the connection
     * @param string               $connection the name of the provider connection
     * @param string               $type       the provider type, `ollama` or `anthropic`
     * @param bool                 $stale      the row was described from another endpoint; the schema then has the options of the provider without limits of the model, as the publish applies them
     * @param array<string, mixed> $schema     the JSON schema (draft 2020-12) of `modelOptions`
     */
    public function __construct(
        public readonly string $model,
        public readonly string $connection,
        public readonly string $type,
        public readonly bool $stale,
        public readonly array $schema,
    ) {
    }
}
