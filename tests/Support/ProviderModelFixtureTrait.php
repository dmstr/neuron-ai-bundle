<?php

// file generated with AI assistance: Claude Code - 2026-10-08 15:38:00 UTC

declare(strict_types=1);

namespace Dmstr\NeuronAiBundle\Tests\Support;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\NeuronAiBundle\Entity\Refs\ProviderModel;
use Dmstr\NeuronAiBundle\Provider\EndpointFingerprint;
use Dmstr\NeuronAiBundle\Provider\ModelCapabilityCheck;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Catalogue entries for tests that reference a model
 * (`Refs\ProviderModel`, IRI or UUID) instead of naming a connection and a
 * model: one entry per connection and model name, created when missing
 * and reused otherwise.
 *
 * The lookup is {@see ModelCapabilityCheck::find()}, so an entry is reused
 * by its exact name first, else by an equivalent one (`llama3.2` vs.
 * `llama3.2:latest`), as the live check and the migrations of stored
 * references match names. The returned entry is always usable: available,
 * no `lastError`, described from the connection's current endpoint (not
 * stale). A test that needs an unavailable or stale entry changes it after
 * the call (and calls this again only to make it usable again).
 *
 * The methods are static and take the entity manager, so database-bound
 * suites (`AgentFixtures::entityManager()`), static helpers
 * (`ConversationConnections`) and kernel-free tests with the stub of
 * {@see InMemoryProviderCatalogueTrait} share them. Entries go with their
 * connection (FK ON DELETE CASCADE), so the suites' connection cleanup
 * removes them.
 */
trait ProviderModelFixtureTrait
{
    /**
     * @param list<string>|null $capabilities null keeps the entry's capabilities (unknown for a new entry)
     */
    public static function catalogueModel(EntityManagerInterface $entityManager, ApiConfiguration $connection, string $name, ?array $capabilities = null): ProviderModel
    {
        $model = (new ModelCapabilityCheck($entityManager))->find($connection, $name);
        if (null === $model) {
            $model = new ProviderModel($connection, $name);
            $entityManager->persist($model);
        }

        if (null !== $capabilities) {
            $model->setCapabilities($capabilities)->setSyncedAt(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        }
        $model->setAvailable(true)
            ->setLastError(null)
            ->setEndpointFingerprint(EndpointFingerprint::of($connection));
        $entityManager->flush();

        return $model;
    }

    /**
     * The IRI of {@see catalogueModel()}, as a request body carries it.
     *
     * @param list<string>|null $capabilities
     */
    public static function catalogueModelIri(EntityManagerInterface $entityManager, ApiConfiguration $connection, string $name, ?array $capabilities = null): string
    {
        return self::catalogueModel($entityManager, $connection, $name, $capabilities)->iri();
    }
}
