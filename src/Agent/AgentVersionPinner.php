<?php

// file generated with AI assistance: Claude Code - 2026-10-03 19:10:10 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Agent;

use Dmstr\NeuronAiBundle\Entity\AgentVersion;
use Dmstr\NeuronAiBundle\Entity\Conversation;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Pins a new conversation to a published {@see AgentVersion}: the one
 * place that turns a version into the columns of an agent conversation.
 *
 * Shared by `POST /conversations` with `agent`
 * ({@see \Dmstr\NeuronAiBundle\State\ConversationCreateProcessor}), the
 * anonymous creation with a released agent
 * ({@see \Dmstr\NeuronAiBundle\Anonymous\AnonymousConversationCreator}) and
 * the evaluation batch ({@see \Dmstr\NeuronAiBundle\Evaluation\BatchRunner}).
 *
 * {@see VersionTurnMapper::map()} checks the version with the given
 * variables and language exactly as a turn will; then the version values
 * are copied into the columns for the representation: the model catalogue
 * entry ({@see TurnConfiguration::$providerModel}, the conversation's
 * `model`), the provider connection
 * ({@see TurnConfiguration::getConnection()}, resolved and active; the
 * entry's connection, `provider` becomes its type), the model name,
 * `collections`,
 * `retrievalDepth` and the effective `language` from the mapped
 * configuration, `mcpConnections` as the unique connection UUIDs of
 * the version's `mcpTools` (order of first use), `variables` as given and
 * `agentVersion` (pinned). `maxUserTurns` comes from {@see LimitResolver}
 * with the version's value, the settings default and the requested value,
 * which may only lower the result. The turns read everything from the
 * pinned version again.
 *
 * Owner, status, origin and the sequence counter are the caller's.
 */
final class AgentVersionPinner
{
    /**
     * @param AgentLimitDefaultsInterface|null $limitDefaults the settings step of the limits; null skips it
     */
    public function __construct(
        private readonly VersionTurnMapper $turnMapper,
        private readonly LimitResolver $limitResolver = new LimitResolver(),
        private readonly ?AgentLimitDefaultsInterface $limitDefaults = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $variables             the caller's variables (a JSON object, `[]` for none)
     * @param string|null             $language              null: the version's default language
     * @param int|null                $requestedMaxUserTurns a requested `maxUserTurns`; may only lower the resolved limit
     *
     * @return TurnConfiguration the mapped configuration the columns were copied from
     *
     * @throws StaleReferenceException          for a reference of the version that no longer resolves
     * @throws UnprocessableEntityHttpException for an unsupported language or invalid variables
     * @throws \LogicException                  when the mapper returned no provider connection or no catalogue entry (contract violation)
     */
    public function pin(Conversation $conversation, AgentVersion $version, array $variables = [], ?string $language = null, ?int $requestedMaxUserTurns = null): TurnConfiguration
    {
        $configuration = $this->turnMapper->map($version, $variables, $language);
        $versionConfiguration = AgentConfiguration::fromArray($version->getConfiguration());

        $connection = $configuration->getConnection()
            ?? throw new \LogicException(\sprintf('The mapped configuration of agent version %d has no provider connection.', $version->getVersionNumber()));
        $providerModel = $configuration->providerModel
            ?? throw new \LogicException(\sprintf('The mapped configuration of agent version %d has no model catalogue entry.', $version->getVersionNumber()));

        $conversation
            ->setProviderConnection($connection)
            ->setModel($configuration->model)
            ->setProviderModel($providerModel)
            ->setRetrievalDepth($configuration->retrievalDepth)
            ->setCollections($configuration->collections)
            ->setMcpConnections(self::connectionIds($versionConfiguration))
            ->setLanguage($configuration->language)
            ->setVariables($variables)
            ->setAgentVersion($version)
            ->setMaxUserTurns($this->limitResolver->resolve(
                $versionConfiguration->maxUserTurns,
                $this->limitDefaults?->maxUserTurns(),
                null,
                $requestedMaxUserTurns,
            ));

        return $configuration;
    }

    /**
     * Unique connection UUIDs of the version's `mcpTools` (stored as full
     * UUIDs at publish), in the order of first use.
     *
     * @return list<string>
     */
    public static function connectionIds(AgentConfiguration $configuration): array
    {
        $ids = [];
        foreach ($configuration->mcpTools as $tool) {
            if (!\in_array($tool['connection'], $ids, true)) {
                $ids[] = $tool['connection'];
            }
        }

        return $ids;
    }
}
