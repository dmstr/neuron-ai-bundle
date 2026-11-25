<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;

/**
 * Models of an `anthropic` connection: `GET {base_url}models` with the
 * decrypted `api_key` as `x-api-key`, the `id` of every entry of `data`,
 * following `has_more`/`last_id` for at most {@see self::MAX_PAGES} pages
 * (see {@see AnthropicModelsTrait}).
 */
final class AnthropicModelLister extends AbstractHttpModelLister
{
    use AnthropicModelsTrait;

    public const string DEFAULT_BASE_URL = 'https://api.anthropic.com/v1/';
    public const string API_VERSION = '2023-06-01';

    public const int PAGE_SIZE = 1000;
    public const int MAX_PAGES = 5;

    public function listModels(ApiConfiguration $connection): array
    {
        $ids = array_map(static fn (array $model): string => $model['id'], $this->modelEntries($connection, $this->anthropicContext($connection)));

        return array_values(array_unique($ids));
    }

    /**
     * The connection's `base_url` with a trailing slash, the public API
     * without one.
     *
     * @param array<string, mixed> $config
     */
    public static function baseUrl(array $config): string
    {
        $baseUrl = \is_string($config['base_url'] ?? null) && '' !== $config['base_url'] ? $config['base_url'] : self::DEFAULT_BASE_URL;

        return rtrim($baseUrl, '/').'/';
    }
}
