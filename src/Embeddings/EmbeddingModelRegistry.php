<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:17:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Embeddings;

use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\ModelName;
use Dmstr\NeuronAiBundle\Provider\ProviderType;

/**
 * The embedding models a collection may use, as configured under
 * `dmstr_neuron_ai.embeddings.models`. There is no default model: every
 * collection names its embedding model.
 *
 * A registered name is the vector label of the model: the store rows, the
 * partial vector index (`WHERE model = '<name>'`) and the chunk ids are
 * keyed by it. A collection chooses its model as an entry of the model
 * catalogue ({@see ProviderModel}), whose name is the one the provider
 * lists, e.g. `nomic-embed-text:latest` for the registered
 * `nomic-embed-text`; {@see forEntry()} maps the entry to the registered
 * model with {@see ModelName::equivalent()}.
 */
final class EmbeddingModelRegistry
{
    /** Allowed model names; they are inlined into SQL by the vector store. */
    public const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/';

    public const MAX_NAME_LENGTH = 190;

    public const MAX_DIMENSION = 2000;

    /** @var array<string, EmbeddingModel> */
    private array $models = [];

    /**
     * @param array<string, array{provider?: string, dimension: int, query_prefix?: string, document_prefix?: string, context_length?: int|null}> $models
     */
    public function __construct(array $models)
    {
        foreach ($models as $name => $model) {
            $name = (string) $name;
            $this->models[$name] = new EmbeddingModel(
                name: $name,
                provider: $model['provider'] ?? 'ollama',
                dimension: (int) $model['dimension'],
                queryPrefix: $model['query_prefix'] ?? '',
                documentPrefix: $model['document_prefix'] ?? '',
                contextLength: isset($model['context_length']) ? (int) $model['context_length'] : null,
            );
        }
    }

    public function has(string $name): bool
    {
        return isset($this->models[$name]);
    }

    /**
     * @throws \InvalidArgumentException when the model is not configured
     */
    public function get(string $name): EmbeddingModel
    {
        return $this->models[$name]
            ?? throw new \InvalidArgumentException(sprintf('Unknown embedding model "%s"; known models: %s.', $name, $this->knownList()));
    }

    /**
     * The registered model of the catalogue name $name: the registered name
     * itself first, else an equivalent one (with or without `:latest`,
     * {@see ModelName::equivalent()}); null when none matches.
     */
    public function findEquivalent(string $name): ?EmbeddingModel
    {
        if (isset($this->models[$name])) {
            return $this->models[$name];
        }
        foreach ($this->models as $registered => $model) {
            if (ModelName::equivalent((string) $registered, $name)) {
                return $model;
            }
        }

        return null;
    }

    /**
     * The registered model of the catalogue entry $entry
     * ({@see findEquivalent()} of its name); its name is the vector label of
     * a collection on that entry.
     *
     * @throws \InvalidArgumentException when no registered model matches the entry's name
     */
    public function forEntry(ProviderModel $entry): EmbeddingModel
    {
        return $this->findEquivalent($entry->getName()) ?? throw new \InvalidArgumentException(sprintf(
            'Model "%s" of connection "%s" is no registered embedding model; register it (with or without the tag ":latest") under dmstr_neuron_ai.embeddings.models. Known models: %s.',
            $entry->getName(),
            ProviderType::nameOf($entry->getApiConfiguration()),
            $this->knownList(),
        ));
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(strval(...), array_keys($this->models));
    }

    /**
     * @return list<EmbeddingModel>
     */
    public function all(): array
    {
        return array_values($this->models);
    }

    /**
     * Whether $name is usable as a model name (pattern and length), whether
     * or not it is configured.
     */
    public static function isValidName(string $name): bool
    {
        return \strlen($name) <= self::MAX_NAME_LENGTH && preg_match(self::NAME_PATTERN, $name) === 1;
    }

    private function knownList(): string
    {
        return implode(', ', array_map(static fn (string $n): string => '"'.$n.'"', $this->names()));
    }
}
