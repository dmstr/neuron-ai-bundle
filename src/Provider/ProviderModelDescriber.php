<?php

// file generated with AI assistance: Claude Code - 2026-10-06 17:20:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Provider\Model\ModelDescription;
use Dmstr\NeuronAiBundle\Provider\Model\ModelDescriptionList;

/**
 * The production {@see ModelDescriberInterface}: hands a connection to the
 * describer of its type.
 */
final class ProviderModelDescriber implements ModelDescriberInterface
{
    public function __construct(
        private readonly OllamaModelDescriber $ollama,
        private readonly AnthropicModelDescriber $anthropic,
    ) {
    }

    public function describeModels(ApiConfiguration $connection, array $known = []): ModelDescriptionList
    {
        return $this->describerOf($connection)->describeModels($connection, $known);
    }

    public function describeModel(ApiConfiguration $connection, string $name): ?ModelDescription
    {
        return $this->describerOf($connection)->describeModel($connection, $name);
    }

    private function describerOf(ApiConfiguration $connection): ModelDescriberInterface
    {
        return match ($connection->getConfigJson()['type'] ?? null) {
            ProviderType::OLLAMA => $this->ollama,
            ProviderType::ANTHROPIC => $this->anthropic,
            default => throw new ModelListingException(\sprintf('Connection "%s" is not a provider connection.', ProviderType::nameOf($connection)), ModelListingException::CONFIGURATION),
        };
    }
}
