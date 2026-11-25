<?php

// file generated with AI assistance: Claude Code - 2026-10-06 17:20:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider\Model;

/**
 * Everything a provider connection offers, with the warnings of the
 * describe call (duplicate names, describe budget reached, `/show` not
 * permitted or aborted). Per-model failures are the `error` of the model's
 * {@see ModelDescription}.
 */
final readonly class ModelDescriptionList
{
    /**
     * @param list<ModelDescription> $models
     * @param list<string>           $warnings
     */
    public function __construct(
        public array $models = [],
        public array $warnings = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (ModelDescription $model): string => $model->name, $this->models);
    }
}
