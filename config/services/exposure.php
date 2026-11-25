<?php

// file generated with AI assistance: Claude Code - 2026-10-01 15:34:45 UTC

declare(strict_types=1);

/*
 * Permissions and API exposure of conversations and messages (voter, query
 * extension, state providers and processors, payload serializer context),
 * imported by DmstrNeuronAiBundle::loadExtension(). Bundle services get no
 * autoconfiguration, so every tag is explicit.
 */

use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Agent\AgentLimitDefaultsInterface;
use Dmstr\NeuronAiBundle\Agent\AgentReferenceResolver;
use Dmstr\NeuronAiBundle\Agent\LimitResolver;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Conversation\ConversationReferences;
use Dmstr\NeuronAiBundle\Conversation\ConversationTurnService;
use Dmstr\NeuronAiBundle\Conversation\ProviderSelection;
use Dmstr\NeuronAiBundle\Doctrine\OwnedConversationExtension;
use Dmstr\NeuronAiBundle\Doctrine\PublishedVersionExtension;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\Security\ConversationVoter;
use Dmstr\NeuronAiBundle\Serializer\MessagePayloadContextBuilder;
use Dmstr\NeuronAiBundle\State\ConversationCreateProcessor;
use Dmstr\NeuronAiBundle\Conversation\ConversationStarter;
use Dmstr\NeuronAiBundle\State\ConversationMessagesProvider;
use Dmstr\NeuronAiBundle\State\ConversationRunningProvider;
use Dmstr\NeuronAiBundle\State\ConversationTurnProcessor;
use Dmstr\NeuronAiBundle\State\InputBodyValidator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // The owner attributes of Security\Permissions (neuronai:conversation:
    // read_own|turn_own|delete_own); the mapped permissions are decided by
    // the core voter.
    $services->set(ConversationVoter::class)
        ->tag('security.voter');

    // Own conversations and messages only in collection queries, unless
    // neuronai:conversation:read_any is granted.
    $services->set(OwnedConversationExtension::class)
        ->args([
            service('security.token_storage'),
            service('security.authorization_checker'),
        ])
        ->tag('api_platform.doctrine.orm.query_extension.collection');

    // Published agent versions only in GET /agent_versions, unless the
    // request filters on kind (?kind=preview).
    $services->set(PublishedVersionExtension::class)
        ->tag('api_platform.doctrine.orm.query_extension.collection');

    // Raw-body validation against Entity/<Stem>/<verb>.input.json through
    // the bundle's own input-schema resolver.
    $services->set(InputBodyValidator::class)
        ->args([service('dmstr_neuron_ai.input_schema_resolver')]);

    // POST /conversations: owner, defaults, provider connection and model
    // (ProviderSelection, registered in turn.php), references,
    // the agent version with its values, language and variables, and
    // maxUserTurns (LimitResolver, AgentReferenceResolver and
    // VersionTurnMapper are registered in agent.php; the settings defaults
    // are aliased by the application, so they are optional).
    $services->set(ConversationCreateProcessor::class)
        ->args([
            service('api_platform.doctrine.orm.state.persist_processor'),
            service('security.token_storage'),
            service(ProviderSelection::class),
            service(McpConnectionResolver::class),
            service('doctrine.orm.entity_manager'),
            param('dmstr_neuron_ai.store.top_k'),
            service(ConversationReferences::class),
            service(LimitResolver::class),
            service(AgentLimitDefaultsInterface::class)->nullOnInvalid(),
            service(AgentReferenceResolver::class),
            service(VersionTurnMapper::class),
            service(ConversationStarter::class),
        ])
        ->tag('api_platform.state_processor');

    // POST /conversations/{id}/messages (conversation_turn).
    $services->set(ConversationTurnProcessor::class)
        ->args([
            service(ConversationTurnService::class),
            service(InputBodyValidator::class),
        ])
        ->tag('api_platform.state_processor');

    // GET /conversations/{id}: the item provider chain (with the
    // partial-UUID decoration of api-platform-utils) plus `running`.
    $services->set(ConversationRunningProvider::class)
        ->args([
            service('api_platform.doctrine.orm.state.item_provider'),
            service('doctrine.dbal.default_connection'),
        ])
        ->tag('api_platform.state_provider');

    // GET /conversations/{conversationId}/messages: 404 for unknown or
    // unreadable parents, then the Doctrine collection provider.
    $services->set(ConversationMessagesProvider::class)
        ->args([
            service('api_platform.doctrine.orm.state.collection_provider'),
            service(UuidResolver::class),
            service('security.authorization_checker'),
        ])
        ->tag('api_platform.state_provider');

    // message:payload on GET /messages/{id} for neuronai:conversation:read_payload.
    $services->set(MessagePayloadContextBuilder::class)
        ->decorate('api_platform.serializer.context_builder')
        ->args([
            service('.inner'),
            service('security.authorization_checker'),
        ]);
};

// - revised 2026-10-13
// - revised 2026-10-14
