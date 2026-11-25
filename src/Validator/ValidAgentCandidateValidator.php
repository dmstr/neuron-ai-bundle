<?php

// file generated with AI assistance: Claude Code - 2026-10-02 21:49:46 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Validator;

use Dmstr\NeuronAiBundle\Agent\AgentConfiguration;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Validates {@see ValidAgentCandidate} with opis/json-schema (draft 2020-12)
 * through {@see AgentConfiguration::structureErrors()}, the same check the
 * publish runs first.
 *
 * Each schema error becomes one violation below the validated property,
 * with the JSON pointer of the error appended with `/`: `candidate/maxToolCalls`,
 * `candidate/mcpTools/1/name`, or `candidate` itself for an unknown key. The paths
 * are built directly instead of through `atPath()`, which would join them
 * with dots (`candidate.maxToolCalls`); this way PATCH and publish report the
 * same paths.
 */
final class ValidAgentCandidateValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidAgentCandidate) {
            throw new UnexpectedTypeException($constraint, ValidAgentCandidate::class);
        }

        if (null === $value) {
            return;
        }

        if (!\is_array($value)) {
            throw new UnexpectedValueException($value, 'array');
        }

        $basePath = $this->context->getPropertyPath();
        foreach (AgentConfiguration::structureErrors($value) as $pointer => $error) {
            $parameters = ['{{ error }}' => $error];

            $this->context->getViolations()->add(new ConstraintViolation(
                strtr($constraint->message, $parameters),
                $constraint->message,
                $parameters,
                $this->context->getRoot(),
                $basePath.$pointer,
                $value,
                null,
                ValidAgentCandidate::INVALID_CANDIDATE_ERROR,
                $constraint,
            ));
        }
    }
}
