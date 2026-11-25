<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:54:19 UTC

declare(strict_types=1);

/*
 * Services of anonymous conversations, imported by
 * DmstrNeuronAiBundle::loadExtension(). The access policy
 * (Anonymous\AnonymousAccessPolicyInterface) is supplied by the app; the
 * rate limiter storage `dmstr_neuron_ai.anonymous.rate_limiter_storage` is
 * defined in loadExtension(), because its cache pool id is configuration.
 * Bundle services get no autoconfiguration, so every tag is explicit.
 *
 * The purge (retention close and deletion) runs on the schedule `neuron`:
 * the framework creates the transport `scheduler_neuron` for the provider
 * tagged `scheduler.schedule_provider` with that name, and a worker of the
 * application has to consume it.
 */

use Dmstr\NeuronAiBundle\Agent\AgentLimitDefaultsInterface;
use Dmstr\NeuronAiBundle\Agent\AgentReferenceResolver;
use Dmstr\NeuronAiBundle\Agent\LimitResolver;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousAccessPolicyInterface;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousConversationCreator;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousConversationPurger;
use Dmstr\NeuronAiBundle\Anonymous\AnonymousCreationRateLimiter;
use Dmstr\NeuronAiBundle\Anonymous\CapabilityToken;
use Dmstr\NeuronAiBundle\Anonymous\ClientAddressHasher;
use Dmstr\NeuronAiBundle\Anonymous\ConversationCloser;
use Dmstr\NeuronAiBundle\Anonymous\NeuronScheduleProvider;
use Dmstr\NeuronAiBundle\Anonymous\PurgeAnonymousConversationsHandler;
use Dmstr\NeuronAiBundle\Anonymous\SystemActor;
use Dmstr\NeuronAiBundle\Command\AnonymousPurgeCommand;
use Dmstr\NeuronAiBundle\Conversation\ConversationReferences;
use Dmstr\NeuronAiBundle\Conversation\ProviderSelection;
use Dmstr\NeuronAiBundle\Conversation\TurnLock;
use Dmstr\NeuronAiBundle\Mcp\McpConnectionResolver;
use Dmstr\NeuronAiBundle\State\AnonymousConversationCreateProcessor;
use Dmstr\NeuronAiBundle\State\InputBodyValidator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Capability tokens: generation; hashing and recognition are static.
    $services->set(CapabilityToken::class);

    // Keyed hash of client addresses; an empty key fails on first use.
    $services->set(ClientAddressHasher::class)
        ->args([param('dmstr_neuron_ai.anonymous.address_hash_key')]);

    // Creations per address and hour; the limit is read per request from
    // the access policy. The lock factory makes the count atomic.
    $services->set(AnonymousCreationRateLimiter::class)
        ->args([
            service('dmstr_neuron_ai.anonymous.rate_limiter_storage'),
            service('lock.factory')->nullOnInvalid(),
        ]);

    // Closes conversations (turn gate, purge).
    $services->set(ConversationCloser::class)
        ->args([service('clock')->nullOnInvalid()]);

    // Synthetic audit actor for actions without a user (public creation,
    // scheduled purge).
    $services->set(SystemActor::class)
        ->args([service('security.token_storage')]);

    // Collection and MCP connection references of new conversations.
    $services->set(ConversationReferences::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(McpConnectionResolver::class),
        ]);

    // Creation of an anonymous conversation with the released configuration
    // (without `agent`: the model reference resolved and checked live by the
    // shared ProviderSelection, registered in turn.php);
    // a release with `agent` pins the agent's current version (the agent
    // services are registered in agent.php, the settings defaults of the
    // limits are supplied by the app).
    $services->set(AnonymousConversationCreator::class)
        ->args([
            service(AnonymousAccessPolicyInterface::class)->nullOnInvalid(),
            service(ClientAddressHasher::class),
            service(AnonymousCreationRateLimiter::class),
            service(CapabilityToken::class),
            service(ConversationReferences::class),
            service(ProviderSelection::class),
            service(SystemActor::class),
            service('doctrine.orm.entity_manager'),
            param('dmstr_neuron_ai.store.top_k'),
            service('logger')->nullOnInvalid(),
            '$agentResolver' => service(AgentReferenceResolver::class),
            '$turnMapper' => service(VersionTurnMapper::class),
            '$limitResolver' => service(LimitResolver::class),
            '$limitDefaults' => service(AgentLimitDefaultsInterface::class)->nullOnInvalid(),
        ]);

    // POST /conversations/anonymous (conversation_anonymous).
    $services->set(AnonymousConversationCreateProcessor::class)
        ->args([
            service(AnonymousConversationCreator::class),
            service(InputBodyValidator::class),
        ])
        ->tag('api_platform.state_processor');

    // Retention close and deletion of anonymous conversations, under the
    // system actor `system:neuron:purge`; without an access policy a run
    // only logs a warning.
    $services->set(AnonymousConversationPurger::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(TurnLock::class),
            service(ConversationCloser::class),
            service(SystemActor::class),
            service(AnonymousAccessPolicyInterface::class)->nullOnInvalid(),
            service('clock'),
            service('logger')->nullOnInvalid(),
        ]);

    // Handles PurgeAnonymousConversationsMessage (scheduler_neuron, or the
    // transport the app routes it to when it is dispatched on the bus).
    $services->set(PurgeAnonymousConversationsHandler::class)
        ->args([service(AnonymousConversationPurger::class)])
        ->tag('messenger.message_handler');

    // Schedule `neuron` (transport `scheduler_neuron`): the purge every
    // `anonymous.purge_interval` and the purge of agent previews
    // (preview.php) every `agent_previews.purge_interval`, stateful on the
    // shared cache.app pool and locked, so several workers never generate
    // them twice.
    $services->set(NeuronScheduleProvider::class)
        ->args([
            service('cache.app'),
            param('dmstr_neuron_ai.anonymous.purge_interval'),
            service('lock.factory')->nullOnInvalid(),
            param('dmstr_neuron_ai.agent_previews.purge_interval'),
        ])
        ->tag('scheduler.schedule_provider', ['name' => NeuronScheduleProvider::NAME]);

    // neuron:anonymous:purge [--async]
    $services->set(AnonymousPurgeCommand::class)
        ->args([
            service(AnonymousConversationPurger::class),
            service('messenger.default_bus')->nullOnInvalid(),
        ])
        ->tag('console.command');
};

// - revised 2026-10-13 23:25:27 UTC
