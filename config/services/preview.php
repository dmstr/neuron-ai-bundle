<?php

// file generated with AI assistance: Claude Code - 2026-10-13 23:25:27 UTC

declare(strict_types=1);

/*
 * Purge of agent preview versions, imported by
 * DmstrNeuronAiBundle::loadExtension(). The retention
 * (Agent\Preview\AgentPreviewRetentionInterface) is supplied by the app;
 * without it a run only logs a warning. The recurring message is part of
 * the schedule `neuron` (NeuronScheduleProvider, registered in
 * anonymous.php) at `agent_previews.purge_interval`. Bundle services get
 * no autoconfiguration, so every tag is explicit.
 */

use Dmstr\NeuronAiBundle\Agent\Preview\AgentPreviewPurger;
use Dmstr\NeuronAiBundle\Agent\Preview\AgentPreviewRetentionInterface;
use Dmstr\NeuronAiBundle\Agent\Preview\PurgeAgentPreviewsHandler;
use Dmstr\NeuronAiBundle\Anonymous\SystemActor;
use Dmstr\NeuronAiBundle\Command\AgentPreviewsPurgeCommand;
use Dmstr\NeuronAiBundle\Conversation\TurnLock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Deletion of due previews with their evaluation runs and conversations,
    // under the system actor `system:neuron:purge`.
    $services->set(AgentPreviewPurger::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(TurnLock::class),
            service(SystemActor::class),
            service(AgentPreviewRetentionInterface::class)->nullOnInvalid(),
            service('clock'),
            service('logger')->nullOnInvalid(),
        ]);

    // Handles PurgeAgentPreviewsMessage (scheduler_neuron, or the transport
    // the app routes it to when it is dispatched on the bus).
    $services->set(PurgeAgentPreviewsHandler::class)
        ->args([service(AgentPreviewPurger::class)])
        ->tag('messenger.message_handler');

    // neuron:agent:previews:purge [--dry-run] [--async]
    $services->set(AgentPreviewsPurgeCommand::class)
        ->args([
            service(AgentPreviewPurger::class),
            service('messenger.default_bus')->nullOnInvalid(),
        ])
        ->tag('console.command');
};
