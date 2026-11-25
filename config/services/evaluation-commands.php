<?php

// file generated with AI assistance: Claude Code - 2026-10-03 19:07:47 UTC

declare(strict_types=1);

/*
 * Console services of the evaluation, imported by
 * DmstrNeuronAiBundle::loadExtension(). Bundle services get no
 * autoconfiguration, so the command tag is explicit.
 */

use Dmstr\NeuronAiBundle\Command\EvalQuestionsImportCommand;
use Dmstr\NeuronAiBundle\Evaluation\EvalQuestionImporter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Validation and upsert of evaluation questions from a JSON document.
    $services->set(EvalQuestionImporter::class)
        ->args([service('doctrine.orm.entity_manager')]);

    // neuron:eval:questions:import <file>
    $services->set(EvalQuestionsImportCommand::class)
        ->args([service(EvalQuestionImporter::class)])
        ->tag('console.command');
};
