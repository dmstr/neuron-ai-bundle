<?php

// file generated with AI assistance: Claude Code - 2026-10-06 17:20:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;

/**
 * The Models API of an `anthropic` connection shared by
 * {@see AnthropicModelLister} and {@see AnthropicModelDescriber}: the
 * decrypted `api_key` as `x-api-key`, `GET {base_url}models` following
 * `has_more`/`last_id` for at most {@see AnthropicModelLister::MAX_PAGES}
 * pages, and `GET {base_url}models/{id}`. An endpoint without a Models API
 * (Microsoft Foundry) names its models in the connection's `models` instead;
 * they are listed as given, without a request.
 *
 * The using class extends {@see AbstractProviderHttpClient}.
 */
trait AnthropicModelsTrait
{
    /**
     * @return array{baseUrl: string, headers: array<string, string>, key: string, models: list<string>}
     *
     * @throws ModelListingException CONFIGURATION for a missing key
     */
    private function anthropicContext(ApiConfiguration $connection): array
    {
        $config = $this->configOf($connection, ProviderType::ANTHROPIC);
        $key = $config['api_key'] ?? null;
        if (!\is_string($key) || '' === $key) {
            throw new ModelListingException(\sprintf('Anthropic connection "%s" has no "api_key".', ProviderType::nameOf($connection)), ModelListingException::CONFIGURATION);
        }

        return [
            'baseUrl' => AnthropicModelLister::baseUrl($config),
            'headers' => ['x-api-key' => $key, 'anthropic-version' => AnthropicModelLister::API_VERSION],
            'key' => $key,
            'models' => array_values(array_filter((array) ($config['models'] ?? []), static fn (mixed $m): bool => \is_string($m) && '' !== $m)),
        ];
    }

    /**
     * Every entry of `data` over all pages.
     *
     * @param array{baseUrl: string, headers: array<string, string>, key: string, models: list<string>} $context
     *
     * @return list<array<mixed>>
     *
     * @throws ModelListingException
     */
    private function modelEntries(ApiConfiguration $connection, #[\SensitiveParameter] array $context): array
    {
        if ([] !== $context['models']) {
            return array_map(static fn (string $id): array => ['id' => $id], $context['models']);
        }
        $entries = [];
        $after = null;
        for ($page = 0; $page < AnthropicModelLister::MAX_PAGES; ++$page) {
            $query = ['limit' => AnthropicModelLister::PAGE_SIZE] + (null !== $after ? ['after_id' => $after] : []);
            $data = $this->getJson($connection, $context['baseUrl'].'models?'.http_build_query($query), $context['headers'], [$context['key']]);
            if (!\is_array($data['data'] ?? null)) {
                throw new ModelListingException(\sprintf('Anthropic connection "%s" answered without a "data" list.', ProviderType::nameOf($connection)), ModelListingException::PROTOCOL);
            }

            foreach ($data['data'] as $model) {
                if (\is_array($model) && \is_string($model['id'] ?? null) && '' !== $model['id']) {
                    $entries[] = $model;
                }
            }

            $after = \is_string($data['last_id'] ?? null) ? $data['last_id'] : null;
            if (true !== ($data['has_more'] ?? false) || null === $after) {
                break;
            }
        }

        return $entries;
    }
}
// - revised 2026-11-18
