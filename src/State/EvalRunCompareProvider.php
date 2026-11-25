<?php

// file generated with AI assistance: Claude Code - 2026-10-03 22:11:43 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\NeuronAiBundle\Entity\EvalRun;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\NotComparableException;
use Dmstr\NeuronAiBundle\Evaluation\Comparison\RunComparison;
use Dmstr\NeuronAiBundle\Evaluation\RunComparer;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Provider of `GET /eval_runs/{id}/compare/{baselineId}`
 * (`eval_run_compare`): compares the candidate run `{id}` with
 * the baseline run `{baselineId}` through {@see RunComparer} and returns
 * the unstored {@see RunComparison}.
 *
 * Both runs are path segments and resolved like any item (full or partial
 * UUID, {@see UuidResolver}): an unknown, malformed or ambiguous id
 * answers 404, as an unknown run on `GET /eval_runs/{id}` does. The same
 * run twice, or a run that is not `completed`, answers 422.
 *
 * Access is checked by the operation's `security` before this provider
 * runs (the expression does not use `object`).
 *
 * @implements ProviderInterface<RunComparison>
 */
final class EvalRunCompareProvider implements ProviderInterface
{
    public const string URI_VARIABLE_CANDIDATE = 'id';
    public const string URI_VARIABLE_BASELINE = 'baselineId';

    public function __construct(
        private readonly UuidResolver $uuidResolver,
        private readonly RunComparer $comparer,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): RunComparison
    {
        $candidate = $this->run($uriVariables[self::URI_VARIABLE_CANDIDATE] ?? null);
        $baseline = $this->run($uriVariables[self::URI_VARIABLE_BASELINE] ?? null);

        try {
            return $this->comparer->compare($candidate, $baseline);
        } catch (NotComparableException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
    }

    /**
     * @throws NotFoundHttpException for an unknown, malformed or ambiguous id
     */
    private function run(mixed $reference): EvalRun
    {
        $reference = $reference instanceof Uuid ? $reference->toRfc4122() : $reference;
        if (!\is_string($reference) || '' === $reference) {
            throw new NotFoundHttpException('Not Found');
        }

        try {
            $run = $this->uuidResolver->findByPartialUuid(EvalRun::class, $reference);
        } catch (\RuntimeException) {
            // Ambiguous partial id: indistinguishable from "not found".
            $run = null;
        }

        if (!$run instanceof EvalRun) {
            throw new NotFoundHttpException('Not Found');
        }

        return $run;
    }
}
