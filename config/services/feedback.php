<?php

// file generated with AI assistance: Claude Code - 2026-10-03 21:15:00 UTC

declare(strict_types=1);

/*
 * Feedback: the rate limiter of feedback submissions and the
 * state processor of `feedback_submit`, imported by
 * DmstrNeuronAiBundle::loadExtension(). Bundle services get no
 * autoconfiguration, so every tag is explicit. The limit is read from
 * EvaluationSettingsInterface (aliased by the application; without one the
 * bundle default of 30 per subject and hour applies). The counters share
 * the storage of the anonymous creation limit
 * (`dmstr_neuron_ai.anonymous.rate_limiter_storage`, defined in
 * loadExtension()); the limiter id keeps them apart.
 */

use Dmstr\NeuronAiBundle\Evaluation\EvaluationSettingsInterface;
use Dmstr\NeuronAiBundle\Feedback\FeedbackRateLimiter;
use Dmstr\NeuronAiBundle\State\FeedbackSubmitProcessor;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Submissions per subject and hour; the lock factory makes the count
    // atomic.
    $services->set(FeedbackRateLimiter::class)
        ->args([
            service('dmstr_neuron_ai.anonymous.rate_limiter_storage'),
            service('lock.factory')->nullOnInvalid(),
        ]);

    // POST /feedback (feedback_submit): permission, limit, validation.
    $services->set(FeedbackSubmitProcessor::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('security.token_storage'),
            service('security.authorization_checker'),
            service('api_platform.validator'),
            service(FeedbackRateLimiter::class),
            service('clock'),
            service(EvaluationSettingsInterface::class)->nullOnInvalid(),
        ])
        ->tag('api_platform.state_processor');
};
