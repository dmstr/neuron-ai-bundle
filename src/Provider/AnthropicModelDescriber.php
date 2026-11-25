<?php

// file generated with AI assistance: Claude Code - 2026-10-06 17:20:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Provider\Model\ModelCapability;
use Dmstr\NeuronAiBundle\Provider\Model\ModelDescription;
use Dmstr\NeuronAiBundle\Provider\Model\ModelDescriptionList;

/**
 * Describes the models of an `anthropic` connection from the Models API
 * (`GET {base_url}models`, paginated like {@see AnthropicModelLister};
 * `GET {base_url}models/{id}` for one model, which also resolves aliases).
 * No request per model, so neither a describe budget nor a circuit
 * breaker applies, and `$known` is not needed.
 *
 * Mapping: `id` as the name, `display_name`, `line` as the family (null
 * when absent; the family is never inferred from the id),
 * `max_input_tokens` as the context length and `max_tokens` as the output
 * limit (0 or missing = null). Capabilities are synthesized: `completion`
 * and `tools` always (the Messages API contract, not reported by the API),
 * `vision` when `capabilities.image_input.supported`, `thinking` when
 * `capabilities.thinking.supported`, never `embedding`. Whether the model
 * takes sampling parameters comes from {@see AnthropicSamplingSupport}.
 */
final class AnthropicModelDescriber extends AbstractProviderHttpClient implements ModelDescriberInterface
{
    use AnthropicModelsTrait;

    public function describeModels(ApiConfiguration $connection, array $known = []): ModelDescriptionList
    {
        $models = [];
        $warnings = [];
        foreach ($this->modelEntries($connection, $this->anthropicContext($connection)) as $entry) {
            if (isset($models[$entry['id']])) {
                $warnings[] = \sprintf('Model "%s" is listed more than once; the first entry is used.', $entry['id']);
                continue;
            }
            $models[$entry['id']] = self::fromEntry($entry);
        }

        return new ModelDescriptionList(array_values($models), $warnings);
    }

    public function describeModel(ApiConfiguration $connection, string $name): ?ModelDescription
    {
        $context = $this->anthropicContext($connection);
        if ([] !== $context['models']) {
            return \in_array($name, $context['models'], true) ? self::fromEntry(['id' => $name]) : null;
        }

        try {
            $entry = $this->getJson($connection, $context['baseUrl'].'models/'.rawurlencode($name), $context['headers'], [$context['key']]);
        } catch (ModelListingException $e) {
            if (404 === $e->statusCode) {
                return null;
            }

            throw $e;
        }

        if (!\is_string($entry['id'] ?? null) || '' === $entry['id']) {
            throw new ModelListingException(\sprintf('Anthropic connection "%s" answered without a model "id".', ProviderType::nameOf($connection)), ModelListingException::PROTOCOL);
        }

        return self::fromEntry($entry);
    }

    /**
     * @param array<mixed> $entry
     */
    private static function fromEntry(array $entry): ModelDescription
    {
        $id = (string) $entry['id'];
        $reported = \is_array($entry['capabilities'] ?? null) ? $entry['capabilities'] : [];

        $capabilities = [ModelCapability::COMPLETION, ModelCapability::TOOLS];
        if (self::supported($reported, 'image_input')) {
            $capabilities[] = ModelCapability::VISION;
        }
        if (self::supported($reported, 'thinking')) {
            $capabilities[] = ModelCapability::THINKING;
        }

        return new ModelDescription(
            name: $id,
            displayName: self::stringOrNull($entry['display_name'] ?? null),
            family: self::stringOrNull($entry['line'] ?? null),
            capabilities: ModelCapability::normalize($capabilities),
            maxContextLength: self::positiveInt($entry['max_input_tokens'] ?? null),
            maxOutputTokens: self::positiveInt($entry['max_tokens'] ?? null),
            supportsSampling: AnthropicSamplingSupport::of($id),
            providerData: array_filter([
                'capabilities' => [] !== $reported ? $reported : null,
                'created_at' => self::stringOrNull($entry['created_at'] ?? null),
            ], static fn (mixed $value): bool => null !== $value),
        );
    }

    /**
     * @param array<mixed> $capabilities
     */
    private static function supported(array $capabilities, string $key): bool
    {
        return \is_array($capabilities[$key] ?? null) && true === ($capabilities[$key]['supported'] ?? null);
    }
}
// - revised 2026-11-18
