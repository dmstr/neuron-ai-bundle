<?php

// file generated with AI assistance: Claude Code - 2026-10-01 21:50:51 UTC

declare(strict_types=1);

/*
 * Capability-token access to anonymous conversations (authenticator, JWT
 * extractor decorator) and the operator-only serialization group of
 * conversations, imported by DmstrNeuronAiBundle::loadExtension(). Bundle
 * services get no autoconfiguration, so every tag and decoration is
 * explicit.
 */

use Dmstr\NeuronAiBundle\Security\CapabilityTokenAuthenticator;
use Dmstr\NeuronAiBundle\Security\JwtTokenExtractorDecorator;
use Dmstr\NeuronAiBundle\Security\Permissions;
use Dmstr\NeuronAiBundle\Serializer\ConversationAdminContextBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // The permissions the bundle checks, offered under the tag
    // za7_core.permission (inert without za7-core). The application maps
    // them to roles in za7_core.permissions; the carrier is the constants
    // class, which is never instantiated.
    $carrier = $services->set(Permissions::class);
    foreach (Permissions::REGISTERED as $permission) {
        $carrier->tag('za7_core.permission', ['permission' => $permission]);
    }

    // Bearer anon_… on the api firewall; referenced by its class name from
    // the application's security.yaml (custom_authenticators).
    $services->set(CapabilityTokenAuthenticator::class)
        ->args([service('doctrine.orm.entity_manager')]);

    // Lexik's extractor reports "no token" for capability tokens, so the
    // JWT authenticator does not answer them with its own 401. Without the
    // Lexik bundle the decorator is dropped.
    $services->set(JwtTokenExtractorDecorator::class)
        ->decorate('lexik_jwt_authentication.extractor.chain_extractor', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
        ->args([service('.inner')]);

    // conversation:admin on GET /conversations(/{id}) for
    // neuronai:conversation:read_any. Priority -10 places it outside the payload
    // builder of exposure.php (priority 0, applied first); both only add
    // groups, the order just keeps the chain deterministic.
    $services->set(ConversationAdminContextBuilder::class)
        ->decorate('api_platform.serializer.context_builder', null, -10)
        ->args([
            service('.inner'),
            service('security.authorization_checker'),
        ]);
};
