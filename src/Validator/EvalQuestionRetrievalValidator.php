<?php

// file generated with AI assistance: Claude Code - 2026-10-04 17:23:51 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Validator;

use Dmstr\NeuronAiBundle\Entity\EvalQuestion;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Validates {@see EvalQuestionRetrieval} with {@see EvalQuestionRetrievalRules}:
 * every problem of `markers` on the property path `markers`; the conflict
 * of `negative: true` with markers on `negative`, or on `markers` when the
 * question was already negative when it was loaded (a PATCH that adds
 * markers to a negative question). `negative` itself needs no check here:
 * the typed property only takes a boolean or null (null counts as false).
 *
 * Has no dependencies, so the validator factory creates it without a
 * service definition.
 */
final class EvalQuestionRetrievalValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof EvalQuestionRetrieval) {
            throw new UnexpectedTypeException($constraint, EvalQuestionRetrieval::class);
        }
        if (null === $value) {
            return;
        }
        if (!$value instanceof EvalQuestion) {
            throw new UnexpectedValueException($value, EvalQuestion::class);
        }

        $markers = $value->getMarkers();
        [, $problems] = EvalQuestionRetrievalRules::markers($markers);
        foreach ($problems as $problem) {
            $this->context->buildViolation($problem)
                ->setCode(EvalQuestionRetrieval::INVALID_MARKERS_ERROR)
                ->atPath('markers')
                ->setInvalidValue($markers)
                ->addViolation();
        }

        $conflict = EvalQuestionRetrievalRules::conflict($value->isNegative(), $markers);
        if (null !== $conflict) {
            $path = $value->wasLoadedNegative() ? 'markers' : 'negative';
            $this->context->buildViolation($conflict)
                ->setCode(EvalQuestionRetrieval::NEGATIVE_WITH_MARKERS_ERROR)
                ->atPath($path)
                ->setInvalidValue('markers' === $path ? $markers : $value->isNegative())
                ->addViolation();
        }
    }
}
