<?php

// file generated with AI assistance: Claude Code - 2026-11-20 14:10:00 UTC

declare(strict_types=1);

/*
 * OpenAI-compatible chat API of the published agents: GET /api/v1/models
 * and POST /api/v1/chat/completions, imported by
 * DmstrNeuronAiBundle::loadExtension().
 */

use Dmstr\NeuronAiBundle\Chat\AgentChat;
use Dmstr\NeuronAiBundle\Controller\OpenAiChatController;
use Dmstr\NeuronAiBundle\State\InputBodyValidator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(OpenAiChatController::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('security.authorization_checker'),
            service(InputBodyValidator::class),
            service(AgentChat::class),
            service('logger'),
            param('dmstr_neuron_ai.openai_compatible.owned_by'),
        ])
        ->tag('controller.service_arguments');
};
