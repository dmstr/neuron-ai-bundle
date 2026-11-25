<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;

/**
 * The production {@see ModelListerInterface}: hands a connection to the
 * lister of its type.
 */
final class ProviderModelLister implements ModelListerInterface
{
    public function __construct(
        private readonly OllamaModelLister $ollama,
        private readonly AnthropicModelLister $anthropic,
    ) {
    }

    public function listModels(ApiConfiguration $connection): array
    {
        $type = $connection->getConfigJson()['type'] ?? null;

        return match ($type) {
            ProviderType::OLLAMA => $this->ollama->listModels($connection),
            ProviderType::ANTHROPIC => $this->anthropic->listModels($connection),
            default => throw new ModelListingException(\sprintf('Connection "%s" is not a provider connection.', ProviderType::nameOf($connection)), ModelListingException::CONFIGURATION),
        };
    }
}
