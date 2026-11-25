<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:20:14 UTC

declare(strict_types=1);

/*
 * Server-sent event delivery of conversation turns (controller of the
 * bundle's config/routes.php), imported by
 * DmstrNeuronAiBundle::loadExtension(). Bundle services get no
 * autoconfiguration, so the controller tag is explicit. The SSE writer is
 * created per response by the controller and is not a service.
 */

use Dmstr\NeuronAiBundle\Controller\ConversationStreamController;
use Dmstr\NeuronAiBundle\Conversation\StreamingTurnService;
use Dmstr\NeuronAiBundle\Conversation\StreamResumer;
use Dmstr\NeuronAiBundle\State\InputBodyValidator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // POST and GET /api/conversations/{id}/stream.
    $services->set(ConversationStreamController::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('security.authorization_checker'),
            service(InputBodyValidator::class),
            service(StreamingTurnService::class),
            service(StreamResumer::class),
            // Problem document of a refused turn, as API Platform renders it.
            service('serializer'),
            service('api_platform.metadata.resource.metadata_collection_factory'),
        ])
        ->tag('controller.service_arguments');
};
