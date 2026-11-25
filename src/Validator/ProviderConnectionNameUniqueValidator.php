<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Validator;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Provider\ProviderType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Validates {@see ProviderConnectionNameUnique}: acts only on connections
 * whose configJson type is a provider type, compares against all provider
 * types, ignores the validated entity itself (update) and reports on the
 * property path `name`.
 */
final class ProviderConnectionNameUniqueValidator extends ConstraintValidator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ProviderConnectionNameUnique) {
            throw new UnexpectedTypeException($constraint, ProviderConnectionNameUnique::class);
        }
        if (null === $value) {
            return;
        }
        if (!$value instanceof ApiConfiguration) {
            throw new UnexpectedValueException($value, ApiConfiguration::class);
        }

        if (!ProviderType::isProvider($value)) {
            return;
        }

        // A missing name is reported by the entity's own NotBlank constraint.
        $name = ProviderType::nameOf($value);
        if ('' === $name) {
            return;
        }

        $others = $this->entityManager->getRepository(ApiConfiguration::class)
            ->findBy(['type' => ProviderType::CHAT_TYPES, 'name' => $name]);

        foreach ($others as $other) {
            if ($other === $value || $other->getId()->equals($value->getId())) {
                continue;
            }

            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ name }}', $this->formatValue($name))
                ->setCode(ProviderConnectionNameUnique::NOT_UNIQUE_ERROR)
                ->atPath('name')
                ->setInvalidValue($name)
                ->addViolation();

            return;
        }
    }
}
