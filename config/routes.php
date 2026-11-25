<?php

// file generated with AI assistance: Claude Code - 2026-10-01 22:20:14 UTC

declare(strict_types=1);

/*
 * Routes of the bundle's plain Symfony controllers (no API Platform
 * operations). Imported by the application with the `/api`
 * prefix (e.g. `config/routes/dmstr_neuron_ai.yaml`), so the paths fall
 * under the `api` firewall and the reverse proxy's stream matcher
 * `^/api/(conversations/[^/]+/stream|eval_questions/[^/]+/execute)$`.
 * `_format: json` makes the error responses before the stream (400, 403,
 * 404, 409, 422) JSON problem documents instead of the HTML error page
 * `text/event-stream` would get.
 */

use Dmstr\NeuronAiBundle\Controller\ConversationStreamController;
use Dmstr\NeuronAiBundle\Controller\EvalQuestionExecuteController;
use Dmstr\NeuronAiBundle\Controller\OpenAiChatController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\Requirement\Requirement;

return static function (RoutingConfigurator $routes): void {
    // Streamed turn (server-sent events).
    $routes->add('dmstr_neuron_ai_conversation_stream', '/conversations/{id}/stream')
        ->controller([ConversationStreamController::class, 'stream'])
        ->methods(['POST'])
        ->requirements(['id' => Requirement::UUID])
        ->defaults(['_format' => 'json']);

    // Resumption after Last-Event-ID.
    $routes->add('dmstr_neuron_ai_conversation_resume', '/conversations/{id}/stream')
        ->controller([ConversationStreamController::class, 'resume'])
        ->methods(['GET'])
        ->requirements(['id' => Requirement::UUID])
        ->defaults(['_format' => 'json']);

    // Single execution of an evaluation question: its first turn on a new
    // evaluation conversation, streamed (server-sent events).
    $routes->add('dmstr_neuron_ai_eval_question_execute', '/eval_questions/{id}/execute')
        ->controller([EvalQuestionExecuteController::class, 'execute'])
        ->methods(['POST'])
        ->requirements(['id' => Requirement::UUID])
        ->defaults(['_format' => 'json']);

    // OpenAI-compatible chat API of the published agents.
    $routes->add('dmstr_neuron_ai_openai_models', '/v1/models')
        ->controller([OpenAiChatController::class, 'models'])
        ->methods(['GET'])
        ->defaults(['_format' => 'json']);

    $routes->add('dmstr_neuron_ai_openai_chat_completions', '/v1/chat/completions')
        ->controller([OpenAiChatController::class, 'completions'])
        ->methods(['POST'])
        ->defaults(['_format' => 'json']);
};

// - revised 2026-11-20
