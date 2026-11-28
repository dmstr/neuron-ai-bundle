<?php

// file generated with AI assistance: Claude Code - 2026-10-02 22:05:06 UTC

declare(strict_types=1);

/*
 * Agents and their versions: publish validation, publisher,
 * rollback, limit resolver, the candidate structure validator, the state
 * processors of `agent_publish`, `agent_rollback`, `agent_chat` and of the
 * agent writes (POST, PATCH) and the
 * mapping of a version to the configuration of a turn and the agent
 * reference resolver, imported by
 * DmstrNeuronAiBundle::loadExtension(). Bundle services get no
 * autoconfiguration, so every tag is explicit. The application, not the
 * bundle, aliases Agent\AgentLimitDefaultsInterface.
 */

use ApiPlatform\Metadata\IriConverterInterface;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Agent\AgentConfigurationValidator;
use Dmstr\NeuronAiBundle\Agent\AgentLimitDefaultsInterface;
use Dmstr\NeuronAiBundle\Agent\AgentPreviewFactory;
use Dmstr\NeuronAiBundle\Agent\AgentPublisher;
use Dmstr\NeuronAiBundle\Agent\AgentReferenceResolver;
use Dmstr\NeuronAiBundle\Agent\AgentRollback;
use Dmstr\NeuronAiBundle\Agent\LimitResolver;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Artifact\ArtifactAccessResolver;
use Dmstr\NeuronAiBundle\Artifact\ArtifactToolkitFactory;
use Dmstr\NeuronAiBundle\Chat\AgentChat;
use Dmstr\NeuronAiBundle\Chat\AgentRunner;
use Dmstr\NeuronAiBundle\Chat\ChatService;
use Dmstr\NeuronAiBundle\Conversation\ConversationReferences;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\Mcp\McpToolFactory;
use Dmstr\NeuronAiBundle\Provider\Connection\ProviderConnectionResolver;
use Dmstr\NeuronAiBundle\Provider\ModelAvailabilityCheck;
use Dmstr\NeuronAiBundle\Provider\ProviderModelResolver;
use Dmstr\NeuronAiBundle\Provider\ToolModelCheck;
use Dmstr\NeuronAiBundle\Retrieval\SourceFreshnessInterface;
use Dmstr\NeuronAiBundle\State\AgentChatProcessor;
use Dmstr\NeuronAiBundle\State\AgentPublishProcessor;
use Dmstr\NeuronAiBundle\State\AgentRollbackProcessor;
use Dmstr\NeuronAiBundle\State\AgentWriteProcessor;
use Dmstr\NeuronAiBundle\State\InputBodyValidator;
use Dmstr\NeuronAiBundle\Validator\ValidAgentCandidateValidator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Publish check of a candidate: structure, model reference (catalogue
    // entry with its connection, live check of the model), collections, MCP tools (and the tools
    // capability of the model from the catalogue), the model options (against
    // the schema of the model, narrowed by its catalogue entry), schemas and
    // languages; 422 at candidate/<field>.
    $services->set(AgentConfigurationValidator::class)
        ->args([
            service(ProviderModelResolver::class),
            service(ModelAvailabilityCheck::class),
            service(ConversationReferences::class),
            service(McpConnectionResolver::class),
            service('doctrine.orm.entity_manager'),
            service(ToolModelCheck::class),
        ]);

    // Freezes a candidate as the next version under a lock on the agent.
    $services->set(AgentPublisher::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(AgentConfigurationValidator::class),
        ]);

    // Freezes the candidate as a preview version (kind preview, number 0)
    // for test chats and evaluations; reuses the preview of an unchanged
    // candidate, locks the agent like the publisher.
    $services->set(AgentPreviewFactory::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(AgentConfigurationValidator::class),
        ]);

    // Makes an earlier version of the agent current again.
    $services->set(AgentRollback::class)
        ->args([service('doctrine.orm.entity_manager')]);

    // Version, settings and bundle default of a limit; applied in 5a-2.
    $services->set(LimitResolver::class);

    // Agent reference (IRI, full UUID or slug) -> Agent, and its current
    // published version with the 422 texts for unknown, disabled and
    // unpublished agents; shared by the conversation creation paths.
    $services->set(AgentReferenceResolver::class)
        ->args([service('doctrine.orm.entity_manager')]);

    // #[ValidAgentCandidate] on Agent::$candidate (structure only, POST and PATCH).
    $services->set(ValidAgentCandidateValidator::class)
        ->tag('validator.constraint_validator');

    // POST /agents/{id}/publish (agent_publish).
    $services->set(AgentPublishProcessor::class)
        ->args([
            service(AgentPublisher::class),
            service('security.token_storage'),
        ])
        ->tag('api_platform.state_processor');

    // POST /agents/{id}/rollback (agent_rollback).
    $services->set(AgentRollbackProcessor::class)
        ->args([
            service(AgentRollback::class),
            service(InputBodyValidator::class),
            service(UuidResolver::class),
        ])
        ->tag('api_platform.state_processor');

    // Published version + caller variables, language and maxToolCalls ->
    // TurnConfiguration of one turn; stale references are a 422 naming the
    // path in the version (StaleReferenceException). The model entry and its
    // provider connection are resolved from the database only, the model is
    // not checked live.
    $services->set(VersionTurnMapper::class)
        ->args([
            service(ProviderConnectionResolver::class),
            service('doctrine.orm.entity_manager'),
            service(McpConnectionResolver::class),
            service(McpToolFactory::class),
            service(LimitResolver::class),
            service(AgentLimitDefaultsInterface::class)->nullOnInvalid(),
            service('clock'),
            param('dmstr_neuron_ai.store.top_k'),
            param('dmstr_neuron_ai.turn.max_tool_calls_default'),
            service('logger')->nullOnInvalid(),
            // Versions stored without the context keys keep the bundle cap.
            param('dmstr_neuron_ai.turn.context_max_chunks'),
            param('dmstr_neuron_ai.turn.context_max_chars'),
            param('dmstr_neuron_ai.turn.context_instruction'),
            // Artifact tools: both turn services build their
            // TurnPersistence from this mapper, so the turn gate and the
            // toolkit factory reach the conversation turn through it; the
            // services are defined in DmstrNeuronAiBundle::registerArtifactServices().
            service(ArtifactToolkitFactory::class),
            service(ArtifactAccessResolver::class),
        ]);

    // One stateless turn on an agent version without request, token or
    // user: disabled and published checks, mapping, pre-answer rules and
    // ChatService with the answer gates; used by AgentChat and the judge.
    $services->set(AgentRunner::class)
        ->args([
            service(VersionTurnMapper::class),
            service(ChatService::class),
            service(IriConverterInterface::class),
            // Freshness of the sources from the application's alias; no dates without one.
            service(SourceFreshnessInterface::class)->nullOnInvalid(),
        ]);

    // POST /agents/{id}/chat (agent_chat): one stateless turn on the
    // current version, or with `target: candidate` (neuronai:agent:chat_candidate,
    // checked in the processor) on the preview of the candidate (created by
    // the token's user), run by AgentRunner.
    // One stateless turn on an agent: agent_chat and /v1/chat/completions.
    // The first four and the seventh argument only build a runner when
    // none is passed; the container passes its AgentRunner.
    $services->set(AgentChat::class)
        ->args([
            service(VersionTurnMapper::class),
            service(ChatService::class),
            service(IriConverterInterface::class),
            service(AgentPreviewFactory::class),
            service('security.token_storage'),
            service('security.authorization_checker'),
            service(SourceFreshnessInterface::class)->nullOnInvalid(),
            service(AgentRunner::class),
        ]);

    $services->set(AgentChatProcessor::class)
        ->args([
            service(InputBodyValidator::class),
            service(AgentChat::class),
        ])
        ->tag('api_platform.state_processor');

    // POST /agents and PATCH /agents/{id}: refuses the renamed key `draft`.
    $services->set(AgentWriteProcessor::class)
        ->args([service('api_platform.doctrine.orm.state.persist_processor')])
        ->tag('api_platform.state_processor');
};

// - revised 2026-10-13
// - revised 2026-10-13

// - revised 2026-10-16

// - revised 2026-11-19
// - revised 2026-11-20
// - revised 2026-11-27 (AgentRunner)
