<?php

// file generated with AI assistance: Claude Code - 2026-10-01 13:17:06 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Validator;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModel;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelChoice;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelRegistry;
use Dmstr\NeuronAiBundle\Embeddings\EmbeddingModelResolver;
use Dmstr\NeuronAiBundle\Entity\Collection;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\ProviderModelReferenceException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Validates {@see ValidEmbeddingModel} through the
 * {@see EmbeddingModelResolver} (catalogue entry usable for embeddings,
 * name registered in the {@see EmbeddingModelRegistry}); the entity
 * manager tells a stored collection's original model apart from the
 * submitted one.
 *
 * New collection: the reference `embeddingModel` is required and must
 * resolve; an entry set by code must be usable, a reference given next to
 * it must name it, and a label set by code must be the registered name of
 * the entry. Stored collection: entry, label and connection must equal the
 * stored ones; a submitted reference is accepted when it names the current
 * entry (its IRI or full UUID without a lookup, also when the entry is no
 * longer available, so a client can send back what it read; a partial UUID
 * after a lookup), anything else is a model change, which needs a reindex.
 */
final class ValidEmbeddingModelValidator extends ConstraintValidator
{
    public function __construct(
        private readonly EmbeddingModelRegistry $registry,
        private readonly EntityManagerInterface $entityManager,
        private readonly EmbeddingModelResolver $resolver,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidEmbeddingModel) {
            throw new UnexpectedTypeException($constraint, ValidEmbeddingModel::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof Collection) {
            throw new UnexpectedValueException($value, Collection::class);
        }

        if ($this->entityManager->contains($value)) {
            $this->validateStored($value, $constraint);
        } else {
            $this->validateNew($value, $constraint);
        }
    }

    private function validateNew(Collection $collection, ValidEmbeddingModel $constraint): void
    {
        $entry = $collection->getEmbeddingProviderModel();
        $reference = self::input($collection);

        if (null === $entry) {
            if (null === $reference) {
                $this->context->buildViolation($constraint->modelRequiredMessage)
                    ->atPath(ValidEmbeddingModel::PATH)
                    ->setParameter('{{ known }}', $this->knownModels())
                    ->setCode(ValidEmbeddingModel::MODEL_REQUIRED_ERROR)
                    ->addViolation();

                return;
            }
            $choice = $this->resolve($reference);
        } else {
            // Set by code: it must be usable now, and a reference given next
            // to it must name it.
            $choice = $this->choice(fn (): EmbeddingModelChoice => $this->resolver->check($entry), $entry->iri());
            if (null !== $choice && null !== $reference && !self::namesEntry($reference, $entry)) {
                $resolved = $this->resolve($reference);
                if (null === $resolved) {
                    return;
                }
                if (!self::sameEntry($resolved->entry, $entry)) {
                    $this->modelChange($constraint);

                    return;
                }
            }
        }
        if (null === $choice) {
            return;
        }

        $label = $collection->getEmbeddingModel();
        if (null !== $label && $label !== $choice->model->name) {
            $this->context->buildViolation($constraint->labelMismatchMessage)
                ->atPath(ValidEmbeddingModel::PATH)
                ->setParameter('{{ given }}', $this->formatValue($label))
                ->setParameter('{{ entry }}', $this->formatValue($choice->entry->getName()))
                ->setParameter('{{ expected }}', $this->formatValue($choice->model->name))
                ->setCode(ValidEmbeddingModel::INVALID_MODEL_ERROR)
                ->addViolation();

            return;
        }

        $this->validateDimension($collection, $choice->model, $constraint);
    }

    private function validateStored(Collection $collection, ValidEmbeddingModel $constraint): void
    {
        $original = $this->entityManager->getUnitOfWork()->getOriginalEntityData($collection);
        $entry = $collection->getEmbeddingProviderModel();
        $originalEntry = ($original['embeddingProviderModel'] ?? null) instanceof ProviderModel ? $original['embeddingProviderModel'] : null;
        $originalConnection = ($original['embeddingProviderConnection'] ?? null) instanceof ApiConfiguration ? $original['embeddingProviderConnection'] : null;
        $originalLabel = $original['embeddingModel'] ?? null;

        if (!self::sameEntry($originalEntry, $entry)
            || (\is_string($originalLabel) && $originalLabel !== $collection->getEmbeddingModel())
            || $originalConnection?->getId()->toRfc4122() !== $collection->getEmbeddingProviderConnection()?->getId()->toRfc4122()
        ) {
            $this->modelChange($constraint);

            return;
        }

        $reference = self::input($collection);
        if (null !== $reference && (null === $entry || !self::namesEntry($reference, $entry))) {
            $resolved = $this->resolve($reference);
            if (null !== $resolved && !self::sameEntry($resolved->entry, $entry)) {
                $this->modelChange($constraint);
            }

            return;
        }

        $label = $collection->getEmbeddingModel();
        if (null !== $label && $this->registry->has($label)) {
            $this->validateDimension($collection, $this->registry->get($label), $constraint);
        }
    }

    private function validateDimension(Collection $collection, EmbeddingModel $model, ValidEmbeddingModel $constraint): void
    {
        $dimension = $collection->getEmbeddingDimension();
        if (null !== $dimension && $dimension !== $model->dimension) {
            $this->context->buildViolation($constraint->dimensionMessage)
                ->atPath('embeddingDimension')
                ->setParameter('{{ given }}', $this->formatValue($dimension))
                ->setParameter('{{ expected }}', $this->formatValue($model->dimension))
                ->setParameter('{{ model }}', $this->formatValue($model->name))
                ->setCode(ValidEmbeddingModel::DIMENSION_MISMATCH_ERROR)
                ->addViolation();
        }
    }

    /**
     * Resolves the reference; a failure becomes a violation at
     * `embeddingModel` ({@see ValidEmbeddingModel::PATH}, null returned).
     */
    private function resolve(string $reference): ?EmbeddingModelChoice
    {
        return $this->choice(fn (): EmbeddingModelChoice => $this->resolver->resolve($reference), $reference);
    }

    /**
     * Runs $lookup; a {@see ProviderModelReferenceException} becomes a
     * violation at `embeddingModel` with its message (null returned).
     *
     * @param \Closure(): EmbeddingModelChoice $lookup
     */
    private function choice(\Closure $lookup, string $invalidValue): ?EmbeddingModelChoice
    {
        try {
            return $lookup();
        } catch (ProviderModelReferenceException $e) {
            $this->context->buildViolation($e->getMessage())
                ->atPath(ValidEmbeddingModel::PATH)
                ->setParameter('{{ reason }}', $e->reason)
                ->setInvalidValue($invalidValue)
                ->setCode(ProviderModelReferenceException::UNREGISTERED === $e->reason ? ValidEmbeddingModel::UNKNOWN_MODEL_ERROR : ValidEmbeddingModel::INVALID_MODEL_ERROR)
                ->addViolation();

            return null;
        }
    }

    private function modelChange(ValidEmbeddingModel $constraint): void
    {
        $this->context->buildViolation($constraint->modelChangeMessage)
            ->atPath(ValidEmbeddingModel::PATH)
            ->setCode(ValidEmbeddingModel::MODEL_CHANGE_ERROR)
            ->addViolation();
    }

    /**
     * The trimmed input reference, null when none or an empty one was given.
     */
    private static function input(Collection $collection): ?string
    {
        $reference = $collection->embeddingModelInput();

        return null !== $reference && '' !== trim($reference) ? trim($reference) : null;
    }

    /**
     * Whether $reference names $entry without a lookup: its full UUID or an
     * IRI ending in it.
     */
    private static function namesEntry(string $reference, ProviderModel $entry): bool
    {
        $id = $entry->getId()->toRfc4122();

        return 0 === strcasecmp($reference, $id)
            || (str_starts_with($reference, '/') && 0 === strcasecmp(basename($reference), $id));
    }

    private static function sameEntry(?ProviderModel $a, ?ProviderModel $b): bool
    {
        return $a?->getId()->toRfc4122() === $b?->getId()->toRfc4122();
    }

    private function knownModels(): string
    {
        return implode(', ', array_map(fn (string $name): string => $this->formatValue($name), $this->registry->names()));
    }
}
