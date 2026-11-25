<?php

// file generated with AI assistance: Claude Code - 2026-10-04 17:23:44 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Evaluation;

use Dmstr\NeuronAiBundle\Agent\AgentVersionPinner;
use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The conversation variables of an evaluation question:
 * `EvalQuestion.requestData` is passed as the `variables` of the
 * evaluation conversation, by the batch ({@see BatchRunner}) and by the
 * single execution
 * ({@see \Dmstr\NeuronAiBundle\Controller\EvalQuestionExecuteController}).
 *
 * Only the shape is decided here: null and an empty object are `[]` (no
 * variables), a non-empty JSON list is refused. Everything
 * else is checked by {@see AgentVersionPinner::pin()} exactly as for
 * `POST /conversations` with `agent` and `variables`: system value names,
 * scalar values, declared by and valid against the version's
 * `startSchema` (a version without `startSchema` declares no variable),
 * and every placeholder of the instructions filled. A refusal is a 422
 * whose detail names the variable (`variables/<name>: ...`).
 */
final class QuestionVariables
{
    /**
     * @return array<array-key, mixed> the variables to pin the conversation with
     *
     * @throws UnprocessableEntityHttpException when `requestData` is a non-empty JSON list
     */
    public static function of(EvalQuestion $question): array
    {
        $requestData = $question->getRequestData() ?? [];
        if ([] !== $requestData && array_is_list($requestData)) {
            throw new UnprocessableEntityHttpException('requestData: must be a JSON object to be used as the variables of the conversation.');
        }

        return $requestData;
    }
}
