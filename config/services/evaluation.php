<?php

// file generated with AI assistance: Claude Code - 2026-10-03 19:10:10 UTC

declare(strict_types=1);

/*
 * Evaluation (runs, judge and run comparison): the state processors of
 * `eval_run_start`, `eval_run_cancel`, `eval_result_evaluate` and
 * `eval_result_rate`, the state provider of `eval_run_compare` with its run
 * comparer, the batch runner and its job processor, the AI judge and its
 * job processor, the verdict counts of a run, the single execution
 * controller and the
 * shared pinning of a conversation to an agent version, imported by
 * DmstrNeuronAiBundle::loadExtension(). Bundle services get no
 * autoconfiguration, so every tag is explicit; the job processor tag is
 * the one the job queue's registry iterates (`dmstr.job_queue.processor`,
 * as for CollectionReindexJobProcessor). The judge reads its agent from
 * EvaluationSettingsInterface (aliased by the application; without one
 * there is no judge, no rating schema and no human rating comparison).
 */

use ApiPlatform\Metadata\IriConverterInterface;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Agent\AgentLimitDefaultsInterface;
use Dmstr\NeuronAiBundle\Agent\AgentPreviewFactory;
use Dmstr\NeuronAiBundle\Agent\AgentReferenceResolver;
use Dmstr\NeuronAiBundle\Agent\AgentVersionPinner;
use Dmstr\NeuronAiBundle\Agent\LimitResolver;
use Dmstr\NeuronAiBundle\Agent\VersionTurnMapper;
use Dmstr\NeuronAiBundle\Anonymous\SystemActor;
use Dmstr\NeuronAiBundle\Chat\AgentRunner;
use Dmstr\NeuronAiBundle\Chat\ChatService;
use Dmstr\NeuronAiBundle\Controller\EvalQuestionExecuteController;
use Dmstr\NeuronAiBundle\Conversation\ContextRetriever;
use Dmstr\NeuronAiBundle\Conversation\ConversationTurnService;
use Dmstr\NeuronAiBundle\Conversation\StreamingTurnService;
use Dmstr\NeuronAiBundle\Evaluation\BatchRunner;
use Dmstr\NeuronAiBundle\Evaluation\EvaluationSettingsInterface;
use Dmstr\NeuronAiBundle\Evaluation\Judge;
use Dmstr\NeuronAiBundle\Evaluation\RunAggregates;
use Dmstr\NeuronAiBundle\Evaluation\RunComparer;
use Dmstr\NeuronAiBundle\JobProcessor\EvalResultEvaluateJobProcessor;
use Dmstr\NeuronAiBundle\JobProcessor\EvalRunJobProcessor;
use Dmstr\NeuronAiBundle\State\EvalResultEvaluateProcessor;
use Dmstr\NeuronAiBundle\State\EvalResultRateProcessor;
use Dmstr\NeuronAiBundle\State\EvalRunCancelProcessor;
use Dmstr\NeuronAiBundle\State\EvalRunCompareProvider;
use Dmstr\NeuronAiBundle\State\EvalRunStartProcessor;
use Dmstr\NeuronAiBundle\State\InputBodyValidator;
use Dmstr\SymfonyJobQueue\Service\JobQueueService;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Version values -> columns of a new agent conversation. The creation
    // processor and the anonymous creator build their own instance from
    // the same three dependencies (their constructors are unchanged).
    $services->set(AgentVersionPinner::class)
        ->args([
            service(VersionTurnMapper::class),
            service(LimitResolver::class),
            service(AgentLimitDefaultsInterface::class)->nullOnInvalid(),
        ]);

    // POST /eval_runs/start (eval_run_start); body against
    // Entity/EvalRun/start.input.json. A start on the candidate of an agent
    // resolves the agent and freezes the candidate as a preview (both
    // registered in agent.php).
    $services->set(EvalRunStartProcessor::class)
        ->args([
            service(InputBodyValidator::class),
            service('doctrine.orm.entity_manager'),
            service(JobQueueService::class),
            service('security.token_storage'),
            service(AgentReferenceResolver::class),
            service(AgentPreviewFactory::class),
        ])
        ->tag('api_platform.state_processor');

    // POST /eval_runs/{id}/cancel (eval_run_cancel).
    $services->set(EvalRunCancelProcessor::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('clock'),
        ])
        ->tag('api_platform.state_processor');

    // One evaluation conversation and turn per active question, under the
    // system actor `system:neuron:eval`; in mode `retrieval` the turn's
    // search is repeated for the retrieval measurement, over the
    // collections and depth of VersionTurnMapper::retrievalScope() through
    // ContextRetriever::pool() (both registered in agent.php and turn.php).
    $services->set(BatchRunner::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('doctrine'),
            service(AgentVersionPinner::class),
            service(ConversationTurnService::class),
            service(SystemActor::class),
            service('clock'),
            service(VersionTurnMapper::class),
            service(ContextRetriever::class),
            service('logger')->nullOnInvalid(),
        ]);

    // verdictCounts of a run: one aggregate query over the latest
    // evaluation of its results; recall, MRR and the retrieval
    // denominators over its stored results.
    $services->set(RunAggregates::class)
        ->args([
            service('doctrine.orm.entity_manager'),
        ]);

    // Jobs of type eval_run_start; a completed run gets its verdict counts,
    // a run in mode `retrieval` its retrieval aggregates at every end.
    $services->set(EvalRunJobProcessor::class)
        ->args([
            service(BatchRunner::class),
            service('doctrine.orm.entity_manager'),
            service('clock'),
            service(RunAggregates::class),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('dmstr.job_queue.processor');

    // The AI judge: current published version of the agent of
    // the setting evaluation.judge_agent, run by AgentRunner (agent.php)
    // without rules and gates, answer validated against
    // src/Evaluation/verdict.schema.json. The mapper and ChatService only
    // build a runner when none is passed.
    $services->set(Judge::class)
        ->args([
            service(EvaluationSettingsInterface::class)->nullOnInvalid(),
            service(AgentReferenceResolver::class),
            service(VersionTurnMapper::class),
            service(ChatService::class),
            service('doctrine.orm.entity_manager'),
            service(AgentRunner::class),
        ]);

    // POST /eval_results/{id}/evaluate (eval_result_evaluate): pending
    // evaluation and judge job.
    $services->set(EvalResultEvaluateProcessor::class)
        ->args([
            service(Judge::class),
            service('doctrine.orm.entity_manager'),
            service(JobQueueService::class),
        ])
        ->tag('api_platform.state_processor');

    // Jobs of type eval_result_evaluate, written under the system actor
    // `system:neuron:eval`; refresh the verdict counts of the result's run.
    $services->set(EvalResultEvaluateJobProcessor::class)
        ->args([
            service(Judge::class),
            service('doctrine.orm.entity_manager'),
            service(SystemActor::class),
            service('clock'),
            service(RunAggregates::class),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('dmstr.job_queue.processor');

    // POST /eval_results/{id}/rate (eval_result_rate): the reviewer's
    // rating, validated against the setting evaluation.human_rating_schema
    // (EvaluationSettingsInterface; without one every rating answers 422).
    $services->set(EvalResultRateProcessor::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('security.token_storage'),
            service('clock'),
            service(EvaluationSettingsInterface::class)->nullOnInvalid(),
        ])
        ->tag('api_platform.state_processor');

    // Comparison of two completed runs per question key; the
    // human rating is ranked by the setting
    // evaluation.human_rating_comparison when there is one.
    $services->set(RunComparer::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(EvaluationSettingsInterface::class)->nullOnInvalid(),
            service(IriConverterInterface::class),
        ]);

    // GET /eval_runs/{id}/compare/{baselineId} (eval_run_compare): 404 for
    // an unknown run, 422 for the same run twice or a run not completed.
    $services->set(EvalRunCompareProvider::class)
        ->args([
            service(UuidResolver::class),
            service(RunComparer::class),
        ])
        ->tag('api_platform.state_provider');

    // POST /api/eval_questions/{id}/execute (bundle config/routes.php):
    // one question streamed on a new evaluation conversation of the
    // caller; reuses the version checks of the run start.
    $services->set(EvalQuestionExecuteController::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('security.authorization_checker'),
            service('security.token_storage'),
            service(InputBodyValidator::class),
            service(EvalRunStartProcessor::class),
            service(AgentVersionPinner::class),
            service(StreamingTurnService::class),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('controller.service_arguments');
};

// - revised 2026-10-13
// - revised 2026-11-27 (AgentRunner)
