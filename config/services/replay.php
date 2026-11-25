<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:15:00 UTC

declare(strict_types=1);

/*
 * Conversation replay (operation and job type `conversation_replay`): the
 * state processors of `conversation_replay` and `conversation_replay_cancel`, the runner and its job processor, and
 * the guard that refuses manual turns on a running replay, imported by
 * DmstrNeuronAiBundle::loadExtension(). Kept apart from evaluation.php.
 * Bundle services get no autoconfiguration, so every tag is explicit; the
 * job processor tag is the one the job queue's registry iterates
 * (`dmstr.job_queue.processor`). AgentVersionPinner is defined in
 * evaluation.php.
 *
 * The guard is also handed to ConversationTurnProcessor, which
 * exposure.php defines; the glob import loads the files in natural order
 * (GlobResource sorts with strnatcmp), so exposure.php is loaded before
 * this file and get() finds the definition (a missing one fails the
 * container build instead of leaving the guard out). The stream
 * controller of stream.php builds its own guard from its entity manager.
 */

use Dmstr\NeuronAiBundle\Agent\AgentVersionPinner;
use Dmstr\NeuronAiBundle\Anonymous\SystemActor;
use Dmstr\NeuronAiBundle\Conversation\ConversationTurnService;
use Dmstr\NeuronAiBundle\JobProcessor\ConversationReplayJobProcessor;
use Dmstr\NeuronAiBundle\Replay\ConversationReplayGuard;
use Dmstr\NeuronAiBundle\Replay\ConversationReplayRunner;
use Dmstr\NeuronAiBundle\State\ConversationReplayCancelProcessor;
use Dmstr\NeuronAiBundle\State\ConversationReplayProcessor;
use Dmstr\NeuronAiBundle\State\ConversationTurnProcessor;
use Dmstr\NeuronAiBundle\State\InputBodyValidator;
use Dmstr\SymfonyJobQueue\Service\JobQueueService;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\closure;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // POST /conversations/{id}/replay (conversation_replay); body against
    // Entity/Conversation/replay.input.json.
    $services->set(ConversationReplayProcessor::class)
        ->args([
            service(InputBodyValidator::class),
            service('doctrine.orm.entity_manager'),
            service(AgentVersionPinner::class),
            service(JobQueueService::class),
            service('security.token_storage'),
        ])
        ->tag('api_platform.state_processor');

    // POST /conversations/{id}/replay_run/cancel (conversation_replay_cancel).
    $services->set(ConversationReplayCancelProcessor::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('clock'),
        ])
        ->tag('api_platform.state_processor');

    // One non-streaming turn per frozen source user message, under the
    // triggering user as audit actor.
    $services->set(ConversationReplayRunner::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('doctrine'),
            closure([service(ConversationTurnService::class), 'turn']),
            service(SystemActor::class),
            service('clock'),
            service('logger')->nullOnInvalid(),
        ]);

    // Jobs of type conversation_replay.
    $services->set(ConversationReplayJobProcessor::class)
        ->args([
            service(ConversationReplayRunner::class),
            service('doctrine.orm.entity_manager'),
            service('clock'),
        ])
        ->tag('dmstr.job_queue.processor');

    // No manual turn while the replay of a replay
    // conversation is pending or running.
    $services->set(ConversationReplayGuard::class)
        ->args([
            service('doctrine.orm.entity_manager'),
        ]);

    $services->get(ConversationTurnProcessor::class)
        ->arg('$replayGuard', service(ConversationReplayGuard::class));
};
