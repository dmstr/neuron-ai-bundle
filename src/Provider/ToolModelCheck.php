<?php

// file generated with AI assistance: Claude Code - 2026-10-06 17:28:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Provider\Model\ModelCapability;

/**
 * Whether a model may be given MCP tools: the one check shared by the
 * agent publish ({@see \Dmstr\NeuronAiBundle\Agent\AgentConfigurationValidator},
 * `candidate/model`) and the stateless chat
 * ({@see \Dmstr\NeuronAiBundle\Chat\ChatService::chat()}, `model`). Both
 * call it only when the connection resolved, the model passed the live
 * check and the tool list is not empty.
 *
 * The model is rejected when the catalogue knows it lacks `tools`
 * ({@see ModelCapabilityCheck}); with known capabilities `completion` is
 * required as well, which catches embedding-only models. An unknown
 * verdict passes. The capability says nothing about how well a model uses
 * tools.
 *
 * Second, the connection's allow-list `configJson.tool_models` releases
 * models for tool use explicitly: when it lists at least one model, any
 * other model is rejected, matched with {@see ModelName::equivalent()}. An
 * empty or missing list allows every model. The capability verdict comes
 * first, so a listed model that the catalogue knows to lack `tools` is
 * still rejected with the capability message.
 */
final class ToolModelCheck
{
    public function __construct(
        private readonly ModelCapabilityCheck $capabilities,
    ) {
    }

    /**
     * @param string $toolsField the request field that attaches the tools, named in the message (`mcpTools`, `mcpConnections`)
     *
     * @return string|null the violation message, null when the model may take tools
     */
    public function violation(ApiConfiguration $connection, string $model, string $toolsField): ?string
    {
        $verdict = $this->capabilities->verdict($connection, $model, [ModelCapability::COMPLETION, ModelCapability::TOOLS]);
        if (!$verdict->isMissing()) {
            return $this->allowListViolation($connection, $model, $toolsField);
        }

        return \sprintf(
            'Model "%s" of connection "%s" %s (capabilities: %s); choose a model with the tools capability or remove %s. If the model was re-pulled, refresh it with neuron:provider-models:sync %s.',
            $model,
            $connection->getName(),
            \in_array(ModelCapability::COMPLETION, $verdict->missing, true) ? 'is not a chat model and does not support tool calls' : 'does not support tool calls',
            [] === $verdict->known ? 'none' : implode(', ', $verdict->known),
            $toolsField,
            $verdict->modelId,
        );
    }

    /**
     * The models of `configJson.tool_models`; an empty list allows every model.
     *
     * @return list<string>
     */
    public static function allowedModels(ApiConfiguration $connection): array
    {
        $list = $connection->getConfigJson()['tool_models'] ?? null;
        if (!\is_array($list)) {
            return [];
        }

        return array_values(array_filter($list, static fn (mixed $name): bool => \is_string($name) && '' !== trim($name)));
    }

    private function allowListViolation(ApiConfiguration $connection, string $model, string $toolsField): ?string
    {
        $allowed = self::allowedModels($connection);
        if ([] === $allowed) {
            return null;
        }

        foreach ($allowed as $name) {
            if (ModelName::equivalent($name, $model)) {
                return null;
            }
        }

        return \sprintf(
            'Model "%s" is not released for tools on connection "%s" (tool_models: %s); choose one of these models or remove %s.',
            $model,
            $connection->getName(),
            implode(', ', $allowed),
            $toolsField,
        );
    }
}
