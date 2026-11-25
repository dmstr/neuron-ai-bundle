<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider\Connection;

use Dmstr\NeuronAiBundle\Provider\AnthropicModelLister;
use Dmstr\NeuronAiBundle\Provider\ProviderType;

/**
 * The `anthropic` connection type (anthropic.schema.json): `api_key`
 * (`writeOnly`: encrypted at rest, masked in responses and logs), optional
 * `base_url` (default https://api.anthropic.com/v1/), `max_tokens`
 * (default 8192) and `timeout`. Health: `GET {base_url}models`.
 */
final class AnthropicConnectionType extends AbstractProviderConnectionType
{
    public const string NAME = ProviderType::ANTHROPIC;

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSchemaPath(): string
    {
        return __DIR__.'/anthropic.schema.json';
    }

    public function getEndpoint(array $config): string
    {
        return AnthropicModelLister::baseUrl($config);
    }
}
