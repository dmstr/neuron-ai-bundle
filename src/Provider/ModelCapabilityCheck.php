<?php

// file generated with AI assistance: Claude Code - 2026-10-06 17:28:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Provider;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Capability lookup in the model catalogue
 * ({@see ProviderModel}), without network I/O.
 *
 * The row of a model is the connection's row with exactly that name, else
 * the first one whose name is equivalent ({@see ModelName::equivalent()},
 * `x` vs. `x:latest`). Without a row, with `capabilities` null, or when the
 * row was described from another endpoint than the connection points to
 * now ({@see ProviderModel::isStale()}) or is no longer offered
 * ({@see ProviderModel::isAvailable()}), the verdict is `unknown`; callers
 * let `unknown` pass, because the catalogue is a snapshot refreshed by
 * scans and the live {@see ModelAvailabilityCheck} still guarantees that
 * the model is offered.
 */
final class ModelCapabilityCheck
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<string> $required capabilities the model must have
     */
    public function verdict(ApiConfiguration $connection, string $model, array $required): CapabilityVerdict
    {
        return self::verdictOf($this->find($connection, $model), $required);
    }

    /**
     * The verdict for a catalogue row the caller already has, e.g. one
     * resolved from a model reference ({@see ProviderModelResolver}); null
     * stands for "no row".
     *
     * @param list<string> $required capabilities the model must have
     */
    public static function verdictOf(?ProviderModel $row, array $required): CapabilityVerdict
    {
        $known = $row?->getCapabilities();
        if (null === $row || null === $known || $row->isStale() || !$row->isAvailable()) {
            return CapabilityVerdict::unknown();
        }

        $missing = array_values(array_diff($required, $known));

        return [] === $missing ? CapabilityVerdict::supported() : CapabilityVerdict::missing($missing, $known, $row->getId()->toRfc4122());
    }

    /**
     * The catalogue row of the model: exact name first, else an equivalent one.
     */
    public function find(ApiConfiguration $connection, string $model): ?ProviderModel
    {
        /** @var list<ProviderModel> $rows */
        $rows = $this->entityManager->getRepository(ProviderModel::class)->findBy(['apiConfiguration' => $connection]);

        return array_find($rows, static fn (ProviderModel $row): bool => $row->getName() === $model)
            ?? array_find($rows, static fn (ProviderModel $row): bool => ModelName::equivalent($row->getName(), $model));
    }
}

// - revised 2026-10-08
