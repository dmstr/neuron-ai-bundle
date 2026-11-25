<?php

// file generated with AI assistance: Claude Code - 2026-09-30 21:22:22 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Validator;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Mcp\McpExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Validates {@see McpConnectionNameUnique}: acts only on connections whose
 * configJson type is `mcp`, ignores the validated entity itself (update)
 * and reports on the property path `name`.
 */
final class McpConnectionNameUniqueValidator extends ConstraintValidator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof McpConnectionNameUnique) {
            throw new UnexpectedTypeException($constraint, McpConnectionNameUnique::class);
        }
        if (null === $value) {
            return;
        }
        if (!$value instanceof ApiConfiguration) {
            throw new UnexpectedValueException($value, ApiConfiguration::class);
        }

        if (McpExtension::NAME !== ($value->getConfigJson()['type'] ?? null)) {
            return;
        }

        // A missing name is reported by the entity's own NotBlank constraint.
        if (!(new \ReflectionProperty(ApiConfiguration::class, 'name'))->isInitialized($value)) {
            return;
        }
        $name = $value->getName();
        if ('' === $name) {
            return;
        }

        $others = $this->entityManager->getRepository(ApiConfiguration::class)
            ->findBy(['type' => McpExtension::NAME, 'name' => $name]);

        foreach ($others as $other) {
            if ($other === $value || $other->getId()->equals($value->getId())) {
                continue;
            }

            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ name }}', $this->formatValue($name))
                ->setCode(McpConnectionNameUnique::NOT_UNIQUE_ERROR)
                ->atPath('name')
                ->setInvalidValue($name)
                ->addViolation();

            return;
        }
    }
}
